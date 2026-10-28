<?php

namespace App\Console\Commands;

use App\Install\InstalledState;
use App\Media\ProtectMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class KomaSetup extends Command
{
    protected $signature = 'koma:setup
        {--json : Emit machine-readable JSON}';

    protected $description = 'Prepare a flat-file install for the web installer (safe to rerun)';

    /** @var list<array{step: string, result: string, detail: string, ok: bool}> */
    protected array $steps = [];

    public function handle(InstalledState $installedState, ProtectMediaStorage $media): int
    {
        $this->steps = [];
        $managedEnvironment = config('installer.environment_mode') === 'process';
        $envCreated = false;

        if ($managedEnvironment) {
            $this->record('env', 'skipped', 'managed by the process environment', true);
            $this->record('app_key', 'skipped', 'managed by the process environment', true);
        } else {
            $envCreated = $this->prepareEnvironmentFile();
            if ($this->lastStepOk()) {
                $this->prepareApplicationKey();
            }
        }

        $this->prepareStorageLink();
        $this->prepareMediaStorage($media);

        $stepsOk = ! in_array(false, array_column($this->steps, 'ok'), true);
        $doctor = null;
        $ready = false;

        // A .env created by this run was not loaded when the command started,
        // so its database settings cannot be checked until the next run.
        if ($stepsOk && ! $envCreated) {
            // Run the doctor into its own buffer: a nested Artisan::call would
            // replace the output a caller of this command reads back.
            $buffer = new BufferedOutput;
            $input = new ArrayInput(['--mode' => 'bootstrap', '--json' => true]);
            $status = $this->getApplication()
                ->find('koma:doctor')
                ->run($input, new OutputStyle($input, $buffer));
            $doctor = json_decode(trim($buffer->fetch()), true) ?: null;
            $ready = $status === self::SUCCESS;
        }

        $installed = $installedState->installed();
        $next = $this->nextSteps($stepsOk, $envCreated, $ready, $installed);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ready' => $ready,
                'installed' => $installed,
                'steps' => $this->steps,
                'doctor' => $doctor,
                'next' => $next,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($doctor, $next);
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    protected function prepareEnvironmentFile(): bool
    {
        $path = $this->environmentPath();
        $example = (string) config('installer.environment_example_path', base_path('.env.example'));
        $created = false;

        if (! file_exists($path)) {
            if (! is_file($example)) {
                $this->record('env', 'failed', '.env and .env.example are both missing', false);

                return false;
            }

            if (! @copy($example, $path)) {
                $this->record('env', 'failed', 'could not create .env from .env.example', false);

                return false;
            }

            $created = true;
        }

        if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            $this->record('env', 'failed', '.env must be a file this account can read and write', false);

            return $created;
        }

        $mode = fileperms($path) & 0777;
        if ($mode !== 0600 && ! @chmod($path, 0600)) {
            $this->record('env', 'failed', sprintf('mode %04o is unsafe and could not be changed; run chmod 600 .env', $mode), false);

            return $created;
        }

        clearstatcache(true, $path);
        $result = $created ? 'created' : ($mode !== 0600 ? 'fixed' : 'ok');
        $detail = $created ? 'created from .env.example with mode 0600' : 'mode 0600';
        $this->record('env', $result, $detail, true);

        return $created;
    }

    protected function prepareApplicationKey(): void
    {
        if ($this->validApplicationKey((string) config('app.key', ''))) {
            $this->record('app_key', 'ok', 'already set; left unchanged', true);

            return;
        }

        $path = $this->environmentPath();
        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));

        try {
            $contents = (string) file_get_contents($path);
            $line = 'APP_KEY='.$key;
            $contents = preg_match('/^APP_KEY=.*$/m', $contents)
                ? (string) preg_replace('/^APP_KEY=.*$/m', $line, $contents, 1)
                : rtrim($contents).PHP_EOL.$line.PHP_EOL;

            $this->replaceFile($path, $contents);
        } catch (Throwable $exception) {
            $this->record('app_key', 'failed', 'could not write APP_KEY to .env: '.$exception->getMessage(), false);

            return;
        }

        config(['app.key' => $key]);
        $this->record('app_key', 'generated', 'written to .env', true);
    }

    protected function prepareStorageLink(): void
    {
        $link = (string) config('installer.public_storage_link', public_path('storage'));
        $target = (string) config('installer.public_storage_target', storage_path('app/public'));
        $relative = $this->relativePath(dirname($link), $target);
        $command = sprintf('ln -s %s %s', $relative, $this->displayPath($link));

        if (is_link($link) && realpath($link) !== false && realpath($link) === realpath($target)) {
            $this->record('storage_link', 'ok', 'already linked', true);

            return;
        }

        if (file_exists($link) && ! is_link($link)) {
            $this->record('storage_link', 'failed', 'public/storage is occupied by a file or directory; move it, then run this command again', false);

            return;
        }

        if (! is_dir($target) && ! @mkdir($target, 0755, true) && ! is_dir($target)) {
            $this->record('storage_link', 'failed', 'storage/app/public is missing and could not be created', false);

            return;
        }

        $replacing = is_link($link);
        if (! function_exists('symlink') || $this->symlinkDisabled()) {
            $this->record('storage_link', 'failed', "PHP cannot create links on this host; run: {$command}", false);

            return;
        }

        if ($replacing && ! @unlink($link)) {
            $this->record('storage_link', 'failed', "the existing public/storage link points elsewhere and could not be replaced; run: rm public/storage && {$command}", false);

            return;
        }

        if (! @symlink($relative, $link) || realpath($link) !== realpath($target)) {
            $this->record('storage_link', 'failed', "the link could not be created; run: {$command}", false);

            return;
        }

        $this->record('storage_link', $replacing ? 'fixed' : 'created', "public/storage -> {$relative}", true);
    }

    protected function prepareMediaStorage(ProtectMediaStorage $media): void
    {
        try {
            $moved = $media->migrate();
        } catch (Throwable $exception) {
            $this->record('media', 'failed', 'protected media storage: '.$exception->getMessage(), false);

            return;
        }

        $this->record('media', 'ok', $moved > 0 ? "protected ({$moved} legacy file(s) moved)" : 'protected', true);
    }

    /**
     * @return list<string>
     */
    protected function nextSteps(bool $stepsOk, bool $envCreated, bool $ready, bool $installed): array
    {
        if (! $stepsOk) {
            return ['Fix the failed step above, then run php artisan koma:setup again.'];
        }

        if ($envCreated) {
            return [
                'Edit .env: set APP_URL and the database values (DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD).',
                'Run php artisan koma:setup again to check readiness.',
            ];
        }

        if (! $ready) {
            return ['Fix each FAIL line above (see docs/install/troubleshooting.md), then run php artisan koma:setup again.'];
        }

        if ($installed) {
            return ['KomaEngine is already installed. Run php artisan koma:doctor --mode=operational to check it.'];
        }

        $url = rtrim((string) config('app.url', ''), '/');
        $install = $url !== '' && ! str_contains($url, 'your-domain.example') && ! str_contains($url, 'localhost')
            ? "{$url}/install"
            : 'your site\'s /install address';

        return [
            'Run php artisan koma:installer-token to print the setup token.',
            "Open {$install} over HTTPS and enter the token.",
        ];
    }

    /**
     * @param  array<string, mixed>|null  $doctor
     * @param  list<string>  $next
     */
    protected function render(?array $doctor, array $next): void
    {
        $labels = ['env' => '.env', 'app_key' => 'APP_KEY', 'storage_link' => 'public/storage', 'media' => 'media storage'];

        $this->line('Preparing KomaEngine in '.base_path());
        $this->newLine();

        foreach ($this->steps as $step) {
            $this->line(sprintf('  %-16s %-10s %s', $labels[$step['step']] ?? $step['step'], strtoupper($step['result']), $step['detail']));
        }

        if ($doctor !== null) {
            $this->newLine();
            $this->line('Readiness (koma:doctor --mode=bootstrap):');
            $pending = (array) ($doctor['pending'] ?? []);

            foreach ((array) ($doctor['checks'] ?? []) as $name => $passed) {
                $state = in_array($name, $pending, true) ? 'PENDING' : ($passed ? 'OK' : 'FAIL');
                $this->line(sprintf('  %-26s %s', $name, $state));
            }
        }

        $this->newLine();
        $this->line('Next:');
        foreach ($next as $index => $step) {
            $this->line(sprintf('  %d. %s', $index + 1, $step));
        }
    }

    protected function record(string $step, string $result, string $detail, bool $ok): void
    {
        $this->steps[] = compact('step', 'result', 'detail', 'ok');
    }

    protected function lastStepOk(): bool
    {
        return (bool) (end($this->steps)['ok'] ?? false);
    }

    protected function environmentPath(): string
    {
        return (string) config('installer.environment_path', base_path('.env'));
    }

    protected function validApplicationKey(string $key): bool
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }

    protected function replaceFile(string $path, string $contents): void
    {
        // Some layouts keep the application folder read-only for PHP (the
        // Ubuntu guide does, until the installer runs); write in place there.
        if (! is_writable(dirname($path))) {
            if (file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException('the file could not be written');
            }

            return;
        }

        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('the file could not be replaced');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * The path from $fromDirectory to $to, using the paths as given rather
     * than their resolved form, so a link survives the folder being moved.
     */
    protected function relativePath(string $fromDirectory, string $to): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', $fromDirectory)), 'strlen'));
        $target = array_values(array_filter(explode('/', str_replace('\\', '/', $to)), 'strlen'));

        while ($from !== [] && $target !== [] && $from[0] === $target[0]) {
            array_shift($from);
            array_shift($target);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$target]) ?: '.';
    }

    protected function displayPath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    protected function symlinkDisabled(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array('symlink', $disabled, true);
    }
}
