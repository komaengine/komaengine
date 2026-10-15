<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function series(Series $series, string $variant): BinaryFileResponse
    {
        $path = match ($variant) {
            'cover' => $series->cover_path,
            'thumbnail' => $series->cover_thumbnail_path ?: $series->cover_path,
            default => null,
        };

        return $this->serve($path);
    }

    public function page(Request $request, Series $series, Chapter $chapter, Page $page): BinaryFileResponse
    {
        if (! $chapter->isPublished() && ! $request->user()?->can('edit_series')) {
            abort(404);
        }

        return $this->serve($page->image_path);
    }

    private function serve(?string $path): BinaryFileResponse
    {
        if (! is_string($path) || $path === '') {
            abort(404);
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($path)) {
            abort(404);
        }

        $resolved = realpath($disk->path($path));
        $root = realpath($disk->path(''));
        if ($resolved === false || $root === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $response = response()->file($resolved, [
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
