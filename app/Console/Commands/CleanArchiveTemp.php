<?php

namespace App\Console\Commands;

use App\Archives\ArchiveTempCleaner;
use Illuminate\Console\Command;

class CleanArchiveTemp extends Command
{
    protected $signature = 'koma:archive-temp:clean
        {--older-than-days=7 : Only consider workspaces untouched for at least this many days}
        {--force : Delete the listed workspaces; without it nothing is changed}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Preview or remove archive staging workspaces left behind by a killed import or export';

    public function handle(ArchiveTempCleaner $cleaner): int
    {
        $days = filter_var($this->option('older-than-days'), FILTER_VALIDATE_INT);
        $minimumDays = intdiv(ArchiveTempCleaner::MINIMUM_AGE_SECONDS, 86400);

        if ($days === false || $days < $minimumDays || $days > 3650) {
            $this->error("--older-than-days must be a whole number of days, at least {$minimumDays}.");

            return self::INVALID;
        }

        $force = (bool) $this->option('force');

        // Deleting as root would turn any mistake, or any path tampered with
        // by the web user, into damage far outside the staging directories.
        if ($force && ! $this->laravel->environment('testing')
            && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('Refusing to delete as root. Run this as the application user, for example with gosu or sudo -u www-data.');

            return self::FAILURE;
        }

        $seconds = $days * 86400;
        $plan = $cleaner->plan($seconds);
        $workspaces = $force ? $cleaner->apply($plan, $seconds) : $plan;
        $eligible = $this->count($plan, ArchiveTempCleaner::REMOVE);
        $failed = $this->count($workspaces, ArchiveTempCleaner::FAILED);

        if ($this->option('json')) {
            $this->line(json_encode([
                'applied' => $force,
                'older_than_days' => $days,
                'workspaces' => array_map(
                    fn (array $entry): array => array_diff_key($entry, ['identity' => true]),
                    $workspaces,
                ),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($workspaces as $entry) {
            $this->line(sprintf(
                '%-7s %s (%d file(s), %d bytes): %s',
                $entry['action'],
                $entry['path'],
                $entry['files'],
                $entry['bytes'],
                $entry['reason'],
            ));
        }

        if (! $force) {
            $this->info($eligible === 0
                ? 'Nothing is eligible for cleanup. Nothing was changed.'
                : "Nothing was changed. Re-run with --force to delete {$eligible} stale workspace(s).");

            return self::SUCCESS;
        }

        $removed = $this->count($workspaces, ArchiveTempCleaner::REMOVED);
        $this->info("Removed {$removed} stale workspace(s).");

        if ($failed > 0) {
            $this->error("{$failed} workspace(s) could not be removed; see the application log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{action:string}>  $entries
     */
    private function count(array $entries, string $action): int
    {
        return count(array_filter($entries, fn (array $entry): bool => $entry['action'] === $action));
    }
}
