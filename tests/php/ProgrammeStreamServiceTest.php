<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Service\BroadcastLibrary;
use OCA\MusicRadio\Service\ProgrammeStreamService;
use OCA\MusicRadio\Service\TrackService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ISession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Composing a stretch of programme out of prepared copies.
 *
 * Two of these cover bugs that reached a running server, and they are the reason the rest
 * exist: a plan that never terminated (a looping channel whose files had gone walked round
 * for ever and the request died in a gateway timeout), and spans cut mid-frame (every join
 * made the decoder resynchronise, which on iOS is a stall).
 */
class ProgrammeStreamServiceTest extends TestCase {

	/** Where the synthesised broadcast copies live for the duration of one test. */
	private string $dir;

	/** @var array<int, string> track id => path of its prepared copy */
	private array $prepared = [];

	/** Shared so a test can assert what the walk did about a track it could not read. */
	private TrackService&\PHPUnit\Framework\MockObject\MockObject $trackService;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/music_radio_programme_' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->trackService = $this->createMock(TrackService::class);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	// ------------------------------------------------------------------- fixtures

	/**
	 * A stand-in for a prepared copy: real frame headers, real length, no audio.
	 *
	 * Frames alternate between 417 and 418 bytes exactly as libmp3lame's do at 44.1 kHz and
	 * 128 kbit/s, because that alternation — the padding bit — is the thing the offset
	 * arithmetic cannot see and the frame scan has to cope with. Filler is 0x00 so the only
	 * 0xFF bytes in the file are genuine sync words.
	 */
	private function writePrepared(int $trackId, int $durationMs): string {
		$path = $this->dir . '/' . $trackId . '.mp3';
		$handle = fopen($path, 'wb');
		self::assertNotFalse($handle);

		$target = $durationMs * BroadcastLibrary::BYTES_PER_MS;
		$written = 0;
		$padded = false;

		while ($written < $target) {
			$size = $padded ? 418 : 417;
			$padded = !$padded;

			// FF FB 90 00: sync, MPEG-1, Layer III, 128 kbit/s, 44.1 kHz, stereo.
			fwrite($handle, "\xFF\xFB\x90\x00" . str_repeat("\x00", $size - 4));
			$written += $size;
		}

		fclose($handle);
		$this->prepared[$trackId] = $path;

		return $path;
	}

	private static function track(int $id, int $durationMs): Track {
		$track = new Track();
		$track->setId($id);
		$track->setFileId(1000 + $id);
		$track->setAddedBy('alice');
		$track->setDurationMs($durationMs);
		$track->setUnavailable(false);
		$track->setDisabled(false);
		$track->setApproved(true);

		return $track;
	}

	private static function channel(bool $loop = true): Channel {
		$channel = new Channel();
		$channel->setId(7);
		$channel->setLoopEnabled($loop);
		$channel->setShuffle(false);
		$channel->setAllowVoting(false);

		return $channel;
	}

	/**
	 * @param Track[] $tracks
	 * @param list<int> $missingFiles ids whose source file cannot be resolved
	 * @param list<int>|null $unbuilt ids with no prepared copy yet; null means all are ready
	 */
	private function service(
		array $tracks,
		Channel $channel,
		array $missingFiles = [],
		?array $unbuilt = null,
	): ProgrammeStreamService {
		$mapper = $this->createMock(TrackMapper::class);
		$mapper->method('findAllForChannelInPlayOrder')->with($channel)->willReturn($tracks);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			function (int $fileId) use ($missingFiles): array {
				if (in_array($fileId - 1000, $missingFiles, true)) {
					return [];
				}

				$file = $this->createMock(File::class);
				$file->method('isReadable')->willReturn(true);

				return [$file];
			}
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		$library = $this->createMock(BroadcastLibrary::class);
		$library->method('isBuilt')->willReturnCallback(
			static fn (Track $track): bool => $unbuilt === null || !in_array($track->getId(), $unbuilt, true)
		);
		$library->method('ensure')->willReturnCallback(
			fn (Track $track): string => $this->prepared[$track->getId()] ?? '/nonexistent'
		);

		return new ProgrammeStreamService(
			$mapper,
			$this->trackService,
			$library,
			$rootFolder,
			$this->createMock(ISession::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** Whether the four bytes at $offset are a frame header the decoder would accept. */
	private static function isFrameHeader(string $path, int $offset): bool {
		$handle = fopen($path, 'rb');
		self::assertNotFalse($handle);
		fseek($handle, $offset);
		$header = fread($handle, 4);
		fclose($handle);

		if ($header === false || strlen($header) < 4) {
			return false;
		}

		return ord($header[0]) === 0xFF
			&& (ord($header[1]) & 0xE0) === 0xE0
			&& (ord($header[1]) & 0x18) === 0x18
			&& (ord($header[1]) & 0x06) === 0x02;
	}

	// ------------------------------------------------------------------- from a track start

	public function testAPositionAtATrackStartTakesTheWholeFile(): void {
		$tracks = [self::track(1, 10_000)];
		$this->writePrepared(1, 10_000);

		$spans = $this->service($tracks, self::channel(false))->plan(self::channel(false), 0, 5_000);

		self::assertCount(1, $spans);
		self::assertSame(0, $spans[0]->start);
		self::assertSame(filesize($this->prepared[1]), $spans[0]->length);
	}

	// ------------------------------------------------------------------- mid-track

	public function testAMidTrackPositionStartsOnARealFrameHeader(): void {
		$tracks = [self::track(1, 60_000)];
		$this->writePrepared(1, 60_000);
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel)->plan($channel, 30_000, 5_000);

		self::assertCount(1, $spans);
		self::assertTrue(
			self::isFrameHeader($this->prepared[1], $spans[0]->start),
			'the span must begin on a frame header, not at the byte the arithmetic named',
		);
	}

	public function testAMidTrackStartLandsWithinOneFrameOfTheAskedPosition(): void {
		$tracks = [self::track(1, 60_000)];
		$this->writePrepared(1, 60_000);
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel)->plan($channel, 30_000, 5_000);

		// Snapping forward to the next header can cost at most one frame — 418 bytes.
		$asked = 30_000 * BroadcastLibrary::BYTES_PER_MS;
		self::assertGreaterThanOrEqual($asked, $spans[0]->start);
		self::assertLessThan($asked + 418, $spans[0]->start);
	}

	public function testAMidTrackSpanStillRunsToTheEndOfTheFile(): void {
		$tracks = [self::track(1, 60_000)];
		$path = $this->writePrepared(1, 60_000);
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel)->plan($channel, 30_000, 5_000);

		self::assertSame(filesize($path) - $spans[0]->start, $spans[0]->length);
	}

	// ------------------------------------------------------------------- crossing tracks

	public function testTheSpanCrossesIntoFollowingTracks(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel)->plan($channel, 5_000, 20_000);

		self::assertSame([1, 2, 3], array_map(static fn ($s): int => $s->trackId, $spans));
		// Only the first is entered part way; every later one starts at its beginning,
		// which is what makes the joins land on frame boundaries for free.
		self::assertGreaterThan(0, $spans[0]->start);
		self::assertSame(0, $spans[1]->start);
		self::assertSame(0, $spans[2]->start);
	}

	public function testATrackIsNeverCutShortToFitTheBudget(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(false);

		// A budget that runs out half way through the second track. Truncating there would
		// end the body mid-frame; overshooting is the deliberate choice.
		$spans = $this->service($tracks, $channel)->plan($channel, 0, 15_000);

		self::assertCount(2, $spans);
		foreach ($spans as $span) {
			self::assertSame(filesize($this->prepared[$span->trackId]), $span->length);
		}
	}

	// ------------------------------------------------------------------- looping

	public function testALoopingChannelWrapsBackToTheStart(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel)->plan($channel, 15_000, 12_000);

		// 15 s into a 20 s programme: the tail of track 2, then round to track 1.
		self::assertSame([2, 1], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	/**
	 * A programme too long to send whole still has to fill the budget, not stop at the end
	 * of the playlist.
	 *
	 * The first bound that terminated this walk counted tracks visited, which also capped
	 * the answer: it stopped after a couple of laps however much was asked for. Channels
	 * short enough to be sent whole are now a lap and never reach this walk at all — but a
	 * long one still wraps, and still must not stop early.
	 */
	public function testALongProgrammeFillsTheBudgetPastTheEndOfThePlaylist(): void {
		$tracks = [self::track(1, 120_000), self::track(2, 120_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 120_000);
		}
		$channel = self::channel(true);

		// Four minutes of programme, two and a half asked for — too long to send whole, so
		// this is the walk. Starting a minute from the end, it has to come round and go on.
		$spans = $this->service($tracks, $channel)->plan($channel, 180_000, 150_000);

		$got = array_sum(array_map(static fn ($s): int => $s->durationMs, $spans));
		self::assertGreaterThanOrEqual(150_000, $got);
		self::assertSame([2, 1], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	public function testAPositionPastTheEndIsTheSameAsTheWrappedOne(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		$wrapped = $this->service($tracks, $channel)->plan($channel, 100_005_000, 12_000);
		$direct = $this->service($tracks, $channel)->plan($channel, 5_000, 12_000);

		self::assertEquals($direct, $wrapped);
	}

	public function testANonLoopingChannelPastItsEndHasNothingToBroadcast(): void {
		$tracks = [self::track(1, 10_000)];
		$this->writePrepared(1, 10_000);
		$channel = self::channel(false);

		self::assertSame([], $this->service($tracks, $channel)->plan($channel, 10_000, 5_000));
	}

	// ------------------------------------------------------------------- looping whole

	/**
	 * A programme that fits in the budget is sent once, whole, rotated to where the
	 * listener is — for the element to loop by itself.
	 *
	 * Two wins at once: a sixteen-second channel stops being answered with a hundred
	 * repetitions to fill half an hour, and a body that repeats never runs out, so the
	 * buffer ceiling that stops a locked phone does not apply to these channels at all.
	 */
	public function testAShortProgrammeIsSentAsOneWholeLap(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel)->plan($channel, 15_000, 300_000);

		// Joined half way through track 2: the rest of 2, then 3, then 1, then the head of
		// 2 that the rotation left behind.
		self::assertSame([2, 3, 1, 2], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	/**
	 * And it closes exactly, which is the only reason it can be looped.
	 *
	 * The split is at a frame header, so the tail and the head of the rotated track are
	 * both whole numbers of frames and add back up to the file. A seam crossed on every
	 * lap is the last place a partial frame could be tolerated.
	 */
	public function testTheRotatedLapAddsUpToEveryFileExactlyOnce(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel)->plan($channel, 4_000, 300_000);

		$sent = array_sum(array_map(static fn ($s): int => $s->length, $spans));
		$whole = array_sum(array_map(static fn (string $p): int => (int)filesize($p), $this->prepared));
		self::assertSame($whole, $sent);

		// The two pieces of the rotated track meet at the byte the scan found.
		$head = end($spans);
		self::assertSame(0, $head->start);
		self::assertSame($spans[0]->start, $head->length);
	}

	public function testJoiningExactlyOnATrackStartNeedsNoClosingPiece(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel)->plan($channel, 10_000, 300_000);

		self::assertSame([2, 1], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	public function testAProgrammeTooLongToSendWholeIsStillAStretchOfIt(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(true);

		// A budget smaller than the programme, so no lap fits.
		$spans = $this->service($tracks, $channel)->plan($channel, 0, 15_000);

		self::assertSame([1, 2], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	/**
	 * A lap that cannot be completed is never sent as one.
	 *
	 * Looping a partial lap would repeat a fragment of the programme while presenting it as
	 * the programme — silently, and for as long as the tab stayed open. Falling back to an
	 * ordinary segment means the listener hears the right thing until the library is ready.
	 */
	public function testAnIncompleteLapFallsBackToAnOrdinarySegment(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000)];
		$this->writePrepared(1, 10_000);
		$this->writePrepared(2, 10_000);
		$this->writePrepared(3, 10_000);
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel, [3])->plan($channel, 0, 300_000);

		// Track 3 is unreadable, so no lap exists; what comes back is the plain walk, which
		// ends where it cannot go on.
		self::assertSame([1, 2], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	public function testANonLoopingChannelIsNeverSentAsALap(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel)->plan($channel, 15_000, 300_000);

		// From half way through the last track to the end of the programme, and no further.
		self::assertSame([2], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	// ------------------------------------------------------------------- degrading

	/**
	 * A track that cannot be read ends the segment; it is never stepped over.
	 *
	 * Skipping was the first answer and it is silently wrong. The timeline counts every
	 * playable track whether or not its bytes can be found, so a segment that leaves one
	 * out runs the audio ahead of the position everyone is synchronised to — by the whole
	 * length of the missing track — while every measurement the client can make still
	 * agrees with itself.
	 */
	public function testATrackWhoseFileHasGoneEndsTheSegment(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000)];
		$this->writePrepared(1, 10_000);
		$this->writePrepared(3, 10_000);
		$channel = self::channel(false);

		$spans = $this->service($tracks, $channel, [2])->plan($channel, 0, 30_000);

		self::assertSame([1], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	/**
	 * And it is flagged, so the next request gets past it.
	 *
	 * Without this the channel would stop at that track for ever. Flagged, the timeline
	 * stops counting it, every position after it shifts back by its length, and the stream
	 * and the clock agree again — the same repair the per-track path performs.
	 */
	public function testATrackWhoseFileHasGoneIsFlaggedSoTheChannelRepairsItself(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		$this->writePrepared(1, 10_000);
		$channel = self::channel(false);

		$this->trackService
			->expects(self::once())
			->method('markUnavailable')
			->with($channel, $tracks[1]);

		$this->service($tracks, $channel, [2])->plan($channel, 0, 30_000);
	}

	/**
	 * The gateway timeout, as a test.
	 *
	 * Every track missing means no span is ever produced, so nothing is ever subtracted
	 * from the budget. Bounding the walk by spans rather than ending it on a track that
	 * yields nothing let a looping channel run until PHP was killed.
	 */
	public function testALoopingChannelWithNoUsableFilesTerminates(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000)];
		$channel = self::channel(true);

		$spans = $this->service($tracks, $channel, [1, 2])->plan($channel, 0, 30_000);

		self::assertSame([], $spans);
	}

	// ------------------------------------------------------------------- preparing

	/**
	 * One request prepares what the listener is about to need, and stops there.
	 *
	 * The first listener on a channel nobody has broadcast yet would otherwise pay for the
	 * whole segment at once — thirty minutes of programme is eight or ten transcodes, each
	 * with a ten-minute ceiling of its own, inside one request against a pool of eight PHP
	 * workers. The short segment that comes back instead is asked to be extended a minute
	 * or two later, by which time more of the library exists.
	 */
	public function testARequestOnlyPreparesAFewTracksAndEndsTheSegmentThere(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000), self::track(4, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(false);

		// Nothing prepared yet, and a budget that would happily take all four.
		$spans = $this->service($tracks, $channel, [], [1, 2, 3, 4])->plan($channel, 0, 300_000);

		self::assertSame([1, 2], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	public function testTracksAlreadyPreparedDoNotCountAgainstThatBudget(): void {
		$tracks = [self::track(1, 10_000), self::track(2, 10_000), self::track(3, 10_000), self::track(4, 10_000)];
		foreach ($tracks as $track) {
			$this->writePrepared($track->getId(), 10_000);
		}
		$channel = self::channel(false);

		// Only the last one is missing, so the walk reaches it with its budget intact.
		$spans = $this->service($tracks, $channel, [], [4])->plan($channel, 0, 300_000);

		self::assertSame([1, 2, 3, 4], array_map(static fn ($s): int => $s->trackId, $spans));
	}

	public function testAnEmptyPlaylistHasNothingToBroadcast(): void {
		$channel = self::channel(true);

		self::assertSame([], $this->service([], $channel)->plan($channel, 0, 30_000));
	}
}
