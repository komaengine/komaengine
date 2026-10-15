<?php

namespace App\Livewire\Admin;

use App\Models\Genre;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Genres extends Component
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
            'name' => ['required', 'string', 'max:60', 'unique:genres,name'],
        ]);

        Genre::create(['name' => $this->name]);

        $this->reset('name');
        session()->flash('status', 'Genre added.');
    }

    public function remove(int $id): void
    {
        Gate::authorize('manage_taxonomy');

        Genre::whereKey($id)->delete();
        session()->flash('status', 'Genre removed.');
    }

    public function render()
    {
        return view('livewire.admin.genres', [
            'genres' => Genre::withCount('series')->orderBy('name')->get(),
        ]);
    }
}
