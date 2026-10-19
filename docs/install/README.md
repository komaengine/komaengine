# Installing KomaEngine

Pick the guide that matches where your site will run. Each guide goes from an
empty account or server to a working site, one step at a time.

| Where you are installing | Guide |
|---|---|
| Hosting with **Plesk**. You manage the site under **Websites & Domains**, and its files are in a folder called `httpdocs`. | [Plesk](plesk.md) |
| Hosting with **cPanel**. You see tools such as **File Manager** and **MySQL Databases**, and your site's files are in `public_html`. | [cPanel](cpanel.md) |
| A server (VPS) running **Ubuntu**, reached over SSH, with no control panel. | [Ubuntu server](ubuntu-vps.md) |
| Your own server, and you are comfortable with **Docker**. | [Docker Compose](docker.md) |
| Another control panel (DirectAdmin, CyberPanel, a host's own panel) or a setup not listed here. | [Flat-file reference](shared-hosting.md) |
| You are working on KomaEngine's own code. | [Local development](local.md) |

Not sure which panel you have? Sign in to your hosting account and look at the
page title and logo. Plesk and cPanel both show their name on the sign-in page
and in the browser tab.

## What you need

The Plesk, cPanel, and Ubuntu guides install the **release ZIP**: a folder of
ready-to-run PHP files, with everything already built. You do not need
Composer, Node, or Git on the server. You need:

- **A domain or subdomain** for the site, for example `manga.example.com`.
- **PHP 8.3, 8.4, or 8.5** with these extensions: GD, ZIP, PDO MySQL,
  mbstring, intl, bcmath, and fileinfo. Most hosts enable all of them; the
  guides show how to check.
- **A MySQL or MariaDB database** and a database user. KomaEngine is tested with
  MariaDB 11.8 and MySQL 8.4.
- **Terminal access**: SSH, or the terminal built into the control panel.
  A few setup commands cannot be run any other way, so a host with no terminal
  at all cannot install KomaEngine from the ZIP.
- **Control of the document root**: you must be able to choose which folder the
  domain serves. KomaEngine's `public/` folder has to be that folder (see
  below).
- **Cron jobs** (scheduled tasks) that can run every minute, for scheduled
  chapters and archive imports.
- **HTTPS**. Every guide sets up a free Let's Encrypt certificate.

## Why the `public/` folder matters

The ZIP unpacks into one `komaengine` folder:

```text
komaengine/                 the application: private, never reachable from the web
├── .env                    your settings, database password, and secret key
├── app/  config/  vendor/  KomaEngine's code
├── storage/                manga pages, uploads, logs, and cache
└── public/                 the ONLY folder the web server may serve
    ├── index.php
    └── build/
```

The domain must point at `komaengine/public`, not at `komaengine`. Hosts
usually make the domain serve `httpdocs` or `public_html`; if you unpack the
ZIP into that folder unchanged, anyone could download your `.env` file with the
database password and the secret key. Each guide shows how to unpack the ZIP
**next to** the web folder and point the domain at `public/` instead. The
installer checks this and will not continue until the document root is
correct.

## Known issues in 1.0.1

Neither stops the installation, and the guides already include the
workarounds. Both are planned to be fixed in the next release.

- **The health check reports `migrations FAIL` before installation**
  ([#1](https://github.com/komaengine/komaengine/issues/1)). Before the web
  installer has run, `php artisan koma:doctor --mode=bootstrap` shows
  `migrations FAIL` because the database is still empty. If every other line
  is `OK`, continue: the installer creates the tables.
- **You are asked to sign in again right after installing**
  ([#2](https://github.com/komaengine/komaengine/issues/2)). Add
  `SESSION_COOKIE=komaengine_session` to `.env` before running the installer
  and this does not happen. If you have already installed without it, sign in
  once with the administrator email and password you just chose.

## After installing

- **Back up**: the [recovery guide](recovery.md) covers full-site backup,
  verification, and restore.
- **Update**: never unpack a new version over the running site. Follow
  [Update and rollback](shared-hosting.md#update-and-rollback).
- **Problems**: see [Troubleshooting](troubleshooting.md).
- **Check a download**: the [supply-chain guide](../SUPPLY_CHAIN.md) explains
  how to verify the ZIP's signature before installing.
