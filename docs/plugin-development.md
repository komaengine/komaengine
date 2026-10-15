# Plugin development

Koma plugins are trusted, server-side extensions installed by an operator. A
plugin registers filters and actions through `App\Support\Facades\Hook`; it
should keep provider credentials, network delivery, consent, and retention in
the plugin rather than in Koma core. Plugins run with the same access as the
rest of the application, so install them only from authors you trust.

## Your first plugin

A plugin is a directory under `plugins/` with a `plugin.json` manifest and one
PHP entry file. This example adds a note to the admin footer and logs each
published chapter.

`plugins/reading-note/plugin.json`:

```json
{
    "name": "Reading Note",
    "slug": "reading-note",
    "version": "1.0.0",
    "description": "Adds a note to the admin footer and logs published chapters.",
    "author": "Your Name",
    "requires": {
        "koma": "1.0.0",
        "php": "8.3.0"
    },
    "class": "Acme\\ReadingNote\\ReadingNotePlugin",
    "entry": "reading-note.php"
}
```

`plugins/reading-note/reading-note.php`:

```php
<?php

namespace Acme\ReadingNote;

use App\Engine\Plugins\Contracts\Plugin;
use App\Models\Chapter;
use App\Support\Facades\Hook;
use Illuminate\Support\Facades\Log;

class ReadingNotePlugin implements Plugin
{
    public function register(): void
    {
        Hook::addFilter('admin.footer_text', fn (string $text): string => $text.' · Reading Note');

        Hook::addAction('chapter.published', function (Chapter $chapter): void {
            Log::info('Chapter published', ['series_id' => $chapter->series_id, 'chapter_id' => $chapter->id]);
        });
    }

    public function boot(): void
    {
        // Runs after every enabled plugin has registered.
    }
}
```

`class` and `entry` are required; `slug` defaults to the directory name and
must be unique. `requires.koma` and `requires.php` are minimum versions, and a
plugin that does not meet them is not loaded. Koma loads only the entry file,
so require any other files you need from it.

Enable the plugin under **Admin → Plugins** (`/admin/plugins`). `register()`
runs early on every request and should only add hooks or bind services;
`boot()` runs once all enabled plugins have registered. If either throws, Koma
records the error, disables the plugin, and shows the reason on the Plugins
screen.

## Hook reference

Filters receive a value and return it, changed or not. Actions are
notifications and return nothing.

| Hook | Type | Arguments |
|---|---|---|
| `admin.footer_text` | Filter | `string $text` |
| `monetization.slot` | Filter | `Htmlable $content`, `string $slot`, `array $context` (see below) |
| `archive.gallery_adapters` | Filter | `array $adapters` of `GalleryArchiveAdapter` instances |
| `archive.import.multiple_enabled` | Filter | `bool $enabled`; only a strict `true` enables multi-file intake |
| `archive.import.multiple_max_files` | Filter | `int $maximum` |
| `koma.booted` | Action | none; fired after plugins have booted |
| `series.saved`, `series.deleted` | Action | `Series $series` |
| `chapter.saved`, `chapter.deleted`, `chapter.published`, `chapter.scheduled` | Action | `Chapter $chapter` |
| `page.saved`, `page.deleted` | Action | `Page $page` |
| `reader.view` | Action | `Chapter $chapter` |
| `analytics.event` | Action | `CommunityAnalyticsEvent $event` (see below) |
| `comment.created` | Action | `Comment $comment` |
| `comment.reported` | Action | `CommentReport $report`, `Comment $comment` |
| `comment.moderated` | Action | `Comment $comment`, `string $action`, `User $moderator` |
| `archive.import.finished` | Action | `SeriesArchiveImport $import` |
| `user.registered` | Action | `User $user` |

Views can fire hooks too: `@action('hook.name', ...)` runs an action and
`@filter('hook.name', $value)` prints a filtered value. **Admin → Plugins →
Hooks** lists the hooks with registered callbacks on a running site.

## Community monetization slots

When an administrator enables **Community advertising** under Settings →
Monetization, each built-in theme renders one `header` and one `footer` slot.
The core calls this filter once per rendered slot:

```php
use App\Support\Facades\Hook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

Hook::addFilter('monetization.slot', function (Htmlable $content, string $slot, array $context): Htmlable {
    if ($slot !== 'footer' || $context['route_name'] !== 'home') {
        return $content;
    }

    return new HtmlString('<p>Community sponsor</p>');
});
```

The filter receives an empty `HtmlString`, the slot name (`header` or
`footer`), and a privacy-minimized context with `route_name` plus optional
`series_id` and `chapter_id`. Core emits an `Htmlable` return as trusted markup;
ordinary strings are escaped and displayed as text. Disabled slots produce no
markup and do not invoke the filter.

Core stores no ad HTML, provider identifiers, credentials, or analytics data.
Plugins must deliberately own any remote scripts, iframes, pixels, cookies,
privacy obligations, and third-party terms they introduce.

## Community analytics events

Core publishes an `analytics.event` action after an authorized public
series-detail or reader view. It does not store, send, queue, or display
analytics data:

```php
use App\Engine\Analytics\CommunityAnalyticsEvent;
use App\Support\Facades\Hook;

Hook::addAction('analytics.event', function (CommunityAnalyticsEvent $event): void {
    if ($event->name === 'reader.view') {
        // A plugin may deliberately collect this event.
    }
});
```

The immutable event contains only `name` (`series.view` or `reader.view`), a
UTC `occurredAt` timestamp, `seriesId`, optional `chapterId`, and an
`authenticated` boolean. It never contains an IP address, user agent, URL,
query parameters, user ID, email address, or chapter-page data. Plugins own
any collection, retention, consent, and provider delivery they add.

## Installing and keeping a plugin

A plugin is a directory under `plugins/` in the application:

- **Docker Compose from a source checkout:** the image copies `plugins/` when
  it is built. Put the plugin in the checkout's `plugins/` directory and run
  `docker compose --env-file ../koma.docker.env up -d --build --wait`.
- **The published container image:** build a small image of your own on top of
  it, for example:

  ```dockerfile
  FROM ghcr.io/komaengine/komaengine:1.0.1
  COPY reading-note /var/www/html/plugins/reading-note
  ```

- **Shared hosting:** upload the directory into `plugins/` in the application
  directory, and copy it into each new release when you
  [update](install/shared-hosting.md#update-and-rollback).

Full-site backups include the files of every enabled plugin.

## Community, Pro, and managed-service boundary

These hooks are Community Edition extension contracts, not an entitlement or
provider service. Community itself has no Pro module, license check, payment
service, advertisement provider, analytics store, or managed-service
dependency. An operator-installed plugin remains responsible for every remote
integration it adds.

First-party self-hosted Pro modules, if introduced, will be separately
distributed from this MIT Community repository under separate commercial terms.
Candidate areas include payment and premium access, advanced advertising and
analytics, storage/CDN integration, advanced workflow, and content protection.
A managed service is likewise future and optional; it is not available or
required by Community today.
