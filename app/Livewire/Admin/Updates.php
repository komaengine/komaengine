<?php

namespace App\Livewire\Admin;

use App\Engine\Updates\UpdateCheck;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Updates extends Component
{
    public function enableChecks(UpdateCheck $updates): void
    {
        Gate::authorize('manage_settings');

        if ($updates->allowedByServer()) {
            $updates->enable();
        }
    }

    public function disableChecks(UpdateCheck $updates): void
    {
        Gate::authorize('manage_settings');

        $updates->disable();
    }

    public function render()
    {
        $updates = app(UpdateCheck::class);

        return view('livewire.admin.updates', [
            'update' => $updates->status(),
            'feedUrl' => (string) config('koma.updates.feed_url'),
            'updateGuideUrl' => $updates->updateGuideUrl(),
        ]);
    }
}
