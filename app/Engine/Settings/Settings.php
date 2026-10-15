<?php

namespace App\Engine\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * A cached key/value store backed by the `settings` table.
 *
 * Values are JSON-encoded so booleans, numbers and arrays round-trip cleanly.
 * The whole map is stored in the configured cache; the engine reads settings
 * on nearly every request, so this keeps ordinary reads to a single query or
 * zero. We intentionally do not retain a second process-local copy because
 * queue workers must observe cache invalidation performed by another request.
 *
 * All database access is guarded: before the migrations have run (fresh install,
 * flat-file setup) reads fall back to defaults instead of throwing.
 */
class Settings
{
    protected const CACHE_KEY = 'koma.settings';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()
                ->pluck('value', 'key')
                ->map(fn ($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR))
                ->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $items = $this->all();

        return array_key_exists($key, $items) ? $items[$key] : $default;
    }

    /**
     * Read a credential that was stored with {@see self::setSecret()}.
     *
     * A value that cannot be decrypted (the application key was rotated, or the
     * row predates encryption) is reported as absent rather than thrown: mail
     * failing to authenticate is a better outcome than the admin screen 500ing.
     */
    public function getSecret(string $key, string $default = ''): string
    {
        $stored = $this->get($key);

        if (! is_string($stored) || $stored === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $default;
        }
    }

    /** Store a credential encrypted with the application key. */
    public function setSecret(string $key, ?string $value): void
    {
        $this->setManyWithSecrets([], [$key => $value]);
    }

    /**
     * Atomically persist ordinary settings and encrypted credentials.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, null|string>  $secrets
     */
    public function setManyWithSecrets(array $values, array $secrets): void
    {
        foreach ($secrets as $key => $value) {
            $values[$key] = ($value === null || $value === '')
                ? ''
                : Crypt::encryptString($value);
        }

        $this->setMany($values);
    }

    public function hasSecret(string $key): bool
    {
        $stored = $this->get($key);

        return is_string($stored) && $stored !== '';
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Persist several settings at once.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->ensureTableReady();
        $now = now();
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = [
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            Setting::query()->upsert(
                $rows,
                ['key'],
                ['value', 'updated_at'],
            );
        });

        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        $this->ensureTableReady();

        DB::transaction(function () use ($key): void {
            Setting::query()->where('key', $key)->delete();
        });

        Cache::forget(self::CACHE_KEY);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function ensureTableReady(): void
    {
        if (! $this->tableReady()) {
            throw new RuntimeException('The settings table is unavailable. Run the database migrations and retry.');
        }
    }

    protected function tableReady(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }
}
