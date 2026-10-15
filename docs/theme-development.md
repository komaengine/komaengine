# Theme development contract

Koma themes are trusted, server-side extensions. A theme lives in a directory
under `themes/`, ships Blade templates and precompiled static assets, and is
discovered from its `theme.json` manifest. Installing a theme grants its Blade
code the same application access as other local source code, so install themes
only from trusted authors.

## Your first theme

The quickest start is a copy of a built-in theme under a new slug:

```bash
cp -R themes/aozora themes/paper-moon
```

In `themes/paper-moon/theme.json`, change `name` to `Paper Moon` and `slug` to
`paper-moon`, and set your own `author`, `description`, and `accent`. Slugs
must be unique: two themes with the same slug collide, and only one of them is
used. Then edit the views in `views/` and the files in `assets/`, and activate
the theme under **Admin → Themes** (`/admin/themes`). The sections below
describe what each part must provide.

## Manifest

Each theme directory must contain a valid JSON manifest:

```json
{
    "name": "Example",
    "slug": "example",
    "version": "1.0.0",
    "description": "A short operator-facing summary.",
    "author": "Theme Author",
    "accent": "#315fc7",
    "preview": "preview.webp",
    "requires": {
        "koma": "1.0.0",
        "php": "8.3.0"
    }
}
```

`slug` is the stable lowercase kebab-case identifier (for example,
`paper-moon`). `requires.koma` and
`requires.php` are minimum versions and use the existing activation gate.
`preview` is a path relative to the theme root. PNG, JPEG, GIF, WebP, AVIF, and
SVG previews are supported; 1280×800 (16:10) is recommended. Missing, invalid,
unsupported, or out-of-directory preview paths show neutral fallback artwork
in admin. Preview URLs identify one theme slug and never resolve through the
active theme.

## View resolution and templates

Every public controller renders the `theme::` namespace. Koma checks the
active theme first, then Aozora when it is not already active, then
`resources/views/theme`. Duplicate directories are removed. This fallback is a
recovery boundary, not a substitute for testing a published theme.

A complete built-in or distributable theme should own these templates:

| Template | Variables |
|---|---|
| `layout.blade.php` | Shared request, authenticated user, site settings; yields `title`, `description`, and `content` |
| `home.blade.php` | `$featured` (`Series|null`), `$latest` (chapter collection), `$popular` (series collection) |
| `browse.blade.php` | `$series` (paginator), `$genres`, `$q`, `$activeGenre`, `$activeStatus` |
| `series.blade.php` | `$series`, `$chapters`, `$firstChapter`, `$comments` (paginator) |
| `reader.blade.php` | `$series`, `$chapter` with pages, `$prev`, `$next`, `$readerMode`, `$comments` |
| `pagination.blade.php` | Laravel `$paginator` and `$elements` |
| `partials/series-card.blade.php` | `$series` |

These state templates may be overridden. If omitted, Aozora and then the
minimal application templates provide them:

| Template | Variables / responsibility |
|---|---|
| `mature-gate.blade.php` | No controller variables; must POST to the named confirm/decline routes |
| `partials/comments.blade.php` | `$series`, optional `$chapter`, `$comments`; owns signed-out, composer, validation, pending, empty, deleted, reporting, and pagination states |
| `partials/comment-item.blade.php` | `$comment`, `$series`, `$viewer`; preserves escaped plain-text output and policy-gated actions |

Use `@extends('theme::layout')`, `@include('theme::…')`, and named routes so
fallback templates keep working after activation. Do not assume a specific
database driver, storage path, hostname, or installation subdirectory.

## Assets

Put dependency-free, production-ready files in `assets/`. Themes must ship
precompiled CSS and JavaScript; production servers are not expected to run npm.
Use `@themeAsset('theme.css')` or
`Koma::themeAsset('images/example.webp')` to create an active-theme URL.

Ordinary assets are intentionally active-theme-only and do not fall back to
Aozora. Every theme must therefore ship every asset referenced by any of its
views and by the Aozora templates it may inherit. Asset and preview resolvers
canonicalize the final file and reject traversal outside the expected theme
directory.

## Accessibility and state coverage

- Use semantic headings, landmarks, links, buttons, labels, and form error/status
  regions. Do not make a `div` the only interactive target.
- Keep keyboard focus visible and preserve reader keyboard exclusions while a
  user is typing in a form control.
- Provide useful alternative text for meaningful images and empty alt text for
  decorative covers; never encode required information by color alone.
- Support zoom and reflow without horizontal page chrome overflow at 390px.
- Exercise safe and mature catalogs, empty/search results, private staff
  previews, long titles, all reader modes, comment states, pagination, and
  missing media before release.

Administrators can inspect previews and activate compatible themes under
**Admin → Themes** (`/admin/themes`). Activation remains subject to the
manifest compatibility checks and does not modify content or API/admin
rendering.

## Installing and keeping a theme

A theme is a directory under `themes/` in the application. How it gets there
depends on how KomaEngine is installed:

- **Docker Compose from a source checkout:** the image copies `themes/` when it
  is built. Put the theme in the checkout's `themes/` directory and run
  `docker compose --env-file ../koma.docker.env up -d --build --wait`.
- **The published container image:** build a small image of your own on top of
  it, for example:

  ```dockerfile
  FROM ghcr.io/komaengine/komaengine:1.0.1
  COPY paper-moon /var/www/html/themes/paper-moon
  ```

- **Shared hosting:** upload the directory into `themes/` in the application
  directory. The release package contains only the built-in themes, so copy
  your theme into each new release when you
  [update](install/shared-hosting.md#update-and-rollback).

Full-site backups include the active theme's files, so a restore brings it back
with the rest of the site.
