# Changelog

Notable changes to KomaEngine Community Edition. The version comes from the
root `VERSION` file, and every build also records its exact source commit.

## 1.0.0

The first public release of Community Edition. It is the code accepted as
`1.0.0-rc.2`, plus the changes below.

### Changed

- Under Docker Compose the installer no longer offers SQLite, which the Compose
  stack does not support, and says that the database password is the
  `KOMA_DB_PASSWORD` value in the Docker env file. Elsewhere, an SQLite
  location the web server cannot write to is explained before you submit.
- A failed database test now names the problem: rejected credentials, an
  unreachable host, or a missing or inaccessible database.

### Fixed

- A database connection that failed during installation was reported as a
  failure of the installer's cleanup step instead of the real error.
- The release source manifest required planning files that only exist in the
  maintainers' private repository.

### Added

- A contributing guide, a code of conduct, issue and pull request templates,
  and a security policy for private reporting through GitHub.
- `scripts/test-in-docker.sh`, which runs the PHP suite or Pint in a
  disposable container without PHP on the host.

## 1.0.0-rc.2 — release candidate, not published

Replaces `1.0.0-rc.1`. Application code is unchanged; the container image is
rebuilt on a patched base. It was accepted as a candidate on October 13,
2026, and a test run of the signing workflow passed on its commit for both
architectures. It is not published as a release, and it is not `1.0.0`.

### Security

- The container image now builds on PHP 8.4.26, whose base image ships fixed
  Debian packages for seven Perl CVEs, three of them critical, and one high
  `libpcre2` CVE. The `1.0.0-rc.1` image carried the unfixed packages. The
  flat-file ZIP contains no system packages and was never affected.

### Changed

- The PHP compatibility test images move to 8.3.35, 8.4.26, and 8.5.11.
- A local release check now applies the same image vulnerability gate as the
  signing workflow, so a stale base image is caught before a candidate is
  frozen instead of after it is accepted.

### Known limits

- Everything listed under `1.0.0-rc.1` still applies.
- The vulnerability gate fails only on critical and high findings that have a
  fix available, and scans only the application image. The database and
  Adminer images used by Docker Compose are pinned but not scanned.
- Pinned images do not receive distribution security updates. A candidate
  that passes the gate today will fail it once its packages are fixed
  upstream, until the pins are moved again.

## 1.0.0-rc.1 — superseded, never published

The first release candidate of Community Edition, accepted on October 13,
2026. It was replaced by `1.0.0-rc.2` after a vulnerability scan
found fixable critical and high findings in its container image, and it was
never signed or published. There is no earlier published release, so upgrade
evidence is limited to pre-release predecessor compatibility.

### What Community Edition includes

- A public catalog and reader with four built-in themes, right-to-left,
  left-to-right, and vertical reading, and comments with moderation.
- A Livewire admin for series, chapters, pages, taxonomy, users, settings, and
  API tokens.
- Bulk chapter publishing in the browser and through a token-authenticated
  API, and scheduled releases.
- Local image processing, with manga media held in protected storage and
  served through authorization-aware routes.
- Portable series archives: Koma Archive v2 by default, v1 still importable,
  with queued, retryable imports and explicit conflict policies.
- Plugin and theme extension points, including gallery archive adapters.
- A web installer guarded by a server-generated setup token, and an
  operational doctor.
- Full-site backup, independent verification, and guarded restore for SQLite,
  MySQL, and MariaDB.
- Docker Compose and flat-file ZIP packaging for PHP 8.3, 8.4, and 8.5.
- Release tooling: exact-commit packages, pinned images, SBOM, provenance, and
  tamper checks.

### Added since the previous recorded state

- Optional multi-file archive intake that a plugin can enable. It is off by
  default and Community ships no plugin that turns it on.
- Limits on how many genres, tags, chapters, and media files one archive
  manifest may describe, applied to import and export alike.
- `koma:archive-temp:clean`, a preview-first command for staging directories
  left by a killed import or export.
- Bounded logs: rotated container output in Docker Compose and daily,
  14-day file logs in the flat-file template.
- An operator [recovery policy](docs/OPERATIONS_RECOVERY_POLICY.md).

### Fixed

- The backup command refused to run on the documented Ubuntu layout, where
  release storage is a link to shared storage.
- An import interrupted after its content committed could be imported a second
  time; a rerun now adopts the committed result.
- A retry whose queue dispatch failed left the import queued with no job.
- A restore that failed after loading the database did not say the database
  was now populated, and staging left by a killed restore was not detected.
- A restored site had a dangling `public/storage` link, which the doctor
  reported as healthy.
- The Ubuntu install and update steps referred to a `robots.txt` no package
  contains and to a link command the web user cannot run.

### Security

- Hardened the staging cleaner against a workspace swapped for a link, kept
  media when a failure occurs at commit, stopped a queue timeout from adopting
  an uncommitted import, and corrected a restore error path. An independent
  review of all code changed since the pre-release security audit found no
  critical or high issue.

### Known limits

- KomaEngine creates, verifies, and restores backups on command. It does not
  schedule them, copy them off the server, or retire old ones.
- The one real cross-machine recovery drill used a 461 KB site with no manga
  media, so there is no production-scale recovery timing.
- A process killed mid-import or mid-upload can leave unreferenced media or
  uploaded files on disk.
- The built-in themes load fonts from Google Fonts.
- Media storage is local only.
