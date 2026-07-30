<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Music Radio

Turns your Nextcloud files into radio stations. Create a **channel**, fill it with audio,
and hit play — everyone tuned in hears the same track at the same moment, kept in step by a
server-authoritative timeline rather than a streaming server.

Sharing separates *listening* from *adding*, so people can queue music up without taking
over what is playing.

## Getting music onto a channel

Three ways, all gated on the same "add tracks" permission:

| | Where the file lives |
|---|---|
| **From your files** — the file picker | Stays in the adder's own storage |
| **From a public link** — a visitor uploads | The channel owner's music folder |
| **From YouTube** — paste a link | The channel owner's music folder |

The music folder is `Music` by default, and each person can change theirs under
**Settings → Personal → Music Radio**.

## YouTube import

Off unless the server can do it, which needs two things that Nextcloud does not ship.

### ffmpeg

Required, not optional. YouTube never serves MP3, so producing a 128 kbit/s MP3 is a
transcode. Install `ffmpeg` with the system's package manager — `ffprobe` comes with it and
is also needed.

### yt-dlp

The app does **not** bundle it, deliberately:

- `occ integrity:sign-app` hashes every shipped file, so a downloader that updates itself
  would break the instance's integrity check the first time it did its job;
- it is ~38 MB per architecture, and picking the wrong one produces a file that exists, is
  executable, and cannot run;
- YouTube changes often enough that a copy frozen at release time would be broken for most
  of its life.

So it is located instead, in this order: a path an administrator set explicitly → the copy
this app manages → whatever is on the system.

```bash
# Fetch a copy the app manages and can update. Detects the right build for this
# machine (preferring the small architecture-independent one when python3 is
# available) and checks it against the published checksums.
occ music_radio:ytdlp:install

# What the server can and cannot do, and why.
occ music_radio:ytdlp:status

# Update it. Expect to need this every few weeks — see below.
occ music_radio:ytdlp:install --force
```

Installing it through the system (`apt`, `pipx`, `nix`) works too and is found
automatically. Distribution packages are often a long way behind, which for this program
means broken.

### Imports run as background jobs

A download and transcode takes tens of seconds, so it happens outside the request. On a
server using system cron that means an import starts within the next cron run — up to five
minutes. For it to start promptly, run a worker:

```bash
occ background-job:worker 'OCA\MusicRadio\BackgroundJob\ImportYoutubeAudioJob' -i 1
```

A long-lived worker caches app configuration for its lifetime, so restart it after changing
a setting.

### Keeping it working

YouTube breaks extractors every few weeks. When that happens imports fail with *"YouTube
changed something the downloader on this server cannot handle yet"* — the fix is
`occ music_radio:ytdlp:install --force`.

The admin **Overview** page reports a missing or stale yt-dlp without anyone having to
check, and **Settings → Administration → Music Radio** has the switch, the path override
and the limits.

### What it does not do

- **No importing through a public link.** An anonymous visitor starting server-side
  downloads against the owner's quota and CPU is a different proposition from uploading a
  file they already have.
- **No working around restrictions.** A video that is private, members-only, age-gated or
  blocked in the server's region is reported as such, not circumvented.

Downloading from YouTube conflicts with its Terms of Service. Whether to allow it is the
decision of whoever runs the server, which is why nothing here works until an administrator
has deliberately made yt-dlp available.

## Development

The host generally lacks the right PHP and native binaries, so everything runs in the
container.

```bash
composer run cs:fix          # php-cs-fixer
composer run phpstan
composer run test:unit       # pure unit tests; no server needed
npm run build                # bundles into js/, which is committed
```

The unit suite has no Nextcloud behind it — `tests/bootstrap.php` maps `OCP\*` onto the
`nextcloud/ocp` stubs. Anything needing real storage is covered end to end instead.

End-to-end tests use Playwright. The import ones run against `tests/fixtures/fake-yt-dlp`
rather than the real thing, so that failure cases which cannot be produced on demand — a
private video, a broken extractor — are testable at all, and so no run depends on the
network.

`make appstore` builds a signed release tarball.
