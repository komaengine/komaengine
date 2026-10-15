<?php

namespace App\Recovery;

final readonly class SiteBackup
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public string $path,
        public array $manifest,
    ) {}
}
