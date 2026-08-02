<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\RemoteImportQueue;
use OCA\MusicRadio\Service\RemoteImportSettings;
use OCA\MusicRadio\Service\WorkerScript;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The API a remote import worker talks to.
 *
 * **Why this exists.** A Nextcloud host is frequently the worst machine on the network for
 * fetching from YouTube: a datacentre address is what a bot check is looking at, the
 * distribution's yt-dlp is often a year old, and there may be no ffmpeg and no JavaScript
 * runtime at all. In remote mode the queue, the rules and the storage stay here and only
 * the fetching moves — to a machine that has a residential route and a current yt-dlp.
 *
 * **How a worker authenticates.** As an ordinary Nextcloud account, over HTTP Basic, with
 * an **app password** rather than the account's own — `occ user:add-app-password` makes
 * one, and it can be revoked from Settings → Security without touching the account. Nothing
 * bespoke is invented here: Nextcloud's own login handling does the work, which means
 * brute-force protection, rate limiting and revocation all behave the way an administrator
 * already expects them to. That is the whole reason these routes are OCS ones — an OCS
 * controller reached with `OCS-APIRequest: true` is the supported way for a non-browser
 * client to make an authenticated call, and it is what exempts it from a CSRF token it has
 * no session to get one from.
 *
 * **Authentication is not authorisation.** Being signed in is not enough. A worker can be
 * handed *any* queued job and can upload audio that lands in *another* user's storage, so
 * "any account with a password" would be a way for any user to write files into someone
 * else's files. The account also has to be on the allow-list an administrator maintains
 * ({@see RemoteImportSettings::isWorker()}), which is empty until somebody fills it in.
 *
 * **And the lease is a third thing.** Every call below names a job by id, and ids are small
 * integers that any allow-listed account could guess. The token minted when the job was
 * claimed is what distinguishes the worker actually doing it — see
 * {@see RemoteImportQueue}.
 *
 * Errors come back as a code rather than a sentence, because the reader is a script. The
 * sentences are for the person who asked for the import, and they are produced from the row
 * later, in a request that knows what language they read.
 */
class WorkerController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private RemoteImportQueue $queue,
		private RemoteImportSettings $settings,
		private WorkerScript $script,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Whether these credentials work, and what this server currently thinks.
	 *
	 * The first thing the worker script calls, and everything `--check` prints. Deliberately
	 * answers even when the server is in local mode: "your credentials are fine, but this
	 * server is not handing out work" is a much more useful thing to be told than a refusal.
	 */
	#[NoAdminRequired]
	public function status(): DataResponse {
		$account = $this->worker();
		if ($account === null) {
			return $this->refused();
		}

		return new DataResponse($this->queue->greeting($account));
	}

	/**
	 * The worker script this app ships.
	 *
	 * How `music-radio-worker update` keeps itself in step with the server it talks to.
	 * Only ever fetched when the checksum on the greeting differs from the worker's own, so
	 * this is a rare call rather than part of the polling loop.
	 *
	 * Gated like everything else here — an allow-listed account — but with no lease, since
	 * this is not about a job. Worth being clear-eyed about what it is: an authenticated
	 * account can read a file this app ships, and a worker that takes it lets its Nextcloud
	 * decide what code it runs. That is a real capability, it is why the worker verifies the
	 * checksum and refuses anything that will not compile, and it is why `--no-script`
	 * exists.
	 */
	#[NoAdminRequired]
	public function script(): DataResponse {
		if ($this->worker() === null) {
			return $this->refused();
		}

		$described = $this->script->describe();
		$contents = $this->script->contents();

		if ($described === null || $contents === null) {
			// A stripped install, or an app directory somebody manages themselves. The
			// worker treats this as "nothing to update to" rather than as a fault.
			return new DataResponse(['error' => 'no_script'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(array_merge($described, ['script' => $contents]));
	}

	/**
	 * Ask for something to do.
	 *
	 * @param string $worker what this worker calls itself, for whoever reads the queue later
	 * @param string $jsRuntime what it found to run YouTube's JavaScript in, as `name:/path`
	 */
	#[NoAdminRequired]
	public function claim(string $worker = '', string $jsRuntime = ''): DataResponse {
		$account = $this->worker();
		if ($account === null) {
			return $this->refused();
		}

		// Refused rather than handed a job the server has decided it does not want done:
		// the master switch is off, or an administrator has moved importing back onto this
		// server, in which case the queue is not the worker's to drain.
		if (!$this->settings->isRemote()) {
			return new DataResponse(['error' => 'not_remote'], Http::STATUS_CONFLICT);
		}

		try {
			$job = $this->queue->claim(
				$worker === '' ? $account : $worker,
				$jsRuntime === '' ? null : $jsRuntime,
			);
		} catch (\Throwable $e) {
			return $this->unexpected('claim a job', $e);
		}

		return new DataResponse(['job' => $job]);
	}

	/**
	 * What the video turned out to be. The answer says whether to go on.
	 *
	 * @param array<string, mixed> $metadata what `--dump-single-json` produced
	 */
	#[NoAdminRequired]
	public function metadata(int $importId, string $lease = '', array $metadata = []): DataResponse {
		return $this->act(fn (): array => $this->queue->metadata($importId, $lease, $metadata));
	}

	/**
	 * Still going. The answer is also how a worker hears that somebody pressed cancel.
	 */
	#[NoAdminRequired]
	public function progress(int $importId, string $lease = '', string $phase = '', int $progress = 0): DataResponse {
		return $this->act(fn (): array => $this->queue->progress($importId, $lease, $phase, $progress));
	}

	/**
	 * The channel owner's YouTube cookies, if this server lends them out at all.
	 */
	#[NoAdminRequired]
	public function cookies(int $importId, string $lease = ''): DataResponse {
		return $this->act(function () use ($importId, $lease): array {
			$jar = $this->queue->cookiesFor($importId, $lease);

			return ['cookies' => $jar];
		});
	}

	/**
	 * The jar as yt-dlp left it, so the stored copy does not go stale.
	 */
	#[NoAdminRequired]
	public function returnCookies(int $importId, string $lease = '', string $cookies = ''): DataResponse {
		return $this->act(function () use ($importId, $lease, $cookies): array {
			$this->queue->returnCookies($importId, $lease, $cookies);

			return [];
		});
	}

	/**
	 * The finished MP3.
	 *
	 * A `PUT` with the audio as the whole body, which is what lets it be read as a stream
	 * rather than held in memory — Nextcloud hands back a resource for a PUT whose content
	 * type is neither form-encoded nor JSON. A multipart upload would mean PHP buffering
	 * the file to disk first and applying `upload_max_filesize` to it, neither of which is
	 * wanted for a transfer between two servers.
	 *
	 * Everything else about this request is in the query string for the same reason: the
	 * body is audio and nothing else.
	 */
	#[NoAdminRequired]
	public function audio(int $importId, string $lease = '', int $durationMs = 0): DataResponse {
		$account = $this->worker();
		if ($account === null) {
			return $this->refused();
		}

		// The body, read as a stream and never as a string.
		//
		// `IRequest` has no way to say this — its `put` accessor is on the implementation
		// rather than the interface, and it is this same handle. Opened directly, which is
		// also the only form that is honest about what is happening: PHP has not parsed
		// this body and will not, because the content type is audio.
		$stream = @fopen('php://input', 'rb');
		if (!is_resource($stream)) {
			return new DataResponse(['error' => 'no_body'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$trackId = $this->queue->complete($importId, $lease, $stream, $durationMs > 0 ? $durationMs : null);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		} catch (\Throwable $e) {
			return $this->unexpected('file an uploaded import', $e);
		} finally {
			fclose($stream);
		}

		return new DataResponse(['trackId' => $trackId]);
	}

	/**
	 * It did not work. What yt-dlp said comes with it; this server decides what it meant.
	 */
	#[NoAdminRequired]
	public function fail(
		int $importId,
		string $lease = '',
		string $stage = 'download',
		string $code = '',
		int $exitCode = 1,
		bool $timedOut = false,
		bool $producedFile = false,
		bool $cookiesUsed = false,
		bool $jsRuntime = false,
		string $stdout = '',
		string $stderr = '',
	): DataResponse {
		return $this->act(function () use ($importId, $lease, $stage, $code, $exitCode, $timedOut, $producedFile, $cookiesUsed, $jsRuntime, $stdout, $stderr): array {
			$this->queue->failed(
				$importId,
				$lease,
				$stage === 'probe' ? 'probe' : 'download',
				$code === '' ? null : $code,
				$exitCode,
				$timedOut,
				$producedFile,
				$cookiesUsed,
				$jsRuntime,
				// Cut here rather than trusting the worker to have done it. Only the tail
				// matters — yt-dlp's last words are the ones that say why — and the column
				// this eventually informs is 255 characters wide.
				mb_substr($stdout, -4000),
				mb_substr($stderr, -8000),
			);

			return [];
		});
	}

	/**
	 * Hand the job back untouched, on the way out.
	 */
	#[NoAdminRequired]
	public function release(int $importId, string $lease = ''): DataResponse {
		return $this->act(function () use ($importId, $lease): array {
			$this->queue->release($importId, $lease);

			return [];
		});
	}

	// ---------------------------------------------------------------- plumbing

	/**
	 * The account making this call, if it is allowed to be a worker at all.
	 *
	 * Both halves matter and they are different questions. Nextcloud has already decided
	 * *who* this is — an app password over Basic auth, checked by the same code that checks
	 * every other client. This decides whether that account was granted this particular
	 * capability, which is one an administrator has to hand out deliberately.
	 */
	private function worker(): ?string {
		return $this->settings->isWorker($this->userId) ? $this->userId : null;
	}

	private function refused(): DataResponse {
		// Deliberately not 401. The credentials were fine; this account is simply not one
		// of the ones allowed to collect imports, and telling a script to go and
		// re-authenticate would send it round a loop that cannot end.
		return new DataResponse(['error' => 'not_a_worker'], Http::STATUS_FORBIDDEN);
	}

	/**
	 * Every reporting call has the same shape: check the account, do the thing, turn a
	 * refusal into a status.
	 *
	 * @param callable(): array<string, mixed> $work
	 */
	private function act(callable $work): DataResponse {
		if ($this->worker() === null) {
			return $this->refused();
		}

		try {
			return new DataResponse($work());
		} catch (MusicRadioException $e) {
			// The message is a code — `lease_not_held`, `no_such_import`, `cancelled` — and
			// the reader is a script, so it is passed through rather than translated.
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		} catch (\Throwable $e) {
			return $this->unexpected('handle a worker report', $e);
		}
	}

	private function unexpected(string $what, \Throwable $e): DataResponse {
		$this->logger->error('Could not ' . $what, [
			'app' => Application::APP_ID,
			'exception' => $e,
		]);

		return new DataResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
