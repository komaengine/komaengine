<?php

namespace App\Console\Commands;

use App\Recovery\SiteBackupCreator;
use App\Recovery\SiteBackupException;
use Illuminate\Console\Command;

class CreateSiteBackup extends Command
{
    protected $signature = 'koma:site-backup:create
        {destination : Absolute, new destination directory outside the application and storage directories}
        {--secrets-bundle= : Absolute path to the operator-provided encrypted secret bundle}';

    protected $description = 'Create and verify a full-site backup directory';

    public function handle(SiteBackupCreator $creator): int
    {
        $this->warn('Confirm queue workers and schedulers are stopped before creating a site backup.');

        try {
            $backup = $creator->create(
                (string) $this->argument('destination'),
                $this->option('secrets-bundle'),
            );
        } catch (SiteBackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Site backup created and independently verified.');
        $this->line('Backup: '.$backup->path);

        return self::SUCCESS;
    }
}
