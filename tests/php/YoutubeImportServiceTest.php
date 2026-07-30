<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\BackgroundJob\ImportYoutubeAudioJob;
use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\MusicLibrary;
use OCA\MusicRadio\Service\ToolStatus;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCA\MusicRadio\Tests\Fake\FakeProcessRunner;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The import state machine.
 *
 * Every dependency here is either an OCP interface or a class whose methods can be mocked,
 * which is what makes the whole of perform() testable without a server, a network or a
 * binary — a fake runner returns whatever a real yt-dlp would have, and the assertions are
 * about what ends up on the row.
 *
 * The failure paths carry most of the weight. A successful import is one path; the ways it
 * can fail are the reason the row has an error code at all, and each one has to leave
 * something a person can act on.
 */
class YoutubeImportServiceTest extends TestCase {

	private const NOW = 1_700_000_000;
	private const CHANNEL_ID = 7;
	private const OWNER = 'owner';
	private const IMPORTER = 'contributor';
	private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
	private const VIDEO_ID = 'dQw4w9WgXcQ';

	private ImportMapper&MockObject $importMapper;
	private ChannelMapper&MockObject $channelMapper;
	private YtDlpLocator&MockObject $locator;
	private MusicLibrary&MockObject $library;
	private IJobList&MockObject $jobList;
	private IAppConfig&MockObject $appConfig;
	private FakeProcessRunner $runner;
	private YoutubeImportService $service;
	private string $temporaryDirectory;
	/** @var list<Import> every row the service wrote, newest last */
	private array $savedImports = [];

	protected function setUp(): void {
		parent::setUp();

		$this->importMapper = $this->createMock(ImportMapper::class);
		$this->channelMapper = $this->createMock(ChannelMapper::class);
		$this->locator = $this->createMock(YtDlpLocator::class);
		$this->library = $this->createMock(MusicLibrary::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->runner = new FakeProcessRunner();

		// A real directory, so the service's own file discovery and cleanup are exercised
		// rather than mocked away.
		$this->temporaryDirectory = sys_get_temp_dir() . '/mr-import-test-' . bin2hex(random_bytes(6));
		mkdir($this->temporaryDirectory, 0700, true);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFolder')->willReturn($this->temporaryDirectory);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$this->appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');

		$this->locator->method('status')->willReturn(new ToolStatus(
			available: true,
			reason: null,
			ytDlpPath: '/usr/local/bin/yt-dlp',
			ytDlpVersion: '2026.07.04',
			ffmpegDir: '/usr/bin',
		));

		$this->service = new YoutubeImportService(
			$this->importMapper,
			$this->channelMapper,
			$this->locator,
			$this->runner,
			$this->library,
			$tempManager,
			$this->jobList,
			$this->appConfig,
			$config,
			$clock,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		if (is_dir($this->temporaryDirectory)) {
			foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->temporaryDirectory);
		}

		parent::tearDown();
	}

	// ---------------------------------------------------------------- helpers

	private static function channel(): Channel {
		$channel = new Channel();
		$channel->setId(self::CHANNEL_ID);
		$channel->setUserId(self::OWNER);
		$channel->setTitle('A channel');

		return $channel;
	}

	private static function import(int $status = Import::STATUS_QUEUED): Import {
		$import = new Import();
		$import->setId(42);
		$import->setChannelId(self::CHANNEL_ID);
		$import->setUserId(self::IMPORTER);
		$import->setSource(Import::SOURCE_YOUTUBE);
		$import->setVideoId(self::VIDEO_ID);
		$import->setStatus($status);
		$import->setPhase(Import::PHASE_PENDING);
		$import->setProgress(0);
		$import->setAttempts(0);
		$import->setCreatedAt(self::NOW);

		return $import;
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private static function metadata(array $overrides = []): string {
		return json_encode(array_merge([
			'id' => self::VIDEO_ID,
			'title' => 'A Song',
			'uploader' => 'An Artist',
			'duration' => 210,
			'is_live' => false,
			'live_status' => 'not_live',
			'availability' => 'public',
			'age_limit' => 0,
		], $overrides), JSON_THROW_ON_ERROR);
	}

	/** Let the import be claimed, and let the status polls say it is running. */
	private function allowClaim(): void {
		$this->importMapper->method('claim')->willReturn(1);
		$this->importMapper->method('statusOf')->willReturn(Import::STATUS_RUNNING);
	}

	// ------------------------------------------------------------- requesting

	public function testRequestQueuesAnImportAndSchedulesAJob(): void {
		$this->importMapper->method('hasActiveForVideo')->willReturn(false);
		$this->importMapper->method('countActiveForUser')->willReturn(0);
		$this->importMapper->method('countActiveForChannel')->willReturn(0);
		$this->importMapper->method('insert')->willReturnCallback(static function (Import $import): Import {
			$import->setId(99);

			return $import;
		});

		$this->jobList->expects(self::once())
			->method('add')
			->with(ImportYoutubeAudioJob::class, ['importId' => 99]);

		$import = $this->service->request(self::channel(), self::IMPORTER, self::URL);

		self::assertSame(Import::STATUS_QUEUED, $import->getStatus());
		// Stored canonical, never the string that was pasted.
		self::assertSame(self::VIDEO_ID, $import->getVideoId());
		self::assertSame(self::IMPORTER, $import->getUserId());
	}

	public function testRequestRefusesSomethingThatIsNotAYoutubeLink(): void {
		$this->jobList->expects(self::never())->method('add');

		$this->expectException(MusicRadioException::class);
		$this->expectExceptionMessage(ImportError::NOT_A_YOUTUBE_URL);

		$this->service->request(self::channel(), self::IMPORTER, 'https://evil.example/watch?v=dQw4w9WgXcQ');
	}

	public function testRequestRefusesWhenTheSameVideoIsAlreadyBeingImported(): void {
		$this->importMapper->method('hasActiveForVideo')->willReturn(true);
		$this->jobList->expects(self::never())->method('add');

		$this->expectException(MusicRadioException::class);
		$this->expectExceptionMessage(ImportError::DUPLICATE_IN_FLIGHT);

		$this->service->request(self::channel(), self::IMPORTER, self::URL);
	}

	public function testRequestRefusesWhenTheUserAlreadyHasEnoughRunning(): void {
		$this->importMapper->method('hasActiveForVideo')->willReturn(false);
		$this->importMapper->method('countActiveForUser')
			->willReturn(YoutubeImportService::MAX_ACTIVE_PER_USER);
		$this->jobList->expects(self::never())->method('add');

		$this->expectException(MusicRadioException::class);
		$this->expectExceptionMessage(ImportError::TOO_MANY_IMPORTS);

		$this->service->request(self::channel(), self::IMPORTER, self::URL);
	}

	public function testRequestRefusesWhenTheChannelAlreadyHasEnoughRunning(): void {
		$this->importMapper->method('hasActiveForVideo')->willReturn(false);
		$this->importMapper->method('countActiveForUser')->willReturn(0);
		$this->importMapper->method('countActiveForChannel')
			->willReturn(YoutubeImportService::MAX_ACTIVE_PER_CHANNEL);

		$this->expectException(MusicRadioException::class);
		$this->expectExceptionMessage(ImportError::TOO_MANY_IMPORTS);

		$this->service->request(self::channel(), self::IMPORTER, self::URL);
	}

	public function testRequestRefusesWhenTheServerCannotImport(): void {
		$locator = $this->createMock(YtDlpLocator::class);
		$locator->method('status')->willReturn(ToolStatus::unavailable(ImportError::FFMPEG_MISSING));

		$service = $this->serviceWith($locator);

		$this->expectException(MusicRadioException::class);
		$this->expectExceptionMessage(ImportError::FFMPEG_MISSING);

		$service->request(self::channel(), self::IMPORTER, self::URL);
	}

	/**
	 * A row nothing will ever pick up must not be left looking like it is waiting.
	 */
	public function testAnImportThatCannotBeQueuedIsFailedImmediately(): void {
		$this->importMapper->method('hasActiveForVideo')->willReturn(false);
		$this->importMapper->method('countActiveForUser')->willReturn(0);
		$this->importMapper->method('countActiveForChannel')->willReturn(0);
		$this->importMapper->method('insert')->willReturnCallback(static function (Import $import): Import {
			$import->setId(99);

			return $import;
		});
		$this->importMapper->method('statusOf')->willReturn(Import::STATUS_QUEUED);
		$this->jobList->method('add')->willThrowException(new \RuntimeException('queue is down'));

		$failed = null;
		$this->importMapper->method('update')->willReturnCallback(
			static function (Import $import) use (&$failed): Import {
				$failed = $import;

				return $import;
			},
		);

		try {
			$this->service->request(self::channel(), self::IMPORTER, self::URL);
			self::fail('expected a refusal');
		} catch (MusicRadioException) {
			// expected
		}

		self::assertNotNull($failed);
		self::assertSame(Import::STATUS_FAILED, $failed->getStatus());
	}

	// --------------------------------------------------------------- claiming

	/**
	 * Cron can hand the same queued job to two workers. The second must do nothing at all
	 * — not download, not write, not fail the row somebody else is working on.
	 */
	public function testAnImportSomebodyElseClaimedIsLeftAlone(): void {
		$this->importMapper->method('claim')->willReturn(0);
		$this->importMapper->expects(self::never())->method('update');

		$this->service->perform(self::import());

		self::assertSame([], $this->runner->calls, 'nothing should have been run');
	}

	// -------------------------------------------------------------- the happy path

	public function testASuccessfulImportFilesTheTrackAndRecordsIt(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());

		$this->runner->queueSuccess(self::metadata());   // probe
		$this->runner->queueSuccess();                    // download
		$this->runner->producesFile = 'audio.mp3';

		$track = new Track();
		$track->setId(555);

		$this->library->expects(self::once())
			->method('ingest')
			->with(
				self::anything(),
				// The owner's storage, not the importer's.
				self::equalTo(self::OWNER),
				self::stringEndsWith('/audio.mp3'),
				self::equalTo('A Song.mp3'),
				// Credited to whoever asked.
				self::equalTo(self::IMPORTER),
				self::equalTo(210_000),
			)
			->willReturn($track);

		$this->captureUpdates();

		$this->service->perform(self::import());

		$final = $this->lastSaved();
		self::assertSame(Import::STATUS_DONE, $final->getStatus());
		self::assertSame(555, $final->getTrackId());
		self::assertSame('A Song', $final->getTitle());
		self::assertNull($final->getErrorCode());
	}

	public function testTheProbeRunsBeforeTheDownload(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';
		$this->library->method('ingest')->willReturn(new Track());

		$this->service->perform(self::import());

		self::assertCount(2, $this->runner->calls);
		self::assertContains('--dump-single-json', $this->runner->calls[0]);
		self::assertNotContains('--dump-single-json', $this->runner->calls[1]);
		self::assertContains('--extract-audio', $this->runner->calls[1]);
	}

	/**
	 * The command must be built from the canonical URL, never from anything a caller
	 * supplied — this is the same property YoutubeUrlTest pins, asserted where it matters.
	 */
	public function testTheDownloadIsAskedForTheCanonicalUrlOnly(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';
		$this->library->method('ingest')->willReturn(new Track());

		$this->service->perform(self::import());

		$argv = $this->runner->calls[1];
		self::assertSame(self::URL, $argv[array_key_last($argv)]);
		self::assertSame('--', $argv[array_key_last($argv) - 1]);
	}

	// ------------------------------------------------------- pre-flight refusals

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function metadataRefusalProvider(): array {
		return [
			'a live stream' => [['is_live' => true], ImportError::LIVE_STREAM],
			'an upcoming premiere' => [['live_status' => 'is_upcoming'], ImportError::LIVE_STREAM],
			'a members-only video' => [['availability' => 'subscriber_only'], ImportError::MEMBERS_ONLY],
			'a private video' => [['availability' => 'private'], ImportError::VIDEO_PRIVATE],
			'a video needing an account' => [['availability' => 'needs_auth'], ImportError::VIDEO_PRIVATE],
			'an age-restricted video' => [['age_limit' => 18], ImportError::AGE_RESTRICTED],
			'a video over the length limit' => [['duration' => 99999], ImportError::TOO_LONG],
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	#[DataProvider('metadataRefusalProvider')]
	public function testTheProbeRefusesBeforeDownloadingAnything(array $overrides, string $expected): void {
		$this->allowClaim();
		$this->runner->queueSuccess(self::metadata($overrides));

		$this->captureUpdates();
		$this->library->expects(self::never())->method('ingest');

		$this->service->perform(self::import());

		self::assertCount(1, $this->runner->calls, 'nothing should have been downloaded');
		self::assertSame($expected, $this->lastSaved()->getErrorCode());
		self::assertSame(Import::STATUS_FAILED, $this->lastSaved()->getStatus());
	}

	public function testAProbeThatFailsIsClassifiedNotRetried(): void {
		$this->allowClaim();
		$this->runner->queueFailure('ERROR: [youtube] abc: Private video');

		$this->captureUpdates();

		$this->service->perform(self::import());

		self::assertCount(1, $this->runner->calls);
		self::assertSame(ImportError::VIDEO_PRIVATE, $this->lastSaved()->getErrorCode());
	}

	public function testUnreadableMetadataFails(): void {
		$this->allowClaim();
		$this->runner->queueSuccess('this is not json');

		$this->captureUpdates();

		$this->service->perform(self::import());

		self::assertSame(ImportError::UNKNOWN, $this->lastSaved()->getErrorCode());
	}

	// ------------------------------------------------------------ download failures

	public function testADownloadThatProducesNoFileFails(): void {
		$this->allowClaim();
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		// producesFile stays null: yt-dlp exited cleanly and wrote nothing.

		$this->captureUpdates();
		$this->library->expects(self::never())->method('ingest');

		$this->service->perform(self::import());

		self::assertSame(ImportError::NO_AUDIO, $this->lastSaved()->getErrorCode());
	}

	public function testABrokenExtractorTellsTheUserToUpdateYtDlp(): void {
		$this->allowClaim();
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueFailure('ERROR: [youtube] abc: nsig extraction failed');

		$this->captureUpdates();

		$this->service->perform(self::import());

		self::assertSame(ImportError::DOWNLOADER_OUTDATED, $this->lastSaved()->getErrorCode());
	}

	// ------------------------------------------------------------------ storage

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function storageFailureProvider(): array {
		return [
			'a full quota' => ['There is not enough space left on this channel', ImportError::QUOTA_EXCEEDED],
			'a full channel' => ['This channel has reached its track limit', ImportError::CHANNEL_FULL],
			'something else' => ['That audio format is not supported', ImportError::NO_AUDIO],
		];
	}

	#[DataProvider('storageFailureProvider')]
	public function testAFailureOnTheWayIntoFilesIsRecorded(string $message, string $expected): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';
		$this->library->method('ingest')->willThrowException(new MusicRadioException($message));

		$this->captureUpdates();

		$this->service->perform(self::import());

		self::assertSame($expected, $this->lastSaved()->getErrorCode());
		self::assertSame(Import::STATUS_FAILED, $this->lastSaved()->getStatus());
	}

	public function testAnImportWhoseChannelWasDeletedFailsQuietly(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';

		$this->captureUpdates();

		$this->service->perform(self::import());

		self::assertSame(Import::STATUS_FAILED, $this->lastSaved()->getStatus());
	}

	// ---------------------------------------------------------------- cancelling

	/**
	 * Deleting a channel takes its imports with it. The worker has to notice, or it keeps
	 * downloading for something nobody can see — and because only one import runs at a
	 * time, everything queued behind it waits for a result that will be thrown away.
	 */
	public function testAnImportWhoseRowHasGoneIsAbandoned(): void {
		$this->importMapper->method('claim')->willReturn(1);
		$this->importMapper->method('statusOf')->willReturn(null);
		$this->runner->queueSuccess(self::metadata());

		$this->service->perform(self::import());

		self::assertCount(1, $this->runner->calls, 'the download should never have started');
		self::assertTrue($this->runner->sawAbort, 'the runner was not told to stop');
	}

	public function testACancelledImportIsNotOverwrittenWithAFailure(): void {
		$this->importMapper->method('claim')->willReturn(1);
		// The worker notices at its next status check that somebody stopped it.
		$this->importMapper->method('statusOf')->willReturn(Import::STATUS_CANCELLED);
		$this->runner->queueSuccess(self::metadata());

		// Telling someone their import "failed" when they cancelled it would be a lie.
		$this->importMapper->expects(self::never())->method('update');

		$this->service->perform(self::import());
	}

	// ------------------------------------------------------------------ cleanup

	public function testTheTemporaryDirectoryIsAlwaysRemoved(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';
		$this->library->method('ingest')->willReturn(new Track());
		$this->importMapper->method('update')->willReturnArgument(0);

		$this->service->perform(self::import());

		self::assertDirectoryDoesNotExist(
			$this->temporaryDirectory,
			'a long-lived worker would accumulate one of these per import',
		);
	}

	public function testTheTemporaryDirectoryIsRemovedAfterAFailureToo(): void {
		$this->allowClaim();
		$this->runner->queueFailure('ERROR: [youtube] abc: Video unavailable');
		$this->importMapper->method('update')->willReturnArgument(0);

		$this->service->perform(self::import());

		self::assertDirectoryDoesNotExist($this->temporaryDirectory);
	}

	// --------------------------------------------------------------- progress

	public function testProgressIsWrittenWhileDownloading(): void {
		$this->allowClaim();
		$this->channelMapper->method('find')->willReturn(self::channel());
		$this->runner->queueSuccess(self::metadata());
		$this->runner->queueSuccess();
		$this->runner->producesFile = 'audio.mp3';
		$this->runner->withProgress(['mrprogress:500 1000 NA']);
		$this->library->method('ingest')->willReturn(new Track());
		$this->importMapper->method('update')->willReturnArgument(0);

		$phases = [];
		$this->importMapper->method('touch')->willReturnCallback(
			static function (int $id, int $now, int $phase, int $progress) use (&$phases): void {
				$phases[] = [$phase, $progress];
			},
		);

		$this->service->perform(self::import());

		self::assertContains([Import::PHASE_DOWNLOADING, 0], $phases);
		self::assertContains([Import::PHASE_SAVING, 100], $phases);
	}

	// ---------------------------------------------------------------- plumbing

	/**
	 * Record every row the service writes, so a test can assert on the last one.
	 *
	 * The saved rows live on the test rather than being returned, because a returned array
	 * would be a copy taken before the callback ever ran.
	 */
	private function captureUpdates(): void {
		$this->importMapper->method('update')->willReturnCallback(
			function (Import $import): Import {
				$this->savedImports[] = clone $import;

				return $import;
			},
		);
	}

	private function lastSaved(): Import {
		self::assertNotEmpty($this->savedImports, 'the service never wrote to the import row');

		return $this->savedImports[array_key_last($this->savedImports)];
	}

	private function serviceWith(YtDlpLocator $locator): YoutubeImportService {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFolder')->willReturn($this->temporaryDirectory);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');

		return new YoutubeImportService(
			$this->importMapper,
			$this->channelMapper,
			$locator,
			$this->runner,
			$this->library,
			$tempManager,
			$this->jobList,
			$this->appConfig,
			$config,
			$clock,
			new NullLogger(),
		);
	}
}
