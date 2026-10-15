<?php

namespace App\Console\Commands;

use App\Recovery\SiteBackupException;
use App\Recovery\SiteBackupVerifier;
use Illuminate\Console\Command;

class VerifySiteBackup extends Command
{
    protected $signature = 'koma:site-backup:verify
        {backup : Absolute published full-site backup directory}';

    protected $description = 'Verify a KomaEngine full-site backup without changing it';

    public function handle(SiteBackupVerifier $verifier): int
    {
        try {
            $backup = $verifier->verify((string) $this->argument('backup'));
        } catch (SiteBackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Site backup verified.');
        $this->line('Backup: '.$backup->path);

        return self::SUCCESS;
    }
}
