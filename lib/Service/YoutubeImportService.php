<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\BackgroundJob\ImportYoutubeAudioJob;
use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Process\IProcessRunner;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Fetching the audio from a YouTube video and putting it on a channel.
 *
 * Split in two on purpose. request() runs inside somebody's request: it validates, refuses
 * what it can refuse cheaply, writes a row and returns — it never touches the network, so
 * a click always gets an immediate answer. perform() runs inside a background job and does
 * the slow part, recording everything it learns on the row as it goes.
 *
 * perform() never throws. Every outcome, including the ones nobody anticipated, has to end
 * up on the row, because the row is the only thing the person waiting can see. A job that
 * threw would leave them watching a spinner until the reaper noticed.
 *
 * @see YoutubeUrl for why the URL is safe by the time it gets here
 * @see MusicLibrary for what happens to the file once it exists
 */
class YoutubeImportService {

	/**
	 * 90 minutes. Long enough for a DJ set or a full album upload, short enough that a
	 * mistyped link to an eight-hour livestream is refused rather than attempted.
	 */
	public const DEFAULT_MAX_DURATION_SECONDS = 5400;

	/** The source stream, before transcoding. */
	public const DEFAULT_MAX_SOURCE_BYTES = 300 * 1024 * 1024;

	/**
	 * How many imports one person can have going. Two, so a second link can be queued
	 * behind the first without it feeling like a queue.
	 */
	public const MAX_ACTIVE_PER_USER = 2;
	public const MAX_ACTIVE_PER_CHANNEL = 3;

	public const CONFIG_MAX_DURATION = 'import_max_duration';
	public const CONFIG_MAX_SOURCE_BYTES = 'import_max_source_bytes';

	private const PROBE_TIMEOUT_SECONDS = 60;
	private const DOWNLOAD_TIMEOUT_SECONDS = 900;

	/**
	 * How often the row is updated while a download runs. Every progress line would be
	 * dozens of writes a second for no visible gain.
	 */
	private const HEARTBEAT_INTERVAL_SECONDS = 2;

	/** A running import whose worker has said nothing for this long is presumed dead. */
	public const STALL_AFTER_SECONDS = 600;

	/** A queued import nobody picked up in this long means cron is not running. */
	public const NEVER_STARTED_AFTER_SECONDS = 3600;

	/** Finished rows are kept this long so somebody can read why something failed. */
	public const KEEP_FINISHED_SECONDS = 7 * 86400;

	public function __construct(
		private ImportMapper $importMapper,
		private ChannelMapper $channelMapper,
		private YtDlpLocator $locator,
		private IProcessRunner $runner,
		private MusicLibrary $library,
		private ITempManager $tempManager,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private IConfig $config,
		private Clock $clock,
		private LoggerInterface $logger,
	) {
	}

	public function availability(): ToolStatus {
		return $this->locator->status();
	}

	public function maxDurationSeconds(): int {
		return max(60, $this->appConfig->getValueInt(
			Application::APP_ID,
			self::CONFIG_MAX_DURATION,
			self::DEFAULT_MAX_DURATION_SECONDS,
		));
	}

	private function maxSourceBytes(): int {
		return max(1024 * 1024, $this->appConfig->getValueInt(
			Application::APP_ID,
			self::CONFIG_MAX_SOURCE_BYTES,
			self::DEFAULT_MAX_SOURCE_BYTES,
		));
	}

	// -------------------------------------------------------------- the request

	/**
	 * Accept an import, or say why not.
	 *
	 * @throws MusicRadioException carrying an ImportError code as its message, which the
	 *                             controller turns into a sentence
	 */
	public function request(Channel $channel, string $userId, string $rawUrl): Import {
		$status = $this->availability();
		if (!$status->available) {
			throw $this->refuse($status->reason ?? ImportError::UNKNOWN, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$videoId = YoutubeUrl::videoId($rawUrl);
		if ($videoId === null) {
			throw $this->refuse(ImportError::NOT_A_YOUTUBE_URL, Http::STATUS_BAD_REQUEST);
		}

		if ($this->importMapper->hasActiveForVideo($channel->getId(), $videoId)) {
			throw $this->refuse(ImportError::DUPLICATE_IN_FLIGHT, Http::STATUS_CONFLICT);
		}

		// Counted, not locked. Two simultaneous requests can both pass and put one extra
		// import in flight; the real protection against a server drowning in downloads is
		// that only one job runs at a time, and being off by one here costs nothing.
		if ($this->importMapper->countActiveForUser($userId) >= self::MAX_ACTIVE_PER_USER
			|| $this->importMapper->countActiveForChannel($channel->getId()) >= self::MAX_ACTIVE_PER_CHANNEL) {
			throw $this->refuse(ImportError::TOO_MANY_IMPORTS, Http::STATUS_TOO_MANY_REQUESTS);
		}

		$now = $this->clock->nowSeconds();

		$import = new Import();
		$import->setChannelId($channel->getId());
		$import->setUserId($userId);
		$import->setSource(Import::SOURCE_YOUTUBE);
		$import->setVideoId($videoId);
		$import->setStatus(Import::STATUS_QUEUED);
		$import->setPhase(Import::PHASE_PENDING);
		$import->setProgress(0);
		$import->setAttempts(0);
		$import->setCreatedAt($now);
		$import->setStartedAt(0);
		$import->setHeartbeatAt(0);
		$import->setFinishedAt(0);

		$import = $this->importMapper->insert($import);

		// The row is committed before the job is queued, so a worker can never be handed
		// an id that is not there yet. The other order is a race that only shows up under
		// load.
		try {
			$this->jobList->add(ImportYoutubeAudioJob::class, ['importId' => $import->getId()]);
		} catch (\Throwable $e) {
			// Nothing will ever pick this up, so it must not be left looking queued.
			$this->fail($import, ImportError::UNKNOWN);
			$this->logger->error('Could not queue a YouTube import', [
				'app' => Application::APP_ID,
				'importId' => $import->getId(),
				'exception' => $e,
			]);
			throw $this->refuse(ImportError::UNKNOWN, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $import;
	}

	/**
	 * Stop an import that has not finished.
	 *
	 * @throws MusicRadioException
	 */
	public function cancel(Import $import): void {
		if ($this->importMapper->cancel($import->getId(), $this->clock->nowSeconds()) === 0) {
			throw $this->refuse(ImportError::UNKNOWN, Http::STATUS_CONFLICT);
		}
	}

	// ----------------------------------------------------------------- the work

	/**
	 * Do the import. Called from the background job, and only from there.
	 *
	 * Deliberately does not throw: every path ends with the row saying what happened.
	 */
	public function perform(Import $import): void {
		if ($this->importMapper->claim($import->getId(), $this->clock->nowSeconds()) === 0) {
			// Somebody else has it, or it was cancelled before it started. Either way this
			// worker must not do the work again.
			return;
		}

		$temporaryDirectory = $this->tempManager->getTemporaryFolder();
		if ($temporaryDirectory === false) {
			$this->fail($import, ImportError::UNKNOWN);

			return;
		}

		try {
			$this->run($import, rtrim($temporaryDirectory, '/'));
		} catch (\Throwable $e) {
			$this->logger->error('A YouTube import failed unexpectedly', [
				'app' => Application::APP_ID,
				'importId' => $import->getId(),
				'exception' => $e,
			]);
			$this->fail($import, ImportError::UNKNOWN);
		} finally {
			// ITempManager cleans up per request, and a background-job worker is a single
			// long-lived process — left to it, one temp directory per import would pile up
			// until the disk filled.
			$this->removeDirectory($temporaryDirectory);
		}
	}

	/**
	 * @throws MusicRadioException
	 */
	private function run(Import $import, string $temporaryDirectory): void {
		$status = $this->availability();
		if (!$status->available || $status->ytDlpPath === null || $status->ffmpegDir === null) {
			$this->fail($import, $status->reason ?? ImportError::YTDLP_MISSING);

			return;
		}

		$url = YoutubeUrl::canonical($import->getVideoId());
		$environment = $this->environmentFor($temporaryDirectory);
		$proxy = $this->config->getSystemValueString('proxy', '') ?: null;

		// --- what is this? ----------------------------------------------------
		$probe = $this->runner->run(
			YtDlpArgv::probe($status->ytDlpPath, $url, $proxy),
			$temporaryDirectory,
			$environment,
			self::PROBE_TIMEOUT_SECONDS,
			null,
			fn (): bool => $this->wasCancelled($import),
		);

		$failure = YtDlpFailure::classifyProbe($probe);
		if ($failure !== null) {
			$this->logFailure($import, $failure, $probe->stderr);
			$this->fail($import, $failure);

			return;
		}

		$metadata = json_decode($probe->stdout, true);
		if (!is_array($metadata)) {
			$this->fail($import, ImportError::UNKNOWN);

			return;
		}

		$this->recordMetadata($import, $metadata);

		// Everything knowable before a byte moves is checked here rather than being left
		// to a failed download: the reasons are clearer and the bandwidth is not spent.
		$refusal = $this->inspect($metadata);
		if ($refusal !== null) {
			$this->fail($import, $refusal);

			return;
		}

		// --- fetch it ----------------------------------------------------------
		$this->advance($import, Import::PHASE_DOWNLOADING, 0);

		$lastWrite = 0;
		$result = $this->runner->run(
			YtDlpArgv::download(
				$status->ytDlpPath,
				$url,
				$temporaryDirectory,
				$status->ffmpegDir,
				$this->maxDurationSeconds(),
				$this->maxSourceBytes(),
				$proxy,
			),
			$temporaryDirectory,
			$environment,
			self::DOWNLOAD_TIMEOUT_SECONDS,
			function (string $line) use ($import, &$lastWrite): void {
				$this->onProgress($import, $line, $lastWrite);
			},
			fn (): bool => $this->wasCancelled($import),
		);

		$produced = $this->producedFile($temporaryDirectory);

		$failure = YtDlpFailure::classify($result, $produced !== null);
		if ($failure !== null) {
			$this->logFailure($import, $failure, $result->stderr);
			$this->fail($import, $failure);

			return;
		}

		// --- file it ------------------------------------------------------------
		$this->advance($import, Import::PHASE_SAVING, 100);
		$this->store($import, $produced ?? '');
	}

	/**
	 * Put the finished file in the channel owner's music folder and on the playlist.
	 */
	private function store(Import $import, string $path): void {
		try {
			$channel = $this->channelMapper->find($import->getChannelId());
		} catch (\Throwable) {
			// The channel was deleted while this was downloading. Nothing to file it
			// against, and nobody to tell.
			$this->fail($import, ImportError::UNKNOWN);

			return;
		}

		try {
			$track = $this->library->ingest(
				$channel,
				// The owner's storage and quota, whoever asked for the import — the same
				// arrangement as a public-link upload, and what keeps the track playable
				// after a contributor's share is revoked.
				$channel->getUserId(),
				$path,
				$import->displayTitle() . '.mp3',
				// Credited to whoever pasted the link, which is not whose folder it is in.
				$import->getUserId(),
				$import->getDurationMs(),
			);
		} catch (MusicRadioException $e) {
			$this->fail($import, $this->classifyStorageFailure($e));

			return;
		} catch (\Throwable $e) {
			$this->logger->error('Could not file an imported track', [
				'app' => Application::APP_ID,
				'importId' => $import->getId(),
				'exception' => $e,
			]);
			$this->fail($import, ImportError::UNKNOWN);

			return;
		}

		$import->setStatus(Import::STATUS_DONE);
		$import->setPhase(Import::PHASE_SAVING);
		$import->setProgress(100);
		$import->setTrackId($track->getId());
		$import->setErrorCode(null);
		$import->setFinishedAt($this->clock->nowSeconds());
		$this->importMapper->update($import);

		$this->logger->info('Imported audio from YouTube', [
			'app' => Application::APP_ID,
			'importId' => $import->getId(),
			'channelId' => $import->getChannelId(),
			'videoId' => $import->getVideoId(),
			'trackId' => $track->getId(),
		]);
	}

	// ------------------------------------------------------------- pre-flight

	/**
	 * Reasons to refuse that the metadata already knows.
	 *
	 * The duration limit is enforced here as well as by `--match-filter`, because knowing
	 * it up front means saying "that video is longer than 90 minutes" instead of noticing
	 * afterwards that no file appeared.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function inspect(array $metadata): ?string {
		$liveStatus = $metadata['live_status'] ?? null;
		if (($metadata['is_live'] ?? false) === true
			|| in_array($liveStatus, ['is_live', 'is_upcoming', 'post_live'], true)) {
			return ImportError::LIVE_STREAM;
		}

		if (in_array($metadata['availability'] ?? null, ['premium_only', 'subscriber_only'], true)) {
			return ImportError::MEMBERS_ONLY;
		}
		if (in_array($metadata['availability'] ?? null, ['needs_auth', 'private'], true)) {
			return ImportError::VIDEO_PRIVATE;
		}

		if ((int)($metadata['age_limit'] ?? 0) >= 18) {
			return ImportError::AGE_RESTRICTED;
		}

		$duration = $metadata['duration'] ?? null;
		if (is_numeric($duration) && (int)$duration > $this->maxDurationSeconds()) {
			return ImportError::TOO_LONG;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function recordMetadata(Import $import, array $metadata): void {
		$title = $metadata['title'] ?? null;
		if (is_string($title) && trim($title) !== '') {
			$import->setTitle(mb_substr(trim($title), 0, 255));
		}

		$duration = $metadata['duration'] ?? null;
		if (is_numeric($duration) && (int)$duration > 0) {
			$import->setDurationMs((int)round((float)$duration * 1000));
		}

		$this->importMapper->update($import);
	}

	// ---------------------------------------------------------------- plumbing

	/**
	 * The child's entire environment.
	 *
	 * HOME and XDG_CACHE_HOME point into the temporary directory so that anything yt-dlp
	 * or ffmpeg decides to write beside itself goes somewhere that is about to be deleted,
	 * rather than into a web user's home — which may not exist, may not be writable, and on
	 * shared hosting may belong to somebody else.
	 *
	 * @return array<string, string>
	 */
	private function environmentFor(string $temporaryDirectory): array {
		return [
			'PATH' => '/usr/local/bin:/usr/bin:/bin',
			'HOME' => $temporaryDirectory,
			'XDG_CACHE_HOME' => $temporaryDirectory,
			'TMPDIR' => $temporaryDirectory,
			'LC_ALL' => 'C',
			// Nothing should be left behind, including .pyc files.
			'PYTHONDONTWRITEBYTECODE' => '1',
		];
	}

	private function onProgress(Import $import, string $line, int &$lastWrite): void {
		$fraction = YtDlpArgv::parseProgress($line);
		if ($fraction === null) {
			return;
		}

		$now = $this->clock->nowSeconds();
		if ($now - $lastWrite < self::HEARTBEAT_INTERVAL_SECONDS) {
			return;
		}
		$lastWrite = $now;

		$this->importMapper->touch(
			$import->getId(),
			$now,
			Import::PHASE_DOWNLOADING,
			(int)round($fraction * 100),
		);
	}

	/**
	 * Whether there is still any reason to be doing this.
	 *
	 * Read from the database rather than signalled, because the request that cancels runs
	 * in a different process from the worker that has to notice.
	 *
	 * A row that has *gone* counts the same as one marked cancelled. Deleting a channel
	 * takes its imports with it, and without this the worker would carry on downloading
	 * for something nobody can see any more — holding the one import slot the whole time,
	 * since only one runs at once. Anything waiting behind it would sit there until this
	 * finished or hit its timeout.
	 */
	private function wasCancelled(Import $import): bool {
		try {
			$status = $this->importMapper->statusOf($import->getId());
		} catch (\Throwable) {
			// A database hiccup is not a reason to abandon a download in progress.
			return false;
		}

		return $status === null || $status === Import::STATUS_CANCELLED;
	}

	private function advance(Import $import, int $phase, int $progress): void {
		$this->importMapper->touch($import->getId(), $this->clock->nowSeconds(), $phase, $progress);
	}

	/**
	 * The single audio file the download should have produced.
	 *
	 * More than one match means something unexpected happened and it is not clear which
	 * file is the track, so nothing is guessed at. The info.json is excluded by name
	 * rather than by extension order.
	 */
	private function producedFile(string $temporaryDirectory): ?string {
		$candidates = array_values(array_filter(
			glob($temporaryDirectory . '/' . YtDlpArgv::OUTPUT_STEM . '.*') ?: [],
			static fn (string $path): bool => is_file($path)
				&& !str_ends_with($path, '.info.json')
				&& !str_ends_with($path, '.part'),
		));

		return count($candidates) === 1 ? $candidates[0] : null;
	}

	/**
	 * MusicLibrary speaks in sentences meant for a person; the row stores codes. The two
	 * cases worth distinguishing are the ones somebody can act on.
	 */
	private function classifyStorageFailure(MusicRadioException $e): string {
		$message = strtolower($e->getMessage());

		return match (true) {
			str_contains($message, 'space') => ImportError::QUOTA_EXCEEDED,
			str_contains($message, 'track limit') => ImportError::CHANNEL_FULL,
			default => ImportError::NO_AUDIO,
		};
	}

	private function fail(Import $import, string $code): void {
		// A cancelled import that then fails is still cancelled: the person stopped it, and
		// being told it "failed" would be misleading.
		$current = $this->importMapper->statusOf($import->getId());
		if ($current === Import::STATUS_CANCELLED) {
			return;
		}

		$import->setStatus(Import::STATUS_FAILED);
		$import->setErrorCode($code);
		$import->setFinishedAt($this->clock->nowSeconds());

		try {
			$this->importMapper->update($import);
		} catch (\Throwable $e) {
			$this->logger->error('Could not record why a YouTube import failed', [
				'app' => Application::APP_ID,
				'importId' => $import->getId(),
				'code' => $code,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Keep the tail of stderr where an administrator can find it. It never reaches the
	 * person who asked for the import — it can name paths, and it is written for somebody
	 * debugging yt-dlp.
	 */
	private function logFailure(Import $import, string $code, string $stderr): void {
		$this->logger->warning('A YouTube import could not be completed', [
			'app' => Application::APP_ID,
			'importId' => $import->getId(),
			'videoId' => $import->getVideoId(),
			'code' => $code,
			'stderr' => substr($stderr, -2000),
		]);
	}

	private function removeDirectory(string $directory): void {
		try {
			$entries = glob($directory . '/*') ?: [];
			foreach ($entries as $entry) {
				is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
			}
			@rmdir($directory);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not clean up after a YouTube import', [
				'app' => Application::APP_ID,
				'directory' => $directory,
				'exception' => $e,
			]);
		}
	}

	/**
	 * @param Http::STATUS_* $status
	 */
	private function refuse(string $code, int $status): MusicRadioException {
		// The code travels as the message; the controller translates it. That keeps every
		// refusal in one vocabulary whether it came from here or from a background job.
		return new MusicRadioException($code, $status);
	}
}
