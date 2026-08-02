<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Importing on another machine

Music Radio can hand its YouTube imports to a **worker** running somewhere else: a NAS at
home, a laptop, a small VM with a residential route. The queue, the rules and the files all
stay on the Nextcloud; only the fetching moves.

## Why you might want this

A Nextcloud host is often the worst machine available for this job.

- **YouTube looks at where the request comes from.** Datacentre address ranges are routinely
  asked to prove they are not a bot, which nothing on the server can answer. The same import
  from a home connection simply works.
- **The yt-dlp a distribution ships is frequently a year old**, and for this program that
  means broken — YouTube changes something every few weeks.
- **A server may have no ffmpeg, no JavaScript runtime and no `proc_open`.** Shared hosting
  and many managed installations have none of the three, and the administrator cannot add
  them.

None of that is fixable from inside a PHP app. Moving the work to a machine that has a
normal internet connection and a current yt-dlp is.

**What does not change:** who may import, what may be imported, how long a video may be,
whose storage the audio lands in, who is credited, approval, quotas, cancellation. All of
that is still decided by the server. The worker runs a program and hands back bytes.

## What you need

On the machine that will do the fetching:

- **python3** (3.9 or newer) — the worker script uses only the standard library
- **ffmpeg** and **ffprobe**
- a **JavaScript runtime** — `deno`, `node`, `quickjs` or `bun`. Not optional in practice:
  YouTube signs its audio links with JavaScript that yt-dlp has to run, and without an
  engine it falls back to a route YouTube refuses at random.

**yt-dlp is not in that list**: `music-radio-worker install` fetches it, and `update` keeps
it current. Bring your own with `--yt-dlp /path/to/yt-dlp` if you would rather manage it
with `pipx` or the system package manager — a copy you name explicitly always wins.

It needs **no** inbound connectivity, no port forwarding and no fixed address. The worker
calls the Nextcloud, never the other way round, so it works from behind any NAT.

On the server: Music Radio 0.13 or newer, and importing switched on.

## Setting it up

### 1. Make an account for the worker

Give it an account of its own. It can put audio into any channel owner's files, so it
should be able to do nothing else — not an administrator's account, and not yours.

```bash
occ user:add radio-worker
```

### 2. Let it collect imports

**Settings → Administration → Music Radio**: choose **Fetch on another machine**, and name
the account under *Accounts that may collect imports*. Several, comma separated, if you run
more than one worker.

Or from a shell:

```bash
occ config:app:set music_radio import_mode --value=remote
occ config:app:set music_radio remote_worker_users --value=radio-worker
```

Being signed in is not enough on its own — this list is what actually grants the capability,
and it is empty until you fill it in.

### 3. Give it a password to use

An **app password**, not the account's own. It can be revoked from Settings → Security
without touching the account, and it is what every non-browser Nextcloud client uses.

```bash
occ user:add-app-password radio-worker
```

Copy the string it prints; it is not shown again.

### 4. Install the worker

`worker/music-radio-worker` ships with the app — one file, which you can copy off the server
from `apps/music_radio/worker/`. It sets the rest up itself:

```bash
sudo install -m 755 music-radio-worker /usr/local/bin/music-radio-worker
sudo music-radio-worker install
```

It asks for the URL, the account and the app password, and then:

- downloads yt-dlp, verifies it against the checksums published with the release, proves it
  runs, and keeps it at `/var/lib/music-radio-worker/bin/yt-dlp`;
- says what is missing — ffmpeg, a JavaScript runtime — and prints the exact command for
  this system's package manager. It does **not** run it: installing system packages is a
  decision for whoever runs the machine, and on RHEL and openSUSE ffmpeg needs a
  third-party repository enabled first;
- creates the `music-radio` service account;
- writes `/etc/music-radio` as `root:music-radio 0750`, holding `worker.env` (0640,
  `root:music-radio`) and `app-password` (0600, owned by the service account) — the group on
  the *directory* matters as much as the modes on the files, since a file cannot be read
  through a directory the account may not traverse;
- installs and starts the systemd service, plus a timer that runs `update` every quarter of
  an hour;
- finishes by running `check`, and by confirming that the service account can actually read
  the credentials — everything else it does, it does as root, which can read anything and
  therefore proves nothing.

```
installing as root
python:     3.13.5
ffmpeg:     /usr/bin
javascript: node:/usr/bin/node
→ downloading yt-dlp (yt-dlp)
  installed yt-dlp 2026.07.04 (yt-dlp) at /var/lib/music-radio-worker/bin/yt-dlp
→ created the music-radio account
→ wrote /etc/music-radio/worker.env
→ wrote /etc/music-radio/app-password
  wrote /etc/systemd/system/music-radio-worker.service
  wrote /etc/systemd/system/music-radio-worker-update.service
  wrote /etc/systemd/system/music-radio-worker-update.timer
→ the worker is running; follow it with: journalctl -fu music-radio-worker
```

Everything it does can be given on the command line instead of answered
(`--server`, `--user`, `--password-file`), which is what a configuration-management tool
would do. Useful flags:

|                                            |                                                                       |
|--------------------------------------------|-----------------------------------------------------------------------|
| `--dry-run`                                | say what would happen, write nothing                                  |
| `--print-unit`, `--print-env`              | print the files it would write, so you can read them first            |
| `--no-systemd`, `--no-start`, `--no-timer` | leave the service, the starting, or the timer alone                   |
| `--force`                                  | replace files it would otherwise keep — including the stored password |

**Re-running it is safe and is the intended way to change something.** It never overwrites
the password, the env file or a unit you have edited without `--force`, and with no
arguments at all it reads back what the last install wrote.

**Without root** it installs the same things under your home directory — `~/.local/bin`,
`~/.config/music-radio-worker`, and a `systemctl --user` unit — which is what a NAS you log
into as yourself wants.

**Without systemd** — a container, say — it does everything else and tells you the command
to run.

### 5. Or set it up by hand

Nothing above is magic, and `--print-unit` / `--print-env` will show you exactly what it
writes. The pieces are: this script somewhere on `PATH`, an env file naming the server and
the account, a file holding the app password, and something that keeps the process running.

The password goes in a file rather than the environment on purpose: anything in the
environment is readable through `systemctl show` and `/proc`.

### 6. Check it from the server

```bash
occ music_radio:remote:status
```

```
mode:     remote
accounts: radio-worker
worker:   nas, 3 seconds ago
js:       node:/usr/bin/node
cookies:  not forwarded
queue:    0 waiting, 0 out with a worker

A worker is collecting imports.
```

The admin settings page says the same thing, and the **Overview** page reports a worker that
has stopped answering.

## How it works

```
  browser                Nextcloud                     worker machine
     │  paste a link         │                               │
     ├──────────────────────>│  writes a queued row          │
     │  <accepted>           │                               │
     │                       │<──────────────────────────────┤  POST /worker/claim
     │                       ├──────────────────────────────>│  job: two command lines
     │                       │                               │  runs yt-dlp --dump-single-json
     │                       │<──────────────────────────────┤  POST …/metadata
     │                       ├──────────────────────────────>│  proceed / refused
     │  polls the row        │                               │  runs yt-dlp, transcodes
     │<─────────────────────>│<──────────────────────────────┤  POST …/progress  (every 15s)
     │                       ├──────────────────────────────>│  keep going / cancelled
     │                       │<──────────────────────────────┤  PUT …/audio
     │                       │  files it in the owner's Files│
     │  the track appears    │                               │
```

Some details worth knowing:

- **The server writes the command line.** A job arrives with the exact yt-dlp argv for both
  passes, with placeholders for the paths only the worker knows (`{ytdlp}`, `{ffmpeg}`,
  `{dir}`, `{cookies}`). The flags that make a run safe therefore live in one tested place,
  and a worker installed six months ago picks up changes to them without being updated. The
  worker refuses any command that does not start with the yt-dlp placeholder or that carries
  an option like `--exec`.
- **Failures are diagnosed on the server.** The worker sends back the exit code and yt-dlp's
  output; the server turns that into "that video is private", "YouTube asked this server to
  prove it is not a bot", and so on. There is no copy of that logic to go stale on the
  worker.
- **The lease.** Claiming a job mints a token, and every later call about that job has to
  present it. It is cleared the moment the job ends — which is also what makes a retried
  upload (the one whose answer was lost on the way back) a clean refusal rather than a
  second copy of the track.
- **Cancelling works.** The answer to each progress report says whether somebody has pressed
  cancel, and the worker stops the download when it does.
- **Stopping a worker is safe.** `SIGTERM` makes it hand its current job back, and another
  worker takes it within seconds. If the machine dies instead, the job is given back
  automatically two minutes later and retried up to three times.
- **Several workers are fine.** They share one queue and claim jobs atomically; run one per
  machine, or several on a machine with bandwidth to spare. Each keeps its own cache.
- **Finished audio is kept**, so the same video imported twice is fetched once — see below.

## Keeping it up to date

yt-dlp goes stale in weeks — YouTube changes something, extractors break, and imports start
failing with *"YouTube changed something the downloader cannot handle yet"*. That is normal
for this class of tool and it is the single most common reason a working setup stops
working. So `install` sets up a timer, and it runs every fifteen minutes:

```bash
music-radio-worker update
```

```
updated yt-dlp 2026.06.11 → 2026.07.04 (yt-dlp) at /var/lib/music-radio-worker/bin/yt-dlp
```

What it does:

1. **This script**, if the server ships a different one. See below.
2. **yt-dlp** — fetches the published `SHA2-256SUMS`, compares the checksum for this
   machine's build with the installed file's, and downloads only if they differ. The
   download is verified against that checksum and run once before being accepted, so a
   truncated transfer or a build that cannot execute here is discarded rather than
   installed.
3. **The prerequisites** — re-checks ffmpeg, ffprobe, the JavaScript runtime and the python
   version, and prints the package command for anything missing.
4. **The protocol** — warns if the server has moved on and this worker has not.

Every quarter of an hour sounds like a lot and costs almost nothing: a run with nothing to
do is two small requests and, with the `--quiet` the timer passes, no output at all. The
journal only gets a line when something actually changed.

```bash
systemctl list-timers music-radio-worker-update.timer   # when it next runs
systemctl start music-radio-worker-update.service       # run it now
journalctl -u music-radio-worker-update                 # what it has done
```

### Updating the script itself

The server ships the worker script, so `update` compares the copy it is running with the
one the Nextcloud has and takes the server's if they differ. That is what stops a worker
installed months ago from drifting out of step with the API it talks to.

Be clear about what it means: **your Nextcloud can replace the code running on the worker
machine.** The worker verifies what arrives against the checksum the server declared,
refuses anything that will not compile, and keeps the previous script as
`music-radio-worker.bak` — but none of that helps if the server itself is not what you
think it is. It is a larger trust than the rest of this arrangement, where the server can
only ever ask for a video by id.

Turn it off with `--no-script`, or permanently:

```
MUSIC_RADIO_SELF_UPDATE=0
```

in `/etc/music-radio/worker.env`. yt-dlp still updates; only the script is left alone, and
`update` will tell you when the protocol versions no longer match so you can copy a new one
across yourself.

## The local cache

A worker keeps the finished MP3s it produces, so a video imported twice is downloaded once.
That happens more often than it sounds: onto a second channel, after a track was deleted,
or because a job was retried. A hit turns a job that takes half a minute into one that takes
as long as the upload.

```
2026-08-02 12:29:03 cache: /var/cache/music-radio-worker — 41 tracks, 180 MB of 2048 MB
2026-08-02 12:29:03 job 952: https://www.youtube.com/watch?v=dQw4w9WgXcQ
2026-08-02 12:29:03 job 952: already on this machine — “Never Gonna Give You Up”
2026-08-02 12:29:03 job 952: uploading 3.4 MB
2026-08-02 12:29:03 job 952: done — track 23368
```

|             |                                                                                                                                 |
|-------------|---------------------------------------------------------------------------------------------------------------------------------|
| Where       | `$XDG_CACHE_HOME/music-radio-worker`, or `/var/cache/music-radio-worker` under systemd. `--cache-dir` / `MUSIC_RADIO_CACHE_DIR` |
| How much    | 2 GB by default — roughly 500 tracks at 128 kbit/s. `--cache-max-mb` / `MUSIC_RADIO_CACHE_MAX_MB`; `0` keeps nothing            |
| What        | One `<videoId>.mp3` per video, with a small `.json` holding the metadata and the audio settings it was made with                |
| Emptying it | Delete the directory, or `systemctl clean --what=cache music-radio-worker`. Nothing depends on an entry being there             |

Things worth knowing about it:

- **The server is still asked.** A cache hit skips the two yt-dlp runs, not the rules: the
  stored metadata is still posted, the server still records the title and duration, and it
  can still refuse — a length limit lowered since the file was fetched is applied to the
  cached copy as well.
- **It is a cache, not a library.** Entries are evicted least-recently-used first to stay
  under the budget, and a *used* entry counts as recent, so a video imported every week
  outlives one fetched once and forgotten. If you want an archive rather than a cache, set
  the budget high enough that nothing is ever evicted — but the app will not manage it for
  you, and the file in the channel owner's Files is the copy that matters.
- **Cookie jobs are never cached, in either direction.** What YouTube serves a signed-in
  account is not what it serves an anonymous request, and a cached copy would be reused for
  somebody else's import — a members-only track fetched for the account that has access
  could otherwise turn up on a channel whose owner has none. So an import that borrows the
  owner's cookies neither reads the cache nor writes to it.
- **A change of audio format retires the old entries by itself.** Each file records the
  `--audio-format` and `--audio-quality` it was produced with, and an entry that does not
  match what the server is asking for now is ignored and replaced.
- **Files are written under a temporary name and moved into place.** A worker killed
  mid-copy cannot leave a truncated MP3 for a later job to upload as somebody's track.
- **A cache that cannot be written is not an error.** The worker says so once at start-up
  and carries on downloading everything — which is what a hardened systemd unit without a
  `CacheDirectory=` will do.

## Cookies

Some channel owners store YouTube cookies (see
[docs/youtube-cookies.md](youtube-cookies.md)) so their imports are made as a signed-in
account. Those cookies are the one part of an import that is a secret rather than a job, so
they are **not** sent to a worker unless you say so:

```bash
occ config:app:set music_radio remote_forward_cookies --value=1 --type=boolean
```

or the switch on the admin page. With it on, a worker can fetch the owner's jar for the
length of one job — it must hold that job's lease, the job must be running, and the jar it
gets is that channel owner's and nobody else's. It writes the file `0600` into a directory
that is deleted when the job ends, and hands back the rotated jar afterwards so the stored
copy does not go stale.

With it off, imports for those channels are made anonymously. That is not a failure — it is
what happened before anyone stored cookies — but it means a bot check may come back.

Turn it on only if you trust the worker machine as much as you trust the server. Whatever
account those cookies came from should be a throwaway either way.

## Switching back

Set the mode to `local` (or choose *Fetch on this server*). Rows already queued for a worker
stay queued for one; rows already queued for this server keep their background job. Nothing
in flight is lost either way, but do not switch back and forth while imports are running.

## When something is wrong

| What you see                                                                        | What it means                                                                                                                                        |
|-------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--check` says **refused**                                                          | Wrong account or wrong app password. Mint a new one with `occ user:add-app-password`.                                                                |
| `--check` says **not on the worker allow-list**                                     | The credentials are fine; add the account to *Accounts that may collect imports*.                                                                    |
| **"The machine that fetches audio for this server is not answering"**               | No worker has checked in for five minutes. Look at `journalctl -u music-radio-worker`.                                                               |
| **"This server hands imports to a separate machine, and none has been set up yet"** | The mode is `remote` but the allow-list is empty.                                                                                                    |
| Imports sit at *Waiting to start…* then fail                                        | Same as above — nothing is collecting. `occ music_radio:remote:status` says which.                                                                   |
| `not_remote` in the worker's log                                                    | The server has been switched back to fetching its own imports.                                                                                       |
| Downloads fail with format errors on every video                                    | The worker has no JavaScript runtime. Install `deno` or `node` **on the worker**, not on the server.                                                 |
| The worker refuses a job with *"the command carries a forbidden option"*            | The server asked for something the worker will not run. This should never happen; check that the Nextcloud is what you think it is, then file a bug. |
| The service will not start: *could not read /etc/music-radio/app-password: Permission denied* | The config directory is not group-owned by the service account, so it cannot be traversed — a 0600 file inside it is unreadable however the file itself is owned. Fix with `sudo chown root:music-radio /etc/music-radio && sudo chmod 750 /etc/music-radio`, or re-run `install`, which sets this and warns if it is still wrong. |
| `install` says a file was *left alone*                                              | It will not overwrite the password, the env file or an edited unit. Pass `--force` when you mean to replace them.                                     |
| `update` says *the worker script the server sent will not compile*                  | The copy on the server is damaged; the running one is kept. Check `occ integrity:check-app music_radio`.                                              |
| Imports fail right after an update                                                  | The previous script is beside the new one as `music-radio-worker.bak`. Move it back, and set `MUSIC_RADIO_SELF_UPDATE=0` while you work out why.      |

Run the worker with `--verbose` to see the exact command lines it is given, and `--once` to
do a single job and exit.

## Security notes

- The worker signs in with **HTTP Basic and an app password**, over HTTPS only — the worker
  refuses a plain `http://` server, because the credential travels with every request.
  Authentication is Nextcloud's own, so brute-force protection, rate limiting and revocation
  all behave as they do for any other client.
- Being signed in is deliberately not enough. The **allow-list** is the real boundary: a
  worker can be handed any queued job and can upload audio that lands in another user's
  storage, so "any account with a password" would be a way for any user to write files into
  someone else's files.
- Uploaded audio is treated exactly like an anonymous upload: the type is sniffed from the
  bytes rather than believed, the size is measured rather than declared, the name is
  sanitised, the owner's quota is checked, and a file that fails to become a track is
  removed again.
- The worker is trusted to run a command the server gave it. That trust is bounded on both
  sides — the server only ever builds that command from an eleven-character video id, and
  the worker only ever runs its own yt-dlp with it — but it is real, and it is the reason
  the worker account should be a dedicated one.
- **Self-update widens that trust considerably**, and it is on by default: the server hands
  over a script and the worker runs it. The checksum check catches a mangled transfer, not
  a server that has been taken over. If the worker machine matters more to you than the
  convenience — or if you deploy the script with configuration management and would rather
  it stayed put — set `MUSIC_RADIO_SELF_UPDATE=0` and update it the way you update anything
  else.
- The downloaded yt-dlp is checked against the checksums published beside it. That is not
  an independent signature — GitHub and TLS remain the trust anchor — but it does catch a
  truncated transfer, and the binary is run once before it is accepted.
