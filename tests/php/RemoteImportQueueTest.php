<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\CookieJar;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\RemoteImportQueue;
use OCA\MusicRadio\Service\RemoteImportSettings;
use OCA\MusicRadio\Service\ToolStatus;
use OCA\MusicRadio\Service\WorkerScript;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCP\AppFramework\Http;
use OCP\ITempManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The queue as a remote worker sees it.
 *
 * Two things carry the weight here and they are the two things that would be dangerous to
 * get wrong. The **lease** is what separates "an allow-listed account" from "the worker
 * actually doing this job", so every way of reporting on a job has to refuse one that is
 * not held. The **command line** is written by the server and executed on somebody else's
 * machine, so what goes into it — and what is left out of it — is asserted rather than
 * assumed.
 */
class RemoteImportQueueTest extends TestCase {

	private const NOW = 1_700_000_000;
	private const IMPORT_ID = 42;
	private const LEASE = 'aaaabbbbccccddddeeeeffff00001111aaaabbbbccccddddeeeeffff00001111';
	private const VIDEO_ID = 'dQw4w9WgXcQ';

	private ImportMapper&MockObject $importMapper;
	private YoutubeImportService&MockObject $importService;
	private RemoteImportSettings&MockObject $settings;
	private WorkerScript&MockObject $workerScript;
	private CookieJar&MockObject $cookieJar;
	private RemoteImportQueue $queue;

	protected function setUp(): void {
		parent::setUp();

		$this->importMapper = $this->createMock(ImportMapper::class);
		$this->importService = $this->createMock(YoutubeImportService::class);
		$this->settings = $this->createMock(RemoteImportSettings::class);
		$this->workerScript = $this->createMock(WorkerScript::class);
		$this->cookieJar = $this->createMock(CookieJar::class);

		$this->importService->method('maxDurationSeconds')->willReturn(5400);
		$this->importService->method('maxSourceBytes')->willReturn(300 * 1024 * 1024);
		$this->importService->method('ownerOf')->willReturn('owner');

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::LEASE);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$this->queue = new RemoteImportQueue(
			$this->importMapper,
			$this->importService,
			$this->settings,
			$this->workerScript,
			$this->cookieJar,
			$this->createMock(ITempManager::class),
			$random,
			$clock,
			new NullLogger(),
		);
	}

	// ---------------------------------------------------------------- helpers

	private function import(int $status = Import::STATUS_RUNNING, ?string $lease = self::LEASE): Import {
		$import = new Import();
		$import->setId(self::IMPORT_ID);
		$import->setChannelId(7);
		$import->setUserId('contributor');
		$import->setVideoId(self::VIDEO_ID);
		$import->setStatus($status);
		$import->setPhase(Import::PHASE_RESOLVING);
		$import->setProgress(0);
		$import->setRemote(true);
		$import->setLeaseToken($lease);
		$import->setWorkerId('nas');

		return $import;
	}

	private function queueHolds(Import $import): void {
		$this->importMapper->method('findById')->willReturn($import);
	}

	/** The little the greeting needs beyond whatever a test is actually about. */
	private function greetable(): void {
		$this->settings->method('mode')->willReturn(RemoteImportSettings::MODE_REMOTE);
		$this->importMapper->method('remoteQueueDepth')->willReturn(['queued' => 0, 'running' => 0]);
		// ToolStatus is final, so it cannot be doubled — and does not need to be: it is a
		// value object with a constructor.
		$this->importService->method('availability')
			->willReturn(new ToolStatus(available: true, reason: null));
	}

	// ---------------------------------------------------------------- hello

	public function testTheGreetingCarriesTheScriptChecksum(): void {
		// What makes a quarter-hourly self-update check affordable: the worker compares a
		// checksum it was given on a call it was making anyway, and asks for the ~40 KB body
		// only when the two differ.
		$this->greetable();
		$this->workerScript->method('describe')->willReturn([
			'version' => '0.13.0',
			'sha256' => str_repeat('a', 64),
			'bytes' => 41554,
		]);

		$greeting = $this->queue->greeting('radio-worker');

		$this->assertSame(str_repeat('a', 64), $greeting['workerScript']['sha256']);
		$this->assertSame(RemoteImportSettings::PROTOCOL, $greeting['protocol']);
	}

	public function testAnInstallWithNoWorkerDirectoryOffersNoScript(): void {
		// A stripped install has nothing to hand out, and a worker that is offered nothing
		// simply carries on with the copy it has.
		$this->greetable();
		$this->workerScript->method('describe')->willReturn(null);

		$this->assertNull($this->queue->greeting('radio-worker')['workerScript']);
	}

	// ------------------------------------------------------------- collecting

	public function testAnEmptyQueueHandsOutNothing(): void {
		$this->importMapper->method('nextQueuedRemote')->willReturn(null);

		$this->assertNull($this->queue->claim('nas', null));
	}

	public function testCollectingSaysTheWorkerIsAlive(): void {
		// The only thing that keeps the feature reporting itself as available, so it has to
		// happen on every poll — including the ones that find nothing to do.
		$this->settings->expects($this->once())
			->method('markSeen')
			->with('nas', 'node:/usr/bin/node');
		$this->importMapper->method('nextQueuedRemote')->willReturn(null);

		$this->queue->claim('nas', 'node:/usr/bin/node');
	}

	public function testExpiredLeasesAreTakenBackBeforeWorkIsLookedFor(): void {
		// The machine most likely to notice that a job's worker died is the one asking for
		// work, and it is a great deal quicker than the five-minutely sweep.
		$this->importMapper->expects($this->once())
			->method('requeueExpiredLeases')
			->with(self::NOW - RemoteImportSettings::LEASE_SECONDS, RemoteImportSettings::MAX_ATTEMPTS);
		$this->importMapper->method('nextQueuedRemote')->willReturn(null);

		$this->queue->claim('nas', null);
	}

	public function testLosingTheRaceForEveryJobIsAnsweredWithNothingToDo(): void {
		// Several workers on one queue is the ordinary arrangement, so losing is expected
		// rather than exceptional — and there is genuinely nothing left for this one.
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(0);

		$this->assertNull($this->queue->claim('nas', null));
	}

	public function testAClaimedJobCarriesBothCommandLines(): void {
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(1);
		$this->queueHolds($this->import());

		$job = $this->queue->claim('nas', 'node:/usr/bin/node');

		$this->assertNotNull($job);
		$this->assertSame(self::IMPORT_ID, $job['importId']);
		$this->assertSame(self::LEASE, $job['lease']);
		$this->assertSame('https://www.youtube.com/watch?v=' . self::VIDEO_ID, $job['url']);

		// The program run is always the worker's own yt-dlp, named by a placeholder. This
		// is the assertion that stops a job descriptor ever becoming "run this binary".
		$this->assertSame(RemoteImportQueue::PLACEHOLDER_YTDLP, $job['probeArgv'][0]);
		$this->assertSame(RemoteImportQueue::PLACEHOLDER_YTDLP, $job['downloadArgv'][0]);

		// The flags that make a run safe travel with it, so a worker installed months ago
		// still gets today's ones.
		$this->assertContains('--ignore-config', $job['downloadArgv']);
		$this->assertContains('--no-exec', $job['downloadArgv']);
		$this->assertContains('--no-playlist', $job['downloadArgv']);
		$this->assertSame(self::VIDEO_ID, substr((string)end($job['downloadArgv']), -strlen(self::VIDEO_ID)));

		// The runtime the *worker* reported, not this server's.
		$this->assertContains('node:/usr/bin/node', $job['probeArgv']);
	}

	public function testAWorkerWithoutARuntimeIsNotHandedTheFlag(): void {
		// `--js-runtimes` with nothing to point at is an error yt-dlp refuses to start on,
		// which would turn "some downloads will fail" into "nothing works at all".
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(1);
		$this->queueHolds($this->import());

		$job = $this->queue->claim('nas', null);

		$this->assertNotContains('--js-runtimes', $job['probeArgv']);
		$this->assertNotContains('--js-runtimes', $job['downloadArgv']);
	}

	public function testCookiesAreNotLentUnlessAnAdministratorSaidSo(): void {
		$this->settings->method('forwardsCookies')->willReturn(false);
		$this->cookieJar->method('has')->willReturn(true);
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(1);
		$this->queueHolds($this->import());

		$job = $this->queue->claim('nas', 'node:/usr/bin/node');

		$this->assertFalse($job['cookies']);
		$this->assertNotContains('--cookies', $job['downloadArgv']);
	}

	public function testCookiesAreWithheldFromAWorkerWithNoJavascriptRuntime(): void {
		// The same judgement the local path makes: authenticating moves yt-dlp onto
		// clients that need an engine, so a jar would break an import that works.
		$this->settings->method('forwardsCookies')->willReturn(true);
		$this->cookieJar->method('has')->willReturn(true);
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(1);
		$this->queueHolds($this->import());

		$job = $this->queue->claim('nas', null);

		$this->assertFalse($job['cookies']);
	}

	public function testAnEquippedWorkerIsOfferedTheJar(): void {
		$this->settings->method('forwardsCookies')->willReturn(true);
		$this->cookieJar->method('has')->willReturn(true);
		$this->importMapper->method('nextQueuedRemote')->willReturn(self::IMPORT_ID);
		$this->importMapper->method('claimRemote')->willReturn(1);
		$this->queueHolds($this->import());

		$job = $this->queue->claim('nas', 'node:/usr/bin/node');

		$this->assertTrue($job['cookies']);
		$this->assertContains('--cookies', $job['downloadArgv']);
		$this->assertContains(RemoteImportQueue::PLACEHOLDER_COOKIES, $job['downloadArgv']);
	}

	// ------------------------------------------------------------- the lease

	public function testReportingOnAJobWithoutItsLeaseIsRefused(): void {
		$this->queueHolds($this->import());

		$this->expectException(MusicRadioException::class);

		try {
			$this->queue->progress(self::IMPORT_ID, 'not-the-lease', 'downloading', 50);
		} catch (MusicRadioException $e) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $e->getStatus());
			throw $e;
		}
	}

	public function testAFinishedJobCanNoLongerBeReportedOn(): void {
		// The token is cleared when a job ends, which is also what makes a retried upload —
		// the one whose answer was lost on the way back — a refusal rather than a second
		// copy of the track.
		$this->queueHolds($this->import(Import::STATUS_DONE, null));

		$this->expectException(MusicRadioException::class);
		$this->queue->progress(self::IMPORT_ID, self::LEASE, 'downloading', 50);
	}

	public function testAJobThatHasGoneIsReportedAsGone(): void {
		$this->importMapper->method('findById')->willThrowException(new \RuntimeException('no row'));

		try {
			$this->queue->progress(self::IMPORT_ID, self::LEASE, 'downloading', 50);
			$this->fail('a missing import should be refused');
		} catch (MusicRadioException $e) {
			// Deleting a channel takes its imports with it, and a worker holding one should
			// stop rather than finish.
			$this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus());
		}
	}

	public function testALocalJobIsNotAWorkersToTouch(): void {
		$import = $this->import();
		$import->setRemote(false);
		$this->queueHolds($import);

		$this->expectException(MusicRadioException::class);
		$this->queue->progress(self::IMPORT_ID, self::LEASE, 'downloading', 50);
	}

	// ------------------------------------------------------------- reporting

	public function testProgressIsRecordedAndClampedToASensibleRange(): void {
		$this->queueHolds($this->import());
		$this->importMapper->expects($this->once())
			->method('touch')
			->with(self::IMPORT_ID, self::NOW, Import::PHASE_DOWNLOADING, 100);

		$answer = $this->queue->progress(self::IMPORT_ID, self::LEASE, 'downloading', 400);

		$this->assertFalse($answer['cancelled']);
	}

	public function testACancelledJobIsToldToStop(): void {
		// The only way a cancellation reaches the worker: whoever pressed the button is
		// talking to Nextcloud, and this answer is the whole of their conversation.
		$this->queueHolds($this->import(Import::STATUS_CANCELLED));
		$this->importMapper->expects($this->never())->method('touch');

		$this->assertTrue($this->queue->progress(self::IMPORT_ID, self::LEASE, 'downloading', 50)['cancelled']);
	}

	public function testMetadataThatBreaksARuleStopsTheDownloadBeforeItStarts(): void {
		$this->queueHolds($this->import());
		$this->importService->method('inspect')->willReturn(ImportError::TOO_LONG);
		$this->importService->expects($this->once())
			->method('fail')
			->with($this->anything(), ImportError::TOO_LONG);

		$answer = $this->queue->metadata(self::IMPORT_ID, self::LEASE, ['duration' => 99999]);

		$this->assertFalse($answer['proceed']);
		$this->assertSame(ImportError::TOO_LONG, $answer['code']);
	}

	public function testAcceptableMetadataNamesTheRowAndSaysCarryOn(): void {
		$this->queueHolds($this->import());
		$this->importService->method('inspect')->willReturn(null);
		$this->importService->expects($this->once())->method('recordMetadata');

		$this->assertTrue($this->queue->metadata(self::IMPORT_ID, self::LEASE, ['title' => 'A song'])['proceed']);
	}

	// ------------------------------------------------------------- failures

	public function testAFailureIsClassifiedHereFromWhatYtDlpSaid(): void {
		// The worker deliberately does not decide why: reading yt-dlp's stderr is a hundred
		// lines of pattern matching that changes as YouTube changes, and a copy of it on
		// every worker machine would be a copy going stale.
		$this->queueHolds($this->import());
		$this->importService->expects($this->once())
			->method('fail')
			->with($this->anything(), ImportError::VIDEO_PRIVATE, $this->anything());

		$this->queue->failed(
			self::IMPORT_ID,
			self::LEASE,
			'probe',
			null,
			1,
			false,
			false,
			false,
			true,
			'',
			'ERROR: [youtube] dQw4w9WgXcQ: Private video. Sign in if you have been granted access',
		);
	}

	public function testAWorkerMayNameOnlyTheFailuresItCanSeeForItself(): void {
		$this->queueHolds($this->import());
		$this->importService->expects($this->once())
			->method('fail')
			->with($this->anything(), ImportError::TIMED_OUT, $this->anything());

		$this->queue->failed(self::IMPORT_ID, self::LEASE, 'download', ImportError::TIMED_OUT,
			1, true, false, false, true, '', '');
	}

	public function testAWorkerCannotInventAnyOldCode(): void {
		// `quota_exceeded` is this server's finding, made when the file is filed. A worker
		// claiming it would be putting words in the server's mouth, so the output is read
		// instead — which here says nothing, and lands on `unknown`.
		$this->queueHolds($this->import());
		$this->importService->expects($this->once())
			->method('fail')
			->with($this->anything(), ImportError::UNKNOWN, $this->anything());

		$this->queue->failed(self::IMPORT_ID, self::LEASE, 'download', ImportError::QUOTA_EXCEEDED,
			1, false, false, false, true, '', '');
	}

	public function testACancelledJobIsNotOverwrittenWithAFailure(): void {
		// The person stopped it. Being told afterwards that it "failed" would be a worse
		// account of what happened than the one they already have.
		$this->queueHolds($this->import(Import::STATUS_CANCELLED));
		$this->importService->expects($this->never())->method('fail');

		$this->queue->failed(self::IMPORT_ID, self::LEASE, 'download', null,
			1, false, false, false, true, '', 'ERROR: something');
	}

	// -------------------------------------------------------------- cookies

	public function testTheJarIsNotHandedOverWhenForwardingIsOff(): void {
		$this->settings->method('forwardsCookies')->willReturn(false);
		$this->cookieJar->expects($this->never())->method('lend');
		$this->queueHolds($this->import());

		$this->assertNull($this->queue->cookiesFor(self::IMPORT_ID, self::LEASE));
	}

	public function testTheJarIsTheChannelOwnersRatherThanTheImportersOwn(): void {
		// The same arrangement as the rest of an import: the audio lands in the owner's
		// storage whoever asked for it, and it is their session that fetches it.
		$this->settings->method('forwardsCookies')->willReturn(true);
		$this->cookieJar->expects($this->once())->method('lend')->with('owner')->willReturn('# jar');
		$this->queueHolds($this->import());

		$this->assertSame('# jar', $this->queue->cookiesFor(self::IMPORT_ID, self::LEASE));
	}

	public function testARotatedJarComesBackToTheOwner(): void {
		$this->settings->method('forwardsCookies')->willReturn(true);
		$this->cookieJar->expects($this->once())->method('refreshWith')->with('owner', '# rotated');
		$this->queueHolds($this->import());

		$this->queue->returnCookies(self::IMPORT_ID, self::LEASE, '# rotated');
	}

	public function testAJarCannotBeReturnedWithoutTheLease(): void {
		$this->cookieJar->expects($this->never())->method('refreshWith');
		$this->queueHolds($this->import());

		$this->expectException(MusicRadioException::class);
		$this->queue->returnCookies(self::IMPORT_ID, 'not-the-lease', '# rotated');
	}
}
