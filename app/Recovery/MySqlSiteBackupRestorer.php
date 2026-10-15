<?php

namespace App\Recovery;

use Throwable;

class MySqlSiteBackupRestorer
{
    /** @var array<int, string> */
    private array $clients;

    /**
     * @param  array<int, string>|null  $clients
     */
    public function __construct(?array $clients = null)
    {
        $this->clients = $clients ?? [
            '/usr/bin/mariadb',
            '/usr/bin/mysql',
        ];
    }

    public function target(string $driver, string $dumpPath, ?string $credentialsFile, ?string $database): MySqlRestoreTarget
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new SiteBackupException('Logical database restore supports only MySQL and MariaDB.');
        }
        if (! is_file($dumpPath) || is_link($dumpPath)) {
            throw new SiteBackupException('Verified logical database artifact is missing or unsafe for restore.');
        }
        $this->assertNoSymlinks($dumpPath, 'Logical database artifact path');
        if (! is_string($database) || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1) {
            throw new SiteBackupException('Logical database restore requires a safe explicit target database name.');
        }

        $credentials = $this->credentialsFile($credentialsFile);
        $this->client();

        return new MySqlRestoreTarget(
            $driver,
            $dumpPath,
            $credentials,
            $database,
            $this->endpoint($credentials),
        );
    }

    /**
     * Confirm that the explicitly provisioned target database is reachable and
     * empty without changing it.
     */
    public function preflight(MySqlRestoreTarget $target): void
    {
        $output = $this->run(
            $target,
            [
                '--batch',
                '--skip-column-names',
                '--execute=SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
            ],
            null,
            true,
        );
        $count = trim($output);
        if (preg_match('/\A0\z/D', $count) !== 1) {
            throw new SiteBackupException('Logical database restore requires an empty isolated target database.');
        }
    }

    public function restore(MySqlRestoreTarget $target): void
    {
        $this->run($target, [], $target->dumpPath, false);
    }

    private function credentialsFile(?string $credentialsFile): string
    {
        if (! is_string($credentialsFile) || ! str_starts_with($credentialsFile, DIRECTORY_SEPARATOR)
            || ! is_file($credentialsFile) || is_link($credentialsFile)) {
            throw new SiteBackupException('Logical database restore requires an operator-provided isolated target credentials file.');
        }
        $this->assertNoSymlinks($credentialsFile, 'Target database credentials path');
        $mode = fileperms($credentialsFile);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new SiteBackupException('Target database credentials file must not be accessible to group or other users.');
        }
        $resolved = realpath($credentialsFile);
        if ($resolved === false || filesize($resolved) > 65536) {
            throw new SiteBackupException('Target database credentials file is unsafe.');
        }

        return $resolved;
    }

    private function endpoint(string $credentialsFile): string
    {
        $contents = file_get_contents($credentialsFile);
        if (! is_string($contents) || str_contains($contents, "\0")
            || preg_match('/^\s*!include(?:dir)?\b/m', $contents) === 1) {
            throw new SiteBackupException('Target database credentials file has an unsupported format.');
        }

        $client = false;
        $settings = [];
        $allowedSettings = [
            'host',
            'password',
            'port',
            'protocol',
            'socket',
            'ssl-ca',
            'ssl-cert',
            'ssl-key',
            'ssl-mode',
            'ssl-verify-server-cert',
            'tls-version',
            'user',
        ];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (preg_match('/\A\[([^]]+)\]\z/D', $line, $matches) === 1) {
                if (strtolower($matches[1]) !== 'client') {
                    throw new SiteBackupException('Target database credentials file may contain only a [client] section.');
                }
                $client = true;

                continue;
            }
            if (! $client || preg_match('/\A([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(.*)\z/D', $line, $matches) !== 1) {
                throw new SiteBackupException('Target database credentials file has an unsupported format.');
            }
            $key = strtolower($matches[1]);
            if (! in_array($key, $allowedSettings, true)) {
                throw new SiteBackupException("Target database credentials file contains unsupported option [{$key}].");
            }
            if (in_array($key, ['host', 'port', 'socket'], true)) {
                $settings[$key] = trim($matches[2], " \t\"'");
            }
        }

        $socket = $settings['socket'] ?? '';
        if ($socket !== '') {
            if (! str_starts_with($socket, DIRECTORY_SEPARATOR) || str_contains($socket, "\0")) {
                throw new SiteBackupException('Target database credentials file has an invalid socket endpoint.');
            }

            return 'socket:'.$socket;
        }

        $host = $settings['host'] ?? '';
        $port = $settings['port'] ?? '3306';
        if ($host === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]*\z/D', $host) !== 1
            || preg_match('/\A[0-9]{1,5}\z/D', $port) !== 1 || (int) $port > 65535) {
            throw new SiteBackupException('Target database credentials file must declare a safe host and port.');
        }

        return 'tcp:'.strtolower($host).':'.(int) $port;
    }

    private function run(MySqlRestoreTarget $target, array $options, ?string $input, bool $captureOutput): string
    {
        $command = [
            $this->client(),
            '--defaults-extra-file='.$target->credentialsFile,
            '--binary-mode',
            '--local-infile=0',
            '--database='.$target->database,
            ...$options,
        ];
        $descriptors = [
            0 => $input === null ? ['file', '/dev/null', 'r'] : ['file', $input, 'r'],
            1 => $captureOutput ? ['pipe', 'w'] : ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new SiteBackupException('Logical database restore could not be started.');
        }

        try {
            $output = $captureOutput ? stream_get_contents($pipes[1]) : '';
            if ($captureOutput && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            $status = proc_close($process);
        } catch (Throwable) {
            throw new SiteBackupException('Logical database restore could not be completed.');
        }
        if ($status !== 0 || ! is_string($output)) {
            throw new SiteBackupException('Logical database restore failed. Confirm the isolated target access and retry.');
        }

        return $output;
    }

    private function client(): string
    {
        foreach ($this->clients as $client) {
            if (is_file($client) && is_executable($client) && ! is_link($client)) {
                return $client;
            }
        }

        throw new SiteBackupException('Database restore client is unavailable. Install the supported mariadb-client package and retry.');
    }

    private function assertNoSymlinks(string $path, string $label): void
    {
        $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
        $cursor = DIRECTORY_SEPARATOR;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $cursor .= ($cursor === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR).$segment;
            if (is_link($cursor)) {
                throw new SiteBackupException("{$label} contains a symbolic link.");
            }
        }
    }
}
