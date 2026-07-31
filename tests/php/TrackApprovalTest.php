<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Track;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holding a track until the owner has looked at it.
 *
 * The rules live in two places that have to agree: whether a new track starts approved
 * (decided in TrackService::add, which needs a database and so is covered end to end), and
 * whether an unapproved track reaches the broadcast — which is decided here, by
 * `isPlayable`, and is the half that actually keeps it silent.
 */
class TrackApprovalTest extends TestCase {

	private static function track(
		bool $approved = true,
		bool $disabled = false,
		bool $unavailable = false,
		?int $durationMs = 3_000,
	): Track {
		$track = new Track();
		$track->setApproved($approved);
		$track->setDisabled($disabled);
		$track->setUnavailable($unavailable);
		$track->setDurationMs($durationMs);

		return $track;
	}

	public function testAnApprovedTrackPlays(): void {
		self::assertTrue(self::track()->isPlayable());
		self::assertFalse(self::track()->isAwaitingApproval());
	}

	public function testAnUnapprovedTrackIsKeptOffTheBroadcast(): void {
		$track = self::track(approved: false);

		self::assertFalse($track->isPlayable());
		self::assertTrue($track->isAwaitingApproval());
	}

	/**
	 * The reason the two are separate columns rather than one.
	 *
	 * "Waiting for an answer" and "the owner listened and said no" are different states,
	 * and collapsing them would mean approving something silently un-skipped it.
	 */
	public function testApprovingDoesNotUndoASkip(): void {
		$track = self::track(approved: true, disabled: true);

		self::assertFalse($track->isPlayable(), 'a skipped track stays skipped once approved');
		self::assertFalse($track->isAwaitingApproval(), 'and it is no longer waiting for anybody');
	}

	public function testSkippingDoesNotCountAsApproving(): void {
		$track = self::track(approved: false, disabled: true);

		self::assertTrue($track->isAwaitingApproval());
	}

	/**
	 * @return array<string, array{Track}>
	 */
	public static function unplayableProvider(): array {
		return [
			'waiting for approval' => [self::track(approved: false)],
			'skipped by the owner' => [self::track(disabled: true)],
			'its file has gone' => [self::track(unavailable: true)],
			'never measured' => [self::track(durationMs: null)],
			'measured as nothing' => [self::track(durationMs: 0)],
		];
	}

	#[DataProvider('unplayableProvider')]
	public function testEveryReasonKeepsATrackOffTheTimeline(Track $track): void {
		self::assertFalse($track->isPlayable());
	}

	/**
	 * Only one of those reasons is a decision somebody can make, and the UI keys off this
	 * to tell "act on me" apart from "something is wrong with this file".
	 */
	public function testOnlyApprovalIsReportedAsWaiting(): void {
		self::assertTrue(self::track(approved: false)->isAwaitingApproval());

		self::assertFalse(self::track(disabled: true)->isAwaitingApproval());
		self::assertFalse(self::track(unavailable: true)->isAwaitingApproval());
		self::assertFalse(self::track(durationMs: null)->isAwaitingApproval());
	}

	public function testTheSerialisedTrackTellsTheUiBothThings(): void {
		$json = self::track(approved: false)->jsonSerialize();

		self::assertFalse($json['approved']);
		self::assertTrue($json['awaitingApproval']);
		self::assertFalse($json['playable']);
	}
}
