# Image processing

KomaEngine validates and decodes every new page and cover through the same GD
pipeline. Existing stored media is not rewritten or migrated.

## Server capabilities

- PHP GD is required for new page, replacement, and cover uploads. The browser
  installer blocks completion when GD is missing.
- GD WebP encoding is optional. When available, new JPEG, PNG, and WebP uploads
  are encoded as WebP. Without it, JPEG and PNG retain their source format.
- A bounded WebP page remains readable without a WebP encoder because KomaEngine
  can retain its validated source bytes. Resizing WebP or generating WebP cover
  derivatives requires WebP encoding; the admin/API returns an actionable error
  instead of silently changing formats.
- PHP EXIF is optional. When present, JPEG orientation metadata is applied before
  resizing or cropping.

The installer and upload screens report the active GD/WebP capability instead
of promising an optimization the server cannot perform.

## Output defaults

The defaults live under `images` in `config/koma.php`:

| Output | Default |
|---|---:|
| Page bounds | 2400 × 4000, without upscaling |
| Full cover | 1000 × 1400, 5:7 crop |
| Cover thumbnail | 300 × 420, 5:7 crop |
| WebP quality | 82 |
| JPEG fallback quality | 88 |
| PNG fallback compression | 7 |
| Decode safety ceiling | 12,000,000 pixels |

Animated PNG/WebP and corrupt or unsupported images are rejected. Database
dimensions and byte sizes describe the processed file, not the original upload.

## PHP memory and upload limits

GD expands compressed files into memory. KomaEngine checks decoded pixel count
before allocating the bitmap and defaults to a 12-megapixel ceiling. A PHP
`memory_limit` of at least **256M** is recommended when operators regularly use
large source pages or maximum-size cover crops. Lower the decode ceiling when a
shared host provides less memory; do not raise it without raising PHP memory and
exercising representative uploads.

The original request limits remain separate under `uploads` in `config/koma.php`.
Also align PHP/web-server `upload_max_filesize`, `post_max_size`,
`max_file_uploads`, request timeout, and reverse-proxy body limits with the batch
sizes your site accepts.
