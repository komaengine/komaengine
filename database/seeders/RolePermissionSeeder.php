<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the manga-specific roles and capabilities from the Users & Roles mockup.
 *
 * Idempotent: safe to run on every deploy / install. New capabilities added
 * here are automatically granted to Administrator.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Capability => which roles hold it, mirroring the mockup's matrix
     * (plus a few admin-only capabilities for sections beyond it).
     */
    protected array $matrix = [
        'upload_chapters' => ['Administrator', 'Editor', 'Uploader'],
        'edit_series' => ['Administrator', 'Editor'],
        'manage_taxonomy' => ['Administrator', 'Editor'],
        'moderate_comments' => ['Administrator', 'Editor', 'Moderator'],
        'view_analytics' => ['Administrator', 'Editor'],
        'manage_users' => ['Administrator'],
        'configure_monetization' => ['Administrator'],
        'manage_settings' => ['Administrator'],
        'manage_themes' => ['Administrator'],
        'manage_plugins' => ['Administrator'],
        'comment' => ['Administrator', 'Editor', 'Uploader', 'Moderator', 'Member'],
    ];

    protected array $roles = ['Administrator', 'Editor', 'Uploader', 'Moderator', 'Member'];

    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($this->matrix as $capability => $grantedTo) {
            Permission::findOrCreate($capability, 'web');

            foreach ($this->roles as $role) {
                $roleModel = Role::findByName($role, 'web');

                if (in_array($role, $grantedTo, true)) {
                    $roleModel->givePermissionTo($capability);
                } else {
                    $roleModel->revokePermissionTo($capability);
                }
            }
        }

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
