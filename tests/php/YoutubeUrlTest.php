<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\YoutubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The import feature's security depends on this class and nothing else, so the rejections
 * below are not hypotheticals — each one is a way to reach a yt-dlp option, a host on the
 * server's network, or a few hundred downloads from one click.
 */
class YoutubeUrlTest extends TestCase {

	private const ID = 'dQw4w9WgXcQ';

	/**
	 * @return array<string, array{string}>
	 */
	public static function acceptedProvider(): array {
		return [
			'the canonical watch URL' => ['https://www.youtube.com/watch?v=' . self::ID],
			'without the www' => ['https://youtube.com/watch?v=' . self::ID],
			'the mobile host' => ['https://m.youtube.com/watch?v=' . self::ID],
			'YouTube Music' => ['https://music.youtube.com/watch?v=' . self::ID],
			'the no-cookie host' => ['https://www.youtube-nocookie.com/embed/' . self::ID],
			'a short link' => ['https://youtu.be/' . self::ID],
			'a Shorts link' => ['https://www.youtube.com/shorts/' . self::ID],
			'an embed link' => ['https://www.youtube.com/embed/' . self::ID],
			'a live link' => ['https://www.youtube.com/live/' . self::ID],
			'the old /v/ form' => ['https://www.youtube.com/v/' . self::ID],
			'http rather than https' => ['http://www.youtube.com/watch?v=' . self::ID],
			'an uppercase host' => ['https://WWW.YOUTUBE.COM/watch?v=' . self::ID],
			'surrounded by whitespace' => ['  https://youtu.be/' . self::ID . "\n"],
			'a bare id' => [self::ID],

			// The parameters real links are littered with, none of which survive.
			'a timestamp' => ['https://youtu.be/' . self::ID . '?t=42'],
			'a share tag' => ['https://youtu.be/' . self::ID . '?si=AbCdEfGhIjKlMnOp'],
			'the v parameter after others' => ['https://www.youtube.com/watch?feature=share&v=' . self::ID],
			'a playlist link — one video, not the playlist' => [
				'https://www.youtube.com/watch?v=' . self::ID . '&list=PLabcdefghijklmnop&index=3',
			],
			// The id is valid, so the link is valid; the extra parameter is simply not
			// carried over, which is the whole point of rebuilding the URL from the id.
			'an option hidden in an extra parameter' => [
				'https://www.youtube.com/watch?v=' . self::ID . '&x=--config-location=/tmp/evil',
			],
		];
	}

	#[DataProvider('acceptedProvider')]
	public function testAcceptedFormsYieldTheId(string $input): void {
		self::assertSame(self::ID, YoutubeUrl::videoId($input));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function rejectedProvider(): array {
		return [
			// Argument injection: every one of these would otherwise put an option on the
			// command line.
			'an option smuggled after the id' => [
				'https://www.youtube.com/watch?v=' . self::ID . ' --exec=rm -rf /',
			],
			'an option URL-encoded after the id' => [
				'https://www.youtube.com/watch?v=' . self::ID . '%20--exec=id',
			],
			'a bare option' => ['--version'],
			'an option that looks like a path' => ['--config-location=/etc/passwd'],
			'a newline before a second argument' => ['https://youtu.be/' . self::ID . "\n--exec=id"],

			// Host confusion.
			'a lookalike suffix' => ['https://youtube.com.evil.example/watch?v=' . self::ID],
			'a lookalike prefix' => ['https://notyoutube.com/watch?v=' . self::ID],
			'youtube as a subdomain of something else' => ['https://youtube.com.co/watch?v=' . self::ID],
			'credentials naming an allowed host' => ['https://www.youtube.com@evil.example/watch?v=' . self::ID],
			'credentials with a password' => ['https://user:pass@www.youtube.com/watch?v=' . self::ID],
			'a scheme-relative URL' => ['//www.youtube.com/watch?v=' . self::ID],
			'an unrelated host' => ['https://vimeo.com/watch?v=' . self::ID],

			// SSRF.
			'localhost' => ['https://127.0.0.1/watch?v=' . self::ID],
			'an internal host' => ['https://nextcloud.internal/watch?v=' . self::ID],
			'link-local metadata' => ['https://169.254.169.254/watch?v=' . self::ID],

			// Schemes that are not links.
			'a file URL' => ['file:///etc/passwd'],
			'a javascript URL' => ['javascript:alert(1)'],
			'an ftp URL' => ['ftp://www.youtube.com/watch?v=' . self::ID],

			// Playlists and channels, which must not become an import.
			'a playlist page' => ['https://www.youtube.com/playlist?list=PLabcdefghijklmnop'],
			'a channel page' => ['https://www.youtube.com/@someuser'],
			'a channel by id' => ['https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv'],
			'a search results page' => ['https://www.youtube.com/results?search_query=music'],
			'the bare host' => ['https://www.youtube.com/'],

			// Malformed ids.
			'an id one character short' => ['https://youtu.be/dQw4w9WgXc'],
			'an id one character long' => ['https://youtu.be/dQw4w9WgXcQQ'],
			'an id with an illegal character' => ['https://youtu.be/dQw4w9WgXc!'],
			'an id with a dot' => ['https://youtu.be/dQw4w9WgX.Q'],
			'a watch URL with no v parameter' => ['https://www.youtube.com/watch?t=42'],
			'a watch URL with an empty v parameter' => ['https://www.youtube.com/watch?v='],
			'a bare id one character short' => ['dQw4w9WgXc'],

			// Nothing at all.
			'empty' => [''],
			'whitespace' => ['   '],
			'not a URL' => ['some random text'],
			'a very long string' => ['https://www.youtube.com/watch?v=' . str_repeat('a', 4096)],
		];
	}

	#[DataProvider('rejectedProvider')]
	public function testRejectedFormsYieldNull(string $input): void {
		self::assertNull(YoutubeUrl::videoId($input));
	}

	public function testCanonicalIsBuiltFromTheIdAlone(): void {
		self::assertSame(
			'https://www.youtube.com/watch?v=' . self::ID,
			YoutubeUrl::canonical(self::ID),
		);
	}

	/**
	 * The property the whole design rests on: whatever went in, what comes out is eleven
	 * safe characters, so the URL built from it cannot carry anything else.
	 */
	#[DataProvider('acceptedProvider')]
	public function testAnAcceptedInputCannotCarryAnythingIntoTheCanonicalUrl(string $input): void {
		$id = YoutubeUrl::videoId($input);
		self::assertNotNull($id);

		$url = YoutubeUrl::canonical($id);

		self::assertMatchesRegularExpression('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$#', $url);
		self::assertStringNotContainsString(' ', $url);
		self::assertStringNotContainsString('&', $url);
		self::assertStringNotContainsString('--', $url);
	}
}
