<?php

namespace App\Archives;

use Illuminate\Support\Facades\File;

final readonly class ValidatedArchive
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $mediaPaths
     */
    public function __construct(
        public array $manifest,
        public array $mediaPaths,
        public string $workDirectory,
        public ?array $sourceMetadata = null,
    ) {}

    public function stagedPath(string $archivePath): string
    {
        if (! isset($this->mediaPaths[$archivePath])) {
            throw new ArchiveValidationException("Archive media was not staged: {$archivePath}");
        }

        return $this->mediaPaths[$archivePath];
    }

    public function discard(): void
    {
        File::deleteDirectory($this->workDirectory);
    }
}
