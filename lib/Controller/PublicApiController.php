<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Import;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\AudioStreamService;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\ImportReaper;
use OCA\MusicRadio\Service\ListenerPresence;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\PlaybackService;
use OCA\MusicRadio\Service\ProgrammeStreamService;
use OCA\MusicRadio\Service\ShareService;
use OCA\MusicRadio\Service\TrackService;
use OCA\MusicRadio\Service\UploadService;
use OCA\MusicRadio\Service\VisitorIdentity;
use OCA\MusicRadio\Service\VoteService;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\PublicShareController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;

/**
 * What an anonymous listener's player talks to.
 *
 * Extends the plain PublicShareController rather than the Auth one on purpose: an API
 * call should answer 404 for a bad token, not redirect to a password form. Being a
 * PublicShareController still means core's middleware validates the token and honours a
 * password already entered in this session, since both controllers read the same session
 * key.
 *
 * ⚠ When the middleware rejects a token it renders core's HTML "share not found" page,
 * even for these JSON routes. The client therefore treats any non-200 as "this link is
 * gone" rather than trying to parse the body.
 */
class PublicApiController extends PublicShareController {
	use TokenShareTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		private ShareService $shareService,
		private ChannelMapper $channelMapper,
		private TrackService $trackService,
		private PlaybackService $playbackService,
		private AudioStreamService $audioStreamService,
		private ProgrammeStreamService $programmeStreamService,
		private UploadService $uploadService,
		private PermissionService $permissionService,
		private ListenerPresence $listenerPresence,
		private VisitorIdentity $visitorIdentity,
		private VoteService $voteService,
		private ImportMapper $importMapper,
		private YoutubeImportService $importService,
		private ImportReaper $reaper,
		private IL10N $l10n,
		private Clock $clock,
	) {
		parent::__construct($appName, $request, $session);
	}

	/**
	 * @param string|null $clientId a per-tab id the browser made up; see the signed-in
	 *                              endpoint. Not the visitor cookie — that identifies a
	 *                              browser across visits, this identifies one open tab.
	 * @param bool $listening whether audio is actually playing here
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function state(?string $clientId = null, bool $listening = false): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$listeners = $this->listenerPresence->record($channel->getId(), $clientId, $listening);

		// See the signed-in endpoint: a channel whose listeners are all anonymous still has
		// to spend the votes of tracks that have played.
		if ($channel->getAllowVoting()) {
			$this->voteService->recomputeIfDue($channel);
		}

		return new DataResponse($this->playbackService->buildState(
			$channel,
			$this->linkPermissions(),
			$receivedAt,
			$listeners,
			// This link's own answer, not the channel's.
			$this->share()?->getShowListenerCount() !== false,
		));
	}

	/**
	 * Drive the broadcast from a link.
	 *
	 * The anonymous twin of PlaybackController::control, down to the optimistic-concurrency
	 * contract: the client sends the state version it was last shown, and a mismatch comes
	 * back as 409 with the current state rather than one tab silently overwriting another.
	 * Two people on the same link are exactly the case that makes that matter.
	 *
	 * A link only reaches here when its owner granted CONTROL, which is not the default and
	 * cannot be reached by accident — see Permission::LINK_ALLOWED.
	 *
	 * @param string $action play|pause|next|previous|seek|jumpTo
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function control(
		string $action,
		?int $offsetMs = null,
		?int $trackId = null,
		?int $expectedStateVersion = null,
	): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$permissions = $this->linkPermissions();
		if (!Permission::has($permissions, Permission::CONTROL)) {
			return new DataResponse(
				['error' => 'This link does not allow controlling playback'],
				Http::STATUS_FORBIDDEN,
			);
		}

		if ($expectedStateVersion !== null && $expectedStateVersion !== $channel->getStateVersion()) {
			return new DataResponse([
				'error' => 'The channel changed since you last loaded it',
				'state' => $this->publicState($channel, $permissions, $receivedAt),
			], Http::STATUS_CONFLICT);
		}

		try {
			$channel = match ($action) {
				'play' => $this->playbackService->play($channel),
				'pause' => $this->playbackService->pause($channel),
				'next' => $this->playbackService->next($channel),
				'previous' => $this->playbackService->previous($channel),
				'seek' => $this->playbackService->seek($channel, $offsetMs ?? 0),
				'jumpTo' => $this->playbackService->jumpTo($channel, $trackId ?? 0),
				default => throw new MusicRadioException('Unknown playback action'),
			};
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->publicState($channel, $permissions, $receivedAt));
	}

	/**
	 * Loop and shuffle, from a link that carries CONTROL. Part of the same controls block
	 * as play and skip, so it is gated identically rather than held back separately.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function playbackSettings(?bool $loop = null, ?bool $shuffle = null): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$permissions = $this->linkPermissions();
		if (!Permission::has($permissions, Permission::CONTROL)) {
			return new DataResponse(
				['error' => 'This link does not allow controlling playback'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			$channel = $this->playbackService->updateSettings($channel, $loop, $shuffle);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->publicState($channel, $permissions, $receivedAt));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function tracks(): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$visitorKey = $this->visitorIdentity->current();
		$permissions = $this->linkPermissions();

		$voting = $this->voteService->stateFor(
			$channel,
			$visitorKey === null ? null : $this->visitorIdentity->creditFor($visitorKey),
		);
		$mine = array_flip($voting['mine']);

		// Whether this visitor may take a row back is answered here rather than left to
		// the page to work out. The browser cannot reliably read its own cookie, and even
		// if it could, the server would still have to decide — so it decides once, and the
		// page renders what it is told. The same goes for whether they have already voted.
		$tracks = array_map(
			fn (Track $track): array => array_merge($track->jsonSerialize(), [
				'canRemove' => $this->permissionService->canVisitorRemoveTrack(
					$permissions,
					$track->getAddedBy(),
					$visitorKey,
				),
				'votes' => $voting['counts'][$track->getId()] ?? 0,
				'voted' => isset($mine[$track->getId()]),
			]),
			$this->trackService->listForChannel($channel),
		);

		return new DataResponse([
			'tracks' => $tracks,
			'playlistVersion' => $channel->getPlaylistVersion(),
			// The page needs both: whether the channel is voting at all, and whether this
			// particular link is. The link's own switch, not a bit on its permission mask —
			// see Version001100.
			'votingEnabled' => $channel->getAllowVoting(),
			'canVote' => $channel->getAllowVoting()
				&& $this->share()?->getAllowVoting() === true
				&& $visitorKey !== null,
			// Whether this particular link may fetch from YouTube. Two switches have to
			// agree — the administrator's and this link's — and the button is only offered
			// when they do, so it never promises what the server refuses.
			//
			// The administrator's half used to be missing here: createImport goes through
			// YoutubeImportService::request, which refuses when this server will not fetch
			// from YouTube at all, so the endpoint was safe — but the public page was still
			// offered a button that could only fail.
			'canImport' => Permission::has($permissions, Permission::ADD_TRACKS)
				&& $this->share()?->getAllowImport() === true
				&& $this->importService->availability()->available,
		]);
	}

	/**
	 * A stretch of the programme for an anonymous listener.
	 *
	 * The anonymous twin of StreamController::programme, and the thing a link visitor
	 * actually plays. See there for why a span of the programme rather than one track: on
	 * a locked iPhone nothing of ours runs when a track ends, so the audio has to already
	 * contain what comes next.
	 *
	 * Not rate-limited, for the same reason the per-track stream below is not — this is a
	 * media element fetching audio, and a limit here surfaces as playback stopping.
	 *
	 * @param int $from position in the programme, in milliseconds
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function programme(int $from = 0): Response {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$stream = $this->programmeStreamService->stream($channel, $from);
		if ($stream === null) {
			return new DataResponse(['error' => 'Nothing to broadcast'], Http::STATUS_NOT_FOUND);
		}

		return $stream;
	}

	/**
	 * Audio for an anonymous listener.
	 *
	 * Deliberately not rate-limited. A media element's request pattern is unpredictable
	 * — Safari alone opens with a probe, then ranges, then re-requests on seek, and the
	 * next track is preloaded alongside — so a limit here would show up as playback
	 * randomly stopping. Guessing the token is already covered by the middleware's
	 * brute-force throttling.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function stream(int $trackId): Response {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$track = $this->trackService->find($channel, $trackId);

			return $this->audioStreamService->stream($channel, $track, $this->request);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Put a track on the channel, from someone with no account.
	 *
	 * Off unless the owner has turned uploading on for this particular link. The file is
	 * charged to the owner's storage, so the rate limit here is deliberately tight — a
	 * link that leaks should cost its owner a nuisance, not their quota.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 3600)]
	public function upload(): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (!Permission::has($this->linkPermissions(), Permission::ADD_TRACKS)) {
			return new DataResponse(
				['error' => 'This link does not allow uploading'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$upload = $this->request->getUploadedFile('file');
		if (!is_array($upload)) {
			return new DataResponse(['error' => 'No file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$track = $this->uploadService->storeForChannel(
				$channel,
				$upload,
				$this->visitorIdentity->current(),
				// This link's own answer. It cannot be resolved further down: a visitor key
				// is not an account, so there is nothing to look a share up by.
				$this->share()?->getRequireApproval() !== false,
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse(['track' => $track], Http::STATUS_CREATED);
	}

	/**
	 * Rewrite the running order from a link that carries EDIT_PLAYLIST.
	 *
	 * The anonymous twin of TrackController::reorder, and the reason the removal below now
	 * has a curator branch: reordering anyone's track but being unable to remove one would
	 * be a strange half-permission, and the owner is offered the two as a single switch.
	 *
	 * @param int[] $trackIds the full playlist in its new order
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function reorderTracks(array $trackIds = []): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (!Permission::has($this->linkPermissions(), Permission::EDIT_PLAYLIST)) {
			return new DataResponse(
				['error' => 'This link does not allow reordering the playlist'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			$this->trackService->reorder($channel, $trackIds);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse(['tracks' => $this->trackService->listForChannel($channel)]);
	}

	/**
	 * Take back a track this browser uploaded — or, for a link that curates, any track.
	 *
	 * The counterpart to being able to upload at all: somebody who adds the wrong file, or
	 * thinks better of it, could previously do nothing about it — an anonymous upload was
	 * credited to nobody, so only the owner could remove it.
	 *
	 * Otherwise limited to what this browser added, the key being a cookie rather than
	 * anything authenticated — see VisitorIdentity for how little it proves. Removing a
	 * track costs the owner nothing they had not already agreed to when they allowed
	 * uploads, which is what makes that acceptable. A link given EDIT_PLAYLIST is a
	 * separate, deliberate decision, and PermissionService::canVisitorRemoveTrack is where
	 * the two answers meet.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 3600)]
	public function destroyTrack(int $trackId): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$track = $this->trackService->find($channel, $trackId);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		if (!$this->permissionService->canVisitorRemoveTrack(
			$this->linkPermissions(),
			$track->getAddedBy(),
			$this->visitorIdentity->current(),
		)) {
			// Deliberately the same answer whether the track belongs to somebody else or
			// this link cannot remove anything: neither tells a visitor about tracks that
			// are not theirs.
			return new DataResponse(
				['error' => 'You can only remove tracks you added yourself'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$this->trackService->remove($channel, $track);

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Vote for a track, from somebody with no account.
	 *
	 * The voter key is the per-browser visitor cookie, which is knowingly gameable —
	 * clearing cookies or opening a private window yields a new one. That is accepted:
	 * this is a request to hear a song sooner among people who already share a link, not
	 * a ballot. An owner who does not want it does not grant VOTE on the link, and a link
	 * is created listen-only by default.
	 *
	 * A visitor with no cookie at all cannot vote — otherwise every such browser would
	 * share one identity and between them hold exactly one vote.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	public function vote(int $trackId): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		// The link's own switch, not a bit on its permission mask.
		if (!$channel->getAllowVoting() || $this->share()?->getAllowVoting() !== true) {
			return new DataResponse(['error' => 'This link does not allow voting'], Http::STATUS_FORBIDDEN);
		}

		$visitorKey = $this->visitorIdentity->current();
		if ($visitorKey === null) {
			return new DataResponse(
				['error' => 'This browser cannot be identified, so it cannot vote'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			return new DataResponse(
				$this->voteService->toggle($channel, $trackId, $this->visitorIdentity->creditFor($visitorKey)),
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Imports in flight on this channel, for the public page.
	 *
	 * Gated on being able to add at all, like the signed-in equivalent: someone who may
	 * only listen has no business watching what other people are in the middle of adding.
	 *
	 * **Only this browser's own**, unlike the signed-in equivalent. The queue exists so a
	 * visitor can watch and stop what they started, and that is all they have any claim to
	 * — the owner's and other visitors' imports would tell them who else is using the
	 * channel and what they are adding, which a link was never meant to disclose. It also
	 * matches what destroyImport will actually let them do.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function imports(): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (!Permission::has($this->linkPermissions(), Permission::ADD_TRACKS)) {
			return new DataResponse(['error' => 'This link does not allow adding'], Http::STATUS_FORBIDDEN);
		}

		// Before answering, not after — the same reason as the signed-in endpoint.
		$this->reaper->sweep($channel->getId());

		$visitorKey = $this->visitorIdentity->current();
		$mine = $visitorKey === null ? null : $this->visitorIdentity->creditFor($visitorKey);

		return new DataResponse([
			'imports' => array_values(array_map(
				fn (Import $import): array => $this->presentImport($import),
				array_filter(
					$this->importMapper->findAllForChannel($channel->getId()),
					static fn (Import $import): bool => $mine !== null && $import->getUserId() === $mine,
				),
			)),
			// The same shape the signed-in endpoint answers with, sentence included — a
			// visitor holding a link is owed the reason importing is off as much as a
			// contributor is. See ImportController::index.
			'capabilities' => $this->describeCapabilities(),
		]);
	}

	/**
	 * Whether importing is possible, and why not when it is not.
	 *
	 * @return array<string, mixed>
	 */
	private function describeCapabilities(): array {
		$capabilities = $this->importService->availability();

		return array_merge($capabilities->jsonSerialize(), [
			'reasonText' => $capabilities->reason === null
				? null
				: ImportError::describe($capabilities->reason, $this->l10n),
		]);
	}

	/**
	 * Fetch a track from YouTube, asked for by somebody with no account.
	 *
	 * This is the one thing on the public side that spends more than the owner's disk: the
	 * server does the downloading and the transcoding, on their CPU, into their storage. It
	 * was deliberately not offered for exactly that reason, and is offered now only behind
	 * every gate that could reasonably be put in front of it —
	 *
	 *  - the administrator's server-wide switch, which is off until they turn it on;
	 *  - the channel's own switch;
	 *  - this particular link's switch, which is off unless the owner set it;
	 *  - a rate limit tighter than the upload path's, because the cost is higher;
	 *  - the per-requester and per-channel caps `request()` already applies, keyed on the
	 *    visitor so one browser cannot queue a pile;
	 *  - the administrator's length and size limits, unchanged.
	 *
	 * A link that leaks can still cost its owner quota and CPU until they revoke it. That
	 * is the trade, and it is why the switch is off by default rather than on.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 3600)]
	public function createImport(string $url = ''): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$share = $this->share();

		if (!Permission::has($this->linkPermissions(), Permission::ADD_TRACKS)
			|| $share?->getAllowImport() !== true) {
			return new DataResponse(
				['error' => $this->l10n->t('This link does not allow adding tracks from YouTube')],
				Http::STATUS_FORBIDDEN,
			);
		}

		// Without a key there is nothing to charge the per-requester cap against, and
		// nothing to let the visitor stop their own import afterwards.
		$visitorKey = $this->visitorIdentity->current();
		if ($visitorKey === null) {
			return new DataResponse(
				['error' => $this->l10n->t('This browser cannot be identified, so it cannot import')],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			$import = $this->importService->request(
				$channel,
				$this->visitorIdentity->creditFor($visitorKey),
				$url,
				// The link's answer, decided now — see YoutubeImportService::request().
				$share->getRequireApproval() === false,
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $this->explainImport($e)], $e->getStatus());
		}

		return new DataResponse(['import' => $this->presentImport($import)], Http::STATUS_ACCEPTED);
	}

	/**
	 * Stop an import this browser started, or clear a finished one off the list.
	 *
	 * Only this browser's own. A link *can* now be given EDIT_PLAYLIST, but curating the
	 * playlist is not the same as reaching into somebody else's import queue — and a
	 * visitor is only ever shown their own imports anyway, so there is nothing there for
	 * them to act on.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 3600)]
	public function destroyImport(int $importId): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$visitorKey = $this->visitorIdentity->current();
		if ($visitorKey === null) {
			return new DataResponse(['error' => 'Not yours to stop'], Http::STATUS_FORBIDDEN);
		}

		try {
			$import = $this->importMapper->find($importId, $channel->getId());
		} catch (\Throwable) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($import->getUserId() !== $this->visitorIdentity->creditFor($visitorKey)) {
			return new DataResponse(['error' => 'Not yours to stop'], Http::STATUS_FORBIDDEN);
		}

		// The same two meanings the signed-in endpoint has, which this used to be missing:
		// cancel() only touches a row that is queued or running, and answers 409 for
		// anything else. So pressing × on an import that had already *failed* — the one
		// most likely to still be sitting on the list, since a finished one tidies itself
		// away — refused every time, and the row could not be got rid of at all.
		if ($import->isActive()) {
			$this->importService->cancel($import);
		} else {
			// Already finished, one way or another: this is "clear it off my list".
			$this->importMapper->delete($import);
		}

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * The same shape the signed-in page gets, minus who asked for it.
	 *
	 * A visitor only ever sees their own imports, so the field would tell them nothing they
	 * do not know — and it holds a real user id whenever the owner is the one importing.
	 */
	private function presentImport(Import $import): array {
		$code = $import->getErrorCode();
		$presented = array_merge($import->jsonSerialize(), [
			'error' => $code === null
				? null
				: ImportError::describe($code, $this->l10n, $this->importService->maxDurationSeconds()),
		]);
		unset($presented['userId']);

		return $presented;
	}

	private function explainImport(MusicRadioException $e): string {
		$message = $e->getMessage();
		$described = ImportError::describe($message, $this->l10n, $this->importService->maxDurationSeconds());

		return $described === ImportError::describe(ImportError::UNKNOWN, $this->l10n)
			&& $message !== ImportError::UNKNOWN
				? $message
				: $described;
	}

	/**
	 * What this particular link grants. Never more than LINK_ALLOWED, whatever is in the
	 * row — the mask is re-clamped here so a permission that stopped being appropriate
	 * for links cannot keep working on rows created while it was.
	 */
	private function linkPermissions(): int {
		return ($this->share()?->getPermissions() ?? Permission::NONE) & Permission::LINK_ALLOWED;
	}

	/**
	 * The broadcast state as this link should see it, for the endpoints that hand it back
	 * after changing something.
	 *
	 * Counts listeners read-only rather than skipping the figure: each of these responses
	 * replaces the client's state wholesale, and a missing count would blink the listener
	 * figure out of the page on every press of play. The visibility answer is this link's
	 * own, exactly as in `state()`.
	 *
	 * @return array<string, mixed>
	 */
	private function publicState(Channel $channel, int $permissions, int $receivedAt): array {
		return $this->playbackService->buildState(
			$channel,
			$permissions,
			$receivedAt,
			$this->listenerPresence->count($channel->getId()),
			$this->share()?->getShowListenerCount() !== false,
		);
	}
}
