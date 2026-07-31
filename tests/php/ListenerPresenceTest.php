<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ListenerPresence;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Counting who is actually listening.
 *
 * The number is shown to people, so being wrong in a plausible-looking way is the failure
 * mode that matters: a stale entry that never expires, a browser counted twice because it
 * polls from two components, or — worst — a confident "1" on a server that cannot really
 * count at all.
 */
class ListenerPresenceTest extends TestCase {

	private const NOW = 1_700_000_000;
	private const TTL = 30;

	private int $now = self::NOW;

	/** @var array<string, mixed> */
	private array $store = [];

	private int $writes = 0;

	private function presence(bool $cacheAvailable = true): ListenerPresence {
		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturnCallback(fn (): int => $this->now);

		// A real in-memory cache rather than expectations on a mock: what is being tested
		// is what survives a round trip through storage, which a mock cannot show.
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->store[$key] = $value;
			$this->writes++;

			return true;
		});

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cacheAvailable);
		$factory->method('createDistributed')->willReturn($cache);

		return new ListenerPresence($factory, $clock);
	}

	// ------------------------------------------------------------- the basics

	public function testNobodyIsListeningToBeginWith(): void {
		self::assertSame(0, $this->presence()->count(7));
	}

	public function testAListenerIsCounted(): void {
		$presence = $this->presence();

		self::assertSame(1, $presence->record(7, 'aaaaaaaa', true));
		self::assertSame(1, $presence->count(7));
	}

	public function testTwoListenersAreCountedSeparately(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);

		self::assertSame(2, $presence->record(7, 'bbbbbbbb', true));
	}

	/**
	 * The reason a client id exists at all: `OnAir` and `GlobalPlayer` both poll the same
	 * channel from the same tab, so counting requests would report every listener twice.
	 */
	public function testOneBrowserPollingTwiceIsOnePerson(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);

		self::assertSame(1, $presence->record(7, 'aaaaaaaa', true));
	}

	public function testChannelsAreCountedIndependently(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$presence->record(8, 'bbbbbbbb', true);

		self::assertSame(1, $presence->count(7));
		self::assertSame(1, $presence->count(8));
	}

	// -------------------------------------------------- watching ≠ listening

	/**
	 * `OnAir` starts polling on mount whether or not anyone tuned in. Someone who has the
	 * page open is not an audience.
	 */
	public function testWatchingWithoutListeningIsNotCounted(): void {
		self::assertSame(0, $this->presence()->record(7, 'aaaaaaaa', false));
	}

	public function testTuningOutStopsCountingImmediately(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$presence->record(7, 'bbbbbbbb', true);

		// Not left to expire: somebody who pressed stop should stop being counted now.
		self::assertSame(1, $presence->record(7, 'aaaaaaaa', false));
	}

	// -------------------------------------------------------------- expiring

	public function testSomeoneWhoStoppedPollingDropsOut(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$presence->record(7, 'bbbbbbbb', true);

		// A closed tab says nothing; it just goes quiet.
		$this->now += self::TTL + 1;

		self::assertSame(0, $presence->count(7));
	}

	public function testStillPollingKeepsYouCounted(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);

		for ($i = 0; $i < 5; $i++) {
			$this->now += self::TTL - 5;
			$presence->record(7, 'aaaaaaaa', true);
		}

		self::assertSame(1, $presence->count(7));
	}

	/**
	 * One listener leaving must not take the others with them.
	 */
	public function testOnlyTheSilentOneIsPruned(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);

		$this->now += self::TTL - 5;
		$presence->record(7, 'bbbbbbbb', true);

		$this->now += 6;

		self::assertSame(1, $presence->record(7, 'bbbbbbbb', true));
	}

	// ------------------------------------------------------ not writing needlessly

	/**
	 * This runs on every poll from every open page, most of which are watching rather
	 * than listening and have nothing to add. An unconditional write would make each of
	 * those a write to the shared cache, on the busiest path in the app.
	 */
	public function testAPollThatChangesNothingDoesNotWrite(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$this->writes = 0;

		// Somebody with the page open who never tuned in.
		$presence->record(7, 'bbbbbbbb', false);
		$presence->record(7, null, false);
		$presence->count(7);

		self::assertSame(0, $this->writes);
	}

	public function testAChangeIsStillWritten(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$this->writes = 0;

		$presence->record(7, 'bbbbbbbb', true);

		self::assertSame(1, $this->writes);
		self::assertSame(2, $presence->count(7));
	}

	/**
	 * A listener re-announcing itself later carries a newer timestamp, which is what stops
	 * it being pruned — so that write must not be skipped as "no change".
	 */
	public function testARefreshIsWritten(): void {
		$presence = $this->presence();
		$presence->record(7, 'aaaaaaaa', true);
		$this->writes = 0;

		$this->now += 5;
		$presence->record(7, 'aaaaaaaa', true);

		self::assertSame(1, $this->writes);
	}

	// ---------------------------------------------------- untrusted client id

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedClientIdProvider(): array {
		return [
			'empty' => [''],
			'too short' => ['abc'],
			'too long' => [str_repeat('a', 65)],
			'uppercase' => ['AAAAAAAA'],
			'punctuation' => ['aaaaaaa!'],
			'a cache key separator' => ['aaaa/bbbb'],
			'a path traversal' => ['../../aaaa'],
		];
	}

	#[DataProvider('malformedClientIdProvider')]
	public function testAMalformedClientIdIsNotCounted(string $clientId): void {
		self::assertSame(0, $this->presence()->record(7, $clientId, true));
	}

	public function testAClientThatSendsNoIdIsNotCounted(): void {
		self::assertSame(0, $this->presence()->record(7, null, true));
	}

	/**
	 * A client inventing a fresh id on every request would otherwise grow the entry
	 * without bound. It is capped instead — and the cap must not evict people who are
	 * genuinely there.
	 */
	public function testInventingIdsCannotGrowTheEntryWithoutBound(): void {
		$presence = $this->presence();
		for ($i = 0; $i < 1_100; $i++) {
			$presence->record(7, 'client' . str_pad((string)$i, 6, '0', STR_PAD_LEFT), true);
		}

		self::assertSame(1_000, $presence->count(7));
	}

	public function testAlreadyCountedListenersKeepRefreshingAtTheCap(): void {
		$presence = $this->presence();
		for ($i = 0; $i < 1_000; $i++) {
			$presence->record(7, 'client' . str_pad((string)$i, 6, '0', STR_PAD_LEFT), true);
		}

		$this->now += self::TTL - 5;

		// Full, but this one is already in — so it renews rather than being turned away.
		$presence->record(7, 'client000000', true);
		$this->now += 6;

		self::assertSame(1, $presence->count(7));
	}

	// ---------------------------------------------------------- no cache, no number

	/**
	 * On a server with no distributed cache, createDistributed() still returns a working
	 * cache — a per-request one, which would report every listener as the only listener.
	 * Showing "1" to a room of thirty is worse than showing nothing.
	 */
	public function testWithoutADistributedCacheTheAnswerIsUnknownRatherThanWrong(): void {
		$presence = $this->presence(cacheAvailable: false);

		self::assertNull($presence->record(7, 'aaaaaaaa', true));
		self::assertNull($presence->count(7));
	}

	// ------------------------------------------------------- untrusted storage

	/**
	 * The cache is shared with everything else on the server, and its contents survive
	 * across deploys. Nothing that comes out of it is assumed to be the shape it went in.
	 */
	public function testRubbishInTheCacheDoesNotBecomeACount(): void {
		$presence = $this->presence();
		$this->store['channel-7'] = 'not an array at all';

		self::assertSame(0, $presence->count(7));
	}

	public function testEntriesOfTheWrongShapeAreIgnored(): void {
		$presence = $this->presence();
		$this->store['channel-7'] = [
			'aaaaaaaa' => self::NOW,
			'bbbbbbbb' => 'not a timestamp',
			'cccccccc' => null,
		];

		self::assertSame(1, $presence->count(7));
	}
}
