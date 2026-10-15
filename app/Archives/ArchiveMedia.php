<?php

namespace App\Archives;

final readonly class ArchiveMedia
{
    public function __construct(
        public string $sourcePath,
        public string $archivePath,
        public string $stagedPath,
        public string $sha256,
        public int $bytes,
        public string $mimeType,
    ) {}

    /**
     * @return array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:?int,height:?int}
     */
    public function manifestEntry(?int $width, ?int $height): array
    {
        return [
            'archive_path' => $this->archivePath,
            'mime_type' => $this->mimeType,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'width' => $width,
            'height' => $height,
        ];
    }
}
