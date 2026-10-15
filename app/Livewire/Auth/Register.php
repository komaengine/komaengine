<?php

namespace App\Livewire\Auth;

use App\Engine\Settings\Settings;
use App\Models\User;
use App\Support\Facades\Hook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(Settings $settings): void
    {
        // Public registration can be disabled, but the very first account
        // (the site owner) must always be creatable.
        abort_unless(
            (bool) $settings->get('allow_registration', true) || User::count() === 0,
            404,
        );
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function register(Settings $settings)
    {
        // The very first account to register owns the site (Administrator);
        // everyone after is a regular Member. Mirrors the WordPress install flow.
        $isFirstUser = User::count() === 0;

        abort_unless((bool) $settings->get('allow_registration', true) || $isFirstUser, 404);

        $this->validate();

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $defaultRole = (string) $settings->get('default_user_role', 'Member');
        $user->assignRole($isFirstUser ? 'Administrator' : $defaultRole);

        Hook::doAction('user.registered', $user);

        Auth::login($user);
        session()->regenerate();

        return redirect()->intended(
            $user->canAccessAdmin() ? route('admin.dashboard') : url('/')
        );
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
