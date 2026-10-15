<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.admin')]
class Users extends Component
{
    public array $roleNames = ['Administrator', 'Editor', 'Uploader', 'Moderator', 'Member'];

    public function updateRole(int $userId, string $role): void
    {
        if (! in_array($role, $this->roleNames, true)) {
            return;
        }

        // Don't let an admin lock themselves out by self-demoting.
        if ($userId === auth()->id() && $role !== 'Administrator') {
            session()->flash('status', 'You cannot change your own administrator role.');

            return;
        }

        User::find($userId)?->syncRoles([$role]);
        session()->flash('status', 'Role updated.');
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::with('roles')->orderBy('name')->get(),
            'capabilities' => $this->capabilityMatrix(),
        ]);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    protected function capabilityMatrix(): array
    {
        $labels = [
            'upload_chapters' => 'Upload chapters',
            'edit_series' => 'Edit any series',
            'moderate_comments' => 'Moderate comments',
            'manage_users' => 'Manage users',
            'configure_monetization' => 'Configure monetization',
            'comment' => 'Comment & follow',
        ];

        $roles = Role::with('permissions')->get()->keyBy('name');
        $matrix = [];

        foreach ($labels as $perm => $label) {
            foreach ($this->roleNames as $role) {
                $matrix[$label][$role] = (bool) $roles[$role]?->permissions->contains('name', $perm);
            }
        }

        return $matrix;
    }
}
