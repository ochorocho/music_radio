<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Http\ProgrammeSpan;
use OCA\MusicRadio\Http\ProgrammeStreamResponse;
use OCP\Files\File;
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * Works out which bytes make up the next stretch of a channel's programme.
 *
 * The reason this exists rather than the per-track stream being enough: with an iPhone
 * locked, the page's timers are suspended, so nothing of ours runs when a track ends. The
 * music only continues if the audio the browser is *already holding* runs on into the next
 * track. That means handing it not one track but a span of the programme — enough of it
 * that the phone can sit in a pocket for half an hour without anyone needing to be awake
 * to load anything.
 *
 * Everything about *where* the programme is at a given moment already exists in
 * {@see TimelineService} and is reused rather than re-derived: this class only turns a
 * position and a budget into a list of byte ranges.
 *
 * The bytes come from {@see BroadcastLibrary}, not from the owner's files, because a
 * concatenation only decodes cleanly if every part shares one encoding.
 */
class ProgrammeStreamService {

	/**
	 * How much programme a single request hands over.
	 *
	 * This is the number that decides how long a locked phone keeps playing, and it is a
	 * straight trade: thirty minutes is about 28 MB, fetched whether or not the listener
	 * stays for it. Longer means more uninterrupted playback and more data spent on
	 * somebody who wandered off after one song.
	 *
	 * It is also a ceiling that cannot be designed away here. Fetching the *next* span
	 * needs JavaScript, and JavaScript is exactly what a locked screen suspends — so
	 * playback lasts as long as the buffer and no longer. The alternative, a stream paced
	 * at playback speed that never ends, holds a PHP worker for the whole session against
	 * a pool of eight.
	 */
	public const BUDGET_MS = 30 * 60 * 1000;

	/**
	 * How many tracks one request may transcode before it stops reaching for more.
	 *
	 * A prepared copy is made on demand, and the first listener on a channel nobody has
	 * broadcast yet would otherwise pay for the whole segment at once — thirty minutes of
	 * programme is eight or ten tracks, each a transcode with a ten-minute ceiling of its
	 * own, all inside one request against a pool of eight PHP workers. That is a gateway
	 * timeout and a starved server, in exchange for a listener who is waiting anyway.
	 *
	 * So a request builds what the listener is about to need and no more; past that it
	 * uses only copies that already exist and ends the segment where they run out. The
	 * short segment is asked to be extended a minute or two later, by which time this has
	 * run again and the library is further ahead. It fills in from the front, always
	 * outrunning playback, and never in one go.
	 *
	 * Channels can also be prepared deliberately, which is what
	 * `occ music_radio:broadcast:build` is for.
	 */
	private const BUILD_BUDGET = 2;

	public function __construct(
		private TrackMapper $trackMapper,
		private TrackService $trackService,
		// TimelineService is used only through its static helpers — locate(), durations(),
		// wrap() — so there is nothing to inject.
		private BroadcastLibrary $library,
		private TrackFiles $files,
		private ISession $session,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The programme from `$fromMs`, ready to send.
	 *
	 * Built here rather than in the controllers so both of them — the signed-in one and
	 * the link one — hand back exactly the same thing, the same way
	 * {@see AudioStreamService} exists for the per-track stream. It also keeps the session
	 * and the logger out of the controllers, which is what PublicShareController's private
	 * session would otherwise force.
	 *
	 * @return ProgrammeStreamResponse|null null when there is nothing to broadcast
	 */
	public function stream(Channel $channel, int $fromMs): ?ProgrammeStreamResponse {
		$spans = $this->plan($channel, $fromMs);
		if ($spans === []) {
			return null;
		}

		return new ProgrammeStreamResponse($spans, $this->session, $this->logger);
	}

	/**
	 * The programme from `$fromMs`, as a list of byte ranges to send back to back.
	 *
	 * Returns an empty list when there is nothing to play — an empty playlist, or a
	 * non-looping channel that has run past its end. Callers answer that with a 404 rather
	 * than an empty body, so a client can tell "nothing here" from "here is silence".
	 *
	 * @return list<ProgrammeSpan>
	 */
	public function plan(Channel $channel, int $fromMs, int $budgetMs = self::BUDGET_MS): array {
		$playable = TimelineService::playable($this->trackMapper->findAllForChannelInPlayOrder($channel));
		$durations = TimelineService::durations($playable);
		$total = TimelineService::total($durations);

		if ($playable === [] || $total <= 0) {
			return [];
		}

		$position = max(0, $fromMs);
		if ($channel->getLoopEnabled()) {
			$position = TimelineService::wrap($position, $total);
		} elseif ($position >= $total) {
			// Run out, and not coming back round.
			return [];
		}

		$located = TimelineService::locate($durations, $position);
		if ($located === null) {
			return [];
		}

		$spans = [];
		$index = $located['index'];
		$offsetMs = $located['offsetMs'];
		$remaining = $budgetMs;
		$buildsLeft = self::BUILD_BUDGET;

		// A programme short enough to fit in the budget is not sent as a stretch of itself
		// — it is sent once, whole, and looped by the element.
		//
		// Strictly better on both counts that matter. It is fewer bytes: a sixteen-second
		// channel was being answered with a hundred and thirteen copies of the same three
		// songs to fill half an hour. And it never runs out, so the ceiling this whole
		// design otherwise accepts — a locked phone falling silent when its buffer ends —
		// simply does not apply to these channels. `loop` costs no JavaScript.
		if ($channel->getLoopEnabled() && $total <= $budgetMs) {
			$lap = $this->lap($channel, $playable, $index, $offsetMs, $buildsLeft);
			if ($lap !== []) {
				return $lap;
			}
			// Not yet — something in the playlist is missing or still being prepared. The
			// ordinary walk below sends what there is, and the next request tries again
			// with more of the library built.
		}

		// A segment is a contiguous stretch of the programme, so anything this loop cannot
		// produce ends it — it never steps over a track and carries on.
		//
		// Skipping looked harmless and is not. The timeline counts every playable track
		// whether or not its bytes can be found, so a segment that leaves one out puts the
		// audio ahead of the position everyone is synchronised to, by the whole length of
		// the missing track, and nothing detects it: the client measures its position from
		// where the segment was *asked* to start, which is still perfectly consistent with
		// itself while playing the wrong music.
		//
		// Stopping is also what makes the walk terminate. The first attempt bounded it by
		// spans produced, which a track yielding nothing did not advance, so a looping
		// channel with missing files walked round for ever and the request died in a
		// gateway timeout.
		$playableCount = count($playable);

		// Walking forward one track at a time rather than computing the whole thing at
		// once: each step can decide it is the last. Nothing else bounds this loop and
		// nothing else needs to — every iteration either ends it or takes real time off the
		// budget, so it cannot run for ever whatever the playlist is doing.
		while ($remaining > 0) {
			$track = $playable[$index] ?? null;
			if ($track === null) {
				break;
			}

			$span = $this->spanFor($channel, $track, $offsetMs, $buildsLeft);
			// A span of no measurable length would leave the budget untouched, which is the
			// one other way this loop could fail to end.
			if ($span === null || $span->durationMs <= 0) {
				break;
			}

			$spans[] = $span;
			$remaining -= $span->durationMs;

			$offsetMs = 0;
			$index++;

			if ($index >= $playableCount) {
				if (!$channel->getLoopEnabled()) {
					break;
				}
				$index = 0;
			}
		}

		return $spans;
	}

	/**
	 * The whole programme exactly once, rotated to begin where the listener is.
	 *
	 * The rotation closes *exactly*, and that is the only reason this is possible at all.
	 * The first span starts at a byte found by scanning for a real frame header, so the
	 * bytes before it are themselves a whole number of frames — put that head on the end
	 * and the body is every prepared copy, split once, at a frame boundary. It joins back
	 * to its own beginning as cleanly as it joins anywhere else, which is what lets the
	 * element loop it instead of asking for more.
	 *
	 * Truncating anywhere else would not do: a partial frame at the seam is what makes a
	 * decoder resynchronise, and a seam that is crossed on every lap would do it for ever.
	 *
	 * Empty when the lap cannot be completed — a missing file, or a copy not prepared yet.
	 * A partial lap must never be looped; it would repeat a fragment of the programme and
	 * call it the programme. The caller falls back to an ordinary segment, and the client
	 * has its own check besides.
	 *
	 * @param list<Track> $playable
	 * @return list<ProgrammeSpan>
	 */
	private function lap(Channel $channel, array $playable, int $index, int $offsetMs, int &$buildsLeft): array {
		$count = count($playable);

		$first = $this->spanFor($channel, $playable[$index], $offsetMs, $buildsLeft);
		if ($first === null || $first->durationMs <= 0) {
			return [];
		}

		$spans = [$first];

		for ($i = 1; $i < $count; $i++) {
			$track = $playable[($index + $i) % $count];
			$span = $this->spanFor($channel, $track, 0, $buildsLeft);
			if ($span === null || $span->durationMs <= 0) {
				return [];
			}
			$spans[] = $span;
		}

		// The head of the track the listener joined part way through, which is the piece
		// the rotation left behind.
		if ($first->start > 0) {
			$spans[] = new ProgrammeSpan(
				$first->trackId,
				$first->path,
				0,
				$first->start,
				(int)($first->start / BroadcastLibrary::BYTES_PER_MS),
			);
		}

		return $spans;
	}

	/**
	 * One track's contribution, from `$offsetMs` into it.
	 *
	 * A span always runs to the *end* of its track. Truncating one to fit the budget would
	 * cut mid-frame, and a partial frame at a join is what makes a decoder complain — the
	 * first attempt did exactly that and ffmpeg reported "Header missing" at every seam.
	 * Whole files concatenate cleanly because a file necessarily begins and ends on frame
	 * boundaries. Overshooting the budget by part of a track costs a little bandwidth; the
	 * alternative costs audible glitches on every track change, which is the whole thing
	 * this was built to avoid.
	 *
	 * Null when the track cannot be broadcast — its file is gone, or it will not
	 * transcode. Skipping it is the same thing the timeline does with an unplayable track,
	 * and far better than failing the whole request over one bad row.
	 */
	private function spanFor(Channel $channel, Track $track, int $offsetMs, int &$buildsLeft): ?ProgrammeSpan {
		$source = $this->sourceOf($channel, $track);
		if ($source === null) {
			// Its file has gone. Flagging it is what lets the *next* request past this
			// point: the timeline stops counting it, so positions after it shift back by
			// its length and the stream and the clock agree again. Exactly what the
			// per-track path does, and the reason a channel repairs itself rather than
			// stopping here for ever.
			try {
				$this->trackService->markUnavailable($channel, $track);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not flag an unreadable track', [
					'app' => 'music_radio',
					'trackId' => $track->getId(),
					'exception' => $e,
				]);
			}

			return null;
		}

		// Not prepared, and this request has done its share of preparing. Ending the
		// segment here is the whole point of the budget — see BUILD_BUDGET.
		if (!$this->library->isBuilt($track, $source)) {
			if ($buildsLeft <= 0) {
				return null;
			}
			$buildsLeft--;
		}

		try {
			$path = $this->library->ensure($track, $source);
		} catch (\Throwable $e) {
			$this->logger->warning('Skipping a track that could not be prepared for broadcast', [
				'app' => 'music_radio',
				'trackId' => $track->getId(),
				'exception' => $e,
			]);

			return null;
		}

		$size = @filesize($path);
		if ($size === false || $size <= 0) {
			return null;
		}

		// The copy is constant bitrate, which is what makes this a multiplication rather
		// than a parse: at 128 kbit/s a millisecond is exactly 16 bytes.
		$estimate = (int)min($size, max(0, $offsetMs) * BroadcastLibrary::BYTES_PER_MS);
		$start = $estimate === 0 ? 0 : $this->findFrameStart($path, $estimate);

		$length = $size - $start;
		if ($length <= 0) {
			return null;
		}

		return new ProgrammeSpan(
			$track->getId(),
			$path,
			$start,
			$length,
			(int)($length / BroadcastLibrary::BYTES_PER_MS),
		);
	}

	/**
	 * The first real frame header at or after `$estimate`.
	 *
	 * Arithmetic alone cannot land on one. At 44.1 kHz a Layer III frame is 1152 samples —
	 * 26.122 ms — which at 128 kbit/s is 417.96 bytes, so frames alternate between 417 and
	 * 418 as the padding bit turns on and off. There is no fixed stride to divide by, which
	 * is why the first version of this snapped to a multiple of 418 and handed the decoder
	 * a partial frame.
	 *
	 * So the estimate locates the neighbourhood and this finds the frame: scan forward for
	 * the eleven sync bits, and check the two bytes after them describe MPEG-1 Layer III
	 * rather than being 0xFF from the audio itself. A window is enough — the answer is
	 * always within one frame of the estimate.
	 */
	private function findFrameStart(string $path, int $estimate): int {
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return 0;
		}

		try {
			if (fseek($handle, $estimate) !== 0) {
				return 0;
			}

			// Two frames' worth: the boundary cannot be further away than that.
			$window = fread($handle, 1024);
			if ($window === false || strlen($window) < 4) {
				return 0;
			}

			$length = strlen($window) - 3;
			for ($i = 0; $i < $length; $i++) {
				if (ord($window[$i]) !== 0xFF) {
					continue;
				}

				$second = ord($window[$i + 1]);
				// 111 sync, 11 = MPEG-1, 01 = Layer III. Anything else at this position is
				// audio data that happens to start with 0xFF.
				if (($second & 0xE0) !== 0xE0 || ($second & 0x18) !== 0x18 || ($second & 0x06) !== 0x02) {
					continue;
				}

				// Neither the bitrate nor the sample-rate index may be the reserved value.
				$third = ord($window[$i + 2]);
				if (($third & 0xF0) === 0xF0 || ($third & 0x0C) === 0x0C) {
					continue;
				}

				return $estimate + $i;
			}
		} finally {
			fclose($handle);
		}

		// Nothing recognisable nearby. Starting the track from the beginning is wrong by up
		// to one track, and starting it mid-frame is wrong in a way that can stop playback.
		return 0;
	}

	/**
	 * The file behind a track — {@see TrackFiles}, the same resolution
	 * {@see AudioStreamService} uses, so this can reach nothing the per-track stream could
	 * not.
	 */
	private function sourceOf(Channel $channel, Track $track): ?File {
		if ($track->getUnavailable()) {
			return null;
		}

		return $this->files->resolve($channel, $track);
	}
}
