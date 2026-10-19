# Flat-file (ZIP) installation reference

This page is the reference for installing the KomaEngine release ZIP on any
host. For step-by-step instructions, use the guide for your platform:

- [Plesk](plesk.md)
- [cPanel](cpanel.md)
- [Ubuntu server (VPS)](ubuntu-vps.md)

Use this page directly for other control panels (DirectAdmin, CyberPanel, a
host's own panel), and as the explanation behind each step of those guides.

## Get the package

Download the release ZIP from the
[download page](https://komaengine.com/download/) or the
[releases page](https://github.com/komaengine/komaengine/releases), and note
the SHA-256 listed next to it. Check the downloaded file on your computer
before uploading it:

| Computer | Command |
|---|---|
| macOS | `shasum -a 256 komaengine-YYYYMMDD-HHMMSS.zip` |
| Linux | `sha256sum komaengine-YYYYMMDD-HHMMSS.zip` |
| Windows (PowerShell) | `Get-FileHash .\komaengine-YYYYMMDD-HHMMSS.zip -Algorithm SHA256` |

The printed value must match the published SHA-256 exactly (Windows prints it
in capitals; that is fine). If it does not match, download the file again. To
also check the signature, follow the [supply-chain guide](../SUPPLY_CHAIN.md).

To build the package yourself instead, use a trusted machine with Git, PHP,
Composer, Node/npm, `zip`, and `unzip`. Resolve and review the exact commit
first; the builder rejects branch names, tags, abbreviations, and an omitted
source revision:

```bash
SOURCE_COMMIT="$(git rev-parse HEAD)"
printf '%s\n' "$SOURCE_COMMIT"
KOMA_SOURCE_REF="$SOURCE_COMMIT" scripts/build-flatfile.sh
```

The builder exports that immutable Git tree into temporary staging, applies the
release allowlist, installs production dependencies, builds assets, creates
`dist/komaengine-<timestamp>/` and a matching ZIP, and prints the source commit
and ZIP SHA-256. `VERSION` supplies the authoritative version, and the package
records `<VERSION>+<first-12-source-commit>`, the full commit, exact-clean source
policy, and Composer/npm lock hashes in `KOMA-BUILD-METADATA.txt`. The artifact
inspector rejects any mismatch. Working-tree edits, ignored/private plugins, and unrelated
untracked files cannot enter the package. The package intentionally contains no
`.env`, database, test manga, logs, research files, workstation symlink, or
reusable `APP_KEY`.

`tests/Release/shared-hosting-smoke.sh` proves the extracted ZIP workflow in a
disposable PHP 8.4 Docker environment: clean install, restart, database queue,
scheduler, verified pre-update backup, one migration invocation, current-code
upgrade, exact-code rollback, and content/media checks. That is machine evidence
only. It does not prove a hosting provider's control panel, ownership model,
cron limits, long-running process policy, or symlink policy.

## Host requirements

- PHP 8.3–8.5 with GD, ZIP, PDO MySQL, mbstring, intl, bcmath, and fileinfo.
- MySQL or MariaDB credentials with table DDL and normal read/write access.
  KomaEngine is tested with MariaDB 11.8 and MySQL 8.4.
- A document root that points to the package's `public/` directory.
- Writable `storage/`, all storage runtime subdirectories, and
  `bootstrap/cache/`.
- A control-panel terminal or SSH access for key generation, health checks,
  backups, upgrades, and rollback.
- Either a process supervisor for the queue worker or a provider-approved cron
  recipe that can drain `archive-imports,default` without overlapping.
- Permission to create `public/storage` as a link to `storage/app/public`.
  Manga pages and covers are stored separately under protected
  `storage/app/media` and are served only through KomaEngine authorization.
- Local media storage. S3/R2/CDN media is not supported by Community Edition.

Treat a host that cannot point the document root at `public/`, create the exact
storage link, or run the required background work as unsupported until a
provider-specific deployment has been designed and tested. Do not work around a
document-root restriction by exposing the application root.

For the maximum documented archive size, configure approximately:

```ini
memory_limit=512M
upload_max_filesize=512M
post_max_size=520M
```

Individual durable pages are limited to 20 MB; the browser staging ceiling is
25 MB per page. A host may choose lower request limits, but large archive and
bulk operations will then be unavailable. A web server or proxy in front of
PHP can have its own request limit (Nginx's `client_max_body_size`); raise it
to match, or uploads fail with HTTP 413.

## Install on any host

These are the steps every guide follows. On a control panel, the PHP processes
normally run as your own hosting account, so files you upload or create are
already writable by PHP and no ownership changes are needed.

1. **Record what you install.** Note the exact ZIP filename, SHA-256,
   KomaEngine version, PHP version, database engine and version, and the host
   or control-panel name. Verify the ZIP hash before extracting it.
2. **Create the database.** Create an empty MySQL or MariaDB database and a
   database user, and give the user all privileges on that database. The
   account must be able to create and drop tables and read and write rows.
   Note the database name, user name, password, and host (usually
   `localhost`).
3. **Choose PHP.** Select PHP 8.3, 8.4, or 8.5 for the domain, confirm the
   required extensions are enabled, and raise the limits above.
4. **Upload and extract the ZIP outside the web folder.** Put it next to the
   folder the domain serves today (`httpdocs`, `public_html`, `www`), not
   inside it. Extracting creates a `komaengine/` folder.
5. **Point the domain at `komaengine/public`.** Change the domain's document
   root in the control panel. If the panel cannot do this, see
   [Hosts that cannot change the document root](#hosts-that-cannot-change-the-document-root).
6. **Turn on HTTPS** for the domain, with an automatic redirect from HTTP.
7. **Open a terminal and find the right PHP.** Change to the `komaengine`
   folder (the one that contains `artisan`) and run `php -v`. If it shows a
   version other than the one you chose for the domain, use the full path to
   the matching PHP program in every command below; each panel guide lists the
   usual paths.
8. **Create `.env` and keep it private:**

   ```bash
   cp .env.example .env
   chmod 600 .env
   ```

9. **Fill in `.env`.** Set `APP_URL` and the database values, and add
   `SESSION_COOKIE=komaengine_session`. See [Settings in `.env`](#settings-in-env).
   If you edit the file in a control-panel file manager, run `chmod 600 .env`
   again afterwards.
10. **Generate the secret key**, once, before the first web request:

    ```bash
    php artisan key:generate --force
    ```

11. **Link public files, prepare protected storage, and check readiness:**

    ```bash
    ln -s ../storage/app/public public/storage
    php artisan koma:media:protect
    php artisan koma:doctor --mode=bootstrap
    ```

    Every line should be `OK` except `migrations`, which fails until the
    installer has run
    ([#1](https://github.com/komaengine/komaengine/issues/1)). If
    `public/storage` already exists as the correct link, `ln` reports
    `File exists`; that is fine. The relative link keeps working if the host
    shows the folder at a different absolute path to PHP than to your
    terminal. `php artisan storage:link` creates an absolute link instead and
    also works on most hosts.
12. **Run the web installer.** Print the setup token, open
    `https://your-domain/install`, and enter it:

    ```bash
    php artisan koma:installer-token
    ```

    The token is derived from the site's private `APP_KEY`; do not place it in
    a URL, log, screenshot, ticket, or chat. Confirm every required check is
    ready, test the database connection, and create the first Administrator.
    The installer revokes its browser grant and writes a durable completion
    marker when setup succeeds.
13. **Check the result:**

    ```bash
    php artisan koma:doctor --mode=operational
    ```

    Every line, including `installed`, should be `OK`.
14. **Start the background jobs.** Add the two cron jobs in
    [Queue and scheduler](#queue-and-scheduler).
15. **Test.** Sign in, publish a small test series and chapter, and read its
    pages before adding real content.

### Hosts that cannot change the document root

Do not move `index.php` into `public_html` and improvise relative paths, and do
not unpack the whole package into the web folder. Serving the application root
exposes files that must remain private, including `.env`.

Instead, use one of these:

- A **subdomain or addon domain**, which most panels let you create with any
  document root, for example `manga.example.com` serving `komaengine/public`.
- Ask the host to change the domain's document root for you.

If neither is possible, the host is not supported for the ZIP package.

## Settings in `.env`

The ZIP's `.env.example` is already set up for production. These are the
values you set or check:

| Setting | Value | Notes |
|---|---|---|
| `APP_URL` | `https://manga.example.com` | The site's public address, with `https://` and no trailing slash. The installer updates it from the address you enter. |
| `APP_ENV` | `production` | Already set. |
| `APP_DEBUG` | `false` | Already set. Never `true` on a public site: error pages would show internal details. |
| `APP_KEY` | (empty) | Filled in by `php artisan key:generate --force`. Never change it on an existing site. |
| `DB_CONNECTION` | `mysql` or `mariadb` | Match the database your host provides. |
| `DB_HOST` | `localhost` | Use the host your panel shows for the database. `localhost` is right on most panels; use `127.0.0.1` only if `localhost` fails. |
| `DB_PORT` | `3306` | The standard port; change it only if your host says so. |
| `DB_DATABASE`, `DB_USERNAME` | from your panel | Panels often add your account name as a prefix, for example `acct_komaengine`. Use the full names the panel shows. |
| `DB_PASSWORD` | from your panel | Put it in double quotes if it contains spaces or `#`. |
| `SESSION_COOKIE` | `komaengine_session` | Not in the template; add it. Keeps the installer's sign-in working ([#2](https://github.com/komaengine/komaengine/issues/2)). |
| `SESSION_DRIVER`, `CACHE_STORE` | `file` | Already set. |
| `QUEUE_CONNECTION` | `database` | Already set. Archive imports use the database queue. |
| `DB_QUEUE_RETRY_AFTER` | `3660` | Already set. Must stay higher than the worker's 3,600-second timeout. |
| `FILESYSTEM_DISK` | `public` | Already set. Manga pages are stored separately in protected storage. |
| `LOG_STACK`, `LOG_DAILY_DAYS` | `daily`, `14` | Already set: one log file per day under `storage/logs`, kept for 14 days. |

The installer rewrites `APP_NAME`, `APP_URL`, the database values, and a few
storage settings when it finishes. It keeps every other line, including
`SESSION_COOKIE`, and always leaves the file at mode `0600`.

## Ubuntu Server walkthrough

The Ubuntu walkthrough that used to be here is now the
[Ubuntu server guide](ubuntu-vps.md).

## Queue and scheduler

KomaEngine needs two background tasks:

- **The scheduler** publishes scheduled chapters. Run it every minute.
- **A queue worker** processes archive imports on the `archive-imports` and
  `default` queues.

On a server with root access, run the worker under a process supervisor. The
[Ubuntu guide](ubuntu-vps.md#8-start-the-background-jobs) sets up both as
systemd services with the packaged `scripts/install-ubuntu-services.sh`. The
worker command is:

```bash
php artisan queue:work database --queue=archive-imports,default \
  --tries=1 --timeout=3600 --sleep=3
```

On a control panel, use two cron jobs that run every minute. Replace the folder
with the full path to your `komaengine` folder and `php` with the full path to
the PHP program your panel guide names:

```cron
* * * * * cd /full/path/to/komaengine && php artisan schedule:run >/dev/null 2>&1
* * * * * cd /full/path/to/komaengine && php -d memory_limit=512M artisan queue:work database --queue=archive-imports,default --tries=1 --timeout=3600 --sleep=3 --stop-when-empty >/dev/null 2>&1
```

The second job starts a worker that processes whatever is waiting and then
exits (`--stop-when-empty`), so nothing keeps running between imports. When a
long import is still running a minute later, the next worker finds no
available job and exits straight away: each job is reserved by exactly one
worker. Two archives uploaded together can be processed side by side, each
using up to the memory limit.

Some shared hosts stop cron jobs after a few minutes or limit how often they
run. Small imports still finish, but a host that stops long jobs cannot
reliably import maximum-size archives. Ask the provider whether a PHP job that
runs for up to an hour is allowed, and import smaller archives if it is not.

## Update and rollback

Never overwrite the running application in place. Keep the current exact ZIP
and application directory until the replacement passes acceptance.

1. Quiesce writes, stop the queue worker and scheduler, and create an encrypted
   secret bundle as described in the [recovery contract](recovery.md). Create
   and independently verify a new backup outside the application tree:

   ```bash
   php artisan koma:site-backup:create /absolute/backups/pre-update \
     --secrets-bundle=/absolute/secrets/koma-secrets.age
   php artisan koma:site-backup:verify /absolute/backups/pre-update
   ```

2. Extract the new exact ZIP into a new sibling directory. Copy the existing
   `.env` with mode `0600`, database connection, and stable `APP_KEY`; copy or
   mount the durable `storage/` inventory according to the host's deployment
   layout. Copy any theme or plugin you installed yourself from the old
   `themes/` and `plugins/` directories; the package contains only the
   built-in ones. Recreate `public/storage` rather than copying its old link. Copy
   `public/robots.txt` from the old directory as well: the installer wrote it
   and no package contains one.
3. With the site still closed to writes, run `php artisan optimize:clear`,
   `php artisan storage:link --force`, exactly one
   `php artisan migrate --force`, and `php artisan koma:media:protect`. Where
   the release directory is owned by root, as in the [Ubuntu guide](ubuntu-vps.md), the
   PHP user cannot replace that link and the command stops with
   `symlink(): File exists`; create the link with `sudo ln -s` as that
   guide's step 5 does, and skip `storage:link`. The
   media command copies and verifies any legacy `storage/app/public/media`
   files before removing that public directory; it is safe to rerun and
   refuses conflicting files. Run operational doctor and inspect the migration
   output before switching the document root to the new directory.
4. Sign in and sample the catalog, reader, protected media routes, queue, scheduler, and
   logs. Restart PHP from the control panel and repeat the sample before
   reopening writes.
5. If acceptance fails, stop all writers. Restore the verified pre-update
   database, complete durable storage, encrypted secrets, and the matching old
   exact ZIP into a new isolated target. Restore the original `APP_KEY`, point
   the document root at that target, recreate the storage link, and rerun doctor
   and the same samples. Reverting only code or only the database is not a
   rollback.

A Koma Archive exports one series and is not a full-site backup.

## Real control-panel acceptance checklist

Use a fresh site or disposable subdomain with the intended provider. Do not
paste `.env`, credentials, `APP_KEY`, cookies, or secret-bundle contents into
the evidence.

- [ ] Record host/control-panel, PHP and database versions, exact KomaEngine
  build ID, ZIP SHA-256, tester, and UTC start time.
- [ ] Upload/extract the ZIP; set the domain root to `public/`; secure `.env` as
  `0600`; generate one unique key; create the storage link; prepare protected
  media storage.
- [ ] Generate `koma:installer-token` on the server; open `/install` over HTTPS;
  enter the token; confirm every required preflight row is `OK`; pass the
  database probe; create the first Administrator; run operational doctor.
- [ ] Sign in; publish one small chapter; confirm catalog, reader, and sampled
  media work after a control-panel PHP restart.
- [ ] Configure the real provider worker/cron and one-minute scheduler; prove an
  archive job is drained once and a scheduled chapter publishes once.
- [ ] Stop writers; create and verify a full-site backup outside the app host or
  primary account; retain the old exact ZIP and encrypted secret bundle.
- [ ] Upgrade from that backup using a new sibling directory and one migration
  invocation; repeat login/content/media/worker/scheduler/restart checks.
- [ ] Perform a full rollback into a new isolated target using the old exact
  ZIP, database, storage, and stable key; repeat the checks and record result,
  UTC finish time, sanitized logs/screenshots, and any provider limitation.

Passing this checklist is the real-host human gate. Docker or a developer shell
cannot clear it.
