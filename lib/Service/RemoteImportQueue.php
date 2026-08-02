<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Process\ProcessResult;
use OCP\AppFramework\Http;
use OCP\ITempManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The queue as a remote worker sees it.
 *
 * Everything the worker does goes through here: collect a job, say what the video turned
 * out to be, report progress, hand back the audio, or say why it could not. The class is
 * the *transport* half of an import — the decisions all still belong to
 * {@see YoutubeImportService}, which is why this calls into it rather than reimplementing
 * anything. What may be imported, whose storage it lands in, what a yt-dlp failure means:
 * all of that is answered in one place, for both kinds of worker.
 *
 * Two ideas carry the whole design.
 *
 * **The server writes the command line.** The worker is not told "import this video" and
 * left to work out how; it is handed the exact argv, built by {@see YtDlpArgv} with three
 * placeholders for the paths only the worker knows — its yt-dlp, its ffmpeg, its scratch
 * directory. So the flags that make a run safe (`--ignore-config`, `--no-exec`,
 * `--no-playlist`) are the ones this app has unit tests for, and a worker installed six
 * months ago picks up a change to them without being updated. The worker's side of that
 * bargain is to refuse an argv whose first element is not the placeholder, which is the one
 * thing that would let a command line become an arbitrary program.
 *
 * **The lease is the credential.** Every report names a job by id, and ids are small
 * integers. The token minted at claim time is what makes the difference between "an
 * allow-listed account" and "the worker actually doing this job", and it is cleared the
 * moment the job ends — which also makes a retried upload a clean 403 rather than a second
 * copy of the track.
 */
class RemoteImportQueue {

	/**
	 * What the worker substitutes before running the command.
	 *
	 * Braces because nothing yt-dlp accepts looks like this, so a stray placeholder that
	 * failed to substitute is a loud failure rather than a subtle one.
	 */
	public const PLACEHOLDER_YTDLP = '{ytdlp}';
	public const PLACEHOLDER_FFMPEG = '{ffmpeg}';
	public const PLACEHOLDER_DIR = '{dir}';
	public const PLACEHOLDER_COOKIES = '{cookies}';

	/**
	 * How often a working worker should report in.
	 *
	 * Well inside {@see RemoteImportSettings::LEASE_SECONDS}, so that an ordinary pause —
	 * a slow fragment, a long transcode — never looks like a dead machine.
	 */
	public const HEARTBEAT_SECONDS = 15;

	/**
	 * How many times to retry losing the race for a job.
	 *
	 * Several workers polling one queue is the normal arrangement, so losing is expected.
	 * Three attempts and then "nothing to do", which is true enough: something was taken,
	 * and this worker will ask again in a few seconds.
	 */
	private const CLAIM_ATTEMPTS = 3;

	public function __construct(
		private ImportMapper $importMapper,
		private YoutubeImportService $importService,
		private RemoteImportSettings $settings,
		private WorkerScript $workerScript,
		private CookieJar $cookieJar,
		private ITempManager $tempManager,
		private ISecureRandom $random,
		private Clock $clock,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------ hello

	/**
	 * What a worker is told before it starts, and what `--check` prints.
	 *
	 * Answers the three questions somebody setting this up actually has: are my credentials
	 * right, is this server in remote mode at all, and is there anything waiting.
	 *
	 * @return array<string, mixed>
	 */
	public function greeting(string $workerAccount): array {
		$status = $this->importService->availability();

		return [
			'protocol' => RemoteImportSettings::PROTOCOL,
			'mode' => $this->settings->mode(),
			'account' => $workerAccount,
			'available' => $status->available,
			'reason' => $status->reason,
			'queue' => $this->importMapper->remoteQueueDepth(),
			'cookieForwarding' => $this->settings->forwardsCookies(),
			'leaseSeconds' => RemoteImportSettings::LEASE_SECONDS,
			'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
			// The script this server ships, by checksum rather than by body. A worker
			// checks itself against this every quarter of an hour, and asking here — on a
			// call it was making anyway — is what keeps that check free until the two
			// sides actually differ. Null when the app has no copy to offer.
			'workerScript' => $this->workerScript->describe(),
		];
	}

	// ------------------------------------------------------------- collecting

	/**
	 * Give this worker something to do, if there is anything.
	 *
	 * @param string $workerName what the worker calls itself; advisory, for whoever is
	 *                           reading the queue later
	 * @param string|null $jsRuntimeSpec what it found to run YouTube's JavaScript in, as
	 *                                   `name:/path`. Decides two things: whether the
	 *                                   command line lends it an engine, and whether
	 *                                   cookies would help or ruin the run.
	 * @return array<string, mixed>|null the job, or null when the queue is empty
	 */
	public function claim(string $workerName, ?string $jsRuntimeSpec): ?array {
		$this->settings->markSeen($workerName, $jsRuntimeSpec);

		// Before looking for work, not after. A job whose worker rebooted is waiting to be
		// given back, and the machine most likely to notice is the one asking for work —
		// the timed sweep runs every five minutes, which is a long time to leave somebody
		// watching a progress bar that has stopped.
		$this->importMapper->requeueExpiredLeases(
			$this->clock->nowSeconds() - RemoteImportSettings::LEASE_SECONDS,
			RemoteImportSettings::MAX_ATTEMPTS,
		);

		for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
			$importId = $this->importMapper->nextQueuedRemote();
			if ($importId === null) {
				return null;
			}

			$lease = $this->random->generate(64, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

			if ($this->importMapper->claimRemote($importId, $this->clock->nowSeconds(), $lease, mb_substr($workerName, 0, 64)) === 1) {
				return $this->describe($this->importMapper->findById($importId), $lease, $jsRuntimeSpec);
			}
		}

		return null;
	}

	/**
	 * The job as the worker needs to see it: two command lines and the numbers it must
	 * respect.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Import $import, string $lease, ?string $jsRuntimeSpec): array {
		$url = YoutubeUrl::canonical($import->getVideoId());
		$runtime = JsRuntime::fromSpec($jsRuntimeSpec ?? '');
		$cookies = $this->cookiesAvailableFor($import, $runtime);

		return [
			// Carried on the job as well as on the greeting, so a worker that was started
			// before an upgrade says so on the first job rather than failing oddly on it.
			'protocol' => RemoteImportSettings::PROTOCOL,
			'importId' => $import->getId(),
			'lease' => $lease,
			'videoId' => $import->getVideoId(),
			'url' => $url,

			// Built here rather than on the worker, so the flags that make a run safe are
			// the ones this app tests. The placeholders are the only things the worker is
			// allowed to fill in.
			//
			// No proxy is passed: the server's own proxy setting describes a route out of
			// *this* network, and the worker's network is the whole reason it exists.
			'probeArgv' => YtDlpArgv::probe(
				self::PLACEHOLDER_YTDLP,
				$url,
				null,
				$runtime?->spec(),
				$cookies ? self::PLACEHOLDER_COOKIES : null,
			),
			'downloadArgv' => YtDlpArgv::download(
				self::PLACEHOLDER_YTDLP,
				$url,
				self::PLACEHOLDER_DIR,
				self::PLACEHOLDER_FFMPEG,
				$this->importService->maxDurationSeconds(),
				$this->importService->maxSourceBytes(),
				null,
				$runtime?->spec(),
				$cookies ? self::PLACEHOLDER_COOKIES : null,
			),

			// Whether there is a jar to fetch. Sent as a flag rather than as the jar itself:
			// most jobs have none, and a secret should travel only when it is going to be
			// used.
			'cookies' => $cookies,

			'outputStem' => YtDlpArgv::OUTPUT_STEM,
			'progressPrefix' => YtDlpArgv::PROGRESS_PREFIX,

			'probeTimeoutSeconds' => YoutubeImportService::PROBE_TIMEOUT_SECONDS,
			'downloadTimeoutSeconds' => YoutubeImportService::DOWNLOAD_TIMEOUT_SECONDS,
			'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
			'leaseSeconds' => RemoteImportSettings::LEASE_SECONDS,
			// The finished file, after transcoding — a different limit from the one on the
			// source stream that is already in the argv above, and the one this server will
			// refuse the upload on.
			'maxUploadBytes' => MusicLibrary::MAX_TRACK_BYTES,
		];
	}

	/**
	 * Whether this job may be lent the channel owner's cookies.
	 *
	 * Three conditions, and the third is the same judgement the local path makes: without
	 * a JavaScript runtime, authenticating leaves yt-dlp with no downloadable formats at
	 * all — so a jar would not merely fail to help, it would break an import that works.
	 * The difference is whose runtime is being asked about: the worker's.
	 */
	private function cookiesAvailableFor(Import $import, ?JsRuntime $runtime): bool {
		if (!$this->settings->forwardsCookies() || $runtime === null) {
			return false;
		}

		$ownerId = $this->importService->ownerOf($import->getChannelId());

		return $ownerId !== null && $this->cookieJar->has($ownerId);
	}

	// ------------------------------------------------------------- reporting

	/**
	 * The worker has probed the video; decide whether the download is worth doing.
	 *
	 * @param array<string, mixed> $metadata what `--dump-single-json` produced
	 * @return array{proceed: bool, cancelled: bool, code: string|null}
	 */
	public function metadata(int $importId, string $lease, array $metadata): array {
		$import = $this->leased($importId, $lease);

		if ($this->isCancelled($import)) {
			return ['proceed' => false, 'cancelled' => true, 'code' => ImportError::CANCELLED];
		}

		$this->importService->recordMetadata($import, $metadata);

		$refusal = $this->importService->inspect($metadata);
		if ($refusal !== null) {
			// The same refusals the local path makes before spending any bandwidth — an
			// over-long video, a live stream, an age gate — made here for the same reason.
			$this->importService->fail($import, $refusal);

			return ['proceed' => false, 'cancelled' => false, 'code' => $refusal];
		}

		$this->importMapper->touch($import->getId(), $this->clock->nowSeconds(), Import::PHASE_DOWNLOADING, 0);

		return ['proceed' => true, 'cancelled' => false, 'code' => null];
	}

	/**
	 * Still working, this far along.
	 *
	 * The answer carries the cancellation, because the worker has no other way to hear
	 * about it: whoever pressed the button is on a different machine entirely, and this
	 * request is the only conversation the two of them have.
	 *
	 * @return array{cancelled: bool}
	 */
	public function progress(int $importId, string $lease, string $phase, int $progress): array {
		$import = $this->leased($importId, $lease);

		if ($this->isCancelled($import)) {
			return ['cancelled' => true];
		}

		$this->importMapper->touch(
			$import->getId(),
			$this->clock->nowSeconds(),
			Import::phaseFromName($phase) ?? $import->getPhase(),
			max(0, min(100, $progress)),
		);

		return ['cancelled' => false];
	}

	/**
	 * The channel owner's cookies, for the length of this job.
	 *
	 * @see CookieJar::lend() for what has to be true before this returns anything
	 */
	public function cookiesFor(int $importId, string $lease): ?string {
		$import = $this->leased($importId, $lease);

		if (!$this->settings->forwardsCookies()) {
			return null;
		}

		$ownerId = $this->importService->ownerOf($import->getChannelId());

		return $ownerId === null ? null : $this->cookieJar->lend($ownerId);
	}

	/**
	 * Take back the jar the worker was lent.
	 *
	 * YouTube rotates session cookies and yt-dlp writes the rotated set back out. Without
	 * this the stored copy would be the one that was pasted, going staler every run — the
	 * same reasoning as the local path, over a wire.
	 */
	public function returnCookies(int $importId, string $lease, string $jar): void {
		$import = $this->leased($importId, $lease);

		if (!$this->settings->forwardsCookies()) {
			return;
		}

		$ownerId = $this->importService->ownerOf($import->getChannelId());
		if ($ownerId === null) {
			return;
		}

		$this->cookieJar->refreshWith($ownerId, $jar);
	}

	// -------------------------------------------------------------- finishing

	/**
	 * The audio, at last.
	 *
	 * Streamed to a temporary file rather than read into memory: this is a whole MP3, and
	 * a PHP worker holding one in a string is how a server with several imports running
	 * runs out of memory.
	 *
	 * @param resource $stream the request body
	 * @return int the id of the track it became
	 * @throws MusicRadioException
	 */
	public function complete(int $importId, string $lease, $stream, ?int $durationMs): int {
		$import = $this->leased($importId, $lease);

		if ($this->isCancelled($import)) {
			// Somebody stopped this while it was being uploaded. The bytes are dropped on
			// the floor: filing them would put a track on a playlist that the person who
			// cancelled has already been told is not coming.
			throw new MusicRadioException(ImportError::CANCELLED, Http::STATUS_CONFLICT);
		}

		$this->importMapper->touch($import->getId(), $this->clock->nowSeconds(), Import::PHASE_SAVING, 100);

		$path = $this->receive($import, $stream);

		if ($durationMs !== null && $durationMs > 0 && $import->getDurationMs() === null) {
			$import->setDurationMs($durationMs);
			$this->importMapper->update($import);
		}

		// Writes the outcome onto this same entity, whichever way it goes.
		$this->importService->completeFrom($import, $path);
		@unlink($path);

		$trackId = $import->getTrackId();
		if ($trackId === null) {
			// completeFrom() has already written why on the row — a full quota, a channel
			// at its track limit, a storage that would not take the file. This only turns
			// that into an answer for the worker, which needs to know not to consider the
			// job done.
			throw new MusicRadioException(
				$import->getErrorCode() ?? ImportError::UNKNOWN,
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->logger->info('Filed audio fetched by a remote worker', [
			'app' => Application::APP_ID,
			'importId' => $importId,
			'worker' => $import->getWorkerId(),
			'trackId' => $trackId,
		]);

		return $trackId;
	}

	/**
	 * Write the upload to disk, refusing anything too big as it goes.
	 *
	 * The limit is enforced while reading rather than from a declared Content-Length: a
	 * length is whatever the sender says it is, and the point of the check is what actually
	 * arrives.
	 *
	 * @param resource $stream
	 * @throws MusicRadioException
	 */
	private function receive(Import $import, $stream): string {
		$path = $this->tempManager->getTemporaryFile('.mp3');
		if ($path === false) {
			$this->importService->fail($import, ImportError::UNKNOWN);
			throw new MusicRadioException(ImportError::UNKNOWN, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$target = @fopen($path, 'wb');
		if ($target === false) {
			$this->importService->fail($import, ImportError::UNKNOWN);
			throw new MusicRadioException(ImportError::UNKNOWN, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$written = 0;
		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 1024 * 1024);
				if ($chunk === false) {
					break;
				}

				$written += strlen($chunk);
				if ($written > MusicLibrary::MAX_TRACK_BYTES) {
					$this->importService->fail($import, ImportError::TOO_LARGE);
					throw new MusicRadioException(ImportError::TOO_LARGE, Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
				}

				fwrite($target, $chunk);
			}
		} finally {
			fclose($target);
			if ($written > MusicLibrary::MAX_TRACK_BYTES) {
				@unlink($path);
			}
		}

		if ($written === 0) {
			@unlink($path);
			$this->importService->fail($import, ImportError::NO_AUDIO);
			throw new MusicRadioException(ImportError::NO_AUDIO, Http::STATUS_BAD_REQUEST);
		}

		return $path;
	}

	/**
	 * It did not work, and here is what yt-dlp said about it.
	 *
	 * The worker deliberately does not decide *why*. Reading yt-dlp's stderr is a hundred
	 * lines of pattern matching that changes as YouTube changes, and a copy of it on every
	 * worker machine would be a copy going stale — so what comes back is the raw output and
	 * the exit code, and {@see YtDlpFailure} does here what it already does for a local
	 * import. The only codes a worker may name are the ones about its own machine.
	 *
	 * @param string $stage `probe` or `download`; the probe pass has no file to look for
	 * @param string|null $code what the worker knows that the output cannot say
	 * @param bool $cookiesUsed whether the run presented the jar it was lent. Changes what
	 *                          a sign-in demand means — being asked to sign in *while
	 *                          signed in* is a jar that has stopped being accepted, which
	 *                          is a different sentence and a different remedy.
	 * @param bool $jsRuntimeAvailable whether the worker had an engine for YouTube's
	 *                                 JavaScript. Without one, several failures that read
	 *                                 as a stale downloader are really the missing engine.
	 */
	public function failed(
		int $importId,
		string $lease,
		string $stage,
		?string $code,
		int $exitCode,
		bool $timedOut,
		bool $producedFile,
		bool $cookiesUsed,
		bool $jsRuntimeAvailable,
		string $stdout,
		string $stderr,
	): void {
		$import = $this->leased($importId, $lease);

		if ($this->isCancelled($import)) {
			// Already stopped on purpose. Recording a failure over it would replace "you
			// cancelled this" with something that reads like a fault.
			return;
		}

		$result = new ProcessResult($exitCode, $stdout, $stderr, $timedOut, false);

		if ($code !== null && ImportError::reportableByWorker($code)) {
			$this->importService->fail($import, $code, YtDlpFailure::detail($result));
		} else {
			$classified = $stage === 'probe'
				? YtDlpFailure::classifyProbe($result, $jsRuntimeAvailable, $cookiesUsed)
				: YtDlpFailure::classify($result, $producedFile, $jsRuntimeAvailable, $cookiesUsed);

			$this->importService->fail(
				$import,
				$classified ?? ImportError::UNKNOWN,
				YtDlpFailure::detail($result),
			);
		}

		$this->logger->warning('A remote worker could not complete an import', [
			'app' => Application::APP_ID,
			'importId' => $importId,
			'worker' => $import->getWorkerId(),
			'stage' => $stage,
			'stderr' => substr($stderr, -2000),
		]);
	}

	/**
	 * Give the job back, unstarted.
	 *
	 * What a worker does on the way out — a reboot, a deploy, Ctrl-C. Much better than
	 * letting the lease expire: another worker has it seconds later rather than minutes,
	 * and the person waiting sees a queued row rather than a stalled one.
	 */
	public function release(int $importId, string $lease): void {
		$this->leased($importId, $lease);
		$this->importMapper->releaseRemote($importId, $lease);
	}

	// ---------------------------------------------------------------- plumbing

	/**
	 * The job this worker is entitled to talk about, or a refusal.
	 *
	 * A cleared token is the ordinary state of a finished job, so a late report — the
	 * upload that succeeded and whose answer was lost on the way back, and which the worker
	 * is now retrying — lands here as a refusal rather than as a second track.
	 *
	 * @throws MusicRadioException
	 */
	private function leased(int $importId, string $lease): Import {
		try {
			$import = $this->importMapper->findById($importId);
		} catch (\Throwable) {
			// Deleting a channel takes its imports with it, and a worker holding a job for
			// a channel that has gone should stop rather than finish.
			throw new MusicRadioException('no_such_import', Http::STATUS_NOT_FOUND);
		}

		$token = $import->getLeaseToken();
		if (!$import->getRemote() || $token === null || $lease === '' || !hash_equals($token, $lease)) {
			throw new MusicRadioException('lease_not_held', Http::STATUS_FORBIDDEN);
		}

		return $import;
	}

	private function isCancelled(Import $import): bool {
		return $import->getStatus() === Import::STATUS_CANCELLED;
	}
}
