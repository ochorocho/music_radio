<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ShareService;
use OCA\MusicRadio\Service\VisitorIdentity;
use OCP\AppFramework\AuthPublicShareController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

/**
 * The page an anonymous listener lands on when they open a shared link.
 *
 * ⚠ Naming is not free here. AuthPublicShareController resolves its own routes by
 * reflection — `strtolower($appName) . '.' . <short class name minus "Controller"> . '.'
 * . $method` — so this class must be routed as `ChannelPublic#showShare`,
 * `ChannelPublic#showAuthenticate` and `ChannelPublic#authenticate`, and the URL
 * parameter must literally be called `token`. Renaming either breaks the password
 * redirect with no compile-time warning.
 *
 * Everything else — the password form, brute-force throttling, remembering that this
 * session already authenticated — comes from core's PublicShareMiddleware, which binds
 * itself to any PublicShareController. Nothing is registered for it.
 */
class ChannelPublicController extends AuthPublicShareController {
	use TokenShareTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		IURLGenerator $urlGenerator,
		private ShareService $shareService,
		private ChannelMapper $channelMapper,
		private IInitialState $initialState,
		private VisitorIdentity $visitorIdentity,
	) {
		parent::__construct($appName, $request, $session, $urlGenerator);
	}

	/**
	 * The password prompt.
	 *
	 * Overridden because core's default renders `core/publicshareauth`, and that template
	 * is typed to `OCP\Share\IShare` — it calls getShareType() and getSendPasswordByTalk()
	 * on whatever it is handed. A channel share is not a file share and has no such
	 * object, so the base implementation fatals. This app brings its own form.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showAuthenticate(): TemplateResponse {
		return $this->passwordPrompt();
	}

	protected function showAuthFailed(): TemplateResponse {
		return $this->passwordPrompt(wrongPassword: true);
	}

	private function passwordPrompt(bool $wrongPassword = false): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'authenticate',
			[
				'wrongpw' => $wrongPassword,
				'channelTitle' => $this->channel()?->getTitle() ?? '',
				'actionUrl' => $this->urlGenerator->linkToRoute(
					Application::APP_ID . '.ChannelPublic.authenticate',
					['token' => $this->getToken(), 'redirect' => 'showShare'],
				),
			],
			TemplateResponse::RENDER_AS_GUEST,
		);
	}

	protected function verifyPassword(string $password): bool {
		$share = $this->share();

		return $share !== null && $this->shareService->verifyPassword($share, $password);
	}

	/**
	 * The attributes are required even though the base class is "public": the framework
	 * reads them off the concrete method, and without them the route demands a session
	 * and answers 401 instead of rendering the share.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showShare(): TemplateResponse {
		$channel = $this->channel();
		if ($channel === null) {
			// The middleware normally catches an invalid token before reaching here; this
			// is the belt-and-braces path.
			return new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST);
		}

		// Issued here rather than by the API, because this is the one request that renders
		// a page and so the one that can set a cookie the browser will send back.
		$visitorKey = $this->visitorIdentity->current() ?? $this->visitorIdentity->issue();

		$this->initialState->provideInitialState('music_radio-initial-state', [
			'mode' => 'public',
			'token' => $this->getToken(),
			'channel' => [
				'id' => $channel->getId(),
				'title' => $channel->getTitle(),
				'description' => $channel->getDescription(),
				// Re-clamped rather than taken as stored, for the same reason the API
				// clamps it: a link never grants more than LINK_ALLOWED, whatever a row
				// created under older rules happens to say.
				'permissions' => ($this->share()?->getPermissions() ?? Permission::LISTEN)
					& Permission::LINK_ALLOWED,
			],
		]);

		// A PublicTemplateResponse rather than a plain one, for the two things only it
		// can do: put the channel's name in the page header, and switch off the
		// `guest-box` footer core otherwise pins across the bottom of the page. That
		// footer is a frosted bar advertising Nextcloud, and on a page whose whole
		// purpose is a player it is both noise and — being `position: fixed` — an
		// obstacle sitting over the controls.
		$response = new PublicTemplateResponse(Application::APP_ID, 'public');
		$response->setHeaderTitle($channel->getTitle());
		$response->setFooterVisible(false);

		// Lets this browser take back what it uploads. Re-set on every visit so the expiry
		// keeps moving; the value only changes if the browser lost it. See VisitorIdentity
		// for how little this is meant to prove.
		$response->addCookie(
			VisitorIdentity::COOKIE,
			$visitorKey,
			$this->visitorIdentity->lifetime(),
		);

		// No CSP override: the default policy already allows media-src and connect-src
		// from 'self', which is all the player needs.

		return $response;
	}
}
