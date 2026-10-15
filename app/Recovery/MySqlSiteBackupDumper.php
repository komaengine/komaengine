<?php

namespace App\Recovery;

class MySqlSiteBackupDumper
{
    /** @var array<int, string> */
    private array $clients;

    /**
     * @param  array<int, string>|null  $clients
     */
    public function __construct(?array $clients = null)
    {
        $this->clients = $clients ?? [
            '/usr/bin/mariadb-dump',
            '/usr/bin/mysqldump',
        ];
    }

    /**
     * @param  array{database: string}  $connection
     */
    public function dump(string $driver, array $connection, string $credentialsFile, string $destination): void
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new SiteBackupException('Logical database backup supports only MySQL and MariaDB.');
        }
        if (! is_file($credentialsFile) || is_link($credentialsFile)
            || ! is_dir(dirname($destination)) || file_exists($destination)) {
            throw new SiteBackupException('Logical database backup could not establish safe temporary files.');
        }
        if (! isset($connection['database']) || ! is_string($connection['database']) || $connection['database'] === '') {
            throw new SiteBackupException('Logical database backup requires a configured database name.');
        }

        $client = $this->client();
        $command = [
            $client,
            '--defaults-extra-file='.$credentialsFile,
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--no-tablespaces',
            '--result-file='.$destination,
            $connection['database'],
        ];

        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new SiteBackupException('Logical database dump could not be started.');
        }

        $status = proc_close($process);
        if ($status !== 0 || ! is_file($destination) || is_link($destination) || filesize($destination) === 0) {
            throw new SiteBackupException('Logical database dump failed. Confirm database access and retry.');
        }
    }

    private function client(): string
    {
        foreach ($this->clients as $client) {
            if (is_file($client) && is_executable($client) && ! is_link($client)) {
                return $client;
            }
        }

        throw new SiteBackupException('Database dump client is unavailable. Install the supported mariadb-client package and retry.');
    }
}
