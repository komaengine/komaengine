<?php

namespace App\Console\Commands;

use App\Media\ProtectMediaStorage;
use Illuminate\Console\Command;
use Throwable;

class ProtectMediaStorageCommand extends Command
{
    protected $signature = 'koma:media:protect';

    protected $description = 'Move legacy publicly served manga media into protected storage';

    public function handle(ProtectMediaStorage $storage): int
    {
        try {
            $count = $storage->migrate();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Protected media migration failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Protected media storage is ready ({$count} file(s) migrated).");

        return self::SUCCESS;
    }
}
