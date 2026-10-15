# Docker installation

The Compose topology runs MariaDB 11, a one-shot migration container, the web
application, a dedicated archive/default queue worker, and the scheduler. The
database is not published to the host. Adminer is optional and loopback-only.

`VERSION` is the authoritative application version. Normal Compose builds are
explicitly identified as development builds (`unavailable` commit, `unknown`
dirty state, `development` build ID). A trusted artifact build must instead use
an exact exported Git commit and supply its full commit, `false` dirty state,
`<VERSION>+<first-12-commit>` build ID, and `KOMA_TRUSTED_BUILD=true`. The
Dockerfile rejects a missing commit, dirty trusted source, a version that does
not equal `VERSION`, or a mismatched build ID. The resulting OCI labels,
runtime environment, and `/var/www/html/KOMA-BUILD-METADATA.txt` carry the same
identity and Composer/npm lock hashes.

## Working with the stack

Use the same env file, here `../koma.docker.env`, for every Compose invocation,
and run application commands inside the relevant container. Do not run host
PHP, Composer development servers, queue workers, schedulers, or Vite servers
alongside the stack.

```bash
docker compose --env-file ../koma.docker.env up -d --build --wait
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan migrate:status
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan koma:doctor --mode=operational
```

Inspect PHP limits and extensions in `app`, because host PHP configuration is
not evidence for the Apache/PHP-FPM container. The production image omits
development dependencies; when PHP automation needs them, use or add an
ephemeral Docker test container rather than running host PHP.

## Configure stable secrets

Copy the example outside the application tree and restrict its permissions:

```bash
cp .env.docker.example ../koma.docker.env
chmod 600 ../koma.docker.env
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

Put that generated value in `KOMA_APP_KEY`, set a real `KOMA_APP_URL`, and set
unique `KOMA_DB_PASSWORD` and `KOMA_DB_ROOT_PASSWORD` values. KomaEngine
rejects blank/common placeholder database passwords and an invalid key. Keep
the key stable across every rebuild, restart, and recovery.

Leave the `KOMA_RELEASE_*` values at their documented development defaults when
you build from a source checkout. Release tooling must build from an immutable Git export,
not a dirty working directory, and set all five values together. Merely changing
the env-file values does not turn a working-tree Compose rebuild into a trusted
artifact.

## Start and install

```bash
docker compose --env-file ../koma.docker.env up -d --build --wait
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan koma:doctor --mode=bootstrap
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan koma:installer-token
```

Open the configured URL at `/install` over HTTPS and enter the server-generated
setup token. Do not place it in a URL or shared log. The database step is
already filled in for the Compose MariaDB service: driver MariaDB, host `db`,
port `3306`, and the database and user from the Docker env file. Enter only the
password, which is the `KOMA_DB_PASSWORD` value in that file. SQLite is not
offered under Docker Compose. After setup:

```bash
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan koma:doctor --mode=operational
```

`koma-storage` persists media, private retained imports, sessions, cache, and
logs. `koma-db` persists MariaDB. Recreating containers is safe only while both
volumes and the same application key are retained.

## Operations

```bash
docker compose --env-file ../koma.docker.env ps
docker compose --env-file ../koma.docker.env logs -f app worker scheduler
docker compose --env-file ../koma.docker.env --profile tools up -d adminer
```

The web healthcheck is bootstrap readiness, so a fresh container can expose the
installer. Operational readiness additionally requires `installed_at`. The
worker refuses to start unless `KOMA_QUEUE_WORKER_TIMEOUT` is lower than
`KOMA_DB_QUEUE_RETRY_AFTER`; the shipped values are 3,600 and 3,660 seconds.

Container uploads are configured for a 512 MB file and 520 MB POST body. Keep
`KOMA_SESSION_SECURE_COOKIE=true` behind HTTPS. Application logs go to container
stderr.

Every Compose service uses Docker's rotating `local` log driver instead of the
daemon default, which is often an unbounded `json-file`. Each container keeps
at most `KOMA_LOG_MAX_FILE` files (default 5) of `KOMA_LOG_MAX_SIZE` (default
`10m`); older output is discarded. A changed limit applies when Compose
recreates the container. Forward logs to your own collector if you need longer
history, and check the bound with `tests/Release/log-bounds-smoke.sh`.

Before and after debugging, verify that no host-side KomaEngine server, queue,
scheduler, or Vite process is competing with the Compose stack. A temporary
host process, if ever unavoidable, must be stopped as soon as the check ends.

## Safe update outline

1. Read the target release notes and [recovery contract](recovery.md).
2. Take and verify a consistent database, storage, secret, and release backup.
3. Build the exact target source/image without changing the running volumes.
4. Run `docker compose ... up -d --build --wait`; the one-shot migration must
   complete before the web, worker, or scheduler starts.
5. Run operational doctor and verify a reader image through its protected
   `/media/...` route. The migration container automatically moves and verifies
   media that early pre-release builds kept in `storage/app/public/media`
   before web startup.

Automated full-site backup creation/verification and guarded restore are
available for SQLite, MySQL, and MariaDB. MySQL/MariaDB restore requires a
separately provisioned empty target database and a mode-0600 client credentials
file; it never reuses the active database target. KomaEngine supplies these
commands; the backup schedule, the off-host copy, and a tested recovery are the
operator's, as described in [recovery](recovery.md).
