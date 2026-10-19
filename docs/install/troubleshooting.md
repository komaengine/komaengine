# Installation and operations troubleshooting

Start with the machine-readable readiness checks:

```bash
php artisan koma:doctor --mode=bootstrap --json
php artisan koma:doctor --mode=operational --json
```

Bootstrap mode checks the key, local media driver, database, migrations,
runtime paths, cache, storage link, and absence of the legacy public manga
directory. Operational mode also requires the terminal installer marker.

## Common hosting problems

Find the message or symptom you see. For the step-by-step guides, see
[Installing KomaEngine](README.md).

| What you see | Usual cause | What to do |
|---|---|---|
| Your old site, a host placeholder page, or a file listing instead of KomaEngine | The domain still serves `httpdocs` or `public_html`. | Set the domain's document root to `komaengine/public` in the control panel. |
| The installer's **Web document root** check is not ready | Same as above, or the root points at `komaengine` instead of `komaengine/public`. | Point it at `komaengine/public` exactly. See [Wrong document root](#wrong-document-root). |
| `Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.3.0"` | The domain, or the terminal, uses an older PHP. | Choose PHP 8.3, 8.4, or 8.5 for the domain in the panel. In the terminal, run commands with the full path to the newer PHP; your panel guide lists it. |
| `php -v` in the terminal shows a different version from the panel | Panels keep several PHP versions; plain `php` is often the server's default. | Use the full path to the PHP version you chose for the domain in every command and cron job. |
| `No application encryption key has been specified.` | `.env` is missing, or `APP_KEY` is empty. | Run `php artisan key:generate --force` once in the `komaengine` folder. |
| The installer says `.env` `mode 0644 is unsafe` (or another mode) | A file manager or upload changed the file's permissions. | Run `chmod 600 .env` in the `komaengine` folder and reload the page. |
| Every page except the home page is `404 Not Found` | The web server is not passing requests to `index.php`. On Apache and LiteSpeed, `public/.htaccess` must be allowed; on Nginx, the site needs a `try_files` rule. | On Plesk, see the Plesk guide's note on Nginx-only hosting. Elsewhere, ask the host to allow `.htaccess` overrides (`AllowOverride All`). |
| `SQLSTATE[HY000] [1045] Access denied for user` | Wrong database user name or password. | Copy the full names from the panel, including any `account_` prefix. Re-type the password, and quote it in `.env` if it contains `#` or spaces. |
| `SQLSTATE[HY000] [2002] No such file or directory` | `DB_HOST=localhost` uses a socket that PHP cannot find. | Set `DB_HOST=127.0.0.1` and try again. |
| `SQLSTATE[HY000] [2002] Connection refused` or a timeout | Wrong database host or port. | Use the host and port the panel shows for the database. |
| `ln: failed to create symbolic link 'public/storage': File exists` | The link is already there. | Fine if `php artisan koma:doctor --mode=bootstrap` shows `storage_link OK`. Otherwise see [Permissions and storage link](#permissions-and-storage-link). |
| `Request Entity Too Large` (HTTP 413) when uploading | A web-server limit is lower than PHP's. | See [Upload failures](#upload-failures). |
| Scheduled chapters never publish, or archive imports stay queued | The cron jobs are not running, or use the wrong path or PHP. | Check both cron jobs from [Queue and scheduler](shared-hosting.md#queue-and-scheduler). Run each command once by hand in the terminal to see its error. |

## Installer setup token

Before the first browser setup, run `php artisan koma:installer-token` as the
application user and enter that value on `/install`. The token is derived from
the site's private `APP_KEY`; do not put it in a URL or support log. An invalid
key prevents token generation. After installation, the command refuses to
print a token and the durable marker blocks stale browser sessions.

## Application key

`app_key: false` means `APP_KEY` is missing or invalid. Generate one unique key
with `php artisan key:generate --show`, store it in the correct environment,
and restart the PHP/container processes. Never generate a replacement for an
existing site unless you accept losing sessions and encrypted values.

## Database or migrations

- Verify the selected PDO extension (`pdo_sqlite` or `pdo_mysql`).
- Verify host, port, database, username, and password without pasting secrets
  into logs or support messages.
- The installer account must create/drop a probe table and perform normal
  reads/writes.
- In Docker, inspect `docker compose --env-file <file> logs migrate db`.
- A failed migration or role seed does not write `installed_at`; correct the
  cause and retry the installer.

## Permissions and storage link

The PHP user needs create/read/delete access in `storage/` and
`bootstrap/cache/`. `public/storage` must be a symlink whose resolved target is
exactly `storage/app/public`. Move an occupying file/directory out of the way;
do not link the entire private `storage/app` tree.

The installer reports the first runtime path that fails its create/read/delete
probe. Fix ownership for the control-panel PHP user rather than applying `0777`.
The deployable `.env` must be a regular readable/writable file with mode `0600`;
an existing looser mode is rejected and every installer update rewrites it as
`0600`.

## Wrong document root

`Web document root: must point to the package public/ directory` means the
server-reported document root does not resolve exactly to KomaEngine's
`public/`. Change the domain/subdomain document root in the hosting panel. Do
not copy `index.php` into `public_html`, expose the application root, or edit
framework-relative paths as a shortcut. If the provider cannot select the
`public/` directory, the generic shared-host package is not supported there.

If `Public storage link` fails, confirm that `public/storage` is either absent
or the exact link to `storage/app/public`. An ordinary directory, wrong link,
or host-wide symlink prohibition must be resolved before installation.

If `legacy_public_media_absent` fails, stop writers and run
`php artisan koma:media:protect`. It verifies copies before removing the old
public directory and refuses a conflicting destination; never resolve a
conflict by deleting either copy without comparing it to a verified backup.

## Upload failures

HTTP 413 or an upload that never reaches KomaEngine usually means the proxy or
PHP request limit is too low. Align the web server, proxy, `upload_max_filesize`,
and `post_max_size`. KomaEngine then enforces its own decoded-image, 20 MB page,
25 MB temporary page, and 500 MB archive limits. Corrupt, animated, oversized,
or falsely named images are rejected intentionally.

## Archive jobs stay queued or fail

Run a worker on `archive-imports,default`; keep its 3,600-second timeout lower
than the 3,660-second database reservation lease. Docker includes this worker.
The admin archive screen can retry an owned failed import while its retained
source exists, or discard a terminal source. Do not run two manual retries for
the same import; jobs also carry an import-scoped overlap lock.

On the documented Ubuntu/systemd deployment, reinstall or verify the generated
units and inspect the worker log:

```bash
cd /srv/komaengine/current
sudo ./scripts/install-ubuntu-services.sh \
  --app-root=/srv/komaengine/current
sudo systemctl --no-pager --full status komaengine-queue.service
sudo journalctl -u komaengine-queue.service -n 100 --no-pager
```

The installer is idempotent and refuses to overwrite a unit whose contents
differ. Review an intentional local customization before deciding whether to
rerun it with `--force`; forced replacement keeps a timestamped backup.

## Scheduled chapters do not publish

Run `php artisan schedule:list`, then ensure `schedule:run` executes every
minute or the Docker `scheduler` service is healthy. On Ubuntu, also inspect
`komaengine-scheduler.timer` and `komaengine-scheduler.service` with
`systemctl status` and `journalctl`. Times are stored in UTC.

## Archive staging residue

Imports and exports remove their own staging workspace. One that was killed
outright (out of memory, host reboot, `kill -9`) cannot, and leaves a
UUID-named directory under `storage/app/archive-import-temp/` or
`storage/app/archive-temp/`.

```bash
php artisan koma:archive-temp:clean            # preview; changes nothing
php artisan koma:archive-temp:clean --force    # delete what the preview listed
```

In Docker, run it in the app container as `www-data`, as for `koma:doctor`.
The command only considers UUID-named directories directly inside those two
directories that nothing has touched for `--older-than-days` (default 7,
minimum 1). It leaves every import workspace alone while an archive import is
running or when it cannot tell, refuses symbolic links and unexpected contents,
and checks each workspace again immediately before deleting it. It never looks at retained
source archives under `storage/app/private/`; discard those from the import
screen. It is not scheduled: run it when disk usage or the preview says so.

## Logs

- Flat-file packages: one file per day, `storage/logs/laravel-YYYY-MM-DD.log`.
  Files older than `LOG_DAILY_DAYS` (14) are removed. That limits age, not
  bytes, so an error storm can still fill a disk; watch `storage/logs`.
- A `.env` that says `LOG_STACK=single`, including one copied from an older
  package or a local checkout, writes a single `storage/logs/laravel.log` that
  never rotates. Set `LOG_STACK=daily` and `LOG_DAILY_DAYS=14` in production.
- Docker: `docker compose --env-file <file> logs app worker scheduler migrate`.
  Each container retains a bounded, rotated tail; see [Docker](docker.md).

Installer UI messages use stable public error codes and intentionally omit raw
credentials and internal paths. Use the server log to correlate the underlying
exception. See [recovery](recovery.md) before changing or restoring data.

For shared-host updates, never run rollback against a database already migrated
by newer code. Restore the verified pre-update database, durable storage,
encrypted secrets/stable `APP_KEY`, and exact matching old package together in
a new isolated target.
