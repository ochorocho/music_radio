<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\SettingsStore;
use OCA\MusicRadio\Service\YtDlpInstaller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Settings\IDelegatedSettings;
use Psr\Log\LoggerInterface;

/**
 * Saving the settings pages.
 *
 * One request per page rather than one per field, which is the whole point of the rewrite:
 * the declarative forms these replaced saved on blur, so a value typed and then clicked
 * away from either saved invisibly or, if it was refused, said so with no obvious relation
 * to the button nobody had pressed.
 *
 * Problems come back as a map of field id to message with a 200, not as an error status.
 * A page can carry more than one mistake, and the page needs to put each message beside
 * the field it belongs to — neither of which survives being flattened into an HTTP error.
 */
class SettingsController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsStore $store,
		private YtDlpInstaller $installer,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[AuthorizedAdminSetting(settings: IDelegatedSettings::class)]
	public function saveAdmin(array $values = []): DataResponse {
		$errors = $this->store->saveAdmin($values);

		return new DataResponse([
			'errors' => $errors,
			// The status text depends on what was just saved — switching importing off, or
			// correcting the yt-dlp path, changes what the server can do — so the page is
			// told rather than left showing what was true when it loaded.
			'state' => $this->store->adminState(),
		]);
	}

	/**
	 * Fetch or replace this server's copy of yt-dlp.
	 *
	 * The same thing `occ music_radio:ytdlp:install --force` does, which is what the status
	 * text used to tell administrators to go and run — a shell away from the page that
	 * reported the problem, and not an option at all for anyone administering a server they
	 * do not have a terminal on. YouTube breaks extractors every few weeks, so this is
	 * routine maintenance rather than a one-off setup step.
	 *
	 * Always forced. There is no "install it if it is missing" case worth a separate
	 * button: the page shows the version, so pressing this means "get the current one".
	 *
	 * Gated exactly as saveAdmin is. Deliberately no password confirmation, for
	 * consistency rather than convenience — that form can already point `ytdlp_path` at
	 * any executable on the server, which is the larger of the two capabilities.
	 */
	#[AuthorizedAdminSetting(settings: IDelegatedSettings::class)]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function installYtDlp(): DataResponse {
		try {
			$installed = $this->installer->install(force: true);
		} catch (MusicRadioException $e) {
			// The installer's messages are written for an administrator and name the thing
			// that went wrong — an unbuildable path, no asset for this architecture — so
			// they are passed through rather than flattened into "that did not work".
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Could not install yt-dlp from the settings page', [
				'app' => 'music_radio',
				'exception' => $e,
			]);

			return new DataResponse(
				['error' => $this->l10n->t('yt-dlp could not be installed. The server log has the details.')],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new DataResponse([
			'installed' => $installed,
			// install() re-reads the version it just wrote, so this is the new one rather
			// than whatever was cached before.
			'state' => $this->store->adminState(),
		]);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[NoAdminRequired]
	public function savePersonal(array $values = []): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$errors = $this->store->savePersonal($this->userId, $values);

		return new DataResponse([
			'errors' => $errors,
			'state' => $this->store->personalState($this->userId),
		]);
	}
}
