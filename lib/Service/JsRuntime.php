<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

/**
 * A JavaScript engine yt-dlp can borrow, and where it lives.
 *
 * YouTube signs its media URLs with a function it ships as obfuscated JavaScript, and that
 * function changes constantly. yt-dlp does not reimplement it — it runs it, which means it
 * needs an engine to run it in. Without one it falls back to a client whose URLs need no
 * signature, a route it now warns is deprecated, and YouTube answers those with
 * `403 Forbidden` when it feels like it — *after* the metadata pass has already succeeded.
 *
 * Which is what makes this worth wiring up rather than leaving to chance. A runtime-less
 * server is not broken; it is intermittently broken, in a way that reads as a stale binary
 * or a flaky network, on a fallback that will not be there indefinitely.
 */
final class JsRuntime {

	/**
	 * The engines yt-dlp knows how to drive, in its own order of preference.
	 *
	 * Kept in that order so that a host with two installed gets the one yt-dlp itself would
	 * have picked. Only `deno` is enabled by default, which is why a server with node — most
	 * of them, since Nextcloud's own build tooling wants it — appears to have no runtime.
	 */
	public const SUPPORTED = ['deno', 'node', 'quickjs', 'bun'];

	public function __construct(
		public readonly string $name,
		public readonly string $path,
	) {
	}

	/**
	 * What `--js-runtimes` wants: which engine, and the binary to use for it.
	 *
	 * The path is always included. yt-dlp would find a runtime on PATH by itself, but the
	 * import child is given a minimal environment of our own making, so a lookup that
	 * happens to work on one host would silently not on the next — the same reasoning that
	 * puts `--ffmpeg-location` on the command line.
	 */
	public function spec(): string {
		return $this->name . ':' . $this->path;
	}

	/**
	 * Read the engine's name off the binary an administrator pointed at.
	 *
	 * The match on the file name is exact. Guessing — treating `node22` or `my-deno` as a
	 * runtime — would mean passing a name yt-dlp rejects, and a rejected option aborts the
	 * run before it starts. Somebody with a versioned binary can symlink it, which is a
	 * clearer statement of intent than anything this could infer.
	 */
	public static function fromPath(string $path): ?self {
		$name = basename($path);

		return in_array($name, self::SUPPORTED, true) ? new self($name, $path) : null;
	}
}
