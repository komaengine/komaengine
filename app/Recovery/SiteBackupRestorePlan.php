<?php

namespace App\Recovery;

final readonly class SiteBackupRestorePlan
{
    /**
     * @param  list<array{action: string, source: string|null, target: string}>  $actions
     */
    public function __construct(
        public SiteBackup $backup,
        public string $targetRoot,
        public array $actions,
        public ?MySqlRestoreTarget $databaseTarget = null,
    ) {}
}
