<div class="flex flex-col gap-5">
    @if ($statusMessage)
        <div role="status" class="rounded-xl border border-[oklch(0.7_0.13_150_/_0.3)] bg-[oklch(0.7_0.13_150_/_0.12)] px-4 py-3 text-[13.5px] font-semibold text-[oklch(0.4_0.12_150)]">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)]">
        <section class="rounded-2xl border border-line bg-white p-5 sm:p-6" aria-labelledby="issue-token-title">
            <div class="mb-5">
                <p class="mb-1 text-[11px] font-extrabold uppercase tracking-[0.12em] text-koma">Bulk publishing API</p>
                <h2 id="issue-token-title" class="font-display text-xl font-extrabold tracking-tight">Create an API token</h2>
                <p class="mt-2 max-w-2xl text-[13.5px] leading-6 text-muted">
                    Tokens inherit the selected user's upload permissions. The secret is shown once and cannot be recovered later.
                </p>
            </div>

            <form wire:submit="issue" class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col gap-1.5 sm:col-span-2">
                    <span class="text-[12px] font-bold text-[#57524c]">Token owner</span>
                    <select wire:model="userId" class="h-11 rounded-[10px] border border-line bg-white px-3 text-sm font-semibold outline-none focus:border-koma focus:ring-2 focus:ring-koma/10">
                        <option value="">Choose an uploader</option>
                        @foreach ($eligibleUsers as $eligibleUser)
                            <option value="{{ $eligibleUser->id }}">{{ $eligibleUser->name }} · {{ $eligibleUser->email }}</option>
                        @endforeach
                    </select>
                    @error('userId') <span class="text-xs font-semibold text-koma">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-[12px] font-bold text-[#57524c]">Token name</span>
                    <input wire:model="tokenName" type="text" maxlength="100" autocomplete="off"
                           placeholder="Migration importer"
                           class="h-11 rounded-[10px] border border-line px-3 text-sm outline-none placeholder:text-faint focus:border-koma focus:ring-2 focus:ring-koma/10">
                    @error('tokenName') <span class="text-xs font-semibold text-koma">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-[12px] font-bold text-[#57524c]">Expires <span class="font-medium text-faint">(optional)</span></span>
                    <input wire:model="expiresAt" type="datetime-local" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}"
                           class="h-11 rounded-[10px] border border-line px-3 text-sm outline-none focus:border-koma focus:ring-2 focus:ring-koma/10">
                    @error('expiresAt') <span class="text-xs font-semibold text-koma">{{ $message }}</span> @enderror
                </label>

                <div class="flex items-center gap-3 pt-1 sm:col-span-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="issue"
                            class="inline-flex min-h-11 items-center justify-center rounded-[10px] bg-koma px-5 text-sm font-extrabold text-white hover:bg-koma-hover disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="issue">Create token</span>
                        <span wire:loading wire:target="issue">Creating…</span>
                    </button>
                    <span class="text-xs text-faint">Administrator access required</span>
                </div>
            </form>
        </section>

        <aside class="rounded-2xl border border-line bg-[#faf8f6] p-5 sm:p-6" aria-labelledby="api-quick-start-title">
            <h2 id="api-quick-start-title" class="font-display text-base font-extrabold">Quick start</h2>
            <ol class="mt-4 flex flex-col gap-4 text-[13.5px] text-[#57524c]">
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-koma-soft text-xs font-extrabold text-koma">1</span><span>Create a token for an Administrator, Editor, or Uploader.</span></li>
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-koma-soft text-xs font-extrabold text-koma">2</span><span>Store the one-time secret in your importer's secret manager.</span></li>
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-koma-soft text-xs font-extrabold text-koma">3</span><span>Send multipart batches to the versioned endpoint.</span></li>
            </ol>
            <div class="mt-5 overflow-x-auto rounded-[10px] border border-line bg-white p-3 font-mono text-[11px] leading-5 text-[#57524c]">
                POST /api/v1/series/{series-slug}/chapters/bulk
            </div>
            <p class="mt-4 text-xs leading-5 text-faint">The full request contract and curl example are in <code class="font-mono text-[11px] text-muted">docs/bulk-upload-api.md</code>.</p>
        </aside>
    </div>

    @if ($newPlainTextToken)
        <section x-data="{
                    copied: false,
                    async copyToken() {
                        const input = this.$refs.token;

                        try {
                            await navigator.clipboard.writeText(input.value);
                        } catch (error) {
                            input.select();
                            document.execCommand('copy');
                        }

                        this.copied = true;
                        setTimeout(() => this.copied = false, 1800);
                    }
                 }"
                 class="rounded-2xl border border-[oklch(0.7_0.13_150_/_0.35)] bg-[oklch(0.7_0.13_150_/_0.09)] p-5 sm:p-6" aria-labelledby="new-token-title">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-[11px] font-extrabold uppercase tracking-[0.12em] text-[oklch(0.4_0.12_150)]">Created successfully</p>
                    <h2 id="new-token-title" class="mt-1 font-display text-lg font-extrabold">Copy {{ $issuedTokenName }} now</h2>
                    <p class="mt-1 text-[13px] text-[#5e6a61]">This secret will disappear when you dismiss it or leave this page.</p>
                </div>
                <button type="button" wire:click="dismissNewToken" class="min-h-10 rounded-[9px] border border-[oklch(0.7_0.13_150_/_0.35)] bg-white px-3 text-xs font-bold text-[#4e5e52] hover:bg-white/70">I've stored it</button>
            </div>
            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <input x-ref="token" type="text" readonly value="{{ $newPlainTextToken }}" aria-label="New API token"
                       class="min-h-11 min-w-0 flex-1 rounded-[10px] border border-[oklch(0.7_0.13_150_/_0.35)] bg-white px-3 font-mono text-xs outline-none focus:border-[oklch(0.5_0.12_150)]">
                <button type="button" @click="copyToken()"
                        class="min-h-11 rounded-[10px] bg-[oklch(0.43_0.12_150)] px-5 text-sm font-extrabold text-white hover:bg-[oklch(0.38_0.12_150)]">
                    <span aria-live="polite" x-text="copied ? 'Copied' : 'Copy token'">Copy token</span>
                </button>
            </div>
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl border border-line bg-white" aria-labelledby="active-tokens-title">
        <div class="flex items-center justify-between gap-4 border-b border-line bg-[#faf8f6] px-5 py-4">
            <div>
                <h2 id="active-tokens-title" class="font-display text-base font-extrabold">Issued tokens</h2>
                <p class="mt-0.5 text-xs text-faint">Revoke credentials that are no longer needed.</p>
            </div>
            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-muted">{{ $tokens->count() }}</span>
        </div>

        @if ($tokens->isEmpty())
            <div class="px-5 py-12 text-center">
                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-paper text-xl text-faint">⌁</div>
                <p class="mt-3 text-sm font-bold">No API tokens yet</p>
                <p class="mt-1 text-xs text-faint">Create one above when an importer is ready to connect.</p>
            </div>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[760px] border-collapse text-[13px]">
                    <thead>
                        <tr class="text-left text-[11px] font-bold uppercase tracking-wide text-faint">
                            <th class="border-b border-line px-5 py-3">Token</th>
                            <th class="border-b border-line px-4 py-3">Owner</th>
                            <th class="border-b border-line px-4 py-3">Status</th>
                            <th class="border-b border-line px-4 py-3">Last used</th>
                            <th class="border-b border-line px-4 py-3">Expires</th>
                            <th class="border-b border-line px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr wire:key="api-token-desktop-{{ $token->id }}">
                                <td class="border-b border-line-soft px-5 py-3.5 last:border-0">
                                    <span class="block font-bold">{{ $token->name }}</span>
                                    <span class="text-[11px] text-faint">Created {{ \App\Support\Koma::timeAgo($token->created_at) }}</span>
                                </td>
                                <td class="border-b border-line-soft px-4 py-3.5">
                                    <span class="block font-semibold">{{ $token->user->name }}</span>
                                    <span class="text-[11px] text-faint">{{ $token->user->email }}</span>
                                </td>
                                <td class="border-b border-line-soft px-4 py-3.5">
                                    @if ($token->expired())
                                        <span class="rounded-full bg-koma-soft px-2.5 py-1 text-[11px] font-extrabold text-koma">Expired</span>
                                    @else
                                        <span class="rounded-full bg-[oklch(0.7_0.13_150_/_0.12)] px-2.5 py-1 text-[11px] font-extrabold text-[oklch(0.4_0.12_150)]">Active</span>
                                    @endif
                                </td>
                                <td class="border-b border-line-soft px-4 py-3.5 text-[#57524c]" title="{{ $token->last_used_at?->toDayDateTimeString() }}">{{ \App\Support\Koma::timeAgo($token->last_used_at) ?: 'Never' }}</td>
                                <td class="border-b border-line-soft px-4 py-3.5 text-[#57524c]">{{ $token->expires_at?->format('M j, Y') ?? 'Never' }}</td>
                                <td class="border-b border-line-soft px-5 py-3.5 text-right">
                                    <button type="button" wire:click="revoke({{ $token->id }})" wire:confirm="Revoke {{ $token->name }}? Any importer using it will immediately lose access."
                                            class="min-h-9 rounded-[9px] border border-line px-3 text-xs font-bold text-[#6d3329] hover:border-koma/30 hover:bg-koma-soft">Revoke</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-line-soft md:hidden">
                @foreach ($tokens as $token)
                    <article class="p-4" wire:key="api-token-mobile-{{ $token->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-extrabold">{{ $token->name }}</h3>
                                <p class="mt-0.5 truncate text-xs text-faint">{{ $token->user->name }} · {{ $token->user->email }}</p>
                            </div>
                            @if ($token->expired())
                                <span class="shrink-0 rounded-full bg-koma-soft px-2.5 py-1 text-[10px] font-extrabold text-koma">Expired</span>
                            @else
                                <span class="shrink-0 rounded-full bg-[oklch(0.7_0.13_150_/_0.12)] px-2.5 py-1 text-[10px] font-extrabold text-[oklch(0.4_0.12_150)]">Active</span>
                            @endif
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-3 rounded-xl bg-[#faf8f6] p-3 text-xs">
                            <div><dt class="font-bold text-faint">Last used</dt><dd class="mt-1 font-semibold text-[#57524c]">{{ \App\Support\Koma::timeAgo($token->last_used_at) ?: 'Never' }}</dd></div>
                            <div><dt class="font-bold text-faint">Expires</dt><dd class="mt-1 font-semibold text-[#57524c]">{{ $token->expires_at?->format('M j, Y') ?? 'Never' }}</dd></div>
                        </dl>
                        <button type="button" wire:click="revoke({{ $token->id }})" wire:confirm="Revoke {{ $token->name }}? Any importer using it will immediately lose access."
                                class="mt-3 min-h-10 w-full rounded-[9px] border border-line text-xs font-bold text-[#6d3329] hover:border-koma/30 hover:bg-koma-soft">Revoke token</button>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
