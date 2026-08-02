<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reading and writing the app's settings, for both the pages and the endpoint that saves
 * them.
 *
 * One class rather than two so that a form and the request that saves it cannot disagree
 * about units or validity. Everything here was previously spread across two declarative
 * settings forms; the logic is the same, and the notes explaining why each rule exists
 * came with it.
 *
 * Both save methods report problems per field instead of throwing. A settings page can
 * carry several mistakes at once, and answering only the first — or replacing the whole
 * page with an error — is a poor way to be told about them.
 */
class SettingsStore {

	/**
	 * The one field on the personal page that is not a value.
	 *
	 * Removing a secret cannot be expressed as saving an empty one — see saveCookies() — so
	 * it travels as its own flag rather than as a sentinel value.
	 */
	public const FIELD_COOKIES_CLEAR = 'youtube_cookies_clear';

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private YtDlpLocator $locator,
		private RemoteImportSettings $remote,
		private IUserManager $userManager,
		private CookieJar $cookieJar,
		private IRootFolder $rootFolder,
		private Clock $clock,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	// --------------------------------------------------------------- admin

	/**
	 * @return array<string, mixed>
	 */
	public function adminValues(): array {
		return [
			YtDlpLocator::CONFIG_ENABLED => $this->appConfig->getValueBool(
				Application::APP_ID,
				YtDlpLocator::CONFIG_ENABLED,
				YtDlpLocator::DEFAULT_ENABLED,
			),
			YtDlpLocator::CONFIG_YTDLP_PATH => $this->appConfig->getValueString(
				Application::APP_ID,
				YtDlpLocator::CONFIG_YTDLP_PATH,
			),
			// Where the fetching happens. The rest of the yt-dlp fields on this page
			// describe this server's own tools and stop meaning anything in remote mode,
			// which is why the form hides them rather than leaving them to mislead.
			RemoteImportSettings::CONFIG_MODE => $this->remote->mode(),
			RemoteImportSettings::CONFIG_WORKERS => implode(', ', $this->remote->workerAccounts()),
			RemoteImportSettings::CONFIG_FORWARD_COOKIES => $this->remote->forwardsCookies(),
			// Stored in seconds and bytes, shown in minutes and megabytes: the units people
			// think in are not the units the rest of the code works in.
			YoutubeImportService::CONFIG_MAX_DURATION => (int)round($this->appConfig->getValueInt(
				Application::APP_ID,
				YoutubeImportService::CONFIG_MAX_DURATION,
				YoutubeImportService::DEFAULT_MAX_DURATION_SECONDS,
			) / 60),
			YoutubeImportService::CONFIG_MAX_SOURCE_BYTES => (int)round($this->appConfig->getValueInt(
				Application::APP_ID,
				YoutubeImportService::CONFIG_MAX_SOURCE_BYTES,
				YoutubeImportService::DEFAULT_MAX_SOURCE_BYTES,
			) / 1024 / 1024),
		];
	}

	/**
	 * Everything the admin page needs to render itself, including what the server can
	 * currently do.
	 *
	 * The status is recomputed on every render rather than stored, so the yt-dlp path and
	 * version an administrator is looking at are the ones in force right now.
	 *
	 * @return array<string, mixed>
	 */
	public function adminState(): array {
		return [
			'values' => $this->adminValues(),
			'summary' => $this->summary(),
			'ytDlp' => $this->describeYtDlp(),
			// On its own as well as inside the sentence above, so the page can show it
			// plainly beside the button that updates it. Null when nothing was found, or
			// when what was found would not say what it is.
			'ytDlpVersion' => $this->locator->version(),
			'remote' => $this->describeWorkers(),
		];
	}

	/**
	 * Whether anything is out there collecting imports.
	 *
	 * The one thing an administrator setting this up needs to see, and the one thing they
	 * cannot find out any other way: the workers are on machines they may not be sitting
	 * at, and "is it connected" is otherwise answered only by trying an import.
	 *
	 * @return array{online: bool, name: string, seenAt: int, secondsAgo: int|null,
	 *               jsRuntime: string|null}
	 */
	private function describeWorkers(): array {
		$seenAt = $this->remote->seenAt();

		return [
			'online' => $this->remote->isOnline(),
			'name' => $this->remote->seenName(),
			'seenAt' => $seenAt,
			// Worked out here rather than in the browser, whose clock is not this server's.
			'secondsAgo' => $seenAt === 0 ? null : max(0, $this->clock->nowSeconds() - $seenAt),
			'jsRuntime' => $this->remote->seenJsRuntime(),
		];
	}

	/**
	 * @param array<string, mixed> $values only the fields present are written
	 * @return array<string, string> field id => why it was refused; empty when all saved
	 */
	public function saveAdmin(array $values): array {
		$errors = [];

		if (array_key_exists(YtDlpLocator::CONFIG_ENABLED, $values)) {
			$this->appConfig->setValueBool(
				Application::APP_ID,
				YtDlpLocator::CONFIG_ENABLED,
				(bool)$values[YtDlpLocator::CONFIG_ENABLED],
			);
		}

		if (array_key_exists(YtDlpLocator::CONFIG_YTDLP_PATH, $values)) {
			$path = is_string($values[YtDlpLocator::CONFIG_YTDLP_PATH])
				? trim($values[YtDlpLocator::CONFIG_YTDLP_PATH])
				: '';
			$problem = $this->checkYtDlpPath($path);
			if ($problem !== null) {
				$errors[YtDlpLocator::CONFIG_YTDLP_PATH] = $problem;
			} else {
				$this->setYtDlpPath($path);
			}
		}

		$errors += $this->saveRemote($values);

		foreach ([
			YoutubeImportService::CONFIG_MAX_DURATION => 60,
			YoutubeImportService::CONFIG_MAX_SOURCE_BYTES => 1024 * 1024,
		] as $key => $multiplier) {
			if (!array_key_exists($key, $values)) {
				continue;
			}

			$given = (int)$values[$key];
			if ($given < 1) {
				$errors[$key] = $this->l10n->t('Give a number of at least 1.');
				continue;
			}

			$this->appConfig->setValueInt(Application::APP_ID, $key, $given * $multiplier);
		}

		return $errors;
	}

	/**
	 * The three settings that decide where imports happen and who may do them.
	 *
	 * The allow-list is checked rather than taken as typed, and that is not fussiness. An
	 * account named here can collect any queued import and upload audio that lands in
	 * another user's storage; a typo would leave the feature silently not working, and a
	 * *misdirected* entry would be a capability handed to somebody who never asked for it.
	 * So every name has to be an account that exists.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private function saveRemote(array $values): array {
		$errors = [];

		if (array_key_exists(RemoteImportSettings::CONFIG_MODE, $values)) {
			$mode = $values[RemoteImportSettings::CONFIG_MODE] === RemoteImportSettings::MODE_REMOTE
				? RemoteImportSettings::MODE_REMOTE
				: RemoteImportSettings::MODE_LOCAL;
			$this->appConfig->setValueString(Application::APP_ID, RemoteImportSettings::CONFIG_MODE, $mode);
		}

		if (array_key_exists(RemoteImportSettings::CONFIG_FORWARD_COOKIES, $values)) {
			$this->appConfig->setValueBool(
				Application::APP_ID,
				RemoteImportSettings::CONFIG_FORWARD_COOKIES,
				(bool)$values[RemoteImportSettings::CONFIG_FORWARD_COOKIES],
			);
		}

		if (!array_key_exists(RemoteImportSettings::CONFIG_WORKERS, $values)) {
			return $errors;
		}

		$given = is_string($values[RemoteImportSettings::CONFIG_WORKERS])
			? $values[RemoteImportSettings::CONFIG_WORKERS]
			: '';

		$accounts = [];
		$unknown = [];
		foreach (explode(',', $given) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '') {
				continue;
			}
			if (!$this->userManager->userExists($candidate)) {
				$unknown[] = $candidate;
				continue;
			}
			$accounts[] = $candidate;
		}

		if ($unknown !== []) {
			$errors[RemoteImportSettings::CONFIG_WORKERS] = $this->l10n->t(
				'There is no account called "%1$s".',
				[implode('", "', $unknown)],
			);

			return $errors;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			RemoteImportSettings::CONFIG_WORKERS,
			implode(',', array_unique($accounts)),
		);

		return $errors;
	}

	/**
	 * An override is only useful if it points at something that will run, so it is checked
	 * before it is accepted rather than becoming a broken feature to diagnose later.
	 */
	private function checkYtDlpPath(string $path): ?string {
		if ($path === '') {
			return null;
		}
		if (!str_starts_with($path, '/')) {
			return $this->l10n->t('Give the full path, starting with a slash.');
		}
		if (!is_file($path) || !is_executable($path)) {
			return $this->l10n->t('There is no program the server can run at that path.');
		}

		return null;
	}

	private function setYtDlpPath(string $path): void {
		$this->appConfig->setValueString(Application::APP_ID, YtDlpLocator::CONFIG_YTDLP_PATH, $path);
		// The previous binary's version is cached; keeping it would describe the old one.
		$this->appConfig->setValueInt(Application::APP_ID, YtDlpLocator::CONFIG_CHECKED_AT, 0);
	}

	// ------------------------------------------------------------ personal

	/**
	 * @return array<string, mixed>
	 */
	public function personalState(string $userId): array {
		return [
			'values' => [
				MusicLibrary::CONFIG_FOLDER => $this->userConfig->getValueString(
					$userId,
					Application::APP_ID,
					MusicLibrary::CONFIG_FOLDER,
					MusicLibrary::DEFAULT_FOLDER,
				),
			],
			'defaultFolder' => MusicLibrary::DEFAULT_FOLDER,
			// Note what is not here: the cookies. This is the payload the page renders from,
			// so anything in it is readable by whatever is looking at the page — which for a
			// stored secret is the one thing that must never be true. What comes back is a
			// description of the jar, never the jar. See CookieJar::describe().
			'cookies' => $this->cookieJar->describe($userId),
			// Whether a stored jar would actually be sent. Without a JavaScript runtime it
			// is held back, because authenticating moves yt-dlp onto clients that need one
			// — see YoutubeImportService::cookiesAreUsable(). A setting that is being
			// ignored has to say so on the page that offers it.
			//
			// In remote mode the question is about the *worker's* runtime, and about
			// whether this server lends cookies out at all — which it does not unless an
			// administrator said so.
			'cookiesUsable' => $this->remote->isRemote()
				? ($this->remote->forwardsCookies() && $this->remote->seenJsRuntime() !== null)
				: $this->locator->jsRuntime() !== null,
			// Whether offering the field is worth anything at all. With importing switched
			// off server-side, cookies would be a form that changes nothing.
			'importEnabled' => $this->appConfig->getValueBool(
				Application::APP_ID,
				YtDlpLocator::CONFIG_ENABLED,
				YtDlpLocator::DEFAULT_ENABLED,
			),
		];
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string> field id => why it was refused
	 */
	public function savePersonal(string $userId, array $values): array {
		$errors = $this->saveCookies($userId, $values);

		if (!array_key_exists(MusicLibrary::CONFIG_FOLDER, $values)) {
			return $errors;
		}

		$wanted = is_string($values[MusicLibrary::CONFIG_FOLDER]) ? $values[MusicLibrary::CONFIG_FOLDER] : '';
		$safe = MusicLibrary::sanitiseFolderPath($wanted);

		// Refused rather than quietly corrected. Saving "../music" and being shown "Music"
		// afterwards would be confusing; being told why is not. The one exception is an
		// empty value, which plainly means "back to the default".
		if (trim($wanted) !== '' && $safe !== trim(str_replace('\\', '/', $wanted), " \t\n\r\0\x0B/")) {
			$errors[MusicLibrary::CONFIG_FOLDER] = $this->l10n->t(
				'That folder cannot be used. Choose a folder inside your files.',
			);

			return $errors;
		}

		// The folder has to be there already.
		//
		// The page only offers a picker, so in ordinary use this cannot fail — but the
		// endpoint is reachable directly, and this is the rule the page is presenting, so
		// it is the server that has to hold it. An empty value is exempt: it means "back to
		// the default", which is created on first use like it always was.
		if (trim($wanted) !== '' && !$this->folderExists($userId, $safe)) {
			$errors[MusicLibrary::CONFIG_FOLDER] = $this->l10n->t('There is no folder called "%1$s" in your files.', [$safe]);

			return $errors;
		}

		$this->userConfig->setValueString($userId, Application::APP_ID, MusicLibrary::CONFIG_FOLDER, $safe);

		return $errors;
	}

	/**
	 * Store, replace or remove this person's YouTube cookies.
	 *
	 * Three states out of two fields, because "leave it alone" has to be the default: a page
	 * that saves a folder must not clear a jar it never showed. So an absent key means no
	 * change, `youtube_cookies_clear` means remove, and a non-empty paste replaces.
	 *
	 * An empty paste is *not* a removal. The field renders empty whenever something is
	 * stored — there is nothing to prefill it with — so treating empty as "delete" would
	 * make saving an unrelated setting silently throw the cookies away.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private function saveCookies(string $userId, array $values): array {
		if (($values[self::FIELD_COOKIES_CLEAR] ?? false) === true) {
			$this->cookieJar->clear($userId);

			return [];
		}

		if (!array_key_exists(CookieJar::CONFIG_COOKIES, $values)) {
			return [];
		}

		$pasted = is_string($values[CookieJar::CONFIG_COOKIES]) ? $values[CookieJar::CONFIG_COOKIES] : '';
		if (trim($pasted) === '') {
			return [];
		}

		$problem = $this->cookieJar->store($userId, $pasted);

		return $problem === null ? [] : [CookieJar::CONFIG_COOKIES => $problem];
	}

	/**
	 * Whether the path names a folder the user actually has.
	 *
	 * Anything unreadable counts as "no": a storage that is momentarily unavailable is not
	 * a folder somebody should be able to point this setting at.
	 */
	private function folderExists(string $userId, string $path): bool {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);

			return $userFolder->nodeExists($path) && $userFolder->get($path) instanceof Folder;
		} catch (\Throwable $e) {
			$this->logger->debug('Could not check whether a music folder exists', [
				'app' => Application::APP_ID,
				'path' => $path,
				'exception' => $e,
			]);

			return false;
		}
	}

	// ------------------------------------------------------ what is true now

	private function summary(): string {
		if ($this->remote->isRemote()) {
			return $this->remoteSummary();
		}

		$status = $this->locator->status();

		if ($status->available) {
			// The button is on this page, immediately below. Sending an administrator to a
			// terminal for something they can press is the friction it was added to remove
			// — and on a managed server there may be no terminal to send them to.
			return $status->outdated
				? $this->l10n->t('Working, but the downloader is out of date and some videos will fail. Use "Update yt-dlp" below.')
				: $this->l10n->t('Working.');
		}

		return match ($status->reason) {
			ImportError::DISABLED => $this->l10n->t('Switched off below.'),
			ImportError::FFMPEG_MISSING => $this->l10n->t('Not usable: ffmpeg and ffprobe are not installed. Both are needed, because YouTube does not serve MP3 and the audio has to be converted.'),
			ImportError::PROCESS_DISABLED => $this->l10n->t('Not usable: this PHP installation does not allow running external programs (proc_open is disabled).'),
			// Same reasoning as the outdated message above: the button that does this is
			// on this page.
			default => $this->l10n->t('Not usable: yt-dlp was not found. Install it with "Update yt-dlp" below, or give its path.'),
		};
	}

	/**
	 * The same question answered for a server that does not do the work itself.
	 *
	 * Every state names the next thing to do, because each of them is one step in the same
	 * setup: switch it on, name an account, start the worker.
	 */
	private function remoteSummary(): string {
		$status = $this->remote->status();

		if ($status->available) {
			$name = $this->remote->seenName();

			return $name === ''
				? $this->l10n->t('Working. Imports are fetched by a separate machine.')
				: $this->l10n->t('Working. Imports are fetched by "%1$s".', [$name]);
		}

		return match ($status->reason) {
			ImportError::DISABLED => $this->l10n->t('Switched off below.'),
			ImportError::REMOTE_NOT_CONFIGURED => $this->l10n->t('Not usable: no account may collect imports yet. Name one below, and give it an app password with "occ user:add-app-password".'),
			default => $this->l10n->t('Not usable: no worker has checked in. Start one on the machine that is to do the fetching — see the app\'s documentation.'),
		};
	}

	private function describeYtDlp(): string {
		$path = $this->locator->ytDlpPath();
		if ($path === null) {
			return $this->l10n->t('Nothing found. Looked for an override here, then for the copy "occ music_radio:ytdlp:install" manages, then on the system path.');
		}

		$version = $this->locator->version($path);

		return $version === null
			? $this->l10n->t('Using %1$s, which did not report a version — it may be incomplete.', [$path])
			: $this->l10n->t('Using %1$s, version %2$s.', [$path, $version]);
	}
}
