# KomaEngine full-site backup v1

`koma-site-backup-v1` is the full-site recovery format. The current application
can create and independently verify SQLite, MySQL, and MariaDB backups, and can
inspect or explicitly restore any of those verified backups into new isolated
filesystem and database targets. Destructive SQLite and Docker Compose MariaDB
recovery drills pass from newly created backups in fresh temporary Docker
state.

The schema is [koma-site-backup-v1.schema.json](schema/koma-site-backup-v1.schema.json).
The non-secret example is [koma-site-backup-v1.example.json](schema/koma-site-backup-v1.example.json).

## Directory layout

```text
backup-name/
  manifest.json
  database/
    database.sqlite                 # SQLite only
    database.sql                    # MySQL/MariaDB only
  storage/
    public/media/...                # every durable public media object
    private/...                     # retryable retained inputs, including archive sources
  release/
    composer.lock
    KOMA-BUILD-METADATA.txt         # present when the running build provides it
    extensions/
      plugins/<plugin>/...
      themes/<theme>/...
  secrets/
    <operator-encrypted-bundle>
```

The backup creator must make this layout in a unique staging sibling of the
operator-selected destination, verify every required file while streaming its
SHA-256, write and validate `manifest.json`, then atomically rename the staging
directory. It must reject a destination that already exists, is inside the
application tree or `storage/`, or includes an unsafe path or symbolic link.

`files` is the complete durable-file inventory. The verifier rejects paths that
are absolute, contain `.` or `..` segments, duplicate an entry, escape the
backup root, or disagree with the manifest’s byte size or SHA-256. The database,
`release/composer.lock`, and encrypted secret bundle must each also have a
matching file record. Extension records name every enabled plugin/theme and
inventory its copied manifest/code/assets; application-core identity is captured
by the release metadata and Composer lock rather than copying a second full app
tree into every backup.

New v1 backups record the authoritative `VERSION`, full source commit (or
`unavailable` for a development build), build ID, source-dirty state, Composer
lock hash, and optional `KOMA-BUILD-METADATA.txt` hash. When build metadata is
present it is copied into `release/`, inventoried, and cross-checked against the
running version and both lock files before the backup can publish. Trusted
artifacts use `<version>+<first-12-source-commit>` and require an exact, clean
commit. The verifier intentionally continues accepting the original v1
identity shape so existing valid backups remain restorable; producers always
write the expanded identity.

## Required inventory

The format preserves the recovery contract’s required inventory:

- a consistent SQLite database or MySQL/MariaDB logical dump;
- every public media object and retryable private input;
- Composer lock plus build/git identity and enabled plugin/theme manifests,
  code, and operator-owned assets;
- one **operator-provided encrypted** bundle holding the stable app key,
  database/mail credentials, and deployment configuration; and
- a versioned manifest with UTC capture time, database version, paths, byte
  sizes, and SHA-256 hashes.

The application must not collect `.env`, process-environment values, or
plaintext credentials. It only copies an explicit encrypted secret bundle and
records its format, byte size, and hash. Operators retain responsibility for
the bundle’s encryption key and off-host storage.

Caches, sessions, logs, temporary import workspaces, `vendor`, and compiled
views are intentionally excluded. A restore rebuilds disposable state from the
matching release after it restores durable data.

## Command contract and current support

SQLite, MySQL, and MariaDB creation and verification are available now:

```text
koma:site-backup:create <absolute-destination> --secrets-bundle=<absolute-encrypted-file>
koma:site-backup:verify <absolute-backup-directory>
```

SQLite restore is available behind an explicit destructive guard:

```text
koma:site-backup:restore <absolute-backup-directory> --target-root=<absolute-new-isolated-root>
koma:site-backup:restore <absolute-backup-directory> --target-root=<absolute-new-isolated-root> --force
```

MySQL and MariaDB restore additionally require a separately provisioned empty
target database and an operator-created mode-0600 client credentials file:

```text
koma:site-backup:restore <absolute-backup-directory> --target-root=<absolute-new-isolated-root> --database-credentials=<absolute-target-client.cnf> --database-target=<empty-database-name>
koma:site-backup:restore <absolute-backup-directory> --target-root=<absolute-new-isolated-root> --database-credentials=<absolute-target-client.cnf> --database-target=<empty-database-name> --force
```

`create` validates its destination and encrypted bundle before entering
maintenance mode. The operator must first stop queue workers and schedulers;
the command cannot prove an external worker or cron job has stopped. The SQLite
implementation uses an online-consistent mechanism or fails, never a normal
copy of a live database file. MySQL/MariaDB requires all base tables to be
InnoDB, then runs `mariadb-dump`/`mysqldump` with `--single-transaction` through
a temporary mode-0600 credentials file. The file is removed before the manifest
is written; no password or credential contents enter command arguments, logs,
errors, or the backup. The client receives only the temporary option-file path.
The Docker runtime supplies this through the `mariadb-client` package.

`verify` is non-mutating. It validates the schema, layout, semantic cross-links,
file list, byte sizes, and hashes without trusting exit status alone. For
MySQL/MariaDB it also rejects a hash-valid artifact that does not have the
expected logical-dump structure.

`restore` is non-mutating unless `--force` is supplied. Its default inspect mode
lists the planned release, database, media, private-input, and secret-bundle
actions. Its filesystem target must be a **new**, safe-named directory beneath
an existing writable isolated parent; an existing target is refused rather than
replaced. A forced restore re-verifies the entire backup immediately before any
write, copies only its verified filesystem inventory into a sibling staging
directory, and atomically publishes that stage. It rejects the backup itself,
project roots, current configured source paths, symlinks, and ambiguous targets;
it never runs `migrate`. It creates and checks the target's relative
`public/storage` link and creates only empty disposable cache/view directories.

For MySQL/MariaDB, the logical dump is loaded only through the supplied
mode-0600 `--database-credentials` option file. Supported options are
allowlisted, binary mode is mandatory, and local infile is disabled. Its contents never enter command
arguments, output, exceptions, or the recovered target tree. The file must
declare a target endpoint, its client must be installed, and the explicit target
database must be empty. Provision the restore account with privileges only on
that isolated target database. A target with the same declared endpoint and database
name as the active MySQL/MariaDB source is rejected. The SQL dump is loaded into
that service rather than copied into the filesystem target.

## Restore and upgrade boundary

Restore uses the matching release and stable secrets first, then database and
durable storage, permissions and `public/storage`, then disposable cache/view
rebuild. The restore target contains the recovered release inventory and data,
while the matching application release is provisioned separately. Starting migrations
is a separate, reviewed upgrade action after a restored site is healthy.
Restoring only code or only the database is not a rollback.

The completed SQLite and Compose MariaDB destructive proofs use only isolated
temporary Docker state. The MariaDB drill creates random source and target
Compose projects, proves recovered web/worker/scheduler behavior, then removes
their containers, networks, volumes, and temporary backup material. An active
site is never a test target. Recovery-time and recovery-point claims,
retention, and off-host replication are outside this format; see the
[operations recommendations](OPERATIONS_RECOVERY_POLICY.md).
