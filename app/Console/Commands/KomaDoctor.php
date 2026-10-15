<?php

namespace App\Console\Commands;

use App\Install\InstalledState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class KomaDoctor extends Command
{
    protected $signature = 'koma:doctor
        {--mode=operational : bootstrap or operational readiness}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Check KomaEngine configuration and operational readiness';

    public function handle(InstalledState $installedState): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['bootstrap', 'operational'], true)) {
            $this->error('Mode must be bootstrap or operational.');

            return self::INVALID;
        }

        $checks = [
            'app_key' => $this->validApplicationKey(),
            'media_driver' => config('filesystems.disks.media.driver') === 'local',
            'database' => $this->databaseReady(),
            'migrations' => $this->migrationsReady(),
            'runtime_paths' => $this->runtimePathsReady(),
            'cache' => $this->cacheReady(),
            'storage_link' => $this->storageLinkReady(),
            'legacy_public_media_absent' => ! file_exists((string) config('installer.legacy_public_media_path'))
                && ! is_link((string) config('installer.legacy_public_media_path')),
        ];

        if ($mode === 'operational') {
            $checks['installed'] = $installedState->installed();
        }

        $ready = ! in_array(false, $checks, true);
        $payload = [
            'mode' => $mode,
            'ready' => $ready,
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line(sprintf('%-16s %s', $name, $passed ? 'OK' : 'FAIL'));
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    protected function validApplicationKey(): bool
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }

    protected function databaseReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function migrationsReady(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $ran = DB::table('migrations')->pluck('migration')->all();
            $expected = collect(File::files(database_path('migrations')))
                ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
                ->all();

            return array_diff($expected, $ran) === [];
        } catch (Throwable) {
            return false;
        }
    }

    protected function runtimePathsReady(): bool
    {
        foreach ($this->runtimePaths() as $path) {
            try {
                File::ensureDirectoryExists($path);
                $probe = $path.'/.koma-doctor-'.str()->uuid();

                if (File::put($probe, 'ready') === false
                    || File::get($probe) !== 'ready'
                    || ! File::delete($probe)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    protected function cacheReady(): bool
    {
        $key = 'koma.doctor.'.str()->uuid();

        try {
            Cache::put($key, 'ready', 30);
            $ready = Cache::get($key) === 'ready';
            Cache::forget($key);

            return $ready;
        } catch (Throwable) {
            return false;
        }
    }

    protected function storageLinkReady(): bool
    {
        $link = (string) config('installer.public_storage_link', public_path('storage'));
        $target = (string) config('installer.public_storage_target', storage_path('app/public'));

        $resolved = realpath($link);

        // A dangling link and a missing target both resolve to false; that is
        // a broken link, not a matching one.
        return is_link($link) && $resolved !== false && $resolved === realpath($target);
    }

    /**
     * @return list<string>
     */
    protected function runtimePaths(): array
    {
        return [
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            storage_path('app/media'),
            storage_path('app/private'),
            storage_path('app/archive-import-temp'),
            base_path('bootstrap/cache'),
        ];
    }
}
