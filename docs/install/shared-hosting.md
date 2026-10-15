# Shared-hosting and flat-file installation

## Get the package

Download the release ZIP from the
[releases page](https://github.com/komaengine/komaengine/releases) and check its
SHA-256 and signature as the [supply-chain guide](../SUPPLY_CHAIN.md)
describes. Record the hash; the steps below verify the ZIP against it again on
the server.

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
bulk operations will then be unavailable.

## Install

1. Record the exact ZIP filename, SHA-256, KomaEngine version, PHP version,
   database engine/version, and host/control-panel name. Verify the ZIP hash
   before extracting it.
2. Create an empty database and database user in the hosting control panel. The
   account must be allowed to create/drop tables and read/write rows.
3. Upload and extract the package outside the public document root where
   possible. Point the domain document root to `<application>/public` exactly.
4. Copy `.env.example` to `.env`, set permissions to `0600`, and generate a
   unique key before the first HTTP request:

   ```bash
   chmod 600 .env
   php artisan key:generate --force
   ```

5. Set `APP_URL` and the database values in `.env`. Keep
   `MEDIA_DISK_DRIVER=local` if adding it explicitly.
6. Ensure the PHP user can create, read, and delete files under `storage/` and
   `bootstrap/cache/`, then create and verify the public link:

   ```bash
   php artisan storage:link --force
   php artisan koma:media:protect
   php artisan koma:doctor --mode=bootstrap
   ```

   The installer now names the first failing runtime path and reports an unsafe
   `.env` mode, wrong document root, or invalid/occupied storage link. Fix the
   named condition; do not grant world-write permissions.
7. Generate the setup token from the server console, open `/install` over
   HTTPS, enter the token, complete the database probe, and create the first
   Administrator:

   ```bash
   php artisan koma:installer-token
   ```

   The token is derived from the site's private `APP_KEY`; do not place it in a
   URL, log, screenshot, ticket, or chat. The installer revokes its browser
   grant and writes a durable completion marker when setup succeeds.
8. Run `php artisan koma:doctor --mode=operational`, sign in, publish a small
   test series/chapter, and read its page and media URL before adding real data.

Do not move `index.php` into `public_html` and improvise relative paths. Use a
host feature that changes the document root or a reviewed front-controller
layout supplied for that host. Serving the application root exposes files that
must remain private.

## Ubuntu Server walkthrough (Nginx, PHP-FPM, and MariaDB)

This walkthrough is for a clean Ubuntu server with `sudo` access, a domain
whose DNS already points to the server, and an exact prebuilt KomaEngine ZIP.
It uses a LEMP-style stack: Nginx, MariaDB, PHP-FPM, one durable storage
directory, and versioned release directories. (A traditional LAMP stack uses
Apache; this guide uses Nginx.) The ZIP already contains production Composer
dependencies and built browser assets; do not run Composer or npm on the
server.

Replace the example domain, ZIP name, expected SHA-256, database password, and
release directory name below. Run application commands from the directory that
contains `artisan`.

### 1. Install and verify the server packages

Use an Ubuntu release or PHP repository that supplies PHP 8.3, 8.4, or 8.5.
Ubuntu's unversioned `php-*` packages are used below, so verify the resulting
version rather than assuming it is supported.

```bash
sudo apt update
sudo apt install nginx mariadb-server unzip ca-certificates \
  php php-cli php-fpm php-mysql php-gd php-zip php-mbstring \
  php-intl php-bcmath php-curl php-xml

php -v
php -r '$ok = version_compare(PHP_VERSION, "8.3.0", ">=") && version_compare(PHP_VERSION, "8.6.0", "<"); fwrite(STDOUT, $ok ? "supported\n" : "unsupported\n"); exit($ok ? 0 : 1);'
php -r 'foreach (["pdo_mysql", "gd", "zip", "mbstring", "intl", "bcmath", "fileinfo"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "missing: $extension\n"); $missing = true; } } exit(isset($missing) ? 1 : 0);'
```

Do not continue until both PHP checks exit successfully. Optionally enable the
firewall only after confirming that the server's real SSH port remains allowed;
the web service needs TCP ports 80 and 443.

Set the PHP limits for the PHP-FPM and CLI SAPIs. Replace `8.3` if `php -v`
reported another supported minor version:

```bash
sudo nano /etc/php/8.3/fpm/conf.d/99-komaengine.ini
```

```ini
memory_limit=512M
upload_max_filesize=512M
post_max_size=520M
max_execution_time=3600
```

Save the file, then copy the same limits to the CLI configuration:

```bash
sudo cp /etc/php/8.3/fpm/conf.d/99-komaengine.ini \
  /etc/php/8.3/cli/conf.d/99-komaengine.ini
```

Verify that the PHP-FPM pool runs as `www-data`, then restart PHP-FPM and
Nginx. If `php -v` reported 8.4 or 8.5, the commands derive that service name
automatically:

```bash
KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo grep -E '^(user|group) =' "/etc/php/$KOMA_PHP_MM/fpm/pool.d/www.conf"
sudo systemctl restart "php$KOMA_PHP_MM-fpm" nginx
sudo systemctl --no-pager --full status "php$KOMA_PHP_MM-fpm" nginx
```

The pool should report `user = www-data` and `group = www-data`. If the server
uses a different pool account, substitute that account anywhere this guide
uses `www-data`.

### 2. Create the database

First run MariaDB's operating-system hardening wizard, then create a dedicated
database and account. Replace `USE_A_LONG_RANDOM_PASSWORD` before running the
SQL and use the same value only in the site's `.env` file.

```bash
sudo mariadb-secure-installation
sudo mariadb
```

At the MariaDB prompt:

```sql
CREATE DATABASE komaengine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'komaengine'@'localhost' IDENTIFIED BY 'USE_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON komaengine.* TO 'komaengine'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Do not use the MariaDB root account in KomaEngine.

### 3. Transfer, verify, and unpack the ZIP

On the trusted computer that downloaded or built the package, select the exact
ZIP, calculate its SHA-256, and copy it to a temporary non-public server
location. Replace the example SSH account and filename:

```bash
cd /path/to/KomaEngine
KOMA_LOCAL_ZIP=dist/komaengine-YYYYMMDD-HHMMSS.zip
shasum -a 256 "$KOMA_LOCAL_ZIP"
scp "$KOMA_LOCAL_ZIP" deploy@manga.example.com:/tmp/
```

Record the printed hash before connecting to the server. Do not copy `.env`, a
database, secret bundle, or development storage with the public package.

On the Ubuntu server, set the uploaded filename and recorded hash. The release
label is derived from the ZIP filename, so each differently named package gets
a separate directory:

```bash
KOMA_ZIP=/tmp/komaengine-YYYYMMDD-HHMMSS.zip
KOMA_ZIP_SHA256=PASTE_THE_RECORDED_SHA256_HERE
KOMA_RELEASE_LABEL=$(basename "$KOMA_ZIP" .zip)
KOMA_RELEASE_ROOT=/srv/komaengine/releases/$KOMA_RELEASE_LABEL
KOMA_RELEASE=$KOMA_RELEASE_ROOT/komaengine
KOMA_SHARED=/srv/komaengine/shared

printf '%s  %s\n' "$KOMA_ZIP_SHA256" "$KOMA_ZIP" | sha256sum --check -
if sudo test -e "$KOMA_RELEASE_ROOT"; then
  printf 'Release directory already exists: %s\n' "$KOMA_RELEASE_ROOT" >&2
  exit 1
fi
sudo install -d -m 0755 /srv/komaengine/releases "$KOMA_RELEASE_ROOT" "$KOMA_SHARED"
sudo unzip "$KOMA_ZIP" -d "$KOMA_RELEASE_ROOT"
cd "$KOMA_RELEASE"
sudo sha256sum --check KOMA-FILES.sha256
sudo sed -n '1,20p' KOMA-BUILD-METADATA.txt
```

Stop if the ZIP hash fails, any packaged file is not `OK`, or the build metadata
does not identify the release you intended to install. Confirm that these four
package paths exist before continuing:

```bash
sudo test -f artisan
sudo test -f vendor/autoload.php
sudo test -f public/build/manifest.json
sudo test -f public/.htaccess
```

The `.htaccess` file is included for Apache-based hosts. Nginx does not read it;
the equivalent routing and private-file protection are configured in step 5.

### 4. Create the durable layout and environment file

On the first install, move the package's storage skeleton to the durable
directory and link the release to it. Future releases must link to this same
directory; never replace it with the empty storage skeleton from a new ZIP.
The package does not contain `robots.txt`; create an empty one for the
installer to fill in.

```bash
sudo mv "$KOMA_RELEASE/storage" "$KOMA_SHARED/storage"
sudo ln -s "$KOMA_SHARED/storage" "$KOMA_RELEASE/storage"
sudo cp "$KOMA_RELEASE/.env.example" "$KOMA_RELEASE/.env"
sudo touch "$KOMA_RELEASE/public/robots.txt"
sudo chown -R www-data:www-data "$KOMA_SHARED/storage" "$KOMA_RELEASE/bootstrap/cache"
sudo chown www-data:www-data "$KOMA_RELEASE/.env" "$KOMA_RELEASE/public/robots.txt"
sudo chmod 600 "$KOMA_RELEASE/.env"
sudo chmod 0644 "$KOMA_RELEASE/public/robots.txt"
```

Edit `$KOMA_RELEASE/.env` as root:

```bash
sudo nano "$KOMA_RELEASE/.env"
```

At minimum, set these values; quote a value if it contains spaces or shell-like
punctuation:

```dotenv
APP_NAME="My Manga Library"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://manga.example.com

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=komaengine
DB_USERNAME=komaengine
DB_PASSWORD="USE_A_LONG_RANDOM_PASSWORD"

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3660
FILESYSTEM_DISK=public
MEDIA_DISK_DRIVER=local
```

Generate one application key, create the general public-storage link, prepare
protected manga storage, and verify the bootstrap state as the PHP-FPM user:

```bash
cd "$KOMA_RELEASE"
sudo -u www-data php artisan key:generate --force
sudo ln -s "$KOMA_SHARED/storage/app/public" "$KOMA_RELEASE/public/storage"
sudo -u www-data php artisan koma:media:protect
sudo -u www-data php artisan koma:doctor --mode=bootstrap
```

The web installer atomically updates `.env`, so the release root must be
temporarily writable by PHP-FPM during installation. Only the release root—not
the application tree—needs this temporary permission:

```bash
sudo chown root:www-data "$KOMA_RELEASE"
sudo chmod 0770 "$KOMA_RELEASE"
sudo ln -sfn "$KOMA_RELEASE" /srv/komaengine/current
```

### 5. Configure Nginx and HTTPS

Find the installed PHP-FPM socket. The result should match the supported PHP
minor version shown by `php -v`:

```bash
KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
ls -l "/run/php/php$KOMA_PHP_MM-fpm.sock"
```

Create the Nginx site file:

```bash
sudo nano /etc/nginx/sites-available/komaengine
```

Paste the following server block. Replace both example domain names and
replace `8.3` in `fastcgi_pass` when the socket command reported PHP 8.4 or
8.5:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name manga.example.com www.manga.example.com;

    root /srv/komaengine/current/public;
    index index.php;
    client_max_body_size 520M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ^~ /storage/ {
        try_files $uri =404;
    }

    # Defense in depth for media paths used by early pre-release builds. KomaEngine now serves manga
    # files through authorization-aware /media/... routes.
    location ^~ /storage/media/ {
        return 404;
    }

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/komaengine-access.log;
    error_log /var/log/nginx/komaengine-error.log;
}
```

Enable the site, disable only Ubuntu's default site link, validate the complete
configuration, and reload Nginx:

```bash
sudo ln -s /etc/nginx/sites-available/komaengine /etc/nginx/sites-enabled/komaengine
sudo unlink /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

If either link command reports that its target already exists, inspect the
existing link instead of overwriting or deleting an unknown configuration.

Obtain a valid TLS certificate before entering administrator credentials. One
common Ubuntu/Nginx option is Certbot:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d manga.example.com -d www.manga.example.com
```

Confirm that HTTP redirects to HTTPS and that the certificate name matches the
configured `APP_URL`.

### 6. Complete and lock down the installation

Generate the setup token in the server terminal, then open
`https://manga.example.com/install` and enter it:

```bash
cd /srv/komaengine/current
sudo -u www-data php artisan koma:installer-token
```

Do not send the token through a URL or retain it in shared notes. Confirm every
required preflight row is `OK`, test the MariaDB connection, and create the
first Administrator. Then
remove the temporary write permission from the release root and `robots.txt`:

```bash
KOMA_RELEASE=$(readlink -f /srv/komaengine/current)
sudo chown root:root "$KOMA_RELEASE" "$KOMA_RELEASE/public/robots.txt"
sudo chmod 0755 "$KOMA_RELEASE"
sudo chmod 0644 "$KOMA_RELEASE/public/robots.txt"
cd "$KOMA_RELEASE"
sudo -u www-data php artisan koma:doctor --mode=operational
```

Keep `.env` owned by `www-data`, mode `0600`. Do not make the application or
storage world-writable. Sign in and publish/read a small test chapter before
adding real content.

### 7. Install the queue and scheduler automatically

The package includes a guarded systemd installer. It generates the queue
worker, one-minute scheduler service, and scheduler timer; validates them with
`systemd-analyze`; runs operational doctor as the service account; enables the
units; starts them; runs the scheduler once; and confirms that the queue and
timer are active.

Preview the exact unit definitions without changing the server:

```bash
cd /srv/komaengine/current
./scripts/install-ubuntu-services.sh \
  --app-root=/srv/komaengine/current \
  --dry-run
```

If the preview identifies the expected `www-data` account, PHP binary, and
application path, install everything with one command:

```bash
cd /srv/komaengine/current
sudo ./scripts/install-ubuntu-services.sh \
  --app-root=/srv/komaengine/current
```

The command is safe to rerun: identical units remain unchanged. It refuses to
overwrite a modified unit. If you intentionally want to replace a modified
KomaEngine unit, inspect the difference first and add `--force`; the installer
creates a timestamped backup beside the unit before replacing it.

For multiple KomaEngine sites on one server, give every site a unique safe unit
prefix, for example `--name=koma-library`. If the PHP-FPM account or CLI binary
differs from Ubuntu's defaults, pass `--service-user=ACCOUNT` or
`--php-bin=/absolute/path/to/php`. Run `--help` to see the complete interface.

Inspect the automated result:

```bash
sudo systemctl --no-pager --full status \
  komaengine-queue.service komaengine-scheduler.timer
sudo systemctl list-timers komaengine-scheduler.timer --no-pager
sudo journalctl \
  -u komaengine-queue.service \
  -u komaengine-scheduler.service \
  -n 50 --no-pager
```

Hosts without root access or systemd cannot use this installer. Use the
provider's process manager and cron controls with the commands in **Queue and
scheduler** below.

For the human acceptance gate, prove that an archive-import queue job completes
once and a scheduled chapter publishes once. Restart Nginx and PHP-FPM
afterward and repeat the login, reader, and media checks:

```bash
KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo systemctl restart nginx "php$KOMA_PHP_MM-fpm"
sudo systemctl restart komaengine-queue.service
```

On this layout, stop the queue service and scheduler timer before a backup,
upgrade, or rollback. Extract an update into a new release directory, retain
the new package's storage skeleton under another name, link the new release to
`/srv/komaengine/shared/storage`, copy the existing mode-`0600` `.env`, and
follow **Update and rollback** below. Do not repoint `current` or restart the
services until the new release passes its migration and health checks. Because
the units use `/srv/komaengine/current`, they do not need to be recreated for
each release; restart the queue after switching `current` so it loads the new
code.

## Queue and scheduler

Archive imports need a queue worker and scheduled chapters need Laravel's
scheduler. A process supervisor is preferred:

```bash
php artisan queue:work database --queue=archive-imports,default \
  --tries=1 --timeout=3600 --sleep=3
```

Run the scheduler every minute:

```cron
* * * * * cd /absolute/path/to/komaengine && php artisan schedule:run >> /dev/null 2>&1
```

On cron-only hosting, ask the provider whether a 3,600-second PHP job is
allowed and use its documented non-overlapping worker recipe. A host that kills
long workers cannot reliably process maximum-size imports.

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
   the release directory is owned by root, as in the Ubuntu walkthrough, the
   PHP user cannot replace that link and the command stops with
   `symlink(): File exists`; create the link with `sudo ln -s` as that
   walkthrough's step 4 does, and skip `storage:link`. The
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
