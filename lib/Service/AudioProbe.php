<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Track;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Reads duration and tags out of an audio file.
 *
 * Duration is on the critical path: the whole broadcast timeline is built from it, and
 * an error of a few seconds becomes a permanent desync at the track boundary. Nextcloud
 * core cannot help here — it has a metadata *storage* layer (OCP\FilesMetadata) but ships
 * no audio parser and does not bundle getID3 — so the app carries its own.
 */
class AudioProbe {

	/**
	 * Anything shorter is almost certainly a decode artefact, anything longer than four
	 * hours is not a radio track. Used to sanity-check a browser-supplied duration.
	 */
	private const MIN_PLAUSIBLE_MS = 1000;
	private const MAX_PLAUSIBLE_MS = 14_400_000;

	/**
	 * How far a client's measurement may differ from the file's own headers before the
	 * client is disbelieved.
	 */
	private const HINT_TOLERANCE = 0.015;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param int|null $clientHintMs duration measured by the adding browser, if it offered one
	 * @return array{durationMs: int|null, source: int, title: string|null, artist: string|null, album: string|null}
	 */
	public function probe(File $file, ?int $clientHintMs = null): array {
		$result = [
			'durationMs' => null,
			'source' => Track::DURATION_SOURCE_UNKNOWN,
			'title' => null,
			'artist' => null,
			'album' => null,
		];

		$info = $this->analyze($file);

		if ($info !== null) {
			$result['title'] = $this->firstTag($info, 'title');
			$result['artist'] = $this->firstTag($info, 'artist');
			$result['album'] = $this->firstTag($info, 'album');

			if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
				$probed = (int)round((float)$info['playtime_seconds'] * 1000);
				if ($probed >= self::MIN_PLAUSIBLE_MS && $probed <= self::MAX_PLAUSIBLE_MS) {
					$result['durationMs'] = $probed;
					$result['source'] = Track::DURATION_SOURCE_PROBE;
				}
			}
		}

		$hint = $this->plausibleHint($clientHintMs);

		if ($result['durationMs'] === null) {
			// The probe could not read the file — a known failure mode on some external
			// and network mounts. Fall back to what the browser decoded, if anything.
			if ($hint !== null) {
				$result['durationMs'] = $hint;
				$result['source'] = Track::DURATION_SOURCE_CLIENT;
			}

			return $result;
		}

		if ($hint !== null) {
			// Both available. They should agree; a large disagreement means either a VBR
			// file whose header estimate is off, or a client sending nonsense. Trust the
			// file's own headers — it is the value that is not attacker-controlled — but
			// log it, because a systematic gap here shows up as boundary desync.
			$delta = abs($hint - $result['durationMs']);
			if ($delta > $result['durationMs'] * self::HINT_TOLERANCE) {
				$this->logger->info('Client duration hint disagrees with the probed duration; keeping the probed value', [
					'app' => 'music_radio',
					'fileId' => $file->getId(),
					'probedMs' => $result['durationMs'],
					'hintMs' => $hint,
				]);
			}
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function analyze(File $file): ?array {
		if (!class_exists(\getID3::class)) {
			// Dependencies live in composer/ rather than vendor/ precisely so Nextcloud
			// autoloads them; if this ever fires, that wiring has come undone.
			$this->logger->error('getID3 is not available; track durations cannot be probed', ['app' => 'music_radio']);

			return null;
		}

		$handle = null;
		try {
			$id3 = new \getID3();
			$id3->option_tag_lyrics3 = false;

			// Analyse straight off the Nextcloud stream rather than a local path: the file
			// may live on object storage or an external mount with no local path at all.
			$handle = $file->fopen('rb');
			if ($handle === false) {
				return null;
			}

			$info = $id3->analyze($file->getName(), $file->getSize(), $file->getName(), $handle);

			if (class_exists(\getid3_lib::class)) {
				\getid3_lib::CopyTagsToComments($info);
			}

			if (!empty($info['error'])) {
				$this->logger->info('getID3 could not analyse the file', [
					'app' => 'music_radio',
					'fileId' => $file->getId(),
					'errors' => $info['error'],
				]);

				// Partial results are still useful — playtime is often present even when
				// getID3 complains about something else — so fall through rather than bail.
			}

			return $info;
		} catch (\Throwable $e) {
			$this->logger->warning('Audio probe failed', [
				'app' => 'music_radio',
				'fileId' => $file->getId(),
				'exception' => $e,
			]);

			return null;
		} finally {
			// getID3 takes ownership of the handle and closes it itself on most paths,
			// so this is only a mop-up for the paths where it does not. Closing an
			// already-closed handle is a TypeError, hence the is_resource() guard.
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	private function plausibleHint(?int $clientHintMs): ?int {
		if ($clientHintMs === null) {
			return null;
		}
		if ($clientHintMs < self::MIN_PLAUSIBLE_MS || $clientHintMs > self::MAX_PLAUSIBLE_MS) {
			return null;
		}

		return $clientHintMs;
	}

	/**
	 * @param array<string, mixed> $info
	 */
	private function firstTag(array $info, string $key): ?string {
		$value = $info['comments_html'][$key][0] ?? $info['comments'][$key][0] ?? null;
		if (!is_string($value)) {
			return null;
		}

		$value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($value === '') {
			return null;
		}

		return mb_substr($value, 0, 255);
	}
}
