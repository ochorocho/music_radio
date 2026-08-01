<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Command;

use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Service\BroadcastLibrary;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prepare tracks for broadcast ahead of time.
 *
 * The copies build themselves on demand, so this is never *required* — but the first
 * listener to a cold channel would otherwise wait for a transcode, and an administrator
 * filling a server with music would rather spend that time now than have somebody else
 * spend it later.
 */
class BroadcastBuild extends Command {

	public function __construct(
		private BroadcastLibrary $library,
		private TrackMapper $trackMapper,
		private ChannelMapper $channelMapper,
		private IRootFolder $rootFolder,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('music_radio:broadcast:build')
			->setDescription('Prepare a channel\'s tracks for continuous broadcast')
			->addArgument(
				'channel',
				InputArgument::OPTIONAL,
				'Channel id. Omit to prepare every channel.',
			)
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Rebuild copies that already exist.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$channelId = $input->getArgument('channel');
		$force = (bool)$input->getOption('force');

		try {
			$channels = $channelId === null
				? $this->channelMapper->findAll()
				: [$this->channelMapper->find((int)$channelId)];
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$built = 0;
		$skipped = 0;
		$failed = 0;
		// Counted apart from failures. A track whose file has been deleted or moved out of
		// reach is a state the app already knows how to be in — the broadcast simply skips
		// it, exactly as the per-track stream does — and reporting it as a build failure
		// would make a clean run of a library with any history in it exit non-zero for ever.
		$missing = 0;

		foreach ($channels as $channel) {
			foreach ($this->trackMapper->findAllForChannel($channel->getId()) as $track) {
				if ($track->getUnavailable()) {
					$skipped++;
					continue;
				}

				$source = $this->sourceOf($track);
				if ($source === null) {
					$output->writeln('  <comment>no file: ' . $track->getTitle() . '</comment>');
					$missing++;
					continue;
				}

				if (!$force && $this->library->isBuilt($track, $source)) {
					$skipped++;
					continue;
				}

				if ($force) {
					$this->library->forget($track->getId());
				}

				try {
					$this->library->ensure($track, $source);
					$output->writeln('  ' . $track->getTitle());
					$built++;
				} catch (MusicRadioException $e) {
					$output->writeln('  <error>' . $track->getTitle() . ': ' . $e->getMessage() . '</error>');
					$failed++;
				}
			}
		}

		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%d prepared</info>, %d already current, %d with no file, %d could not be prepared',
			$built,
			$skipped,
			$missing,
			$failed,
		));

		return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * The file behind a track, looked up in the folder of whoever added it — the same rule
	 * {@see \OCA\MusicRadio\Service\AudioStreamService} resolves by, so this cannot reach a
	 * file the broadcast could not.
	 */
	private function sourceOf(Track $track): ?File {
		try {
			$folder = $this->rootFolder->getUserFolder($track->getAddedBy());
			foreach ($folder->getById($track->getFileId()) as $node) {
				if ($node instanceof File && $node->isReadable()) {
					return $node;
				}
			}
		} catch (\Throwable) {
			// An owner whose account has gone, or storage that is unreachable. Reported by
			// the caller as "no file" either way.
		}

		return null;
	}
}
