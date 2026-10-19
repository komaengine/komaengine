# KomaEngine

KomaEngine is a self-hosted publishing platform for manga and webcomics. It
gives creators, publishers, studios, archives, and authorized translation teams
a reader-friendly public site and an admin for publishing series and chapters,
on their own server and under their own control.

KomaEngine Community Edition is free and open source under the
[MIT License](LICENSE). Website: [komaengine.com](https://komaengine.com).

## What it does

- A public catalog and reader with right-to-left, left-to-right, and vertical
  reading, four built-in themes, and comments with moderation.
- An admin for series, chapters, pages, genres and tags, users and roles,
  settings, and API tokens.
- Chapter publishing from the browser or a token-authenticated bulk API, with
  scheduled releases.
- Local image processing. Manga media is kept in protected storage and served
  through routes that respect visibility and mature-content settings.
- Portable series archives (Koma Archive v2, with v1 still importable) for
  moving series between sites, with queued, retryable imports.
- Theme and plugin extension points, including optional support links and
  plugin-rendered header and footer slots.
- A web installer protected by a setup token generated on the server, and an
  operational health check, `koma:doctor`.
- Full-site backup, independent verification, and guarded restore for SQLite,
  MySQL, and MariaDB.

## Who it is for

KomaEngine is for authorized publishing. Each operator is responsible for
confirming that they may publish their works and for supplying their own public
contact and policy information under **Settings → Privacy & Legal**. KomaEngine
does not determine publication rights or an operator's legal obligations.

## Install

Start at [Installing KomaEngine](docs/install/README.md), which helps you pick
a guide and lists what you need.

| Path | Use it when |
|---|---|
| [Plesk](docs/install/plesk.md) | Your hosting uses Plesk. Upload the release ZIP. |
| [cPanel](docs/install/cpanel.md) | Your hosting uses cPanel. Upload the release ZIP. |
| [Ubuntu server](docs/install/ubuntu-vps.md) | You have a VPS with Ubuntu and SSH, and no control panel. |
| [Docker Compose](docs/install/docker.md) | You run your own server. Includes MariaDB, a queue worker, and the scheduler. |
| [Flat-file reference](docs/install/shared-hosting.md) | Another control panel, or you want the detail behind each step. |
| [Local development](docs/install/local.md) | You are working on KomaEngine itself on your own machine. |

Requirements: PHP 8.3, 8.4, or 8.5 with GD, ZIP, PDO, mbstring, intl, bcmath,
and fileinfo; SQLite, MySQL, or MariaDB; and local disk for media. If something
goes wrong, start with [Troubleshooting](docs/install/troubleshooting.md). Back
up your site as described in the [recovery guide](docs/install/recovery.md).

## Verify a download

Every release ZIP and container image is built from an exact Git commit and
signed, with a software bill of materials and build provenance. The
[supply-chain guide](docs/SUPPLY_CHAIN.md) explains how to check a download
before you install it.

## Extend it

- [Theme development](docs/theme-development.md)
- [Plugin development](docs/plugin-development.md)
- [Bulk chapter API](docs/bulk-upload-api.md)
- [Koma Archive v2](docs/koma-archive-v2.md) and the
  [v1 compatibility contract](docs/koma-archive-v1.md)
- [Full-site backup format](docs/koma-site-backup-v1.md)
- [Image processing](docs/image-processing.md)
- [Operations and recovery policy](docs/OPERATIONS_RECOVERY_POLICY.md)

## Privacy

The core does not collect, store, send, or display analytics data, and the
server makes no outbound requests of its own. Visitors' browsers do make one
kind: the admin, installer, and built-in themes load their fonts from Google
Fonts. The core provides no payment, paywall, advertising, or object-storage
service. Plugins that add remote scripts, advertising, analytics, or other
providers own those integrations and their data, retention, consent, and
third-party terms.

## Pro and Koma Cloud

Neither exists yet. If first-party self-hosted Pro modules are introduced, they
will be distributed separately from the MIT Community Edition under separate
commercial terms; candidate areas are payment and premium access, advanced
advertising and analytics, storage and CDN integration, advanced workflow, and
content-protection features. Koma Cloud, a possible managed service for
operators who do not want to run their own stack, is likewise only a plan.
Community Edition must remain fully usable without a license service,
telemetry, or a managed service.

## Releases

What each release contains, and what it knowingly leaves out, is in the
[changelog](CHANGELOG.md). Security fixes are made for the latest release.

## Contributing and security

Contributions are welcome; read [CONTRIBUTING.md](CONTRIBUTING.md) first, and
please follow the [code of conduct](CODE_OF_CONDUCT.md). To report a
vulnerability, do not open an issue: follow the [security policy](SECURITY.md).
