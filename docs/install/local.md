# Local development

> This host-native setup is an alternative to the [Docker](docker.md)
> workflow that the [contributing guide](../../CONTRIBUTING.md) recommends. Do
> not run `composer run dev`, host PHP, a host queue worker, or Vite at the same
> time as a Docker Compose stack for the same checkout.

Use this path to run KomaEngine from a source checkout. For an operator-facing
deployment, use the [Docker](docker.md) or [shared-hosting](shared-hosting.md)
guide instead.

## Requirements

- PHP `>=8.3 <8.6` with GD, ZIP, PDO SQLite and/or PDO MySQL, mbstring, intl,
  bcmath, and fileinfo. EXIF and WebP support are recommended.
- Composer 2.
- Node.js 20 or newer and npm.
- SQLite, Oracle MySQL, or MariaDB.
- At least 512 MB PHP memory for the documented image limits.

## First run

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan storage:link
php artisan koma:installer-token
composer run dev
```

Open `http://127.0.0.1:8000/install` and enter the token printed by the server
command. The installer validates the application
key and extensions, performs a real create/write/read/drop database probe, runs
all migrations, seeds roles, creates the first Administrator, and records the
terminal install marker last.

For MySQL or MariaDB, update `DB_CONNECTION`, `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before opening the installer.
The database user must be able to create and drop tables, insert, select,
update, and delete.

## Day-to-day commands

`composer run dev` starts the development server, Vite, a queue listener, and
the local log viewer. Useful standalone checks are:

```bash
php artisan koma:doctor --mode=bootstrap
php artisan koma:doctor --mode=operational
php artisan test
npm run build
```

The web server document root is always `public/`. These paths must be writable
by the PHP user: `storage/`, its runtime subdirectories, and
`bootstrap/cache/`. Manga pages and covers live under protected
`storage/app/media` and are returned through authorization-aware `/media/...`
routes. The general `public/storage` link still targets `storage/app/public`.

Do not reuse an `APP_KEY` between sites or regenerate it after users or data
exist. A changed key invalidates sessions and encrypted data.

See [troubleshooting](troubleshooting.md) for common failures and
[recovery](recovery.md) before attempting an upgrade or rollback.
