@php
    $chapter = $chapter ?? null;
    $isReaderDiscussion = $chapter !== null;
    $storeRoute = $isReaderDiscussion ? route('comments.chapter.store', [$series, $chapter->number]) : route('comments.series.store', $series);
    $ownerWindow = (int) config('koma.comments.owner_edit_window_minutes', 15);
    $viewer = auth()->user();
    $commentsEnabled = (bool) config('koma.comments.enabled', true);
    $mayCreate = $commentsEnabled && $viewer?->can('create', \App\Models\Comment::class) === true;
    $commentProof = $mayCreate ? app(\App\Community\CommentSpamGuard::class)->issueToken() : null;
@endphp
<section id="comments" @class(['comments', 'reader-discussion' => $isReaderDiscussion]) aria-labelledby="comments-title">
    <div class="comments-shell">
        <header class="comments-heading"><div><p class="eyebrow">Community notes</p><h2 id="comments-title">{{ $isReaderDiscussion ? 'Chapter discussion' : 'Series discussion' }}</h2></div><span>{{ $comments->total() }} {{ \Illuminate\Support\Str::plural('comment', $comments->total()) }}</span></header>
        @if (session('comment_status'))<div class="comment-notice" role="status">{{ session('comment_status') }}</div>@endif
        @if ($errors->has('body'))<div class="comment-error" role="alert">{{ $errors->first('body') }}</div>@endif
        @if (! $commentsEnabled)
            <div class="comment-signed-out" role="status">Comments are closed for this site.</div>
        @elseif (auth()->check())
            @if ($mayCreate)
                <form class="comment-composer" method="POST" action="{{ $storeRoute }}">@csrf
                    <div class="comment-honeypot" aria-hidden="true"><label for="comment-website">Leave this field empty</label><input id="comment-website" name="website" type="text" tabindex="-1" autocomplete="off"></div><input type="hidden" name="comment_proof" value="{{ $commentProof }}">
                    <label for="new-comment-{{ $isReaderDiscussion ? 'chapter' : 'series' }}">Add to the discussion</label><textarea id="new-comment-{{ $isReaderDiscussion ? 'chapter' : 'series' }}" name="body" rows="4" minlength="{{ config('koma.comments.body_min_length', 2) }}" maxlength="{{ config('koma.comments.body_max_length', 2000) }}" required placeholder="Share a thoughtful, plain-text comment…">{{ old('comment_id') ? '' : old('body') }}</textarea>
                    <div class="comment-composer-meta"><span>Plain text · up to {{ number_format(config('koma.comments.body_max_length', 2000)) }} characters</span><button class="theme-button accent" type="submit">Post comment</button></div>
                </form>
            @else<div class="comment-signed-out" role="status">Your account does not have permission to post comments.</div>@endif
        @else<div class="comment-signed-out"><a href="{{ route('login') }}">Sign in</a> to join the discussion. Comments remain readable without an account.</div>@endif
        @if ($comments->isEmpty())
            <div class="comment-empty"><strong>No comments yet.</strong><span>Start a respectful conversation about this {{ $isReaderDiscussion ? 'chapter' : 'series' }}.</span></div>
        @else
            <ol class="comment-list">@foreach ($comments as $comment)@include('theme::partials.comment-item', compact('comment', 'series', 'viewer'))@endforeach</ol>
            @if ($comments->hasPages())<div class="comments-pagination">{{ $comments->links('theme::pagination') }}</div>@endif
        @endif
        @auth<p class="comment-window-note">You can edit or delete your own comment for {{ $ownerWindow }} minutes. Moderators can act after that window.</p>@endauth
    </div>
</section>
