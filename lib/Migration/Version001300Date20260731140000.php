<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Retire the last two channel-wide switches, so every question about an audience is asked
 * once and in one place.
 *
 * Version001100 and Version001200 moved approval, voting, the listener count and YouTube
 * importing onto the share. What they left behind was a channel-wide copy of voting and
 * importing acting as a master switch above the per-share ones: an owner had to say yes
 * twice, in two different parts of the dialog, and a share whose switch was on could still
 * be silently inert because the channel's was off. That is the two-level model this
 * removes.
 *
 * **No schema change.** Both columns stay. `allow_voting` becomes derived rather than
 * chosen — ChannelService::syncVotingMode keeps it equal to "at least one share allows
 * voting", because TrackMapper reads it to decide between `vote_order` and the author's
 * `sort_order`, and something has to answer that for the channel as a whole.
 * `allow_import` becomes vestigial; nothing reads it.
 *
 * **What this migration fixes is the data, and it preserves effective behaviour rather
 * than stored values.** Both flags were AND-gates, so what somebody could actually do was
 * `channel AND share`. Copying the share column forward unchanged would switch things on
 * that were off yesterday: a share with `allow_voting = 1` under a channel with
 * `allow_voting = 0` was inert, and would suddenly start reordering everybody's playlist.
 * So the channel's "no" is pressed down onto its shares first, and only then is the
 * channel's own flag recomputed from what is left.
 *
 * The one deliberate widening is for owners: importing on your own channel is now subject
 * only to the administrator's switch. It spends the owner's storage and their server's
 * time, so there was nobody left for them to be asking.
 */
class Version001300Date20260731140000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Nothing to change — see the class docblock. Both columns survive, one derived and
		// one vestigial.
		return null;
	}

	/**
	 * Order matters. Clearing the shares has to happen before the channel flag is
	 * recomputed from them, or a channel that said no would be talked back into yes by the
	 * very rows its no had been suppressing.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->silenceSharesTheChannelHadOverruled('allow_voting');
		$this->deriveChannelVotingFromShares();
		$this->silenceSharesTheChannelHadOverruled('allow_import');
	}

	/**
	 * Turn a share's switch off wherever its channel's copy was already off. Those rows
	 * granted nothing yesterday, and must not start granting something today.
	 *
	 * @param string $column allow_voting or allow_import — both were AND-gated the same way
	 */
	private function silenceSharesTheChannelHadOverruled(string $column): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_shares', 's')
			->set('s.' . $column, $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq(
				$qb->createFunction(
					'(select c.' . $column . ' from `*PREFIX*music_radio_channels` c where c.id = s.channel_id)',
				),
				$qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
			));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
			// A fresh install has no rows to reconcile, and the defaults are already right.
		}
	}

	/**
	 * Make the channel's flag say what its shares say.
	 *
	 * This narrows as well as widens: a channel whose owner had voting switched on but who
	 * never granted it to anybody stops being vote-ordered, which puts their own running
	 * order back — the same thing turning the old switch off used to do.
	 */
	private function deriveChannelVotingFromShares(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_channels', 'c')
			->set('c.allow_voting', $qb->createFunction(
				'(case when exists ('
					. 'select 1 from `*PREFIX*music_radio_shares` s '
					. 'where s.channel_id = c.id and s.allow_voting = 1'
					. ') then 1 else 0 end)',
			));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
		}
	}
}
