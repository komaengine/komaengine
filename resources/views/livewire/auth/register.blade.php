<div>
    <h1 class="font-display text-[21px] font-extrabold">Create your account</h1>
    <p class="mb-5 mt-1 text-[13.5px] text-muted">
        @if (\App\Models\User::count() === 0)
            You're the first here — this account becomes the site administrator.
        @else
            Join the community to follow series and comment.
        @endif
    </p>

    <form wire:submit="register" class="flex flex-col gap-4">
        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Name</label>
            <input type="text" wire:model="name" autofocus
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
            @error('name') <p class="mt-1.5 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Email</label>
            <input type="email" wire:model="email" autocomplete="username"
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
            @error('email') <p class="mt-1.5 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Password</label>
            <input type="password" wire:model="password" autocomplete="new-password"
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
            @error('password') <p class="mt-1.5 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Confirm password</label>
            <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
        </div>

        <button type="submit"
                class="mt-1 h-11 w-full rounded-[11px] bg-koma text-[14.5px] font-bold text-white hover:bg-koma-hover"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>

    <p class="mt-5 text-center text-[13px] text-muted">
        Already have an account?
        <a href="{{ route('login') }}" class="font-semibold text-koma hover:underline">Sign in</a>
    </p>
</div>
