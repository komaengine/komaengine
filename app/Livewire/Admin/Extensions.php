<?php

namespace App\Livewire\Admin;

use App\Engine\Plugins\PluginManager;
use App\Engine\Themes\ThemeManager;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Extensions extends Component
{
    #[Locked]
    public string $tab = 'themes';

    public function mount(?string $tab = null): void
    {
        if (in_array($tab, ['themes', 'plugins', 'hooks'], true)) {
            $this->tab = $tab;
        }

        Gate::authorize($this->tab === 'themes' ? 'manage_themes' : 'manage_plugins');
    }

    public function activateTheme(string $slug, ThemeManager $themes): void
    {
        Gate::authorize('manage_themes');

        if (! $themes->activate($slug)) {
            session()->flash('error', 'That theme is missing or is not compatible with this server.');

            return;
        }

        session()->flash('status', 'Theme activated.');
    }

    public function togglePlugin(string $slug, PluginManager $plugins): void
    {
        Gate::authorize('manage_plugins');

        if ($plugins->isEnabled($slug)) {
            $plugins->disable($slug);
            session()->flash('status', 'Plugin disabled.');

            return;
        }

        if (! $plugins->enable($slug)) {
            session()->flash('error', 'That plugin is missing or is not compatible with this server.');

            return;
        }

        session()->flash('status', 'Plugin enabled. It will boot on the next request.');
    }

    public function clearPluginFailure(string $slug, PluginManager $plugins): void
    {
        Gate::authorize('manage_plugins');

        $plugins->clearFailure($slug);
        session()->flash('status', 'Plugin failure notice cleared.');
    }

    public function render(ThemeManager $themes, PluginManager $plugins)
    {
        Gate::authorize($this->tab === 'themes' ? 'manage_themes' : 'manage_plugins');

        return view('livewire.admin.extensions', [
            'themes' => $this->tab === 'themes' ? array_values($themes->all()) : [],
            'plugins' => $this->tab !== 'themes' ? array_values($plugins->all()) : [],
            'hooks' => $this->tab !== 'themes' ? config('koma.hooks', []) : [],
            'komaVersion' => config('koma.version'),
        ]);
    }
}
