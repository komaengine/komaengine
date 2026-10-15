# Bulk chapter upload API

KomaEngine exposes a versioned, multipart API for importing multiple chapters
into an existing series. A batch is atomic: either every chapter and page is
stored, or no database rows and page files from the batch are kept.

## Authentication

API tokens belong to Koma users and inherit their existing capabilities. Only
users with `upload_chapters` can use this endpoint (Administrator, Editor, and
Uploader by default).

Administrators can create, inspect, and revoke tokens from **Admin → API
Access**. The new plain-text secret appears once with a copy control; after it is
dismissed or the page is left, it cannot be recovered.

Tokens can also be issued from the application directory:

```bash
php artisan koma:api-token uploader@example.com \
  --name="Migration importer" \
  --expires="2026-12-31T23:59:59Z"
```

The plain-text token is displayed once. Koma stores only its SHA-256 hash. Put
the token in a secret manager; do not commit it or include it in query strings.

Revoke a token using the ID printed when it was issued:

```bash
php artisan koma:api-token:revoke 12
```

## Endpoint

```text
POST /api/v1/series/{series-slug}/chapters/bulk
Authorization: Bearer koma_...
Accept: application/json
Content-Type: multipart/form-data
```

Fields:

| Field | Required | Description |
|---|---:|---|
| `publish` | No | Batch default. Send `true`, `false`, `1`, or `0`; defaults to `false`. |
| `publish_at` | No | Batch scheduling default as a future ISO-8601 timestamp. When present, chapters are scheduled rather than published immediately. |
| `chapters[n][number]` | Yes | Non-negative chapter number; decimals such as `10.5` are supported. |
| `chapters[n][title]` | No | Chapter title, up to 255 characters. |
| `chapters[n][scanlation_group]` | No | Group/credit, up to 255 characters. |
| `chapters[n][language]` | No | Chapter content language, such as `en`, `ja`, or `pt-BR`. |
| `chapters[n][publish]` | No | Overrides the batch default for this chapter. |
| `chapters[n][publish_at]` | No | Overrides the batch schedule. Send an explicit empty value to clear a batch schedule for this chapter. |
| `chapters[n][pages][]` | Yes | Page images in reading order. JPEG, PNG, and WebP are supported. |

The default application limits are 25 chapters per request, 500 pages per
chapter, 20 MiB per image, and 20,000 pixels per dimension. They are configured
under `uploads` in `config/koma.php`. PHP/web-server limits such as
`post_max_size`, `upload_max_filesize`, `max_file_uploads`, and request timeouts
must also be large enough for the chosen batch size.

Accepted pages pass through the shared image processor. New output uses WebP
when GD supports it and is bounded by the `koma.images.pages` dimensions without
upscaling. Otherwise JPEG/PNG retain their source format; a bounded WebP source
can be retained as-is. Response dimensions and `file_size` describe the stored
processed page. See [image-processing.md](image-processing.md) for capability and
memory details.

For very large libraries, send repeatable batches rather than one unbounded
request. Chapter numbers are unique within a series, so retrying a completed
batch cannot create duplicates. A retry after a lost success response receives
HTTP 422 for the already-created chapter numbers; the original chapters remain
unchanged.

`publish=false` cannot be combined with a non-empty `publish_at`. Omitting
`publish` while providing `publish_at` schedules the chapter. `publish=true`
with a future `publish_at` also schedules it; the timestamp takes precedence.
Scheduled chapters remain absent from catalogs and reader routes until
`php artisan koma:publish-scheduled` transitions them. Production schedulers
should run Laravel's scheduler every minute.

## Example

This request publishes chapter 1 and keeps chapter 2 as a draft:

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $KOMA_API_TOKEN" \
  -H "Accept: application/json" \
  -F 'publish=true' \
  -F 'chapters[0][number]=1' \
  -F 'chapters[0][title]=The Beginning' \
  -F 'chapters[0][pages][]=@chapter-001/001.png' \
  -F 'chapters[0][pages][]=@chapter-001/002.png' \
  -F 'chapters[1][number]=2' \
  -F 'chapters[1][title]=The Next Day' \
  -F 'chapters[1][publish]=false' \
  -F 'chapters[1][pages][]=@chapter-002/001.png' \
  https://example.com/api/v1/series/my-series/chapters/bulk
```

A successful request returns HTTP 201:

```json
{
  "data": {
    "series": {
      "id": 7,
      "slug": "my-series",
      "title": "My Series"
    },
    "chapters": [
      {
        "id": 41,
        "number": "1",
        "title": "The Beginning",
        "scanlation_group": null,
        "status": "published",
        "scheduled": false,
        "published_at": "2026-07-17T23:00:00+00:00",
        "page_count": 2,
        "pages": [
          {
            "id": 801,
            "sort_order": 1,
            "url": "/media/series/example-series/chapters/7/pages/41",
            "width": 1200,
            "height": 1800,
            "file_size": 485221
          }
        ]
      }
    ]
  },
  "meta": {
    "created": 2,
    "atomic": true
  }
}
```

## Responses

| Status | Meaning |
|---:|---|
| `201` | Every chapter was created. |
| `401` | Bearer token is missing, invalid, expired, or revoked. |
| `403` | Token owner lacks `upload_chapters`. |
| `404` | Series slug does not exist. |
| `409` | A concurrent request created one of the chapter numbers; nothing from this batch was kept. |
| `422` | Request validation failed, including duplicate/existing chapter numbers; nothing was written. |
| `429` | Token exceeded the default rate limit of 60 requests per minute. |
| `500` | Storage or persistence failed; rows and files from the batch were rolled back. |

Validation failures use Laravel's normal JSON error object, with keys matching
the submitted indexes, for example `chapters.1.pages.3`.
