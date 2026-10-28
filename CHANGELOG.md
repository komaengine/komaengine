# Changelog

Notable changes to KomaEngine Community Edition. The version comes from the
root `VERSION` file, and every build also records its exact source commit.

## 1.1.0

Fixes for the four issues reported against `1.0.1` and for archive imports,
plus bulk actions, one-command setup, a single cron job for flat-file sites,
a custom logo, a system health page, and an optional update notice.

### Security

- Livewire 4.4.8 fixes advisory GHSA-mhf7-xp6r-6qrc, an authorization bypass
  involving the `#[Authorize]` and `#[Json]` attributes. KomaEngine's own
  components use neither; plugin components could.
- The container image runs PHP 8.4.27 (8.4.26 before), a PHP security
  release, and is built with Node 22.23.4 and Composer 2.9.8, both security
  releases. The flat-file ZIP runs on the host's own PHP.
- Build tools are updated for new advisories: Vite 6.4.5 and
  `follow-redirects` 1.16.2, which axios uses only under Node. Neither ships
  in the package or the image.

### Fixed

- Imported gallery-style archives, including those read by plugin adapters,
  now follow the site's **Default visibility** and **Default status on
  upload** settings instead of always arriving private with draft chapters.
- Enabling or disabling a plugin now tells running queue workers to restart,
  so archive imports use the new plugin list straight away. Before, a worker
  started earlier kept the old list, and gallery archives for a newly enabled
  plugin failed with "unsupported keys".
- In the narrow admin layout (menu opened with the ☰ button), hovering a
  section with a mouse pops its sub-menu out beside the menu, as the wide
  sidebar does. On touch screens the first tap expands the section in place,
  and an arrow button does the same for keyboard users.
- Before the web installer has run, `koma:doctor --mode=bootstrap` reports
  `migrations PENDING` and succeeds on a reachable, empty database instead of
  failing ([#1](https://github.com/komaengine/komaengine/issues/1)). The JSON
  output adds `"pending": ["migrations"]`. A partially migrated database
  still fails.
- The administrator stays signed in when the web installer finishes on a
  flat-file install ([#2](https://github.com/komaengine/komaengine/issues/2)).
  The session cookie is now `komaengine_session` and the cache prefix
  `komaengine_cache_`, no longer derived from the site title; `SESSION_COOKIE`
  and `CACHE_PREFIX` still override them.
- Deleting a chapter from the series editor now checks the **edit series**
  permission, deletes the chapter, its pages, and its comments together, and
  removes the page files only after that succeeds. Before, files were removed
  first and the chapter's comments were left behind. Publishing, scheduling,
  and cancelling a schedule from the editor also check the permission.
- Chapter messages in the series editor, and series messages in the series
  list, now appear on the page. Before, they showed on the next page opened.
- Text that was too faint now meets the WCAG AA contrast minimum: the theme
  label in Kasai's top strip and its ranking numbers, the rank numbers on
  Sumi's popular cards, and the link in Tankōbon's mature-titles notice.
- A very long series title in Aozora's latest updates wraps instead of
  running off the side of the home page. On phones, Kasai's faded spotlight
  cover no longer overlaps the section below it.
- The sign-in and registration fields are linked to their labels, so screen
  readers announce what each field is for.
- Release provenance names the public source repository,
  `https://github.com/komaengine/komaengine`, and the build type
  `https://komaengine.com/build-types/community-release/v1`
  ([#4](https://github.com/komaengine/komaengine/issues/4)). 1.0.x provenance
  listed an internal repository URI beside the correct commit; its commit,
  hashes, and signatures are unaffected. The supply-chain verifier now checks
  the source repository as well as the commit. The unused scaffold view
  `welcome.blade.php`, which also named that repository, is removed.

### Added

- The archive import form has a **Publishing** choice, stored with each import
  and kept on retry: **Automatic** (Koma Archives keep their own visibility
  and chapter states; gallery-style archives use the site defaults), **Use
  site defaults**, or **Private drafts**.
- `php artisan koma:setup` prepares a flat-file install in one step and is
  safe to rerun: it sets `.env` to mode `0600` (creating it from
  `.env.example` if missing), generates `APP_KEY` only when there is no valid
  key, links `public/storage` with a relative link, prepares protected media
  storage, runs the bootstrap readiness checks, and names the next command.
  `--json` gives machine-readable output.
- The series editor's chapter list has a checkbox per chapter, **Select
  all**, and **Publish**, **Unpublish**, and **Delete** for the selection.
  Publish publishes drafts and scheduled chapters now; Unpublish returns
  chapters to draft and cancels any schedule.
- The series list has a checkbox per series, select-all for the current page,
  and **Make public**, **Make private**, and **Delete** for the selection.
  Making a series public does not publish its chapters; the message says how
  many stay hidden until they have a published chapter. Changing a filter or
  page clears the selection.
- **Settings → Branding** lets administrators upload their own logo (PNG,
  JPEG, or WebP, scaled to fit 512 × 256 pixels, transparency kept) and choose
  **Logo and site title** or **Logo only**. Every built-in theme shows it in
  place of its own mark in the header and footer, as do the admin bar and the
  sign-in page. An optional site icon replaces the browser-tab icon. Both are
  stored with the rest of the media, so site backups include them. Themes can
  use `Koma::logo()` and `<x-site-icon />`; see the theme development guide.
- A flat-file site needs one cron job, `php artisan schedule:run` every
  minute. With `KOMA_QUEUE_VIA_SCHEDULER=true`, which the flat-file `.env`
  template now sets, the scheduler also starts a queue worker in the
  background that processes archive imports and exits when the queue is
  empty; a lock keeps a second one from starting while an import runs. Docker
  Compose and the Ubuntu systemd services keep their own worker and leave it
  off.
- **Health** in the admin menu (administrators only) runs the `koma:doctor`
  checks with a plain-language fix for each failure, and shows when the
  scheduler last ran and the queue worker last finished a job (with a warning
  after five minutes without a scheduler run, or with a job left waiting),
  waiting and failed jobs, PHP's memory, upload, and execution-time limits
  against the recommended values, free disk space, the release, and the cron
  command for the site, ready to copy. The page only reads; it changes nothing.
  The scheduler and queue workers record their last activity for it.
- An optional update notice. It is off until an administrator turns it on
  under **Updates** or ticks the new installer checkbox. When on, the
  scheduler fetches one public file, `https://komaengine.com/releases/latest.json`,
  at most once a day, with no query string, cookies, or site details, and
  compares versions locally; administrators then see newer stable releases on
  the dashboard and the Updates page, linked to the release notes and the
  update guide. A failed fetch is silent, and the page says when the last one
  worked. `KOMA_UPDATE_CHECK=false` keeps checks off whatever the setting.
  KomaEngine never updates itself.
- Docker Compose can run a published image instead of building the checkout
  ([#3](https://github.com/komaengine/komaengine/issues/3)): set
  `KOMA_APP_IMAGE=ghcr.io/komaengine/komaengine:<version>` in the Docker env
  file, then `docker compose pull` and `up -d --no-build --wait`. Unset, Compose
  builds `komaengine:local` exactly as before.

### Changed

- The container image no longer carries the compilers, header packages, and
  kernel headers (`linux-libc-dev`) used only to build PHP's extensions; it is
  about 130 MB smaller and drops their scanner findings. Both release builds
  now fail if Composer installs any package from a Git checkout instead of
  its published dist archive.
- The container image installs Debian's published updates when it is built,
  so a Debian security fix no longer waits for the upstream PHP image to be
  rebuilt.
- The PHP compatibility test images move to 8.3.36, 8.4.27, and 8.5.12.

### Upgrade notes

- On a flat-file site whose site title is not "KomaEngine", the session cookie
  changes name, so everyone is signed out once after upgrading. A site that
  uses a database, Redis, or Memcached cache store also starts with an empty
  cache; the default file store is unaffected. Docker installs keep the same
  names.
- An existing flat-file `.env` has no `KOMA_QUEUE_VIA_SCHEDULER` line, so the
  option stays off and both existing cron jobs keep working. To move to one
  job, add `KOMA_QUEUE_VIA_SCHEDULER=true` and delete the `queue:work` job; see
  the shared-hosting guide's Queue and scheduler section.
- Update checks stay off on an upgraded site until an administrator turns
  them on under **Updates**. `KOMA_UPDATE_CHECK=false` in `.env` keeps them
  off regardless.
- One new migration adds the archive import's **Publishing** choice; the
  documented update steps run it. An import started before the upgrade and
  retried afterwards uses **Automatic**.
- A Docker env file copied from an earlier `.env.docker.example` sets
  `KOMA_RELEASE_VERSION=1.0.1`, and the image build stops because it no
  longer matches `VERSION`. Set it to `1.1.0` or delete the line.

### Documentation

- Step-by-step installation guides for Plesk, cPanel, and Ubuntu servers, and
  a starting page that helps choose one and lists what each install needs.
- The shared-hosting guide is now the flat-file reference for other hosts,
  with a table of `.env` settings and the cron job for the scheduler and
  queue worker.
- Troubleshooting lists common hosting errors and how to fix them.

## 1.0.1

The first published release of Community Edition: `1.0.0` with dependency
security updates.

### Security

- `symfony/http-foundation` 7.4.21 fixes CVE-2026-108082, a regular-expression
  denial of service through the `Cache-Control` request header.
- `axios` 1.20.0, which is bundled into the application's scripts, fixes the
  advisories published for earlier versions since `1.0.0` was prepared.
- Development and build tools are updated for new advisories:
  `symfony/yaml` 7.4.21, `concurrently` 9.2.5, `shell-quote` 1.12.0, and
  `source-map-js` 1.2.2. None of them ships in the package or the image.

### Changed

- The release workflow audits the locked production dependencies before it
  builds, pushes, or signs anything, so a newly published advisory stops a
  release before it starts.

## 1.0.0 — tagged, not released

Tagged on October 15, 2026, but not published: the release workflow stopped
when a newly published advisory for `symfony/http-foundation` failed its
dependency audit. Its container image was built and signed but never made
public. Use `1.0.1`, which contains everything below.

It is the code accepted as `1.0.0-rc.2`, plus the changes below.

### Changed

- Under Docker Compose the installer no longer offers SQLite, which the Compose
  stack does not support, and says that the database password is the
  `KOMA_DB_PASSWORD` value in the Docker env file. Elsewhere, an SQLite
  location the web server cannot write to is explained before you submit.
- A failed database test now names the problem: rejected credentials, an
  unreachable host, or a missing or inaccessible database.

### Fixed

- Imported gallery-style archives, including those read by plugin adapters,
  now follow the site's **Default visibility** and **Default status on
  upload** settings instead of always arriving private with draft chapters.

- A database connection that failed during installation was reported as a
  failure of the installer's cleanup step instead of the real error.
- The release source manifest required planning files that only exist in the
  maintainers' private repository.

### Added

- The archive import form has a **Publishing** choice, stored with each import
  and kept on retry: **Automatic** (Koma Archives keep their own visibility
  and chapter states; gallery-style archives use the site defaults), **Use
  site defaults**, or **Private drafts**.

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

- Imported gallery-style archives, including those read by plugin adapters,
  now follow the site's **Default visibility** and **Default status on
  upload** settings instead of always arriving private with draft chapters.

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
