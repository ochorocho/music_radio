<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\ImportReaper;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Importing audio into a channel from a link.
 *
 * Gated on Permission::ADD_TRACKS, the same permission as every other way of putting a
 * track on a channel — and offered only to people with an account. There is deliberately
 * no public-link equivalent: letting an anonymous visitor start server-side downloads
 * against the owner's quota and CPU is a different proposition from letting them upload a
 * file they already have.
 *
 * Error codes become sentences here, and only here. A background job cannot translate,
 * because it has no request and so no idea what language anyone reads.
 */
class ImportController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private PermissionService $permissionService,
		private YoutubeImportService $importService,
		private ImportMapper $importMapper,
		private ImportReaper $reaper,
		private IL10N $l10n,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * This channel's imports, and whether the server can do them at all.
	 *
	 * Generous limit because this is what the browser polls while something is running.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function index(int $id): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			// Not LISTEN: someone who can only listen has no business seeing what other
			// people are in the middle of adding.
			$this->permissionService->requirePermission($channel, $this->userId, Permission::ADD_TRACKS);

			// Before answering, not after — see ImportReaper for why this is not just the
			// background job's business.
			$this->reaper->sweep($channel->getId());

			$imports = $this->importMapper->findAllForChannel($channel->getId());
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $this->explain($e)], $e->getStatus());
		}

		return new DataResponse([
			'imports' => array_map(fn (Import $import): array => $this->present($import), $imports),
			'capabilities' => $this->importService->availability(),
		]);
	}

	/**
	 * Ask for a video to be imported.
	 *
	 * The same limit the public upload endpoint uses, and for the same reason: each call
	 * costs somebody's storage, and here it also costs a transcode.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function create(int $id, string $url = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::ADD_TRACKS);

			// Two switches, answering different questions: the administrator's decides
			// whether this server will fetch from YouTube at all, and this one whether this
			// particular person was granted it. Checked here rather than only hidden in the
			// interface, because the endpoint is reachable without it — and it was reachable
			// without it, until this read the share instead of the channel: the tracks
			// endpoint has always advertised `canImport` from the same rule, so a sharee who
			// was told no could nevertheless post one and have it accepted.
			if (!$this->permissionService->shareRulesFor($channel, $this->userId)['allowImport']) {
				return new DataResponse(
					['error' => $this->l10n->t('This channel does not take tracks from YouTube')],
					Http::STATUS_FORBIDDEN,
				);
			}

			$import = $this->importService->request($channel, $this->userId, $url);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $this->explain($e)], $e->getStatus());
		}

		// Accepted, not created: the track does not exist yet, and may never.
		return new DataResponse(['import' => $this->present($import)], Http::STATUS_ACCEPTED);
	}

	/**
	 * Stop an import, or clear a finished one off the list.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function destroy(int $id, int $importId): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$permissions = $this->permissionService->requirePermission($channel, $this->userId, Permission::ADD_TRACKS);

			try {
				$import = $this->importMapper->find($importId, $channel->getId());
			} catch (\Throwable) {
				throw new NotFoundException('Import not found');
			}

			// Your own import is yours to stop. Anyone curating the playlist can stop
			// anyone's — the same shape as removing a track someone else added.
			if ($import->getUserId() !== $this->userId
				&& !Permission::has($permissions, Permission::EDIT_PLAYLIST)) {
				return new DataResponse(
					['error' => $this->l10n->t('You cannot stop an import somebody else started')],
					Http::STATUS_FORBIDDEN,
				);
			}

			if ($import->isActive()) {
				$this->importService->cancel($import);
			} else {
				// Already finished: this is "clear it off my list", not "stop it".
				$this->importMapper->delete($import);
			}
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $this->explain($e)], $e->getStatus());
		}

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * The row plus its error as a sentence.
	 *
	 * When the cause was one yt-dlp's output did not explain — ImportError::UNKNOWN — what
	 * it actually said is appended, so "The import failed." is never the whole answer. The
	 * text has already been cut down by YtDlpFailure::detail(); see there for what is
	 * stripped and why the public endpoint deliberately does not do this.
	 *
	 * @return array<string, mixed>
	 */
	private function present(Import $import): array {
		$code = $import->getErrorCode();
		if ($code === null) {
			return array_merge($import->jsonSerialize(), ['error' => null]);
		}

		$message = ImportError::describe($code, $this->l10n, $this->importService->maxDurationSeconds());
		$detail = $import->getErrorDetail();

		return array_merge($import->jsonSerialize(), [
			'error' => $detail === null || $detail === ''
				? $message
				: $this->l10n->t('%1$s yt-dlp said: %2$s', [$message, $detail]),
		]);
	}

	/**
	 * Refusals from the import service carry a code as their message; everything else
	 * already carries a sentence.
	 */
	private function explain(MusicRadioException $e): string {
		$message = $e->getMessage();
		$described = ImportError::describe($message, $this->l10n, $this->importService->maxDurationSeconds());

		// describe() falls back to a generic sentence for anything it does not recognise,
		// which would swallow the specific message an exception from elsewhere carries.
		return $described === ImportError::describe(ImportError::UNKNOWN, $this->l10n)
			&& $message !== ImportError::UNKNOWN
				? $message
				: $described;
	}
}
