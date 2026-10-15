<?php

namespace App\Console\Commands;

use App\Install\InstalledState;
use App\Install\InstallerSetupAuthority;
use Illuminate\Console\Command;

class ShowInstallerSetupToken extends Command
{
    protected $signature = 'koma:installer-token';

    protected $description = 'Display the browser installer setup token';

    public function handle(InstallerSetupAuthority $authority, InstalledState $installed): int
    {
        if ($installed->installed()) {
            $this->error('Koma Engine is already installed.');

            return self::FAILURE;
        }

        $this->line($authority->token());

        return self::SUCCESS;
    }
}
