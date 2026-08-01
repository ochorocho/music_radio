<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\JsRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsRuntimeTest extends TestCase {

	public function testTheSpecNamesBothTheEngineAndTheBinary(): void {
		self::assertSame(
			'node:/usr/local/bin/node',
			(new JsRuntime('node', '/usr/local/bin/node'))->spec(),
		);
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function pathProvider(): array {
		return [
			'node where a Nextcloud host usually keeps it' => ['/usr/local/bin/node', 'node'],
			'deno' => ['/usr/bin/deno', 'deno'],
			'quickjs' => ['/opt/bin/quickjs', 'quickjs'],
			'bun' => ['/usr/local/bin/bun', 'bun'],

			// Rejected rather than guessed at: yt-dlp exits on an engine name it does not
			// know, so inferring one would trade a failing download for a failing command
			// line — the same import broken slightly earlier and much more confusingly.
			'a versioned binary' => ['/usr/bin/node22', null],
			'a wrapper script' => ['/usr/local/bin/my-node', null],
			'something else entirely' => ['/usr/bin/python3', null],
			'a directory' => ['/usr/local/bin', null],
			'empty' => ['', null],
		];
	}

	#[DataProvider('pathProvider')]
	public function testFromPath(string $path, ?string $expectedName): void {
		$runtime = JsRuntime::fromPath($path);

		self::assertSame($expectedName, $runtime?->name);
		self::assertSame($expectedName === null ? null : $path, $runtime?->path);
	}

	/**
	 * yt-dlp picks the highest-priority runtime it was given. Following its order means a
	 * host with two installed behaves the same whether or not this app is choosing.
	 */
	public function testTheSupportedListIsInYtDlpsOrderOfPreference(): void {
		self::assertSame(['deno', 'node', 'quickjs', 'bun'], JsRuntime::SUPPORTED);
	}
}
