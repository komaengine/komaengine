<?php

namespace Database\Seeders;

use App\Engine\Settings\Settings;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $staff = [
            ['name' => 'Rei Tanaka', 'email' => 'admin@koma.test', 'role' => 'Administrator'],
            ['name' => 'Yuki M.', 'email' => 'editor@koma.test', 'role' => 'Editor'],
            ['name' => 'Koma Scans', 'email' => 'uploader@koma.test', 'role' => 'Uploader'],
            ['name' => 'mod_haru', 'email' => 'mod@koma.test', 'role' => 'Moderator'],
            ['name' => 'sora.reads', 'email' => 'member@koma.test', 'role' => 'Member'],
        ];

        foreach ($staff as $person) {
            $user = User::updateOrCreate(
                ['email' => $person['email']],
                ['name' => $person['name'], 'password' => Hash::make('password')],
            );

            $user->syncRoles([$person['role']]);
        }

        $this->call(SampleDataSeeder::class);

        // Sensible defaults so a freshly seeded site is fully configured.
        app(Settings::class)->setMany([
            'site_title' => 'Koma Engine',
            'tagline' => 'Read manga, support creators',
            'active_theme' => 'aozora',
            'default_reading_direction' => 'rtl',
            'mature_gate' => true,
            'enabled_plugins' => ['hello-koma'],
        ]);
    }
}
