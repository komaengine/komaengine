<div>
    <h1 class="font-display text-[21px] font-extrabold">Sign in</h1>
    <p class="mb-5 mt-1 text-[13.5px] text-muted">Welcome back. Sign in to manage your library.</p>

    <form wire:submit="login" class="flex flex-col gap-4">
        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Email</label>
            <input type="email" wire:model="email" autofocus autocomplete="username"
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
            @error('email') <p class="mt-1.5 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Password</label>
            <input type="password" wire:model="password" autocomplete="current-password"
                   class="h-11 w-full rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma">
            @error('password') <p class="mt-1.5 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        </div>

        <label class="flex cursor-pointer items-center gap-2.5 text-[13px] text-[#57524c]">
            <input type="checkbox" wire:model="remember" class="h-4 w-4 rounded accent-koma">
            Remember me
        </label>

        <button type="submit"
                class="mt-1 h-11 w-full rounded-[11px] bg-koma text-[14.5px] font-bold text-white hover:bg-koma-hover"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>

    @if (\App\Support\Koma::setting('allow_registration', true))
        <p class="mt-5 text-center text-[13px] text-muted">
            New here?
            <a href="{{ route('register') }}" class="font-semibold text-koma hover:underline">Create an account</a>
        </p>
    @endif
</div>
