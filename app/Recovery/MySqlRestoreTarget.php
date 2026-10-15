<?php

namespace App\Recovery;

final readonly class MySqlRestoreTarget
{
    public function __construct(
        public string $driver,
        public string $dumpPath,
        public string $credentialsFile,
        public string $database,
        public string $endpoint,
    ) {}
}
