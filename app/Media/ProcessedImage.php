<?php

namespace App\Media;

final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public string $extension,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {}

    public function fileSize(): int
    {
        return strlen($this->bytes);
    }
}
