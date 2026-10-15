# Koma Archive v1

> Compatibility format: new development exports default to
> [Koma Archive v2](koma-archive-v2.md). V1 remains importable and explicitly
> exportable for the first production transition.

Koma Archive v1 is the portable, single-series backup and transfer format for
KomaEngine. An archive is a standard ZIP file with a `.koma.zip` suffix and the
media type `application/vnd.koma.archive+zip`.

The normative machine-readable contract is
[`schema/koma-archive-v1.schema.json`](schema/koma-archive-v1.schema.json).

## ZIP layout

Every archive has one UTF-8 JSON manifest at the ZIP root. All other entries
are media referenced by that manifest.

```text
manifest.json
media/series/{source-series-id}/cover.{safe-extension}
media/series/{source-series-id}/cover-thumbnail.{safe-extension}
media/series/{source-series-id}/chapters/{source-chapter-id}/pages/{sort-order}-{source-page-id}.{safe-extension}
```

Cover entries are omitted when the corresponding cover is unset. Page sort
orders are zero-padded to six digits. Internal paths use source IDs and fixed
labels; titles, chapter names, and uploaded filenames never become ZIP paths.
Decoded MIME type determines the safe extension: JPEG becomes `.jpg`, PNG
becomes `.png`, and WebP becomes `.webp`. These are the only media types v1
supports; export fails closed if any referenced media does not decode to one of
them, and import applies the same rule.

When both optional cover entries are omitted and at least one page exists, the
importer automatically uses the first ordered page of the first non-empty
chapter to generate Koma's independent full-cover and thumbnail derivatives.
It does not reuse, move, or alter the reader page. Explicit archive cover media
always takes precedence.

Paths are relative, forward-slash separated, unique, and rooted under `media/`.
Absolute paths, backslashes, NUL bytes, empty segments, and `.`/`..` segments
are invalid.

## Manifest contract

`manifest.json` contains these root fields in schema version 1:

| Field | Type | Meaning |
|---|---|---|
| `format` | string | Always `koma-archive`. |
| `schema_version` | integer | Always `1`. Importers must reject unsupported versions. |
| `exported_at` | UTC timestamp | Export completion snapshot time in `YYYY-MM-DDTHH:MM:SSZ` form. |
| `series` | object | The portable series record and all related content. |

The series object preserves source ID, title, slug, author, artist, year,
series status, page-order direction, optional default reader mode, content
rating, visibility, synopsis, placeholder hue, views, timestamps, sorted
genres, sorted tags, optional cover descriptors, and ordered chapters.

Each genre and each tag preserves source ID, name, slug, and timestamps. Genres
and tags are independent vocabularies; both are sorted by slug and then source
ID. Chapters are sorted
by decimal chapter number and then source ID. A chapter preserves its source
ID, fixed-scale decimal number string, title, scanlation group, draft/scheduled/
published status, publication time, views, timestamps, and pages. Pages are
sorted by `sort_order` and then source ID.

Every page and optional cover descriptor contains:

| Field | Type | Meaning |
|---|---|---|
| `archive_path` | string | Unique safe relative ZIP entry. |
| `mime_type` | string | MIME detected from the staged bytes. |
| `sha256` | string | Lowercase SHA-256 of the exact entry bytes. |
| `bytes` | integer | Exact uncompressed entry byte count. |
| `width`, `height` | integer or null | Persisted source dimensions when known. |

Page descriptors additionally preserve source ID, sort order, and timestamps.
All JSON strings are UTF-8; the exporter writes Unicode characters without
ASCII escaping.

## Determinism and integrity

For unchanged source records and media, entry names, entry order, metadata,
sizes, and checksums are deterministic. `exported_at` is the only intentionally
time-varying logical field. ZIP entry timestamps are normalized to the portable
1980 epoch, so temporary filesystem timestamps do not affect logical content.

The exporter reads media through KomaEngine's configured `media` disk in
bounded chunks, calculates SHA-256 during that stream, and stages files on local
temporary disk for standard ZIP output. It never loads the full media set into
PHP memory. A missing or unreadable media object aborts the export. The final
destination is populated only after ZIP finalization and verification; partial
ZIPs and staged files are removed on every failure.

Exports are read-only. They do not update series, chapters, pages, counters,
timestamps, or media objects.

## Privacy boundary

An authorized export includes private visibility and mature content metadata
because those values are part of the series backup. It never includes users,
emails, password hashes, sessions, API tokens, credentials, environment values,
absolute server paths, settings, plugin state, or theme configuration.

## Exporting

Editors and Administrators can open an existing series in the admin and choose
**Download archive**. The same operation is available to local operators:

```bash
php artisan koma:archive:export my-series
php artisan koma:archive:export 13 --output=/srv/backups/my-series.koma.zip
php artisan koma:archive:export 13 --output=/srv/backups/
```

Relative output paths resolve from the KomaEngine project root. The default is
`storage/app/exports`. Existing destination files are never overwritten. The
command prints the final path, byte count, and whole-archive SHA-256.

## Importing

Editors and Administrators can open **Import archive** in the admin navigation.
The upload is retained on KomaEngine's private local disk and dispatched to the
configured queue, so a worker must be running for asynchronous imports. The
workspace polls the persistent import record and shows its validation,
persistence, completion, or failure stage with counts and an actionable error.

An import has three explicit slug-conflict policies:

- **Fail safely** is the default and leaves the existing series unchanged.
- **Create unique** creates a separate series with a numbered slug.
- **Replace selected** requires an existing series and a confirmation. It never
  merges chapters, preserves the selected public URL, and retains the old rows
  and media until the imported replacement is durable.

Before persistence, the importer validates the complete ZIP entry list,
manifest JSON and version, exact paths, duplicate semantic IDs/order values,
entry and expanded-byte limits, compression ratios, checksums, decoded MIME
types, extensions, dimensions, and GD image decoding. It rejects traversal,
backslashes, NUL bytes, symbolic links, encrypted entries, unreferenced files,
remote URLs, unsupported versions, corrupt media, and decompression bombs. It
never calls ZIP extraction or fetches a manifest URL.

Validated media is staged outside its archive path and then stream-copied into
a unique media namespace with observable per-file progress. The short database
transaction begins only after every new media object is durable. A persistence
error rolls back every new row and removes that namespace. Replacement removes
old media only after commit. Failed queue jobs retain the original private
upload; successful jobs remove it after the terminal result is recorded.

Default import limits are 500 MB compressed, 10,000 entries, 5 MB for the
manifest, 25 MB per expanded media entry, 5 GB total expanded bytes, and a
200:1 per-media compression ratio. Operators can adjust them under
`koma.archives.imports`, but PHP and web-server upload limits must also permit
the selected archive size.

One manifest may describe at most 100 genres, 1,000 tags, and 5,000 chapters
(`max_genres`, `max_tags`, `max_chapters`), and no more media files than the
entry limit leaves beside `manifest.json`. These counts are checked before any
per-record validation, so a small manifest cannot demand unbounded work. Export
applies the same counts before staging media and refuses a series it could not
import again. An external gallery's tag list shares the tag limit.

The import record is linked to its new series inside the import transaction.
If a worker is killed after that transaction commits, running the import again
adopts the committed series and completes; it does not import a second time
under any conflict policy. Media written for an import that never committed,
and replaced media a killed worker had not yet deleted, can remain on disk as
unreferenced files.

A hard-killed import or export can also leave its staging workspace under
`storage/app/archive-import-temp/` or `storage/app/archive-temp/`. Preview and
remove those with `php artisan koma:archive-temp:clean`; see
[troubleshooting](install/troubleshooting.md#archive-staging-residue).
