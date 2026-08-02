<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Installing Music Radio

The app itself installs like any other and needs nothing beyond Nextcloud. **Importing from
YouTube is the part with requirements**, and it is off until they are met — the rest of the
app works regardless, so you can install now and set importing up later, or never.

- [Requirements](#requirements)
- [1. Install the app](#1-install-the-app)
- [2. Install ffmpeg](#2-install-ffmpeg)
- [3. Turn importing on](#3-turn-importing-on)
- [4. Install yt-dlp](#4-install-yt-dlp)
- [5. Make background jobs run promptly](#5-make-background-jobs-run-promptly)
- [6. Check it](#6-check-it)
- [Keeping yt-dlp working](#keeping-yt-dlp-working)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)

## Requirements

|                   | For the app | For YouTube import                     |
|-------------------|-------------|----------------------------------------|
| Nextcloud         | 33–34       | —                                      |
| PHP               | 8.1+        | `proc_open` not in `disable_functions` |
| Binaries          | none        | `ffmpeg`, `ffprobe`, `yt-dlp`          |
| Background jobs   | not needed  | required                               |
| Distributed cache | optional    | —                                      |

Importing is unavailable — visibly, with a reason — if any of those is missing. It does not
half-work.

None of the "For YouTube import" column applies if you have the fetching done on **another
machine** — no ffmpeg, no yt-dlp and no `proc_open` are needed on the server, and background
jobs are needed only for tidying up. That is often the better arrangement, because YouTube
routinely refuses servers in data centres whatever they have installed. See
**[remote-import.md](remote-import.md)**, and skip sections 2, 4 and 5 below.

The distributed cache (Redis or Memcached, configured as `memcache.distributed`) is only
used for the listener count, which is the one thing that degrades rather than fails without
it: presence is held in the cache because every listener would otherwise write to the
database every few seconds. With no distributed cache the app reports that it cannot count
and shows nothing, rather than showing every listener a confident "1" — a file-backed cache
is per-request, so each listener would only ever see themselves.

> **A note on what this feature is.** Downloading from YouTube conflicts with its Terms of
> Service. Nothing here functions until an administrator has deliberately installed yt-dlp,
> because whether to allow it is the server operator's decision, not the app's.

## 1. Install the app

From the App Store, or by unpacking a release into `custom_apps/` (or `apps/`), then:

```bash
occ app:enable music_radio
```

That is the whole app install. The database tables are created by the migration `occ`
runs on enable — if you deployed by unpacking files over an existing install, run
`occ upgrade` as well.

Everything below is only about importing.

## 2. Install ffmpeg

Required, not optional, and this catches people out: **YouTube never serves MP3**. Producing
a 128 kbit/s MP3 is a transcode, and yt-dlp performs it by calling ffmpeg. `ffprobe` comes
in the same package and is also needed.

```bash
# Debian / Ubuntu
apt install ffmpeg

# RHEL / Fedora  (ffmpeg lives in RPM Fusion)
dnf install ffmpeg

# Alpine
apk add ffmpeg

# macOS
brew install ffmpeg
```

In a container, add it to the image rather than installing it into a running container —
it will not survive a rebuild. For the official Nextcloud image that means a `Dockerfile`
deriving from it.

The app finds ffmpeg on the system path, or wherever `ffmpeg_path` points (see
[Configuration reference](#configuration-reference)).

## 3. Turn importing on

Importing ships **off**. Downloading from YouTube conflicts with its terms of service, so
whether this server does it is a decision for whoever runs it, not something installing an
app should assume:

```bash
occ config:app:set music_radio import_enabled --value=1 --type=boolean
```

Or the switch under **Settings → Administration → Music Radio**. Until then
`occ music_radio:ytdlp:status` reports it as switched off and the button never appears,
however much of the rest of this you have done.

## 4. Install yt-dlp

The app does **not** bundle yt-dlp. Three reasons, all of which matter:

- `occ integrity:sign-app` hashes every shipped file, so a downloader that updated itself
  would break the instance's integrity check the first time it did its job;
- the self-contained builds are ~38 MB **per architecture**, and the wrong one produces a
  file that exists, is executable, and cannot run;
- YouTube changes often enough that a copy frozen at release time would be broken for most
  of its life.

So the app looks for one instead, in this order:

```
1. the path set in ytdlp_path            an administrator said so explicitly
2. <datadirectory>/music_radio/bin/yt-dlp   the copy this app installs and updates
3. whatever is on the system path        apt / pipx / nix / a container image
```

Any of the three works. Pick whichever suits how the server is managed.

### Option A — let the app install it

```bash
occ music_radio:ytdlp:install
```

```
Installed yt-dlp 2026.07.04
  asset: yt-dlp
  path:  /var/www/nextcloud/data/music_radio/bin/yt-dlp
```

This picks the right build for the machine, verifies it against the checksums published
with the release, and installs it `0700` under the data directory — not in appdata, which
may be object storage and cannot hold something executable.

Which build it picks:

| Machine                     | Chosen                                            |
|-----------------------------|---------------------------------------------------|
| Anything with python3 ≥ 3.9 | `yt-dlp` — ~3 MB, architecture-independent        |
| Linux x86-64, glibc         | `yt-dlp_linux`                                    |
| Linux arm64, glibc          | `yt-dlp_linux_aarch64`                            |
| Linux x86-64 / arm64, musl  | `yt-dlp_musllinux[_aarch64]`                      |
| macOS                       | `yt-dlp_macos`                                    |
| Anything else               | refused, with instructions to install it yourself |

The small python build is preferred wherever it will run: one file for every architecture
and libc, a thirteenth of the download, and no way to pick a subtly wrong one.

**About the checksum.** It is fetched from the same host over the same connection as the
binary, so it is not an independent signature — GitHub and TLS remain the trust anchor.
What it does catch is a truncated transfer or a mismatch between the file asked for and the
file received, both of which would otherwise fail confusingly much later. The download is
also run once before being accepted, so a build that cannot execute on this machine is
discarded rather than installed.

### Option B — install it through the system

```bash
pipx install yt-dlp          # keeps its own updates
apt install yt-dlp           # often far behind; see below
nix profile install nixpkgs#yt-dlp
```

Found automatically if it lands on the system path. Note that distribution packages are
frequently a year or more behind, which for this program usually means broken — `pipx` or
the app's own installer are better bets.

### Option C — point at an existing copy

```bash
occ config:app:set music_radio ytdlp_path --value=/opt/bin/yt-dlp
```

Takes precedence over everything else. The path must be absolute and executable by the web
server user; the app refuses anything else rather than storing a setting that cannot work.
Also settable under **Settings → Administration → Music Radio**.

## 5. Make background jobs run promptly

A download and transcode takes tens of seconds, far too long to hold a request open, so
importing happens in a background job. **How quickly an import starts is entirely down to
how background jobs run on this server.**

| Job mode                      | An import starts             |
|-------------------------------|------------------------------|
| AJAX / Webcron                | unpredictable — not suitable |
| System cron (the usual setup) | within 5 minutes             |
| A dedicated worker            | within ~1 second             |

Cron alone is functional but feels broken: a pasted link sits at *"Waiting to start…"* for
minutes with nothing visibly wrong. For anything but occasional use, run a worker:

```bash
occ background-job:worker 'OCA\MusicRadio\BackgroundJob\ImportYoutubeAudioJob' --interval 1
```

As a systemd unit:

```ini
# /etc/systemd/system/music-radio-import.service
[Unit]
Description=Music Radio import worker
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/nextcloud/occ background-job:worker \
          'OCA\MusicRadio\BackgroundJob\ImportYoutubeAudioJob' --interval 1
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now music-radio-import
```

Two things to know about a long-lived worker:

- **It caches app configuration for its lifetime.** After changing any setting, restart it,
  or it will carry on using the old value with nothing to indicate why.
- **Only one import runs at a time**, server-wide, by design — transcoding is CPU-bound and
  a queue of links should not compete with serving pages. Several workers are harmless;
  they will not process the same import twice, and they will not run imports in parallel.

## 6. Check it

```bash
occ music_radio:ytdlp:status
```

```
yt-dlp:  /var/www/nextcloud/data/music_radio/bin/yt-dlp
version: 2026.07.04
ffmpeg:  /usr/bin

YouTube import is usable.
```

It exits non-zero and explains what to do when something is missing, so it is safe to use
in a provisioning check.

The same result appears on the admin **Overview** page:

> ✓ Music Radio: YouTube import: yt-dlp 2026.07.04 and ffmpeg are available.

Then, in the app: open a channel, press **From YouTube**, paste a link. The button is only
shown when the server can actually do it, so if it is missing, the status command above will
say why.

## 7. Or have another machine do it

Everything above assumes the server fetches its own imports. It does not have to, and often
should not: YouTube asks datacentre addresses to prove they are not a bot, and no amount of
installing helps with that.

A **worker** on another machine — a NAS, a laptop, a small VM — collects queued imports over
the API, runs yt-dlp there, and sends the audio back. The server then needs no ffmpeg, no
yt-dlp and no `proc_open`.

```bash
occ user:add radio-worker
occ config:app:set music_radio import_mode --value=remote
occ config:app:set music_radio remote_worker_users --value=radio-worker
occ user:add-app-password radio-worker      # what the worker signs in with

occ music_radio:remote:status               # is anything collecting?
```

The worker itself is a single Python file shipped with the app
(`apps/music_radio/worker/music-radio-worker`). Copy it to the other machine and let it set
itself up:

```bash
sudo install -m 755 music-radio-worker /usr/local/bin/music-radio-worker
sudo music-radio-worker install
```

That downloads and verifies yt-dlp, creates a service account, writes the credentials,
installs a systemd service, and adds a timer that runs `music-radio-worker update` every
quarter of an hour — so the yt-dlp staleness that section "Keeping yt-dlp working" is about
becomes somebody else's problem, handled automatically.
**[remote-import.md](remote-import.md)** has the whole procedure.

## Keeping yt-dlp working

**Expect to update it every few weeks.** YouTube changes how videos are served, extractors
break, and a copy that worked last month stops. This is normal for this class of tool and
not a fault in the app.

When it happens, imports fail with:

> YouTube changed something the downloader on this server cannot handle yet. Ask an
> administrator to update yt-dlp.

The fix, for a copy the app manages:

```bash
occ music_radio:ytdlp:install --force
```

For a system copy, update it however it was installed (`pipx upgrade yt-dlp`, and so on).

You do not have to wait for a complaint. The app flags a copy more than 90 days old on the
admin Overview page:

> ⚠ The installed yt-dlp (2026.04.01) is more than 90 days old. YouTube changes frequently
> and imports will start failing; update it with "occ music_radio:ytdlp:install --force".

A monthly `occ music_radio:ytdlp:install --force` from cron is a reasonable habit.

## Configuration reference

Everything below is also on the **Settings → Administration → Music Radio** page.

| Key                       | Default     | Meaning                                                     |
|---------------------------|-------------|-------------------------------------------------------------|
| `import_enabled`          | `false`     | Must be turned on before importing works at all             |
| `ytdlp_path`              | *(empty)*   | Absolute path; empty means detect                           |
| `ffmpeg_path`             | *(empty)*   | Absolute path to `ffmpeg`; `ffprobe` must sit beside it     |
| `import_max_duration`     | `5400`      | Longest video, **in seconds**. Refused before downloading   |
| `import_max_source_bytes` | `314572800` | Largest download, **in bytes**, measured before transcoding |
| `import_mode`             | `local`     | `remote` hands imports to a worker on another machine        |
| `remote_worker_users`     | *(empty)*   | Comma-separated accounts allowed to collect imports          |
| `remote_forward_cookies`  | `false`     | Whether a worker may borrow a channel owner's YouTube cookies |

```bash
occ config:app:set music_radio import_max_duration --value=1800 --type=integer
```

The settings page shows minutes and megabytes; `occ` uses seconds and bytes.

Per-user, under **Settings → Personal → Music Radio**: the folder music lands in, `Music`
by default. Any existing folder in their files, at any depth. It applies to uploads as well as imports. Note that an
imported file goes to the **channel owner's** folder and counts against their quota,
whoever pasted the link — so it is the owner's setting that decides.

There is no rate-limit setting: importing is capped at 10 per user per hour in the code,
because each one costs somebody's storage and a transcode.

## Troubleshooting

Start with `occ music_radio:ytdlp:status`. It names the cause and the fix for every case
below.

**"YouTube import is not usable: ytdlp_missing"**
Nothing was found at any of the three locations. Run `occ music_radio:ytdlp:install`, or
point `ytdlp_path` at an existing copy. If you *did* install it, check the web server user
can execute it — a binary in a root-only directory is invisible to the app.

**"YouTube import is not usable: ffmpeg_missing"**
ffmpeg, ffprobe, or both are absent. Both are needed. If ffmpeg is installed somewhere
unusual, set `ffmpeg_path` to it — and make sure `ffprobe` is in the same directory.

**"YouTube import is not usable: process_disabled"**
`proc_open` is in this PHP's `disable_functions`. Common on shared hosting. Importing cannot
work without it; the rest of the app is unaffected.

**Imports sit at "Waiting to start…"**
Nothing is running background jobs. Check with `occ background-job:list`. If the job is
listed and never runs, cron is not working — importing is the messenger, not the problem.
See [step 5](#5-make-background-jobs-run-promptly).

**"That import never started. Background jobs may not be running on this server."**
The same thing, reported by the app after an hour of waiting. This message exists because
the app cannot use a background job to tell you background jobs are broken.

**A setting appears to be ignored**
Restart the worker. It caches app configuration for its lifetime.

**Every import fails, having worked before**
yt-dlp is out of date. See [Keeping yt-dlp working](#keeping-yt-dlp-working).

**One video fails, others work**
Read the message on the failed import — private, members-only, age-restricted, geo-blocked,
live, too long and too large each say so. These are not faults to fix: the app reports
restrictions rather than working around them.

**"An import stopped unexpectedly. Try again."**
The worker died mid-import — most often the OOM killer. Nothing is left behind; the file is
only kept once it has become a track.

### Where to look

```bash
# The app's own log lines, including the tail of yt-dlp's stderr on a failure
occ log:watch | grep music_radio

# What the server thinks it can do
occ music_radio:ytdlp:status

# Or, when the fetching happens on another machine: is it connected, and is the
# queue moving?
occ music_radio:remote:status

# Whether the job was ever queued at all
occ background-job:list | grep MusicRadio
```

Every import's outcome is visible in the app itself — the queue above the playlist keeps
failed entries, with the reason, until they are cleared. For a server-wide view, query
`oc_music_radio_imports` (`status`, `error_code`, `title`) with whatever database client you
normally use.

yt-dlp's raw output is logged but never shown to the person importing: it can contain
server paths, and it is written for someone debugging yt-dlp.

## Uninstalling

```bash
occ app:remove music_radio              # also drops the app's settings
occ app:remove music_radio --keep-data  # leaves them, for a reinstall
```

Three things survive either way, and are worth knowing about:

- **The music.** Imported and uploaded tracks are ordinary files in people's folders. They
  are not touched — delete them from Files if you want them gone.
- **The database tables.** Nextcloud does not drop an app's tables when removing it. If you
  want them gone, drop `oc_music_radio_channels`, `oc_music_radio_tracks`,
  `oc_music_radio_shares` and `oc_music_radio_imports` by hand — check your table prefix
  first, `oc_` is only the default.
- **The yt-dlp copy** at `<datadirectory>/music_radio/bin/`. Remove that directory to be
  rid of it.

To stop importing without uninstalling:

```bash
occ config:app:set music_radio import_enabled --value=0 --type=boolean
```

The button disappears, the endpoint refuses, and playing music carries on as before.
