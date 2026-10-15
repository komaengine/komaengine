<?php

namespace App\Archives;

use JsonException;

final readonly class KomaArchive
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<ArchiveMedia>  $media
     */
    public function __construct(
        public array $manifest,
        public array $media,
    ) {}

    /**
     * @throws JsonException
     */
    public function manifestJson(): string
    {
        return json_encode(
            $this->manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
