<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Command;

use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\YtDlpInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fetch yt-dlp for this server.
 *
 * A command rather than anything automatic: this downloads a program and marks it
 * executable, and an administrator running it is what makes that acceptable. It is also
 * how the copy gets *updated*, which for yt-dlp is a routine, recurring need — YouTube
 * breaks extractors every few weeks.
 */
class YtDlpInstall extends Command {

	public function __construct(
		private YtDlpInstaller $installer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('music_radio:ytdlp:install')
			->setDescription('Download yt-dlp so channels can import audio from YouTube')
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Replace an existing copy. This is also how you update it.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$installed = $this->installer->install((bool)$input->getOption('force'));
		} catch (MusicRadioException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln('<info>Installed yt-dlp ' . $installed['version'] . '</info>');
		$output->writeln('  asset: ' . $installed['asset']);
		$output->writeln('  path:  ' . $installed['path']);
		$output->writeln('');
		$output->writeln('Check the whole setup with: <info>occ music_radio:ytdlp:status</info>');

		return Command::SUCCESS;
	}
}
