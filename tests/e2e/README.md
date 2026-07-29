# End-to-end tests

Playwright specs driving a real browser against the DDEV Nextcloud instance.

## Running

The `ddev-playwright` add-on bakes a single working directory from `PLAYWRIGHT_TEST_DIR` in
`.ddev/.env.playwright`, which currently points at `app/unity`. The container mounts the whole
harness root at `/var/www/html`, so this suite runs without changing that setting:

```bash
docker exec -w /var/www/html/app/music_radio \
  ddev-nextcloud-app-dev-playwright npx playwright test
```

Useful variants:

```bash
# one spec
docker exec -w /var/www/html/app/music_radio \
  ddev-nextcloud-app-dev-playwright npx playwright test tests/e2e/smoke.spec.ts

# one test by title
docker exec -w /var/www/html/app/music_radio \
  ddev-nextcloud-app-dev-playwright npx playwright test -g 'Vue app mounts'
```

Alternatively, set `PLAYWRIGHT_TEST_DIR="app/music_radio"` in `.ddev/.env.playwright` and
`ddev restart` to use `ddev playwright test` — but that takes the shortcut away from the other
app, so the `docker exec` form above is usually preferable.

## Things that bite

- **Pretty URLs are off** on this instance. Every path needs `/index.php`, e.g.
  `/index.php/apps/music_radio/`. A path without it redirects and the spec fails confusingly.
- **`PLAYWRIGHT_DOCKER_IMAGE` must match `@playwright/test`** in `package.json` (both pinned to
  1.61.1). A mismatch produces "Executable doesn't exist" errors.
- **Auth is shared.** `global-setup.ts` logs in once as `admin`/`admin` and writes
  `tests/e2e/.auth/admin.json` (gitignored); every spec starts authenticated via `storageState`.
  For an anonymous context (public share pages), pass `storageState: undefined` explicitly.
- **`workers: 1`, `fullyParallel: false`.** The specs share one Nextcloud instance and one admin
  user; running them in parallel makes state assertions flaky.
- **DB assertions** go through `@ochorocho/playwright-db-connector` — import `test`/`expect` from
  that package (not `@playwright/test`) in any spec taking the `{ db }` fixture. Note the app
  writes over its own PHP connection, so `cleanupStrategy` is `'none'`; specs clean up after
  themselves.
- **New routes can 404 briefly.** Nextcloud caches the compiled route set in APCu; the harness
  sets `debug=true` to drop the TTL to 3s. After adding a route, give it a moment or
  `ddev restart`.
- **Brute-force protection is off in this harness** (`auth.bruteforce.protection.enabled=false`,
  set by `setup-nextcloud.sh`). The suite deliberately exercises wrong passwords and invalid
  share tokens, which looks exactly like an attack — once tripped, Nextcloud answers **429 to
  everything** from the test container and unrelated tests start failing seemingly at random.
  If you ever see a wall of unexplained failures, check for 429 first.
- **The first-run wizard is disabled** for the same reason: it renders a modal over the whole
  UI for every new account and swallows clicks.
- **Sync assertions poll rather than sample.** Two browsers schedule their own clock probes,
  polls and audio independently, so a single snapshot can catch one mid-tune-in. `expectInSync`
  waits for them to converge and then checks they stay converged, which is both the stronger
  claim and the stable one.
- **Tests must select their own channel.** The app auto-selects the first channel it is given,
  so a leftover channel from another test would otherwise be the one on screen and every
  assertion would quietly describe the wrong playlist.
