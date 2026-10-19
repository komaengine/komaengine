# Install on an Ubuntu server (VPS)

This guide installs the KomaEngine release ZIP on a plain Ubuntu server that you
control over SSH, with no hosting control panel. It sets up everything from an
empty server: Nginx, PHP, MariaDB, HTTPS, the web installer, and the background
jobs.

If your server has Plesk or cPanel, use the [Plesk](plesk.md) or
[cPanel](cpanel.md) guide instead. If you prefer containers, use the
[Docker guide](docker.md).

Plan on about 30 to 45 minutes. Every command is meant to be copied exactly;
the only values you change are the ones this guide tells you to replace.

## Before you start

You need:

- **A server running Ubuntu 24.04 LTS** with at least 1 GB of memory (2 GB is
  more comfortable) and enough disk for your manga. This guide was tested on a
  fresh Ubuntu 24.04 server. Ubuntu 22.04 is too old: its PHP 8.1 is not
  supported.
- **SSH access as a user that can run `sudo`.** Most VPS providers give you
  one when the server is created (often named `ubuntu`, or you log in as
  `root`).
- **A domain or subdomain** for the site, for example `manga.example.com`. In
  your DNS provider, create an `A` record that points the name to the server's
  public IPv4 address (and an `AAAA` record for IPv6 if the server has one).
  Do this first: the HTTPS certificate in step 6 needs it.
- **The release ZIP link and its SHA-256** from the
  [download page](https://komaengine.com/download/) or the
  [GitHub releases page](https://github.com/komaengine/komaengine/releases).

Throughout this guide, replace `manga.example.com` with your own domain.

### What gets installed where

```text
/srv/komaengine/
├── current -> releases/<release>/komaengine    the version the site runs
├── releases/
│   └── <release>/komaengine/                   one folder per installed ZIP
│       ├── .env                                settings and secret key (private)
│       ├── storage -> /srv/komaengine/shared/storage
│       └── public/                             the only folder Nginx serves
└── shared/
    └── storage/                                manga, uploads, logs (kept across updates)
```

Each release lives in its own folder, and your data lives in
`shared/storage`, outside every release. That makes updates and rollbacks safe:
a new version goes into a new folder and the old one stays untouched until the
new one works.

## 1. Connect and prepare the server

From your computer, connect to the server. Replace the user and address with
your own:

```bash
ssh ubuntu@manga.example.com
```

Bring the server up to date first:

```bash
sudo apt update
sudo apt full-upgrade
```

If the upgrade installed a new kernel, reboot (`sudo reboot`), wait a minute,
and connect again.

## 2. Install and check Nginx, PHP, and MariaDB

Install the web server, database, PHP, and the PHP extensions KomaEngine needs:

```bash
sudo apt update
sudo apt install nginx mariadb-server unzip ca-certificates \
  php php-cli php-fpm php-mysql php-gd php-zip php-mbstring \
  php-intl php-bcmath php-curl php-xml
```

Check that the PHP version is supported and every required extension is
loaded:

```bash
php -v
php -r '$ok = version_compare(PHP_VERSION, "8.3.0", ">=") && version_compare(PHP_VERSION, "8.6.0", "<"); fwrite(STDOUT, $ok ? "supported\n" : "unsupported\n"); exit($ok ? 0 : 1);'
php -r 'foreach (["pdo_mysql", "gd", "zip", "mbstring", "intl", "bcmath", "fileinfo"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "missing: $extension\n"); $missing = true; } } exit(isset($missing) ? 1 : 0);'
```

You should see the PHP version, then `supported`, and no `missing:` lines. Do
not continue until both checks pass.

### Raise the PHP upload limits

KomaEngine accepts archives of up to 500 MB, so PHP needs higher limits than
Ubuntu's defaults. Create a settings file for PHP-FPM. Ubuntu 24.04 ships PHP
8.3; if `php -v` showed another version, replace `8.3` in the next two commands:

```bash
sudo nano /etc/php/8.3/fpm/conf.d/99-komaengine.ini
```

Paste these lines, then save with `Ctrl+O`, `Enter`, and exit with `Ctrl+X`:

```ini
memory_limit=512M
upload_max_filesize=512M
post_max_size=520M
max_execution_time=3600
```

Copy the same settings to the command-line PHP:

```bash
sudo cp /etc/php/8.3/fpm/conf.d/99-komaengine.ini \
  /etc/php/8.3/cli/conf.d/99-komaengine.ini
```

Check which account PHP-FPM runs as, then restart PHP-FPM and Nginx. These
commands work out the PHP version for you:

```bash
KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo grep -E '^(user|group) =' "/etc/php/$KOMA_PHP_MM/fpm/pool.d/www.conf"
sudo systemctl restart "php$KOMA_PHP_MM-fpm" nginx
sudo systemctl --no-pager --full status "php$KOMA_PHP_MM-fpm" nginx
```

The first command should print `user = www-data` and `group = www-data`, and
both services should show `active (running)`. Press `q` if the status output
waits for you. If your server uses a different PHP-FPM account, use that
account everywhere this guide says `www-data`.

### Optional: turn on the firewall

If you use Ubuntu's firewall, allow SSH **before** enabling it so you do not
lock yourself out, then allow web traffic:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

If your SSH server listens on a port other than 22, allow that port instead of
`OpenSSH`. Many VPS providers also have their own firewall in their web
console; it must allow ports 80 and 443.

## 3. Create the database

Run MariaDB's hardening wizard first. Pressing `Enter` accepts its defaults,
which are the safe choices:

```bash
sudo mariadb-secure-installation
```

Then open the MariaDB prompt:

```bash
sudo mariadb
```

Choose a long random password for the KomaEngine database account and put it in
place of `USE_A_LONG_RANDOM_PASSWORD` below. One way to make one is to run
`openssl rand -hex 24` in another terminal. Keep the password somewhere safe:
you will enter it again in step 5 and in the web installer. Paste the SQL at
the MariaDB prompt:

```sql
CREATE DATABASE komaengine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'komaengine'@'localhost' IDENTIFIED BY 'USE_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON komaengine.* TO 'komaengine'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Each line should answer `Query OK`. KomaEngine uses only this account; never
give it the MariaDB `root` account.

## 4. Download, verify, and unpack the ZIP

On the [download page](https://komaengine.com/download/), copy the link to the
release ZIP and its SHA-256. Download the ZIP on the server into `/tmp`. Replace
the link with the one you copied:

```bash
cd /tmp
curl -fLO https://github.com/komaengine/komaengine/releases/download/vX.Y.Z/komaengine-YYYYMMDD-HHMMSS.zip
```

You can also download it on your computer and upload it with
`scp komaengine-YYYYMMDD-HHMMSS.zip ubuntu@manga.example.com:/tmp/`.

Now set two values: the ZIP's path on the server and the SHA-256 from the
download page. The other lines work out the folder names for you. Keep this SSH
session open until the end of step 5; the commands there use these values.

```bash
KOMA_ZIP=/tmp/komaengine-YYYYMMDD-HHMMSS.zip
KOMA_ZIP_SHA256=PASTE_THE_SHA256_HERE
KOMA_RELEASE_LABEL=$(basename "$KOMA_ZIP" .zip)
KOMA_RELEASE_ROOT=/srv/komaengine/releases/$KOMA_RELEASE_LABEL
KOMA_RELEASE=$KOMA_RELEASE_ROOT/komaengine
KOMA_SHARED=/srv/komaengine/shared
```

Check the download:

```bash
printf '%s  %s\n' "$KOMA_ZIP_SHA256" "$KOMA_ZIP" | sha256sum --check -
```

It must print the ZIP name followed by `OK`. If it prints `FAILED`, the
download is damaged or is not the file you meant: delete it and download it
again. Do not continue with a ZIP that fails this check.

Make sure this release has not been unpacked before:

```bash
sudo test -e "$KOMA_RELEASE_ROOT" && echo "STOP: $KOMA_RELEASE_ROOT already exists"
```

This prints nothing on a first install. If it prints `STOP`, this exact ZIP
was unpacked earlier; inspect that folder instead of overwriting it.

Unpack the ZIP and check every file inside it:

```bash
sudo install -d -m 0755 /srv/komaengine/releases "$KOMA_RELEASE_ROOT" "$KOMA_SHARED"
sudo unzip -q "$KOMA_ZIP" -d "$KOMA_RELEASE_ROOT"
cd "$KOMA_RELEASE"
sudo sha256sum --check --quiet KOMA-FILES.sha256 && echo "All packaged files OK"
sudo sed -n '1,20p' KOMA-BUILD-METADATA.txt
```

You should see `All packaged files OK`. Any line ending in `FAILED` means
stop. The build metadata should name the version you meant to install.

Confirm that the main parts of the package are present:

```bash
sudo test -f artisan
sudo test -f vendor/autoload.php
sudo test -f public/build/manifest.json
sudo test -f public/.htaccess
```

These commands print nothing when everything is in place. (`public/.htaccess`
is for Apache servers; Nginx ignores it and gets the same rules in step 6.)

## 5. Create the settings file and prepare storage

Move the package's empty `storage` folder to the shared location and link the
release to it. Create the settings file from the template and an empty
`robots.txt` for the installer to fill in:

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

`.env` holds the database password and the site's secret key. Mode `600` means
only the PHP account can read it; the installer refuses to run if it is looser.

Open the settings file:

```bash
sudo nano "$KOMA_RELEASE/.env"
```

Change these lines and leave the rest as they are. Use your domain and the
database password from step 3; put the password in double quotes:

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
SESSION_COOKIE=komaengine_session
CACHE_STORE=file
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3660
FILESYSTEM_DISK=public
MEDIA_DISK_DRIVER=local
```

Lines that are missing from the file, such as `SESSION_COOKIE` and
`MEDIA_DISK_DRIVER`, can go anywhere. `SESSION_COOKIE` keeps you signed in when
the installer finishes (see [known issues](README.md#known-issues-in-101)).
Leave `APP_KEY` empty; the next command fills it in. Save and exit
(`Ctrl+O`, `Enter`, `Ctrl+X`).

Now generate the site's secret key, link public files, prepare protected manga
storage, and run the health check, all as the PHP account:

```bash
cd "$KOMA_RELEASE"
sudo -u www-data php artisan key:generate --force
sudo ln -s "$KOMA_SHARED/storage/app/public" "$KOMA_RELEASE/public/storage"
sudo -u www-data php artisan koma:media:protect
sudo -u www-data php artisan koma:doctor --mode=bootstrap
```

Run `key:generate` only this once. A new key on an existing site signs everyone
out and makes encrypted data unreadable.

The health check prints one line per check. Before the web installer has run,
this is the expected result:

```text
app_key          OK
media_driver     OK
database         OK
migrations       FAIL
runtime_paths    OK
cache            OK
storage_link     OK
legacy_public_media_absent OK
```

`migrations FAIL` is normal at this point: the web installer creates the
database tables in step 7. Any other `FAIL` needs fixing first; see
[Troubleshooting](troubleshooting.md).

The installer saves your choices into `.env`, so for the duration of the
installation the PHP account must be able to write to the release folder. Grant
that temporarily (step 7 takes it away again) and point `current` at this
release:

```bash
sudo chown root:www-data "$KOMA_RELEASE"
sudo chmod 0770 "$KOMA_RELEASE"
sudo ln -sfn "$KOMA_RELEASE" /srv/komaengine/current
```

## 6. Configure Nginx and HTTPS

Find PHP-FPM's socket. It should match the PHP version from step 2:

```bash
KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
ls -l "/run/php/php$KOMA_PHP_MM-fpm.sock"
```

Create the site configuration:

```bash
sudo nano /etc/nginx/sites-available/komaengine
```

Paste the following. Replace both `manga.example.com` names with your domain
(drop the `www.` name if you do not use it), and replace `8.3` in
`fastcgi_pass` if the socket command showed another version:

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

What this does: Nginx serves only the `public/` folder, sends every page
request to KomaEngine's `index.php`, refuses to run any other PHP file, and
hides dot-files such as `.env`. `client_max_body_size` matches the PHP upload
limit.

Turn on the site, turn off Ubuntu's default page, check the configuration, and
reload Nginx:

```bash
sudo ln -s /etc/nginx/sites-available/komaengine /etc/nginx/sites-enabled/komaengine
sudo unlink /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

`nginx -t` must end with `test is successful`. If a link command says the file
already exists, look at what is there before changing it; do not delete a
configuration you do not recognize.

Get a free HTTPS certificate from Let's Encrypt. Certbot asks for an email
address for expiry notices and for agreement to the terms of service, then
updates the Nginx configuration and sets up automatic renewal:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d manga.example.com -d www.manga.example.com
```

Leave out `-d www.manga.example.com` if you have no `www` name. If Certbot
cannot verify the domain, check the DNS record from "Before you start" and that
ports 80 and 443 are open.

Open `http://manga.example.com/install` in a browser. It should switch to
`https://` with a valid padlock and show **Authorize this installer**. Do not
enter anything into the installer over plain HTTP.

## 7. Run the web installer

Print the setup token on the server:

```bash
cd /srv/komaengine/current
sudo -u www-data php artisan koma:installer-token
```

The token is a 64-character code derived from the site's secret key. Treat it
like a password: paste it straight into the installer and do not put it in a
URL, chat, ticket, or screenshot.

In the browser at `https://manga.example.com/install`:

| Screen | What to do |
|---|---|
| **Authorize this installer** | Paste the token and choose **Unlock setup**. |
| **Choose your language** | Pick the site language and choose **Continue**. |
| **Welcome to the five-minute install** | A list of checks. Every required row must show it is ready; if one does not, see [Troubleshooting](troubleshooting.md) and reload. Choose **Continue**. |
| **Database connection** | The values from `.env` are already filled in. Type the database password from step 3, choose **Test connection**, wait for **Connection successful.**, then **Continue**. |
| **Where should pages live?** | Keep **Local disk** and choose **Continue**. |
| **Set up your site** | Enter the site title, the address (`https://manga.example.com`), and your administrator name, email, and password. Choose **Install Koma Engine**. |
| **Koma Engine is installed** | Choose **Open dashboard**. |

The installer creates the database tables, your administrator account, and
`robots.txt`, then closes itself: the token no longer works and `/install`
cannot be used again.

Now take away the temporary write permission from step 5 and run the full
health check:

```bash
KOMA_RELEASE=$(readlink -f /srv/komaengine/current)
sudo chown root:root "$KOMA_RELEASE" "$KOMA_RELEASE/public/robots.txt"
sudo chmod 0755 "$KOMA_RELEASE"
sudo chmod 0644 "$KOMA_RELEASE/public/robots.txt"
cd "$KOMA_RELEASE"
sudo -u www-data php artisan koma:doctor --mode=operational
```

Every line, including `migrations` and `installed`, should now say `OK`. Keep
`.env` owned by `www-data` with mode `0600`, and never make the application or
storage world-writable (`chmod 777`).

## 8. Start the background jobs

KomaEngine needs two things running in the background: a **queue worker** that
processes archive imports, and the **scheduler**, which publishes scheduled
chapters every minute. The package includes a script that sets both up as
systemd services.

Preview what it will create, without changing anything:

```bash
cd /srv/komaengine/current
./scripts/install-ubuntu-services.sh \
  --app-root=/srv/komaengine/current \
  --dry-run
```

Check that the preview names the `www-data` account, the PHP program, and
`/srv/komaengine/current`. Then install and start the services:

```bash
cd /srv/komaengine/current
sudo ./scripts/install-ubuntu-services.sh \
  --app-root=/srv/komaengine/current
```

The script validates the services, runs the health check as `www-data`, starts
the worker and scheduler, runs the scheduler once, and confirms both are
active. It is safe to run again: unchanged services stay as they are, and it
refuses to overwrite a service file you have edited unless you add `--force`
(which keeps a timestamped backup). For more than one KomaEngine site on the
same server, give each a unique name with `--name=koma-library`. Run it with
`--help` for every option.

Check the result at any time:

```bash
sudo systemctl --no-pager --full status \
  komaengine-queue.service komaengine-scheduler.timer
sudo systemctl list-timers komaengine-scheduler.timer --no-pager
sudo journalctl \
  -u komaengine-queue.service \
  -u komaengine-scheduler.service \
  -n 50 --no-pager
```

## 9. Check that everything works

1. Sign in at `https://manga.example.com/login` and open the dashboard.
2. Create a small test series, upload a chapter, publish it, and read it on the
   public site. The pages should load.
3. Schedule a test chapter a few minutes ahead and confirm it publishes on its
   own (that proves the scheduler works).
4. Restart the services and repeat the sign-in and reading check:

   ```bash
   KOMA_PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
   sudo systemctl restart nginx "php$KOMA_PHP_MM-fpm"
   sudo systemctl restart komaengine-queue.service
   ```

Delete the test content when you are done.

## Next steps

- **Back up.** Set up regular full-site backups and keep a copy off the server.
  The [recovery guide](recovery.md) explains backup, verification, and
  restore.
- **Updates.** Never unpack a new ZIP over the running site. Stop the queue
  service and scheduler timer, take and verify a backup, unpack the new ZIP
  into its own folder under `releases/`, keep that package's empty `storage`
  under another name, link the new release to `/srv/komaengine/shared/storage`,
  copy the existing `.env` (mode `0600`), and follow
  [Update and rollback](shared-hosting.md#update-and-rollback). Repoint
  `current` and restart the services only after the new release passes its
  migration and health checks. The services use `/srv/komaengine/current`, so
  they do not need to be recreated for each release; restart the queue after
  switching `current` so it loads the new code.
- **Problems?** See [Troubleshooting](troubleshooting.md).
