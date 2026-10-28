<?php

namespace App\Console\Commands;

use App\Engine\Health\ReadinessChecks;
use Illuminate\Console\Command;

class KomaDoctor extends Command
{
    protected $signature = 'koma:doctor
        {--mode=operational : bootstrap or operational readiness}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Check KomaEngine configuration and operational readiness';

    public function handle(ReadinessChecks $readiness): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ReadinessChecks::MODES, true)) {
            $this->error('Mode must be bootstrap or operational.');

            return self::INVALID;
        }

        $payload = $readiness->run($mode);
        $pending = $payload['pending'] ?? [];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload['checks'] as $name => $passed) {
                $state = match (true) {
                    in_array($name, $pending, true) => 'PENDING',
                    $passed => 'OK',
                    default => 'FAIL',
                };
                $this->line(sprintf('%-16s %s', $name, $state));
            }
        }

        return $payload['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
