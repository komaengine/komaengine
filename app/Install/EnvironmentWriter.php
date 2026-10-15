<?php

namespace App\Install;

use Illuminate\Support\Facades\File;

class EnvironmentWriter
{
    /**
     * @param  array<string, string>  $values
     */
    public function write(array $values): ?EnvironmentBackup
    {
        if (config('installer.environment_mode') === 'process'
            || ! config('installer.write_env')
            || (app()->environment('testing') && ! config('installer.write_env_in_tests'))) {
            return null;
        }

        $path = (string) config('installer.environment_path', base_path('.env'));
        $example = (string) config('installer.environment_example_path', base_path('.env.example'));
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InstallationException(
                'ENV_DIRECTORY_NOT_WRITABLE',
                'The environment-file directory is not writable.',
            );
        }

        $lockPath = $path.'.lock';
        $lock = @fopen($lockPath, 'c+');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new InstallationException(
                'ENV_LOCK_UNAVAILABLE',
                'The environment file could not be locked for an atomic update.',
            );
        }

        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $backupPath = null;
        $existed = File::exists($path);

        try {
            if ($existed && ! is_writable($path)) {
                throw new InstallationException('ENV_NOT_WRITABLE', 'The environment file is not writable.');
            }

            $content = $existed
                ? File::get($path)
                : (File::exists($example) ? File::get($example) : '');
            $mode = 0600;

            foreach ($values as $key => $value) {
                $line = $key.'='.$this->formatValue($value);
                $pattern = '/^'.preg_quote($key, '/').'=.*/m';

                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, $line, $content) ?? $content;
                } else {
                    $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
                }
            }

            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new InstallationException('ENV_WRITE_FAILED', 'The environment update could not be written.');
            }

            chmod($temporary, $mode);

            if ($existed) {
                $backupPath = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.bak';

                if (! copy($path, $backupPath)) {
                    throw new InstallationException('ENV_BACKUP_FAILED', 'The environment backup could not be created.');
                }

                chmod($backupPath, $mode);
            }

            if (! @rename($temporary, $path)) {
                throw new InstallationException('ENV_REPLACE_FAILED', 'The environment file could not be replaced atomically.');
            }

            $cachedConfig = (string) config('installer.cached_config_path', app()->getCachedConfigPath());
            if (File::exists($cachedConfig) && ! File::delete($cachedConfig)) {
                throw new InstallationException(
                    'CONFIG_CACHE_CLEAR_FAILED',
                    'The stale configuration cache could not be removed.',
                );
            }

            return new EnvironmentBackup($path, $backupPath, $existed);
        } catch (\Throwable $exception) {
            File::delete($temporary);

            if ($backupPath !== null && File::exists($backupPath)) {
                @rename($backupPath, $path);
            } elseif (! $existed) {
                File::delete($path);
            }

            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            File::delete($lockPath);
        }
    }

    protected function formatValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s|#|=|"|\'/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
