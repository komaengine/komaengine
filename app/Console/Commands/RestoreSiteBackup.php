<?php

namespace App\Console\Commands;

use App\Recovery\SiteBackupException;
use App\Recovery\SiteBackupRestorePlan;
use App\Recovery\SiteBackupRestorer;
use Illuminate\Console\Command;

class RestoreSiteBackup extends Command
{
    protected $signature = 'koma:site-backup:restore
        {backup : Absolute published full-site backup directory}
        {--target-root= : Absolute new directory beneath an existing isolated writable parent}
        {--database-credentials= : Absolute mode-0600 client credentials file for an isolated MySQL/MariaDB target}
        {--database-target= : Explicit empty MySQL/MariaDB target database name}
        {--force : Publish the verified restore into the isolated target root}';

    protected $description = 'Inspect or explicitly restore a verified site backup into isolated filesystem and database targets';

    public function handle(SiteBackupRestorer $restorer): int
    {
        $targetRoot = $this->option('target-root');
        if (! is_string($targetRoot) || $targetRoot === '') {
            $this->error('The --target-root option is required.');

            return self::INVALID;
        }

        try {
            $plan = $this->option('force')
                ? $restorer->restore(
                    (string) $this->argument('backup'),
                    $targetRoot,
                    true,
                    $this->option('database-credentials'),
                    $this->option('database-target'),
                )
                : $restorer->inspect(
                    (string) $this->argument('backup'),
                    $targetRoot,
                    $this->option('database-credentials'),
                    $this->option('database-target'),
                );
        } catch (SiteBackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->displayPlan($plan);
        if (! $this->option('force')) {
            $this->warn('Inspection only. No target files were written. Re-run with --force to publish this isolated restore.');

            return self::SUCCESS;
        }

        $this->info('Verified site backup restored into the isolated target.');
        $this->line('Target: '.$plan->targetRoot);

        return self::SUCCESS;
    }

    private function displayPlan(SiteBackupRestorePlan $plan): void
    {
        $this->line('Verified backup: '.$plan->backup->path);
        $this->line('Planned target: '.$plan->targetRoot);
        $this->table(
            ['Action', 'Source', 'Target'],
            array_map(
                fn (array $action): array => [$action['action'], $action['source'] ?? '—', $action['target']],
                $plan->actions,
            ),
        );
    }
}
