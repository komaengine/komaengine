# Koma Archive v2

Koma Archive v2 is KomaEngine's default portable, single-series backup and
transfer format. It keeps v1's strict ZIP/media safety contract and adds
portable catalog metadata needed by real manga libraries.

The normative schema is
[`schema/koma-archive-v2.schema.json`](schema/koma-archive-v2.schema.json).
Schema v1 remains supported for the first production transition; see
[`koma-archive-v1.md`](koma-archive-v1.md).

## Container and safety contract

The file is a standard ZIP, conventionally ending in `.koma.zip`:

```text
manifest.json
media/...
```

V2 retains v1's exact-entry contract. Every ZIP entry must be declared, every
declared media file must exist exactly once, and no unrelated entry is
permitted. Media paths are relative under `media/`; traversal, absolute paths,
backslashes, symlinks, encryption, unsupported images, decompression bombs,
remote fetching, and descriptor mismatches are rejected before persistence.

Every image descriptor contains:

- relative archive path;
- decoded MIME type;
- exact byte count;
- SHA-256;
- decoded width and height.

## Root manifest

| Field | Type | Meaning |
| --- | --- | --- |
| `format` | string | Always `koma-archive`. |
| `schema_version` | integer | Always `2`. |
| `exported_at` | UTC timestamp | `YYYY-MM-DDTHH:MM:SSZ`. |
| `series` | object | One complete Koma series. |

All documented keys are present. Unknown values use `null`; repeatable metadata
uses `[]`. Unknown keys are rejected.

## V2 catalog metadata

V2 retains every v1 series field and adds:

| Field | Type | Meaning |
| --- | --- | --- |
| `alternate_titles` | array | `{language, title, type}` records. Type is `original`, `alternate`, `official`, or `romanized`. |
| `publication_date` | date/null | Bibliographic date. It is not Koma's operational chapter `published_at`. |
| `work_format` | string/null | Portable normalized identifier such as `manga`, `doujinshi`, or `artist_cg`. |
| `credits` | array | `{name, role, type}`; type is `person` or `organization`. |
| `franchises` | string array | Related franchise/source-work names. |
| `characters` | string array | Character names. |
| `source_references` | array | `{provider, external_id, url}` provenance. URLs are metadata and are never fetched during import. |

The legacy `title`, `author`, and `artist` fields remain deterministic display
projections so current themes and v1 compatibility exports remain useful.

V2 chapters add nullable `language`, using a BCP-47-style code such as `en`,
`ja`, or `pt-BR`. This is the chapter/edition content language, not necessarily
the original language of the work.

## Default export and v1 compatibility

The admin's primary download and the CLI default to v2:

```sh
php artisan koma:archive:export my-series
php artisan koma:archive:export 13 --output=/srv/backups/my-series.koma.zip
```

Use schema 1 only for the first production compatibility window:

```sh
php artisan koma:archive:export my-series --schema=1
```

The importer accepts strict Koma Archive v1 and v2 files. A v1 import receives
empty/null v2 metadata. Exporting a v2 record as v1 intentionally omits the v2
fields without changing the stored series.

## Supported external gallery ZIP

The import workspace also recognizes a generic external gallery manifest,
version 1. It is an input adapter, not a Koma Archive schema, and it is
deliberately provider-neutral: any tool that can write this manifest plus a
`pages/` directory can hand a gallery to KomaEngine. The engine records the
`source` string as provenance and never branches on its value.

```json
{
  "version": 1,
  "source": "my-scanner",
  "source_id": 1234,
  "url": "https://example.test/g/1234",
  "title": "Gallery title",
  "title_original": "原題",
  "title_original_language": "ja",
  "language": "english",
  "published_on": "2019-05-04",
  "work_format": "doujinshi",
  "content_rating": "mature",
  "artists": ["Artist One"],
  "groups": ["Example Circle"],
  "franchises": ["Example Franchise"],
  "characters": ["Example Character"],
  "tags": ["Romance"],
  "pages": ["001.webp", "002.webp"]
}
```

`version`, `source`, `source_id`, `title`, and `pages` are required; everything
else is optional. A gallery with no language is normal — image-only works and
game CG sets have none — and its chapter simply carries no language rather than
a guessed one. `source` is a lowercase slug — the same shape the
Koma v2 validator accepts for a source-reference provider — so a manifest that
validates here cannot produce a Koma manifest the validator then rejects. Unknown keys are rejected rather than ignored, so a
manifest that drifts from this shape fails before anything is written.

How it maps:

- one gallery becomes one private Koma series with draft Chapter 1;
- `title` becomes the Koma title and seeds the slug;
- `title_original` becomes an alternate title in
  `title_original_language` (falling back to `language`);
- `published_on` becomes `publication_date` and supplies `year`;
- `artists` and `groups` become structured credits (`artist` and
  `creator_group`); a group is *not* recorded as a scanlation group, since it
  credits the work rather than a translation team;
- `language`, when given, becomes the chapter language — a BCP-47 tag passes
  through, and the common English language names are translated;
- `franchises`, `characters`, `tags`, `work_format`, and source identity are
  preserved as given;
- `url` is recorded only when it is an absolute `http(s)` URL, and dropped
  otherwise rather than guessed at;
- the first ordered page generates independent cover and thumbnail derivatives
  because the manifest has no explicit cover field;
- the original manifest is kept privately for diagnostics/remapping.

The adapter never requests a source URL and performs no network access at all.
It reads only the uploaded ZIP, derives media hashes, MIME types and dimensions
while staging, and then passes the normalized v2 manifest through the same
strict validator and transactional importer as native archives.

Because external manifests do not provide compatible visibility or rating
metadata, these imports are private, mature, and draft by default. Reading
direction and lifecycle status use explicit configured defaults and should be
reviewed during acceptance.

### Teaching the importer another gallery format

The generic manifest is the only gallery format the engine itself understands.
Any other shape — a provider's own export, a scanner's private format — belongs
in a plugin, which implements `App\Archives\Contracts\GalleryArchiveAdapter`
and registers it through the `archive.gallery_adapters` filter:

```php
Hook::addFilter('archive.gallery_adapters', fn (array $adapters) => [
    ...$adapters,
    app(MyGalleryAdapter::class),
]);
```

Plugin adapters are offered each manifest **before** the built-in one, because
the generic manifest is permissive by design and would otherwise claim a more
specific format first. An adapter that throws while sniffing is skipped and
reported rather than allowed to fail an import it may not own, and a native Koma
Archive never reaches an adapter at all.

The usual way to write one is to translate the foreign manifest into the generic
gallery manifest and delegate to `GalleryArchiveV1Adapter`, so there stays
exactly one mapping code path. Whatever an adapter returns still goes through the
strict Koma Archive validator before anything is written, and adapters must not
perform network access — they read only the uploaded ZIP.

### Opting into batch archive selection

The Community import screen is deliberately one file at a time unless an enabled
plugin opts in. A provider plugin that has a safe reason to accept several
independent source archives may register:

```php
Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled) => true);
Hook::addFilter('archive.import.multiple_max_files', fn (int $maximum) => 12);
```

Koma clamps the requested count to the PHP runtime's `max_file_uploads` limit
and an application maximum of 25. Each selected ZIP is retained and dispatched
as its own normal `SeriesArchiveImport` and `ImportSeriesArchive` job — there is
no parent mega-job or cross-series transaction. This preserves the usual
per-file progress, safe failure, retained-source retry, and audit trail.

Batch intake supports only **Fail safely** and **Create unique** conflicts.
Replacing one explicit existing Series is necessarily a single-archive action,
so it remains available only in the default intake.
