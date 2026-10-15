<?php

namespace App\Http\Controllers;

use App\Engine\Themes\ThemeManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves precompiled assets (CSS/JS/images) from the active theme's `assets/`
 * directory. Themes ship static files so no build step is needed on the server;
 * this route exposes them without symlinking theme source into public/.
 */
class ThemeAssetController extends Controller
{
    /**
     * Content types keyed by extension. Needed because response()->file()
     * sniffs MIME from content, which mislabels CSS/JS as text/plain and makes
     * browsers refuse the stylesheet (strict MIME checking).
     */
    protected const MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'json' => 'application/json',
    ];

    public function show(Request $request, string $path, ThemeManager $themes): BinaryFileResponse
    {
        $requestedTheme = $request->query('theme');
        $hasExplicitTheme = is_string($requestedTheme) && $requestedTheme !== '';
        $absolute = $hasExplicitTheme
            ? $themes->assetPathForTheme($requestedTheme, $path)
            : $themes->assetPath($path);

        abort_if($absolute === null, Response::HTTP_NOT_FOUND);

        return $this->fileResponse(
            $absolute,
            $hasExplicitTheme
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=0, must-revalidate',
        );
    }

    public function preview(string $theme, ThemeManager $themes): BinaryFileResponse
    {
        Gate::authorize('manage_themes');

        $absolute = $themes->previewPath($theme);

        abort_if($absolute === null, Response::HTTP_NOT_FOUND);

        return $this->fileResponse($absolute, 'private, max-age=3600', sandbox: true);
    }

    protected function fileResponse(string $absolute, string $cacheControl, bool $sandbox = false): BinaryFileResponse
    {
        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        $headers = ['Cache-Control' => $cacheControl];

        if ($sandbox) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        if (isset(self::MIME_TYPES[$extension])) {
            $headers['Content-Type'] = self::MIME_TYPES[$extension];
        }

        $response = response()->file($absolute, $headers);
        $response->headers->set('Cache-Control', $cacheControl);

        return $response;
    }
}
