<div class="flex flex-col gap-5">
    @if ($statusMessage)
        <div role="status" class="rounded-xl border border-[oklch(0.7_0.13_150_/_0.3)] bg-[oklch(0.7_0.13_150_/_0.12)] px-4 py-3 text-[13.5px] font-semibold text-[oklch(0.4_0.12_150)]">
            {{ $statusMessage }}
        </div>
    @endif

    <section class="rounded-2xl border border-line bg-white p-5 sm:p-6" aria-labelledby="moderation-title">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="mb-1 text-[11px] font-extrabold uppercase tracking-[0.12em] text-koma">Community safety</p>
                <h2 id="moderation-title" class="font-display text-xl font-extrabold tracking-tight">Comment moderation</h2>
                <p class="mt-2 max-w-2xl text-[13.5px] leading-6 text-muted">Review reader reports and suspicious submissions. Hide is the safe default; permanent deletion is available only after a separate confirmation.</p>
            </div>
            <a href="{{ route('mature.confirmation', ['return_to' => request()->getRequestUri()]) }}" class="text-xs font-bold text-koma hover:underline">Review mature-content access</a>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-5" role="group" aria-label="Moderation filters">
            @foreach (['reported' => 'Reported', 'pending' => 'Pending', 'hidden' => 'Hidden', 'deleted' => 'Deleted', 'all' => 'All'] as $value => $label)
                <button type="button" wire:click="$set('filter', '{{ $value }}')"
                        @class([
                            'min-h-11 rounded-[10px] border px-3 text-left text-xs font-extrabold transition',
                            'border-koma bg-koma-soft text-koma' => $filter === $value,
                            'border-line bg-[#faf8f6] text-muted hover:border-koma/30' => $filter !== $value,
                        ])>
                    <span class="block">{{ $label }}</span>
                    <span class="mt-0.5 block font-mono text-[11px] opacity-70">{{ $counts[$value] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    <form wire:submit="applyBulk" class="grid gap-3 rounded-2xl border border-line bg-[#faf8f6] p-4 sm:grid-cols-[minmax(150px,0.55fr)_minmax(220px,1fr)_auto] sm:items-end">
        <label class="flex flex-col gap-1.5">
            <span class="text-[12px] font-bold text-[#57524c]">Bulk action</span>
            <select wire:model="bulkAction" class="h-11 rounded-[10px] border border-line bg-white px-3 text-sm font-semibold outline-none focus:border-koma focus:ring-2 focus:ring-koma/10">
                <option value="hide">Hide selected</option>
                <option value="approve">Approve selected</option>
                <option value="delete">Soft-delete selected</option>
                <option value="restore">Restore selected</option>
            </select>
        </label>
        <label class="flex flex-col gap-1.5">
            <span class="text-[12px] font-bold text-[#57524c]">Audit note <span class="font-medium text-faint">(optional)</span></span>
            <input wire:model="auditNote" type="text" maxlength="1000" placeholder="Why this action was taken"
                   class="h-11 rounded-[10px] border border-line bg-white px-3 text-sm outline-none placeholder:text-faint focus:border-koma focus:ring-2 focus:ring-koma/10">
        </label>
        <button type="submit" wire:confirm="Apply this moderation action to every selected comment?"
                class="min-h-11 rounded-[10px] bg-koma px-5 text-sm font-extrabold text-white hover:bg-koma-hover disabled:opacity-60">
            Apply to {{ count($selected) }}
        </button>
        @error('selected') <span class="text-xs font-semibold text-koma sm:col-span-3">{{ $message }}</span> @enderror
        @error('auditNote') <span class="text-xs font-semibold text-koma sm:col-span-3">{{ $message }}</span> @enderror
    </form>

    @if ($purgeCommentId)
        <section class="rounded-2xl border border-koma/30 bg-koma-soft p-5" aria-labelledby="purge-title">
            <h2 id="purge-title" class="font-display text-base font-extrabold text-[#6d3329]">Permanently delete comment #{{ $purgeCommentId }}?</h2>
            <p class="mt-1 text-sm leading-6 text-[#765148]">The comment row cannot be restored. Existing reports keep their body, author, and context snapshots for audit history.</p>
            <label class="mt-4 flex max-w-md flex-col gap-1.5">
                <span class="text-xs font-bold text-[#6d3329]">Type DELETE to confirm</span>
                <input wire:model="purgeConfirmation" type="text" autocomplete="off" class="h-11 rounded-[10px] border border-koma/30 bg-white px-3 text-sm outline-none focus:border-koma focus:ring-2 focus:ring-koma/10">
                @error('purgeConfirmation') <span class="text-xs font-semibold text-koma">{{ $message }}</span> @enderror
            </label>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="purge" class="min-h-10 rounded-[9px] bg-[#8d3328] px-4 text-xs font-extrabold text-white hover:bg-[#75281f]">Permanently delete</button>
                <button type="button" wire:click="cancelPurge" class="min-h-10 rounded-[9px] border border-koma/20 bg-white px-4 text-xs font-bold text-[#6d3329]">Cancel</button>
            </div>
        </section>
    @endif

    @if ($comments->isEmpty())
        <section class="rounded-2xl border border-line bg-white px-5 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-paper text-xl text-faint">✓</div>
            <h2 class="mt-3 font-display text-base font-extrabold">Nothing in this queue</h2>
            <p class="mt-1 text-sm text-faint">No comments currently match the {{ $filter }} filter.</p>
        </section>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($comments as $comment)
                @php
                    $target = $comment->commentable;
                    $series = $target instanceof \App\Models\Series ? $target : $target?->series;
                    $contextUrl = $target instanceof \App\Models\Chapter
                        ? route('reader', [$series, $target->displayNumber()]).'#comment-'.$comment->id
                        : route('series.show', $series).'#comment-'.$comment->id;
                    $contextLabel = $target instanceof \App\Models\Chapter
                        ? $series->title.' · '.$target->label()
                        : $series->title.' · Series discussion';
                @endphp
                <article wire:key="moderation-comment-{{ $comment->id }}" class="overflow-hidden rounded-2xl border border-line bg-white">
                    <div class="flex flex-col gap-3 border-b border-line bg-[#faf8f6] px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <input wire:model="selected" id="select-comment-{{ $comment->id }}" type="checkbox" value="{{ $comment->id }}" class="h-4 w-4 shrink-0 rounded border-line text-koma focus:ring-koma">
                            <label for="select-comment-{{ $comment->id }}" class="min-w-0 cursor-pointer">
                                <span class="block truncate text-sm font-extrabold">{{ $comment->user?->name ?? 'Former member' }}</span>
                                <span class="block text-[11px] text-faint">Comment #{{ $comment->id }} · {{ \App\Support\Koma::timeAgo($comment->created_at) }}</span>
                            </label>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 pl-7 sm:pl-0">
                            @if ($comment->trashed())
                                <span class="rounded-full bg-[#eee9e5] px-2.5 py-1 text-[10px] font-extrabold text-muted">Deleted</span>
                            @elseif ($comment->isPending())
                                <span class="rounded-full bg-[#fff3d9] px-2.5 py-1 text-[10px] font-extrabold text-[#744b0f]">Pending</span>
                            @elseif ($comment->isHidden())
                                <span class="rounded-full bg-[#eee9e5] px-2.5 py-1 text-[10px] font-extrabold text-muted">Hidden</span>
                            @else
                                <span class="rounded-full bg-[oklch(0.7_0.13_150_/_0.12)] px-2.5 py-1 text-[10px] font-extrabold text-[oklch(0.4_0.12_150)]">Approved</span>
                            @endif
                            @if ($comment->open_reports_count)
                                <span class="rounded-full bg-koma-soft px-2.5 py-1 text-[10px] font-extrabold text-koma">{{ $comment->open_reports_count }} open {{ \Illuminate\Support\Str::plural('report', $comment->open_reports_count) }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-5 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(300px,0.75fr)]">
                        <div class="min-w-0">
                            <p class="whitespace-pre-wrap break-words text-sm leading-6 text-[#34302c]">{{ $comment->body }}</p>
                            <a href="{{ $contextUrl }}" target="_blank" class="mt-3 inline-flex max-w-full items-center gap-1.5 text-xs font-bold text-koma hover:underline">
                                <span class="truncate">{{ $contextLabel }}</span><span aria-hidden="true">↗</span>
                            </a>

                            @if ($comment->moderated_at)
                                <div class="mt-4 rounded-xl border border-line-soft bg-[#faf8f6] p-3 text-xs leading-5 text-muted">
                                    Last moderated by {{ $comment->moderator?->name ?? 'Former staff member' }} {{ \App\Support\Koma::timeAgo($comment->moderated_at) }}.
                                    @if ($comment->moderation_note)<span class="block font-semibold text-[#57524c]">{{ $comment->moderation_note }}</span>@endif
                                </div>
                            @endif

                            <div class="mt-4 flex flex-wrap gap-2">
                                @if ($comment->trashed())
                                    <button type="button" wire:click="moderate({{ $comment->id }}, 'restore')" class="min-h-9 rounded-[9px] bg-[oklch(0.43_0.12_150)] px-3 text-xs font-extrabold text-white">Restore</button>
                                    <button type="button" wire:click="beginPurge({{ $comment->id }})" class="min-h-9 rounded-[9px] border border-koma/25 px-3 text-xs font-bold text-[#8d3328]">Permanently delete…</button>
                                @else
                                    @unless ($comment->isApproved())
                                        <button type="button" wire:click="moderate({{ $comment->id }}, 'approve')" class="min-h-9 rounded-[9px] bg-[oklch(0.43_0.12_150)] px-3 text-xs font-extrabold text-white">Approve</button>
                                    @endunless
                                    @unless ($comment->isHidden())
                                        <button type="button" wire:click="moderate({{ $comment->id }}, 'hide')" class="min-h-9 rounded-[9px] border border-line px-3 text-xs font-bold text-[#57524c]">Hide</button>
                                    @endunless
                                    <button type="button" wire:click="moderate({{ $comment->id }}, 'delete')" wire:confirm="Soft-delete this comment? It can be restored from the Deleted filter." class="min-h-9 rounded-[9px] border border-koma/25 px-3 text-xs font-bold text-[#8d3328]">Delete</button>
                                @endif
                            </div>
                        </div>

                        <aside class="min-w-0 rounded-xl border border-line-soft bg-[#faf8f6] p-4" aria-label="Reports for comment {{ $comment->id }}">
                            <h3 class="text-xs font-extrabold uppercase tracking-wide text-faint">Report history</h3>
                            @if ($comment->reports->isEmpty())
                                <p class="mt-3 text-xs text-faint">No reader reports.</p>
                            @else
                                <div class="mt-3 flex flex-col gap-3">
                                    @foreach ($comment->reports as $report)
                                        <div class="border-b border-line-soft pb-3 last:border-0 last:pb-0">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <strong class="text-xs">{{ $report->reasonLabel() }}</strong>
                                                <span class="rounded-full bg-white px-2 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-muted">{{ $report->status }}</span>
                                            </div>
                                            <p class="mt-1 text-[11px] text-faint">{{ $report->reporter?->name ?? 'Former member' }} · {{ \App\Support\Koma::timeAgo($report->created_at) }}</p>
                                            @if ($report->details)<p class="mt-2 whitespace-pre-wrap break-words text-xs leading-5 text-[#57524c]">{{ $report->details }}</p>@endif
                                            @if ($report->reviewed_at)<p class="mt-2 text-[11px] text-faint">Reviewed by {{ $report->reviewer?->name ?? 'Former staff member' }} {{ \App\Support\Koma::timeAgo($report->reviewed_at) }}</p>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </aside>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($comments->hasPages())
            <div class="rounded-2xl border border-line bg-white px-4 py-3">{{ $comments->links() }}</div>
        @endif
    @endif
</div>
