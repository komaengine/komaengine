<?php

namespace App\Http\Controllers;

use App\Branding\SiteBranding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the site logo and icon. File names carry a content hash, so a
 * response never changes and may be cached for a year.
 */
class BrandingController extends Controller
{
    protected const MIME_TYPES = [
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        $disk = Storage::disk('media');
        $path = SiteBranding::DIRECTORY.'/'.$file;

        abort_unless($disk->exists($path), 404);

        $response = response()->file($disk->path($path), [
            'Content-Type' => self::MIME_TYPES[pathinfo($file, PATHINFO_EXTENSION)],
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
