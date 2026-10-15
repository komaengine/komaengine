<?php

namespace App\Media;

use GdImage;
use Illuminate\Http\UploadedFile;

class ImageProcessor
{
    /** @var array<string, string> */
    protected const SOURCE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        protected ?bool $webpSupport = null,
        protected ?bool $exifSupport = null,
    ) {}

    /** @return array{gd: bool, webp: bool, exif: bool} */
    public function capabilities(): array
    {
        return [
            'gd' => $this->gdAvailable(),
            'webp' => $this->webpAvailable(),
            'exif' => $this->exifAvailable(),
        ];
    }

    public function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public function webpAvailable(): bool
    {
        return $this->webpSupport
            ?? (function_exists('imagewebp') && imagetypes() & IMG_WEBP);
    }

    public function exifAvailable(): bool
    {
        return $this->exifSupport ?? function_exists('exif_read_data');
    }

    public function processPage(UploadedFile $file): ProcessedImage
    {
        [$image, $sourceMime, $sourceBytes] = $this->decode($file);

        try {
            if ($sourceMime === 'image/webp' && ! $this->webpAvailable()) {
                $width = imagesx($image);
                $height = imagesy($image);
                $maxWidth = (int) config('koma.images.pages.max_width', 2400);
                $maxHeight = (int) config('koma.images.pages.max_height', 4000);

                if ($width > $maxWidth || $height > $maxHeight) {
                    throw new ImageProcessingException(
                        'This WebP page must be resized, but the server cannot encode WebP. Upload a bounded JPEG or PNG instead.',
                    );
                }

                return new ProcessedImage($sourceBytes, 'webp', 'image/webp', $width, $height);
            }

            $image = $this->orient($image, $file, $sourceMime);
            $image = $this->resizeWithin(
                $image,
                (int) config('koma.images.pages.max_width', 2400),
                (int) config('koma.images.pages.max_height', 4000),
            );

            return $this->encode($image, $sourceMime);
        } finally {
            unset($image);
        }
    }

    public function processCover(
        UploadedFile $file,
        float $centerX = 0.5,
        float $centerY = 0.5,
        float $zoom = 1.0,
    ): ProcessedCover {
        if ($centerX < 0 || $centerX > 1 || $centerY < 0 || $centerY > 1 || $zoom < 1 || $zoom > 3) {
            throw new ImageProcessingException('The cover crop position or zoom is outside the allowed range.');
        }

        [$image, $sourceMime] = $this->decode($file);

        try {
            if ($sourceMime === 'image/webp' && ! $this->webpAvailable()) {
                throw new ImageProcessingException(
                    'WebP cover cropping requires WebP encoding support. Upload a JPEG or PNG cover instead.',
                );
            }

            $image = $this->orient($image, $file, $sourceMime);
            $coverWidth = (int) config('koma.images.covers.width', 1000);
            $coverHeight = (int) config('koma.images.covers.height', 1400);
            $crop = $this->cropRectangle(
                imagesx($image),
                imagesy($image),
                $coverWidth / $coverHeight,
                $centerX,
                $centerY,
                $zoom,
            );
            $coverImage = $this->renderCrop($image, $crop, $coverWidth, $coverHeight);

            try {
                $thumbnailImage = $this->renderCrop(
                    $image,
                    $crop,
                    (int) config('koma.images.covers.thumbnail_width', 300),
                    (int) config('koma.images.covers.thumbnail_height', 420),
                );

                try {
                    return new ProcessedCover(
                        $this->encode($coverImage, $sourceMime),
                        $this->encode($thumbnailImage, $sourceMime),
                    );
                } finally {
                    unset($thumbnailImage);
                }
            } finally {
                unset($coverImage);
            }
        } finally {
            unset($image);
        }
    }

    /** @return array{GdImage, string, string} */
    protected function decode(UploadedFile $file): array
    {
        if (! $this->gdAvailable()) {
            throw new ImageProcessingException(
                'Image processing requires the PHP GD extension.',
                false,
            );
        }

        $path = $file->getRealPath();
        $bytes = is_string($path) ? @file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new ImageProcessingException('The uploaded image could not be read.');
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;

        if (! is_string($mime) || ! array_key_exists($mime, self::SOURCE_EXTENSIONS)) {
            throw new ImageProcessingException('Only decoded JPEG, PNG, and WebP images are supported.');
        }

        if ($this->isAnimated($bytes, $mime)) {
            throw new ImageProcessingException('Animated images are not supported.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $maxPixels = (int) config('koma.images.decode_max_pixels', 80000000);

        if ($width < 1 || $height < 1 || $width * $height > $maxPixels) {
            throw new ImageProcessingException(
                'The image is too large to decode safely. Reduce its pixel dimensions and try again.',
            );
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new ImageProcessingException('The uploaded image is corrupt or could not be decoded.');
        }

        if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
            unset($image);
            throw new ImageProcessingException('The uploaded image color palette could not be normalized.');
        }

        imagesavealpha($image, true);

        return [$image, $mime, $bytes];
    }

    protected function isAnimated(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/png' => str_contains($bytes, 'acTL'),
            'image/webp' => str_contains($bytes, 'ANIM') || str_contains($bytes, 'ANMF'),
            default => false,
        };
    }

    protected function orient(GdImage $image, UploadedFile $file, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! $this->exifAvailable()) {
            return $image;
        }

        $orientation = $this->orientationFor($file);

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotate($image, -90),
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    protected function orientationFor(UploadedFile $file): int
    {
        $path = $file->getRealPath();
        $exif = is_string($path) ? @exif_read_data($path, 'IFD0', true, false) : false;

        return (int) data_get($exif, 'IFD0.Orientation', data_get($exif, 'Orientation', 1));
    }

    protected function flip(GdImage $image, int $mode): GdImage
    {
        if (! imageflip($image, $mode)) {
            throw new ImageProcessingException('The image orientation could not be corrected.', false);
        }

        return $image;
    }

    protected function rotate(GdImage $image, float $angle): GdImage
    {
        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $angle, $background);

        if (! $rotated instanceof GdImage) {
            throw new ImageProcessingException('The image orientation could not be corrected.', false);
        }

        imagesavealpha($rotated, true);

        return $rotated;
    }

    protected function resizeWithin(GdImage $image, int $maxWidth, int $maxHeight): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        if ($scale >= 1) {
            return $image;
        }

        $target = $this->canvas(
            max(1, (int) floor($width * $scale)),
            max(1, (int) floor($height * $scale)),
        );

        if (! imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            imagesx($target),
            imagesy($target),
            $width,
            $height,
        )) {
            unset($target);
            throw new ImageProcessingException('The image could not be resized.', false);
        }

        return $target;
    }

    /** @return array{x: int, y: int, width: int, height: int} */
    protected function cropRectangle(
        int $sourceWidth,
        int $sourceHeight,
        float $targetAspect,
        float $centerX,
        float $centerY,
        float $zoom,
    ): array {
        if ($sourceWidth / $sourceHeight > $targetAspect) {
            $baseHeight = $sourceHeight;
            $baseWidth = $baseHeight * $targetAspect;
        } else {
            $baseWidth = $sourceWidth;
            $baseHeight = $baseWidth / $targetAspect;
        }

        $width = max(1, (int) floor($baseWidth / $zoom));
        $height = max(1, (int) floor($baseHeight / $zoom));
        $x = (int) round(($centerX * $sourceWidth) - ($width / 2));
        $y = (int) round(($centerY * $sourceHeight) - ($height / 2));

        return [
            'x' => max(0, min($sourceWidth - $width, $x)),
            'y' => max(0, min($sourceHeight - $height, $y)),
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @param array{x: int, y: int, width: int, height: int} $crop */
    protected function renderCrop(
        GdImage $source,
        array $crop,
        int $maxWidth,
        int $maxHeight,
    ): GdImage {
        if ($crop['width'] >= $maxWidth && $crop['height'] >= $maxHeight) {
            $width = $maxWidth;
            $height = $maxHeight;
        } else {
            $scale = min(1, $maxWidth / $crop['width'], $maxHeight / $crop['height']);
            $width = max(1, (int) floor($crop['width'] * $scale));
            $height = max(1, (int) floor($crop['height'] * $scale));
        }
        $target = $this->canvas($width, $height);

        if (! imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $crop['x'],
            $crop['y'],
            $width,
            $height,
            $crop['width'],
            $crop['height'],
        )) {
            unset($target);
            throw new ImageProcessingException('The cover crop could not be rendered.', false);
        }

        return $target;
    }

    protected function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if (! $canvas instanceof GdImage) {
            throw new ImageProcessingException('Image memory could not be allocated.', false);
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    protected function encode(GdImage $image, string $sourceMime): ProcessedImage
    {
        [$extension, $mime] = $this->outputFormat($sourceMime);
        $bytes = $this->captureEncoding(function () use ($image, $extension): bool {
            return match ($extension) {
                'webp' => imagewebp(
                    $image,
                    null,
                    (int) config('koma.images.quality.webp', 82),
                ),
                'png' => imagepng(
                    $image,
                    null,
                    (int) config('koma.images.quality.png_compression', 7),
                ),
                default => $this->encodeJpeg($image),
            };
        });

        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || ($info['mime'] ?? null) !== $mime) {
            throw new ImageProcessingException('The optimized image could not be verified.', false);
        }

        return new ProcessedImage(
            $bytes,
            $extension,
            $mime,
            (int) $info[0],
            (int) $info[1],
        );
    }

    /** @return array{string, string} */
    protected function outputFormat(string $sourceMime): array
    {
        if ($this->webpAvailable()) {
            return ['webp', 'image/webp'];
        }

        return match ($sourceMime) {
            'image/png' => ['png', 'image/png'],
            'image/jpeg' => ['jpg', 'image/jpeg'],
            default => throw new ImageProcessingException(
                'The source image format cannot be encoded by this server.',
                false,
            ),
        };
    }

    protected function flattenForJpeg(GdImage $image): GdImage
    {
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagealphablending($flattened, true);
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flattened;
    }

    protected function encodeJpeg(GdImage $image): bool
    {
        $flattened = $this->flattenForJpeg($image);

        try {
            return imagejpeg(
                $flattened,
                null,
                (int) config('koma.images.quality.jpeg', 88),
            );
        } finally {
            unset($flattened);
        }
    }

    protected function captureEncoding(callable $encoder): string
    {
        ob_start();

        try {
            $encoded = $encoder();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (! $encoded || ! is_string($bytes) || $bytes === '') {
            throw new ImageProcessingException('The optimized image could not be encoded.', false);
        }

        return $bytes;
    }
}
