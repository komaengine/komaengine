<?php

namespace App\Branding;

use App\Engine\Settings\Settings;
use App\Media\ImageProcessor;
use App\Media\ProcessedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The site logo and icon set under Settings → Branding.
 *
 * Images live on the media disk under `branding/`, so full-site backups carry
 * them, with a content-hash file name so the public URL changes whenever the
 * image does and can be cached for a year. The settings row records the path
 * and dimensions; the file it replaces is removed only after that row is
 * saved.
 */
class SiteBranding
{
    public const DIRECTORY = 'branding';

    public const LOGO = 'branding_logo';

    public const ICON = 'branding_icon';

    /** Matches the file names this class writes; the public route accepts nothing else. */
    public const FILE_PATTERN = '(logo|icon)-[a-f0-9]{32}\.(webp|png|jpg)';

    public function __construct(
        protected ImageProcessor $images,
        protected Settings $settings,
    ) {}

    public function storeLogo(UploadedFile $file): void
    {
        $this->store(self::LOGO, 'logo', $this->images->processLogo(
            $file,
            (int) config('koma.images.branding.logo_max_width', 512),
            (int) config('koma.images.branding.logo_max_height', 256),
        ));
    }

    public function storeIcon(UploadedFile $file): void
    {
        $this->store(self::ICON, 'icon', $this->images->processIcon(
            $file,
            (int) config('koma.images.branding.icon_size', 512),
        ));
    }

    public function removeLogo(): void
    {
        $this->remove(self::LOGO);
    }

    public function removeIcon(): void
    {
        $this->remove(self::ICON);
    }

    /** @return array{path: string, url: string, width: int, height: int, mime: string}|null */
    public function logo(): ?array
    {
        return $this->stored(self::LOGO);
    }

    /** @return array{path: string, url: string, width: int, height: int, mime: string}|null */
    public function icon(): ?array
    {
        return $this->stored(self::ICON);
    }

    /** @return array{path: string, url: string, width: int, height: int, mime: string}|null */
    protected function stored(string $key): ?array
    {
        $value = $this->settings->get($key);
        $path = is_array($value) ? ($value['path'] ?? null) : null;

        if (! is_string($path) || preg_match('#\A'.self::DIRECTORY.'/'.self::FILE_PATTERN.'\z#', $path) !== 1) {
            return null;
        }

        return [
            'path' => $path,
            'url' => route('branding', ['file' => basename($path)]),
            'width' => (int) ($value['width'] ?? 0),
            'height' => (int) ($value['height'] ?? 0),
            'mime' => (string) ($value['mime'] ?? ''),
        ];
    }

    protected function store(string $key, string $prefix, ProcessedImage $image): void
    {
        $path = self::DIRECTORY.'/'.$prefix.'-'.substr(hash('sha256', $image->bytes), 0, 32).'.'.$image->extension;
        $previous = $this->stored($key);
        $disk = Storage::disk('media');

        if (! $disk->put($path, $image->bytes) || ! $disk->exists($path)) {
            throw new RuntimeException('The branding image could not be stored.');
        }

        try {
            $this->settings->set($key, [
                'path' => $path,
                'width' => $image->width,
                'height' => $image->height,
                'mime' => $image->mimeType,
            ]);
        } catch (Throwable $exception) {
            // Keep the new file only if the saved setting already points at it.
            if (($previous['path'] ?? null) !== $path) {
                $this->deleteFile($path);
            }

            throw $exception;
        }

        if ($previous !== null && $previous['path'] !== $path) {
            $this->deleteFile($previous['path']);
        }
    }

    protected function remove(string $key): void
    {
        $previous = $this->stored($key);
        $this->settings->forget($key);

        if ($previous !== null) {
            $this->deleteFile($previous['path']);
        }
    }

    protected function deleteFile(string $path): void
    {
        try {
            if (! Storage::disk('media')->delete($path)) {
                report(new RuntimeException('A replaced branding image could not be removed.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
