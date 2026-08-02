<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Command;

use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\RemoteImportSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether the machine that fetches imports is talking to this one.
 *
 * The counterpart to `music_radio:ytdlp:status`, for a server that does not do the
 * fetching itself. Everything here is a question an administrator cannot answer any other
 * way: the worker is on a machine they may not be sitting at, and the only other way to
 * find out whether it is connected is to try an import and wait.
 *
 * It also prints the exact command that mints a credential, because that is the step
 * people get stuck on — an app password, not the account's own.
 */
class RemoteStatus extends Command {

	public function __construct(
		private RemoteImportSettings $settings,
		private ImportMapper $importMapper,
		private Clock $clock,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('music_radio:remote:status')
			->setDescription('Show whether a remote import worker is collecting jobs');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$accounts = $this->settings->workerAccounts();
		$seenAt = $this->settings->seenAt();
		$depth = $this->importMapper->remoteQueueDepth();

		$output->writeln('mode:     ' . $this->settings->mode());
		$output->writeln('accounts: ' . ($accounts === []
			? '<comment>none — nothing may collect imports</comment>'
			: implode(', ', $accounts)));
		$output->writeln('worker:   ' . ($seenAt === 0
			? '<comment>never seen</comment>'
			: sprintf(
				'%s, %d seconds ago%s',
				$this->settings->seenName() ?: 'unnamed',
				$this->clock->nowSeconds() - $seenAt,
				$this->settings->isOnline() ? '' : ' <comment>(considered offline)</comment>',
			)));
		$output->writeln('js:       ' . ($this->settings->seenJsRuntime() ?? '<comment>none reported</comment>'));
		$output->writeln('cookies:  ' . ($this->settings->forwardsCookies() ? 'forwarded to workers' : 'not forwarded'));
		$output->writeln('queue:    ' . $depth['queued'] . ' waiting, ' . $depth['running'] . ' out with a worker');
		$output->writeln('');

		if (!$this->settings->isRemote()) {
			$output->writeln('<comment>This server fetches imports itself; nothing will be handed out.</comment>');
			$output->writeln('Hand them to another machine with: <info>occ config:app:set music_radio '
				. RemoteImportSettings::CONFIG_MODE . ' --value=remote</info>');

			return Command::SUCCESS;
		}

		if ($accounts === []) {
			$output->writeln('<error>No account may collect imports, so none ever will.</error>');
			$output->writeln('Name one — a dedicated account, not an administrator\'s:');
			$output->writeln('  <info>occ user:add radio-worker</info>');
			$output->writeln('  <info>occ config:app:set music_radio '
				. RemoteImportSettings::CONFIG_WORKERS . ' --value=radio-worker</info>');
			$output->writeln('  <info>occ user:add-app-password radio-worker</info>   (the worker signs in with this)');

			return Command::FAILURE;
		}

		if (!$this->settings->isOnline()) {
			$output->writeln('<error>No worker has checked in recently. Nothing is fetching audio.</error>');
			$output->writeln('Start it on the machine that does the fetching, and check its output:');
			$output->writeln('  <info>music-radio-worker --check</info>');

			return Command::FAILURE;
		}

		$output->writeln('<info>A worker is collecting imports.</info>');

		return Command::SUCCESS;
	}
}
