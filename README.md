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
| **From a public link** — a visitor uploads, by choosing a file or dropping one on the dialog | The channel owner's music folder |
| **From YouTube** — paste a link | The channel owner's music folder |

The music folder is `Music` by default, and each person can change theirs under
**Settings → Personal → Music Radio**. It is chosen with the file picker rather than typed,
so it can only ever name a folder that is really there — the server enforces the same rule,
since the endpoint is reachable without the page. Any depth of nesting is fine. The default
is created on first use if it does not exist yet.

## Running a channel

Almost everything is decided **per share**, in the share dialog, on the row for that link or
that person — not once for the channel. An owner may well trust the people they named by
name quite differently from whoever ends up holding a link passed round a room, and one
answer for everybody could not say so:

| On each share | |
|---|---|
| **Hold what they add for approval** | Anything they add stays off the air until the owner approves it |
| **Can see how many are listening** | The owner always sees it, whatever this says |
| **Can vote for tracks** | Only offered while the channel's voting switch is on. See below |
| **Can add tracks from YouTube** | Only offered while the server's and the channel's switches are both on. Off by default on links, and it says why — see [Importing through a public link](#importing-through-a-public-link) |

Two switches are genuinely properties of the channel rather than of an audience, and stay
under **This channel**:

| On the channel | |
|---|---|
| **Let listeners vote** | Whether voting happens here at all. The owner has no share of their own, so something has to speak for them |
| **Allow adding tracks from YouTube** | Whether the channel accepts imports at all. An administrator must have switched importing on for the server first; all of the switches have to agree |

**Password on share links** is not a switch here at all: the app honours the server's own
*Enforce password protection* setting under **Settings → Administration → Sharing**. With it
on, a link cannot be created without a password, and an existing one cannot have its
password removed.

A share reached two ways — named directly and through a group, say — gets the more generous
answer of the two, the same rule the permissions themselves follow.

The listener count needs a distributed cache (Redis or Memcached) — presence is held
there rather than in the database, since every listener would otherwise write to it every
few seconds. Without one the app reports that it cannot count and shows nothing at all,
rather than showing every listener a confident "1".

### Moving the broadcast

Anyone with **Can control what is playing** can drag the progress bar to a point in the
current track — and reach it from the keyboard, since a control only operable by drag is
not operable at all for some people. Arrow keys move a second at a time, Home and End go to
the ends, and a burst of presses becomes one seek rather than one per press: each seek
re-anchors the timeline and makes every listener refetch.

It moves the broadcast, not a private playhead. There is only one position on a channel,
and everyone tuned in lands where it was put.

### Voting

One vote each per track, and a track spends its votes once it has played. Signed-in
people are identified by their account; anyone on a share link by a cookie their browser
was given — which is to say voting on a link is a show of hands among people who already
have the link, not a ballot. Grant it per link, alongside uploading; a link is created
listen-only.

Counts update for everyone watching: a vote moves a counter of its own, separate from the
one that governs the broadcast, so other people's pages pick it up on their next ordinary
poll without anything being re-anchored. A track's votes are spent the moment it reaches
the front, whether or not anyone is still voting.

**The running order changes between tracks, never during one.** A channel is one
continuous programme, so honouring a vote means rewriting the order — and every rewrite
re-anchors the timeline and makes every listener refetch. Casting a vote therefore does
nothing to the broadcast by itself: the order is recomputed at most every twenty seconds,
and never moves the track playing or the one listeners' browsers have already loaded. A
voted track arrives at the first free position after those, which means it may take a
track or two to come round on short songs. That is the price of nobody hearing a stutter.

Voting is ignored while a channel is shuffling — shuffle is a deliberate instruction to
randomise, and the two are not coherent at once. The author's playlist order is never
overwritten by either, so turning both off restores it exactly.

## YouTube import

**Off by default**, and off until an administrator turns it on — downloading from YouTube
conflicts with its terms of service, so that is their decision to make rather than the
app's. It also needs two things Nextcloud does not ship. The
short version is below; **[docs/INSTALL.md](docs/INSTALL.md)** has the full setup, including
background jobs, updating, and what to do when something is not working.

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

### Importing through a public link

A visitor with no account can be allowed to import, but only deliberately, and it is off
until three separate switches agree: the administrator's server-wide one, the channel's,
and the one on that particular link. Every other setting a share carries is inherited from
the channel when the share is made; this one is not, because it is the only one that
spends more than the owner's disk. An anonymous visitor starting server-side downloads
against the owner's quota and CPU is a different proposition from uploading a file they
already have, so it is opted into per link rather than granted by default.

What bounds it once it is on:

- **Five an hour per visitor**, against the upload's ten — an import costs a download, a
  transcode and quota, not just quota.
- **One at a time.** The per-requester cap that applies to accounts applies here too, keyed
  on the visitor rather than a user id, so one browser cannot queue a pile.
- **The administrator's duration and size limits** apply unchanged.
- **The link's own approval setting** is honoured, decided when the import is asked for
  rather than when the job runs — by then the link is no longer in hand.
- **It is attributable.** The import is recorded against the link it came through, so the
  visitor can stop their own and the owner can see where it came from.

None of that makes it free: a link that leaks is a link that can spend storage and CPU
until the owner revokes it. Leave it off unless the people holding the link are people you
would have given an account.

### What it does not do

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
