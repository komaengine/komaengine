<?php

namespace App\Media;

final readonly class PreparedCover
{
    public function __construct(
        public string $coverPath,
        public string $thumbnailPath,
        public ProcessedImage $cover,
        public ProcessedImage $thumbnail,
    ) {}

    /** @return array<string, int|string> */
    public function attributes(): array
    {
        return [
            'cover_path' => $this->coverPath,
            'cover_thumbnail_path' => $this->thumbnailPath,
            'cover_width' => $this->cover->width,
            'cover_height' => $this->cover->height,
            'cover_file_size' => $this->cover->fileSize(),
            'cover_thumbnail_width' => $this->thumbnail->width,
            'cover_thumbnail_height' => $this->thumbnail->height,
            'cover_thumbnail_file_size' => $this->thumbnail->fileSize(),
        ];
    }
}
