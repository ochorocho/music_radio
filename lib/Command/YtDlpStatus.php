<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Command;

use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\JsRuntime;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the server can and cannot do, said plainly.
 *
 * The paths are printed here, unlike in the API response, because whoever can run occ can
 * already read the filesystem.
 */
class YtDlpStatus extends Command {

	public function __construct(
		private YtDlpLocator $locator,
		private IAppConfig $appConfig,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('music_radio:ytdlp:status')
			->setDescription('Show whether YouTube import is usable on this server');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$status = $this->locator->status(recheck: true);

		// Probed directly rather than read off $status, because status() short-circuits as
		// soon as it knows the answer — when the feature is switched off it never looks for
		// anything, and reporting that as "not found" would send an administrator hunting
		// for a binary that is sitting right there.
		$ytDlp = $this->locator->ytDlpPath();
		$ffmpeg = $this->locator->ffmpegDirectory();
		$jsRuntime = $this->locator->jsRuntime();

		$output->writeln('yt-dlp:  ' . ($ytDlp ?? '<comment>not found</comment>'));
		$output->writeln('version: ' . ($this->locator->version($ytDlp, recheck: true) ?? '<comment>unknown</comment>'));
		$output->writeln('ffmpeg:  ' . ($ffmpeg ?? '<comment>not found</comment>'));
		$output->writeln('js:      ' . ($jsRuntime?->spec() ?? '<comment>not found</comment>'));
		$output->writeln('');

		if (!$status->available) {
			$output->writeln('<error>YouTube import is not usable: ' . $status->reason . '</error>');
			$output->writeln('');
			$output->writeln($this->adviceFor($status->reason));

			return Command::FAILURE;
		}

		$output->writeln('<info>YouTube import is usable.</info>');

		// Reported after "usable" rather than instead of it: everything this app needs is
		// present, and yt-dlp will still be turned away at the download. Said here because
		// the failure it produces looks like a stale binary or a broken network, and an
		// administrator can lose an afternoon to either.
		if ($status->jsRuntime === null) {
			$output->writeln('');
			$output->writeln('<comment>No JavaScript runtime was found, and imports will fail unpredictably.</comment>');
			$output->writeln('YouTube signs its audio links with JavaScript that yt-dlp has to run; without');
			$output->writeln('an engine it falls back to a deprecated route that YouTube refuses at random.');
			$output->writeln('Install one of: <info>' . implode(', ', JsRuntime::SUPPORTED) . '</info>');
			$output->writeln('Or point at an existing copy: <info>occ config:app:set music_radio '
				. YtDlpLocator::CONFIG_JS_RUNTIME_PATH . ' --value=/path/to/node</info>');
		}

		if ($status->outdated) {
			$output->writeln('');
			$output->writeln('<comment>This yt-dlp is more than 90 days old. YouTube changes often;'
				. ' imports will start failing if it is not updated.</comment>');
			$output->writeln('Update it with: <info>occ music_radio:ytdlp:install --force</info>');
		}

		return Command::SUCCESS;
	}

	private function adviceFor(?string $reason): string {
		return match ($reason) {
			ImportError::YTDLP_MISSING => 'Install it with: occ music_radio:ytdlp:install'
				. "\nOr point at an existing copy: occ config:app:set music_radio "
				. YtDlpLocator::CONFIG_YTDLP_PATH . ' --value=/path/to/yt-dlp',
			ImportError::FFMPEG_MISSING => 'Install ffmpeg (which also provides ffprobe) using this system\'s'
				. " package manager.\nBoth are required: YouTube does not serve MP3, so the audio has to be"
				. ' transcoded.',
			ImportError::PROCESS_DISABLED => 'proc_open is in this PHP\'s disable_functions.'
				. ' YouTube import cannot work without it.',
			ImportError::DISABLED => 'Switch it on with: occ config:app:set music_radio '
				. YtDlpLocator::CONFIG_ENABLED . ' --value=1 --type=boolean',
			default => '',
		};
	}
}
