# Backup and recovery contract

This document locks the inventory and consistency rules for full-site
backup/restore tooling. The versioned on-disk contract is
[`koma-site-backup-v1`](../koma-site-backup-v1.md). SQLite/MySQL/MariaDB
capture/verification and guarded SQLite/MySQL/MariaDB restore into isolated
targets are implemented, but this is not a claim that automated recovery is
complete.

## Required backup inventory

A recoverable site backup must contain all of the following from one named
release and one consistent capture window:

1. A transactionally consistent SQLite file or MySQL/MariaDB logical/physical
   database backup, including migration and settings tables.
2. Protected manga media from `storage/app/media/` with paths, sizes, and
   cryptographic hashes. The v1 archive retains its historical logical
   `storage/public/media/` name for compatibility, but restores those files
   into protected storage.
3. Required private retained inputs under `storage/app/private/`, including
   archive sources still expected to be retryable.
4. The exact KomaEngine release identity, `composer.lock`, enabled plugin/theme
   code and manifests, and any operator-owned theme/plugin assets.
5. The environment/secret set, especially `APP_KEY`, database credentials,
   mail credentials, and deployment-specific configuration. Secrets must be
   encrypted separately from public artifacts.
6. A versioned inventory manifest recording capture time, database
   engine/version, application/release identity, file hashes, and backup-tool
   result.

Caches, sessions, logs, temporary import workspaces, `vendor/`, and compiled
assets may be omitted only when the documented restore process recreates them
from the exact release. Retained archive sources are not temporary when the UI
promises that they can be retried.

## Consistency rules

- Quiesce writes for the capture: enter maintenance/read-only mode and stop
  queue workers and the scheduler before coupling the database and filesystem
  snapshots.
- Do not copy a live SQLite file with an ordinary file copy. Use SQLite's
  online backup mechanism or stop every writer first.
- For MySQL/MariaDB, use a transactionally consistent dump/snapshot appropriate
  to the table engine, then capture media within the same quiesced window.
- A backup is incomplete until its manifest and hashes verify from separate
  storage. A command exit code alone is insufficient.
- Keep at least one recovery copy outside the application host and its primary
  storage account.

## Backup-format and command boundary

The v1 format is a self-verifying directory, not a Koma Archive or a monolithic
ZIP. It captures a consistent database artifact, protected media, retryable
private inputs, `composer.lock`, enabled extension code/manifests/assets, release
identity, an operator-supplied encrypted secret bundle, and a complete streamed
size/SHA-256 inventory. The precise layout, schema, command contract, and
security rules are in [`koma-site-backup-v1.md`](../koma-site-backup-v1.md).

The backup command creates into a sibling staging directory, validates all
artifacts, then atomically publishes the final backup. The restore command
likewise stages filesystem inventory into a sibling, validates the complete
backup before writing, then atomically publishes only a new isolated target. It
rejects existing, unsafe, in-project, active configured, backup-contained, and
symlinked targets. MySQL/MariaDB additionally use a mode-0600, option-allowlisted
target client file, a named empty target database, and a declared source/target
endpoint comparison before importing the logical dump. Imports force binary
mode, disable local infile, and should use an account limited to the empty
target database. Verify and default restore
inspection are non-mutating; restore requires explicit `--force` and never runs
migrations.

## Restore order and acceptance

1. Restore the exact application/plugin/theme release and stable secrets.
2. Restore the database and media/private inventories without running new
   migrations against an older backup.
3. Restore ownership/permissions and the exact `public/storage` link.
4. Clear/rebuild disposable caches and compiled assets.
5. Start the matching release's web, worker, and scheduler services. Run any
   upgrade migrations only as a separate reviewed action after recovery is
   healthy; omit the Compose migration service during restore.
6. Require operational doctor success, administrator authentication, catalog
   access, a reader response, sampled media hash/HTTP checks, queue execution,
   and scheduler execution before reopening writes.

Rollback means restoring the matching pre-upgrade code, database, media, and
secret set. Replacing only code or only the database is not a valid rollback.

## When a restore is interrupted

The backup is never modified, so every recovery below starts from it again.

- **Before the database import starts:** nothing was published and the staging
  copy is removed. Correct the reported problem and run the restore again.
- **During or after a MySQL/MariaDB import, before publication:** the command
  says whether the target database may be partly populated or was fully
  restored. Either way no site was published and that database has no matching
  files. Do not reuse or hand-edit it, and never point a retry at the active
  database. Provision a new empty database and run the restore again; a retry
  against the populated one is refused before anything is written.
- **Killed outright:** the restore cannot remove its own
  `.<target>.restore-staging-<id>` directory beside the target. The next
  inspection or restore reports it and stops. It is a private copy of backup
  content, so confirm no restore is still running, remove it yourself, and
  provision a new empty database if one was being loaded.

There is no resume: an interrupted restore is always repeated in full from the
verified backup into a new target and, for MySQL/MariaDB, a new empty database.
SQLite restores publish the database with the rest of the filesystem in one
atomic step and have no partly loaded state.

## Koma Archive boundary

Koma Archive v2 is the default portable series export/import; v1 remains a
first-production compatibility option. Portable archives intentionally exclude
accounts, roles, site settings, credentials, comments, reports, plugins,
themes, and deployment state. It is not a full-site disaster-recovery backup.

## What KomaEngine provides

The [operations recommendations](../OPERATIONS_RECOVERY_POLICY.md) suggest a
backup frequency, how many copies to keep, an off-host receiver, key custody,
and recovery and data-loss goals. KomaEngine does not schedule or enforce any
of them.

SQLite, MySQL, and MariaDB `koma:site-backup:create` and
`koma:site-backup:verify` create and independently verify the v1 directory in
an operator-selected path. SQLite uses an online snapshot; MySQL and MariaDB
require InnoDB tables and use a transactionally consistent logical dump with a
temporary, non-logged credentials file.

SQLite `koma:site-backup:restore --target-root=<new-isolated-root>` only
inspects by default, and restores only with `--force`, into a verified new
staging target with the database, durable storage, release inventory,
encrypted bundle, empty cache and views, and a checked storage link. MySQL and
MariaDB restore uses a separate, empty database through a non-logged
credentials file, and rejects a target that matches the active source.

Before the first release these commands passed destructive drills in
disposable Docker environments: a temporary SQLite site and a temporary Compose
MariaDB site were each backed up, damaged, restored into new targets, and
checked for the doctor, administrator sign-in, catalog and reader, media,
private inputs, queue, scheduler, and restart. Upgrade and rollback from an
earlier pre-release build, and the shared-hosting update and rollback path,
were proven the same way, and one real cross-machine drill restored a small
MariaDB site from a separate Ubuntu server. That site was a few hundred
kilobytes with no manga media, so there is no production-scale recovery timing.

KomaEngine is not a disaster-recovery service: it supplies verified backup and
restore commands, and the operator supplies the schedule, the off-host copy,
and the tested recovery.
