<?php

namespace App\Console\Commands;

use App\Archives\ArchiveException;
use App\Archives\KomaArchiveValidator;
use App\Archives\SeriesArchiveExporter;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportSeriesArchive extends Command
{
    protected $signature = 'koma:archive:export
        {series : Series ID or slug}
        {--output= : Local archive file or directory; defaults to storage/app/exports}
        {--schema=2 : Archive schema version; use 1 for first-release compatibility}';

    protected $description = 'Export one series as a portable Koma Archive ZIP (v2 by default)';

    public function handle(SeriesArchiveExporter $exporter): int
    {
        $identifier = trim((string) $this->argument('series'));
        $series = ctype_digit($identifier)
            ? Series::query()->find((int) $identifier)
            : Series::query()->where('slug', $identifier)->first();

        if ($series === null) {
            $this->error('No series exists with that ID or slug.');

            return self::FAILURE;
        }

        $schemaVersion = filter_var($this->option('schema'), FILTER_VALIDATE_INT);

        if (! is_int($schemaVersion)
            || ! in_array($schemaVersion, KomaArchiveValidator::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $this->error('Schema must be one of: '.implode(', ', KomaArchiveValidator::SUPPORTED_SCHEMA_VERSIONS).'.');

            return self::FAILURE;
        }

        $destination = $this->destination($series, $exporter);

        try {
            $archive = $exporter->export($series, $destination, schemaVersion: $schemaVersion);
        } catch (ArchiveException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Koma Archive v{$schemaVersion} exported successfully.");
        $this->line('Path: '.$archive->path);
        $this->line('Bytes: '.$archive->bytes);
        $this->line('SHA-256: '.$archive->sha256);

        return self::SUCCESS;
    }

    private function destination(Series $series, SeriesArchiveExporter $exporter): string
    {
        $requested = trim((string) $this->option('output'));

        if ($requested === '') {
            $directory = storage_path('app/exports');
            File::ensureDirectoryExists($directory);

            return $directory.DIRECTORY_SEPARATOR.now('UTC')->format('Ymd-His').'-'.$exporter->downloadName($series);
        }

        $path = $this->absolutePath($requested);

        if (is_dir($path) || str_ends_with($requested, '/') || str_ends_with($requested, '\\')) {
            return rtrim($path, '/\\').DIRECTORY_SEPARATOR.$exporter->downloadName($series);
        }

        return $path;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
