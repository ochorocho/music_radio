<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# YouTube cookies

## What this is for

Some imports fail with:

> YouTube asked this server to prove it is not a bot, so the video could not be fetched.

That is YouTube's judgement about **the address the request came from**, not about the
video and not about anything installed on the server. It is far more common from a VPS or
other datacentre address than from a home connection, and there is nothing on the server
that can argue with it.

The only thing that reliably changes the answer is asking as somebody signed in. yt-dlp
does that with a Netscape-format cookie file, and Music Radio stores one per channel owner
so it can hand a fresh copy to each import.

**Try the cheaper things first.** A bot check often clears on its own — the message says so
— and the following cost nothing and carry no account risk:

- Wait and retry. Most of these are transient.
- Check `occ music_radio:ytdlp:status`. A missing JavaScript runtime produces failures that
  look similar and is fixed by installing Deno or Node, not by cookies.
- Keep yt-dlp current, from the admin settings page or
  `occ music_radio:ytdlp:install --force`.
- If the server has a route out through a residential address, set Nextcloud's `proxy`
  system config. The import passes it to yt-dlp.

Cookies are the answer when a bot check is *persistent* rather than occasional.

## Read this before you start

**Use a throwaway Google account.** Not your own, and not one holding anything you would
miss.

- YouTube may **lock or ban** an account it decides is being automated. This is a
  documented risk of the approach, not a theoretical one.
- The stored cookies are a **signed-in session** for that account. Music Radio encrypts
  them at rest (see below), but anybody who can read the server's decrypted database —
  an administrator, or whoever holds a backup and the instance secret — holds that session.
- Do not use an account with 2FA you rely on elsewhere, a linked payment method, or access
  to anything beyond YouTube.

## Exporting the file

The steps that matter are 3 and 5. YouTube rotates its session cookies as you browse, and
signing out invalidates the session you just exported — both produce a file that stores
fine and then does nothing.

1. **Install a cookies.txt exporter** for your browser, one that writes the Netscape
   format. "Get cookies.txt LOCALLY" is the usual choice. A JSON export will be rejected.

2. **Open a private/incognito window** and sign in to YouTube with the throwaway account.
   A private window matters: it keeps this session separate from your normal one, so
   closing it later does not disturb your own login.

3. **Navigate to `https://www.youtube.com/robots.txt`** in that same window. This parks the
   session on a page that will not rotate the cookies while you export them. Exporting from
   a normal YouTube page often captures cookies that have already been superseded.

4. **Export the cookies for youtube.com** with the extension, and open the downloaded file
   in a text editor. It should start with `# Netscape HTTP Cookie File` and have one
   tab-separated line per cookie.

5. **Close the private window without signing out.** Signing out tells Google to invalidate
   that session, which invalidates the file you just saved. Just close the window.

6. **Paste the whole file** into Personal settings → Music Radio → YouTube cookies, and
   press **Save cookies**.

`--cookies-from-browser` is not offered. It reads a browser profile from local disk, which
on a server means whatever profile the web user can read — there is no browser there to
read, and no reason to let a setting point a file read at one.

## What the server does with them

- **Stored** against your user account through `IUserConfig` with the `SENSITIVE` flag,
  which means Nextcloud core encrypts the value at rest with the instance secret (AES-CBC
  plus an HMAC) and masks it in `occ config:list` and support reports. Stored lazily, so
  it is not loaded on ordinary requests.
- **Never sent back to a browser.** The settings page shows a count, the domains, and two
  dates. There is no API that returns the value; the field is empty every time the page
  loads because there is nothing safe to prefill it with.
- **Written per import** to a file with `0600` permissions inside that import's own
  temporary directory, which is deleted when the import ends however it ends.
- **Refreshed automatically.** yt-dlp writes the rotated jar back out, and Music Radio
  stores what comes back — so an actively used jar keeps itself alive rather than decaying
  from the moment you paste it. A rotated jar that no longer parses is discarded and the
  previous one kept.
- **Used for channels you own**, whoever asked for the import. That matches where the audio
  lands: an import files into the owner's storage and against their quota regardless of who
  pasted the link. A public-link visitor therefore never causes a stranger's session to be
  used — only the owner's, on the owner's own channel.

## When they stop working

Cookies expire, and Google ends the session if you sign in to that account somewhere else.
The settings page shows the date the first of them expires; past that, imports will start
failing again.

Two failure messages tell you which is which:

| Message | Meaning |
| --- | --- |
| "YouTube did not accept the stored cookies…" | The jar was presented and refused. Export again. |
| "The stored YouTube cookies could not be read…" | The stored file no longer parses. Export again. |
| "YouTube asked this server to prove it is not a bot…" | No cookies were stored for that channel's owner. |

To stop using them, press **Remove stored cookies**. Imports go back to asking
anonymously, which for most servers works most of the time.
