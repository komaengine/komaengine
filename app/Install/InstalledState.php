<?php

namespace App\Install;

use App\Engine\Settings\Settings;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InstalledState
{
    public function installed(): bool
    {
        $marker = (string) config('installer.installed_marker_path', storage_path('framework/koma-installed'));
        if ($marker !== '' && (file_exists($marker) || is_link($marker))) {
            return true;
        }

        if (! $this->hasTable('settings')) {
            return false;
        }

        if (app(Settings::class)->has('installed_at')) {
            return true;
        }

        // A modern installer attempt writes this retry marker before it can
        // create an Administrator. Do not mistake a partial attempt for a
        // completed legacy installation merely because a user row exists.
        if (app(Settings::class)->get('installation_state') === 'installing') {
            return false;
        }

        // Legacy/dev installs created before the installer shipped should not
        // be forced back through setup if they already contain real content.
        return ($this->hasTable('users') && User::query()->exists())
            || ($this->hasTable('series') && Series::query()->exists());
    }

    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
