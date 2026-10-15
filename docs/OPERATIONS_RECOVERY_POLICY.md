# Backup and recovery operations

These are KomaEngine's recommendations for operating backups of a Community
Edition site. They are not a service commitment. KomaEngine creates, verifies,
and restores a backup when an operator runs the command; it does not schedule
backups, copy them off the server, or retire old ones. The operator does those
things. The [backup format](koma-site-backup-v1.md) and the
[restore guide](install/recovery.md) describe the commands themselves.

## Recommended policy

| Item | Recommendation | Notes |
|---|---|---|
| Capture | Once daily, and before every upgrade or large destructive change, with every writer quiesced | The backup command enters and leaves maintenance mode itself. Pick a quiet daily window |
| Verification | Verify every capture, every received copy, and every restore | `koma:site-backup:verify` checks a backup independently of the code that made it |
| Copies on the server | Keep the three newest verified backups | Nothing deletes copies automatically; retiring one is a manual, reviewed act |
| Copies off the server | Seven daily, four weekly, and three monthly, keeping the union | Backups are full, not incremental: allow for up to fourteen copies plus restore headroom |
| Off-host receiver | A separate machine that pulls from the site, and to which the site holds no credential | A laptop receiver is only as current as its last successful pull |
| Secrets and key custody | Encrypt the environment bundle for a key the server never holds, and keep an offline copy of that key | Without the key, the bundle cannot be restored |
| Protection of the copy | Encrypted in transit, and stored on an encrypted disk or encrypted as a whole | |
| Data-loss goal | No more than 24 hours of writes | Holds only while both the daily capture and the off-host pull succeed |
| Recovery-time goal | A usable site within four hours of an authorized start | Measure it on your own data; see below |
| Restore sampling | Monthly, and after a release, extension, database, or key change | |
| Monitoring | Watch for failed captures, copies, and verifications, copies older than a day, disk pressure, stale imports, and failed restores | KomaEngine sends no alerts; route them yourself |

These are goals, not measured guarantees. A daily job that fails, or a receiver
that is asleep or offline, does not deliver a 24-hour data-loss goal. Recovery
time includes retrieving the backup, provisioning the matching release and an
empty target database, restoring, and checking the running site, not just the
database import.

## Choosing an off-host receiver

The website account must have no credentials that let it modify or delete the
receiver's backup history. Prefer a receiver-initiated pull using a dedicated,
read-only source account and a verified SSH host key. Do not open inbound write
access on the receiver merely to accept backups.

A copy to another directory on the same machine is only a local copy. A
separate physical receiver, or a separately stored offline medium, is still
needed; a shared physical location also shares fire and theft risk. The
receiver must be available as often as the backup schedule needs it.

Store backup material outside the application, the web root, runtime storage,
and any synced folder the hosting account can reach. Keep directory and file
permissions restricted. Check the real backup size and free space before
promising a number of full copies.

## Encryption and custody

The backup's `secrets/` bundle protects the operator's environment secrets,
not the database, media, or the rest of the directory. Encrypt the whole
verified backup before moving or storing it off the server, preserving the
directory layout inside the encrypted envelope.

A tool such as [age](https://github.com/FiloSottile/age) works well: the server
receives only the public recipient, and the operator keeps the decryption
identity, with an offline copy. Keep transfer credentials separate from that
key. Before trusting the arrangement, recover a disposable sample using the
offline key copy. A file name or the creator's opaque-bundle check does not
prove that a bundle is actually encrypted. Never paste private keys,
environment files, database client files, or secret values into an issue,
chat, or Git.

The encrypted-copy and restore-testing principles follow
[CISA's ransomware backup guidance](https://www.cisa.gov/news-events/cybersecurity-advisories/aa23-352a).
The copy counts and time goals above are KomaEngine recommendations, not CISA
requirements.

## Retention and cleanup safety

Retention is a review policy, not an automated deletion. There is no backup
prune command.

If you automate retention yourself, make it preview by default, inventory exact
canonical paths, independently verify each candidate and each copy you keep,
and require an explicit force step to apply the reviewed plan. Keep the newest
three verified copies, the selected daily, weekly, and monthly checkpoints, and
any pre-upgrade copy until the upgrade is accepted. If fewer than three
verified copies exist, keep them all. An unavailable or stale off-host copy must
never authorize local deletion.

Refuse filesystem roots, traversal, symbolic links, live application and
storage paths, unrecognized directories, invalid inventories, and the only
valid copy. Re-check identities immediately before deleting, and stop if the
reviewed plan changed. Prefer quarantine followed by a separate reviewed
removal. Never use a broad `find ... -delete`, a volume prune, or a
mirror-and-delete transfer option as a retention mechanism.

Temporary archive workspaces and retained sources are handled differently:

- `storage/app/archive-import-temp/` and `storage/app/archive-temp/` may hold
  leftovers after a process is killed. `koma:archive-temp:clean` previews by
  default and deletes only with `--force`. Its seven-day default (one-day
  minimum) is an eligibility hint: it also confines itself to UUID-named
  directories in those two roots, refuses links, leaves import workspaces alone
  while an import runs or when that cannot be determined, and re-checks each
  workspace before deleting it. It is not scheduled.
- `storage/app/private/` includes failed and retryable source archives. Never
  expire those files merely because they are old. Discarding an import from
  the admin, and completed-import cleanup, are the deletion paths.
- Backup and restore staging paths need verified ownership and recovery-state
  checks. A filesystem stage may be the only matching partner of an already
  restored database; age alone cannot justify removing it.

## Built-in safety measures

| Area | Behavior | Remaining limit |
|---|---|---|
| Import crash recovery | The importer links the import record to its series inside the import transaction. A rerun, a worker reported stopped, or a failure after commit adopts the committed result instead of importing again | Media written before a killed transaction, and replaced media not yet deleted, can remain as unreferenced files |
| Retry dispatch | First intake and retry both return an import to a failed, retryable state when no job can be queued. Intake keeps every upload before recording any, then records them in one short transaction | A row left queued by a database failure at that same moment has no screen action |
| Archive limits | At most 100 genres, 1,000 tags, and 5,000 chapters per manifest, and no more media than the ZIP entry limit allows, checked before per-record validation. Export applies the same counts | None known |
| Restore safety | Restore fails closed. Once the database load has begun, a failure states whether the database is partly or fully loaded and that it must not be reused. Staging left by a killed restore is reported and refused, never removed or reused | No resume: provision a new empty database and target |
| Log bounds | Compose services use Docker's rotating `local` driver at 10 MB × 5 files each. The flat-file template keeps daily log files for 14 days | Daily files bound age, not size; watch disk space |
| Archive leftovers | `koma:archive-temp:clean`, described above | Not scheduled; running it is an operator action |

Docker's rotation behavior is described in its
[local-driver reference](https://docs.docker.com/engine/logging/drivers/local/).

If a restore is interrupted, keep the verified original backup and treat any
populated target database as unusable. Do not point a retry at the active
database or clear a target by hand. A fresh, isolated, empty database and
target is the supported path; see
[when a restore is interrupted](install/recovery.md#when-a-restore-is-interrupted).

## Running a recovery drill

Practice a restore before you need one. Use disposable targets; never restore
over a live site to test.

1. Record the source host, database engine, exact release, the size of the data
   (rows, files, bytes), the receiver and path, who holds the keys, and the
   tools and clocks involved.
2. Quiesce web writes, workers, and the scheduler. Capture and verify a fresh
   backup, including an environment bundle you have proven you can decrypt.
   Restore normal service after the capture, even if a later step fails.
3. Encrypt the whole verified directory without leaving a plaintext copy for
   transport. Pull the encrypted file to the receiver and compare its SHA-256
   at both ends. Check that the website account cannot delete the receiver's
   copies.
4. On the receiver, decrypt into a new restricted directory, verify the backup
   independently, and provision the exact matching release, with any
   extension code the site used and its stable keys.
5. Inspect the restore plan, then restore into a new root and, for MySQL or
   MariaDB, an empty database with limited privileges. Do not run new
   migrations during recovery. Confirm the doctor, administrator sign-in,
   catalog and reader, media, extensions, worker, scheduler, stable key, and a
   restart before calling the site usable.
6. Time the transfer, decryption, verification, restore, and usable-service
   steps separately, and note the newest write the restored site contains.
   That is your measured data-loss interval and recovery time for that data
   size.

## What has been tested

Before the first release, the backup, verify, and restore commands were proven
for SQLite, MySQL, and MariaDB in disposable Docker environments, including
destructive drills that damage a temporary site and restore it, and upgrade
and rollback drills from an earlier pre-release build. One real cross-machine drill restored a
small MariaDB site from a separate Ubuntu server: about 102 seconds, 88 of them
provisioning the matching release, for a 461 KB site with no manga media.

Those numbers describe that drill only. They are not a recovery-time or
data-loss commitment, and no production-scale timing exists. Measure your own.
