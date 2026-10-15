<?php

namespace App\Archives;

use App\Support\Facades\Hook;
use Throwable;

/**
 * Describes optional multi-file archive intake supplied by an enabled plugin.
 *
 * Community keeps its deliberate one-file intake by default. An importer
 * plugin can opt in without changing the archive worker contract: every file
 * still becomes its own retained source and queue job.
 */
class ArchiveImportBatchOptions
{
    /**
     * @return array{enabled: bool, max_files: int}
     */
    public function details(): array
    {
        $enabled = false;

        try {
            $enabled = Hook::applyFilters('archive.import.multiple_enabled', false) === true;
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! $enabled) {
            return ['enabled' => false, 'max_files' => 1];
        }

        $maximum = 12;

        try {
            $maximum = (int) Hook::applyFilters('archive.import.multiple_max_files', $maximum);
        } catch (Throwable $exception) {
            report($exception);
        }

        $phpMaximum = (int) ini_get('max_file_uploads');

        if ($phpMaximum > 0) {
            $maximum = min($maximum, $phpMaximum);
        }

        return [
            'enabled' => true,
            'max_files' => min(max($maximum, 1), 25),
        ];
    }
}
