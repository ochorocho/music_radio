<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * How many people are actually listening to a channel.
 *
 * Two things make this less obvious than counting requests.
 *
 * **A poll does not mean somebody is listening.** `OnAir` starts watching the moment the
 * page mounts, whether or not anyone tuned in, and once they do `GlobalPlayer` polls as
 * well — so one browser that *is* listening produces two pollers, and one that is not
 * still produces one. Counting requests would therefore double some people and invent
 * others. The client has to say so explicitly, which is why this takes a client id and a
 * flag rather than inferring anything from traffic.
 *
 * **This does not belong in the database.** Every listener writes on every poll, a few
 * seconds apart, forever; that is exactly the contention `PlaybackService::buildState`'s
 * docblock is written to avoid. Presence is also worthless the moment it is stale, which
 * makes an expiring cache the right shape for it rather than a compromise.
 *
 * The whole channel lives under one key as `clientId => lastSeen`, because {@see ICache}
 * cannot enumerate keys — there is no portable way to count "all clients on channel 7" if
 * each has its own entry. Read-modify-write on a shared key means two simultaneous polls
 * can lose one of the two updates. That is accepted rather than locked around: every
 * client re-announces itself a few seconds later, so a lost write costs at most one
 * listener for one poll interval, and paying for a lock on the hottest path in the app to
 * avoid it would be a poor trade.
 */
class ListenerPresence {

	private const CACHE_PREFIX = 'music_radio_presence';

	/**
	 * How long a client is believed without saying anything.
	 *
	 * The slowest poll is 10 s idle, and the client jitters it by up to 1.15×, so a
	 * healthy listener is heard from at least every ~12 s. Thirty seconds leaves room for
	 * a missed request without dropping somebody who is still there.
	 */
	private const TTL_SECONDS = 30;

	/**
	 * A cap on what one channel can hold, so a hostile client cannot grow the entry
	 * without bound by inventing a new id on every request. Past this, the count is
	 * simply reported as the cap — a channel with a thousand listeners does not need the
	 * thousand-and-first to be exact.
	 */
	private const MAX_CLIENTS = 1_000;

	private ?ICache $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private Clock $clock,
	) {
	}

	/**
	 * Note that this client is still there, and say how many others are.
	 *
	 * @param string|null $clientId a per-tab id the browser made up; null from anything
	 *                              that did not send one, which is then only counted as a
	 *                              reader
	 * @param bool $listening whether this client is playing audio, as opposed to
	 *                        merely watching the channel
	 * @return int|null the number of listeners, or null when the server cannot know
	 */
	public function record(int $channelId, ?string $clientId, bool $listening): ?int {
		$cache = $this->cache();
		if ($cache === null) {
			return null;
		}

		$stored = $this->read($cache, $channelId);
		$clients = $this->prune($stored);

		if ($clientId !== null && self::isWellFormed($clientId)) {
			if ($listening) {
				// Refreshing an id already present must not be turned away by the cap,
				// or a full channel would slowly evict its own listeners.
				if (isset($clients[$clientId]) || count($clients) < self::MAX_CLIENTS) {
					$clients[$clientId] = $this->clock->nowSeconds();
				}
			} else {
				// Tuning out is immediate rather than left to expire: someone who pressed
				// stop should stop being counted now, not in half a minute.
				unset($clients[$clientId]);
			}
		}

		// Only when it actually changed. This runs on every poll from every open page,
		// including the many that are watching rather than listening and have nothing to
		// contribute — and an unconditional write would make each of those a write to the
		// shared cache. Entries carry their own timestamps and are pruned by them, so
		// letting an untouched key expire on its own loses nothing.
		if ($clients !== $stored) {
			$cache->set($this->key($channelId), $clients, self::TTL_SECONDS);
		}

		return count($clients);
	}

	/**
	 * The count without announcing anybody — for callers that are not a listener's poll.
	 *
	 * @return int|null null when the server cannot know
	 */
	public function count(int $channelId): ?int {
		$cache = $this->cache();
		if ($cache === null) {
			return null;
		}

		return count($this->prune($this->read($cache, $channelId)));
	}

	/**
	 * A distributed cache, or nothing.
	 *
	 * On a server without one, {@see ICacheFactory::createDistributed()} still hands back
	 * a working cache — but a per-request one, which would report every listener as the
	 * only listener. Showing "1" to a room of thirty people is worse than showing
	 * nothing, so this reports that it cannot know and the UI omits the count entirely.
	 */
	private function cache(): ?ICache {
		if (!$this->cacheFactory->isAvailable()) {
			return null;
		}

		return $this->cache ??= $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
	}

	/**
	 * @return array<string, int>
	 */
	private function read(ICache $cache, int $channelId): array {
		$stored = $cache->get($this->key($channelId));
		if (!is_array($stored)) {
			return [];
		}

		$clients = [];
		foreach ($stored as $clientId => $lastSeen) {
			// The cache is shared with whatever else runs on this server, and a decode of
			// something unexpected must not propagate into a count.
			if (is_string($clientId) && is_int($lastSeen)) {
				$clients[$clientId] = $lastSeen;
			}
		}

		return $clients;
	}

	/**
	 * @param array<string, int> $clients
	 * @return array<string, int>
	 */
	private function prune(array $clients): array {
		$cutoff = $this->clock->nowSeconds() - self::TTL_SECONDS;

		return array_filter($clients, static fn (int $lastSeen): bool => $lastSeen > $cutoff);
	}

	/**
	 * The id is made up by the browser and arrives as a query parameter, so it is checked
	 * before being used as a cache key fragment rather than trusted.
	 */
	private static function isWellFormed(string $clientId): bool {
		return preg_match('/^[a-z0-9]{8,64}$/', $clientId) === 1;
	}

	private function key(int $channelId): string {
		return 'channel-' . $channelId;
	}
}
