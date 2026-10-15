<?php

namespace App\Install;

use Illuminate\Support\Facades\File;

class EnvironmentBackup
{
    protected bool $resolved = false;

    public function __construct(
        protected string $environmentPath,
        protected ?string $backupPath,
        protected bool $environmentExisted,
    ) {}

    public function commit(): void
    {
        if ($this->resolved) {
            return;
        }

        if ($this->backupPath !== null) {
            File::delete($this->backupPath);
        }

        $this->resolved = true;
    }

    public function rollback(): void
    {
        if ($this->resolved) {
            return;
        }

        if ($this->environmentExisted && $this->backupPath !== null && File::exists($this->backupPath)) {
            if (! @rename($this->backupPath, $this->environmentPath)) {
                throw new InstallationException(
                    'ENV_ROLLBACK_FAILED',
                    'The previous environment file could not be restored. Restore the installer backup before retrying.',
                );
            }
        } elseif (! $this->environmentExisted) {
            File::delete($this->environmentPath);
        }

        $this->resolved = true;
    }
}
