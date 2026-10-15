<?php

namespace App\Livewire\Admin;

use App\Models\Tag;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Tags extends Component
{
    public string $name = '';

    public function mount(): void
    {
        Gate::authorize('manage_taxonomy');
    }

    public function add(): void
    {
        Gate::authorize('manage_taxonomy');

        $this->validate([
            'name' => ['required', 'string', 'max:60', 'unique:tags,name'],
        ]);

        Tag::create(['name' => $this->name]);

        $this->reset('name');
        session()->flash('status', 'Tag added.');
    }

    public function remove(int $id): void
    {
        Gate::authorize('manage_taxonomy');

        Tag::whereKey($id)->delete();
        session()->flash('status', 'Tag removed.');
    }

    public function render()
    {
        return view('livewire.admin.tags', [
            'tags' => Tag::orderBy('name')->get(),
        ]);
    }
}
