<?php

namespace App\Archives;

final readonly class ExportedArchive
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public string $path,
        public string $downloadName,
        public int $bytes,
        public string $sha256,
        public array $manifest,
    ) {}
}
