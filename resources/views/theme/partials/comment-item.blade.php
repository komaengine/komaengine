<li id="comment-{{ $comment->id }}" @class(['comment-item', 'is-pending' => $comment->isPending(), 'is-deleted' => $comment->trashed()])>
    @if ($comment->trashed())
        <div class="comment-deleted" role="status">This comment was deleted.</div>
    @else
        <div class="comment-meta">
            <strong>{{ $comment->user?->name ?? 'Former member' }}</strong>
            <time datetime="{{ $comment->created_at->toIso8601String() }}" title="{{ \App\Support\Koma::formatDateTime($comment->created_at) }}">{{ \App\Support\Koma::timeAgo($comment->created_at) }}</time>
            @if ($comment->edited_at)
                <span title="Edited {{ \App\Support\Koma::formatDateTime($comment->edited_at) }}">Edited</span>
            @endif
            @if ($comment->isPending())
                <span class="comment-status">Pending review</span>
            @elseif (! $comment->isApproved())
                <span class="comment-status rejected">Not approved</span>
            @endif
        </div>
        <p class="comment-body">{{ $comment->body }}</p>

        @if ($viewer?->can('update', $comment) || $viewer?->can('delete', $comment) || $viewer?->can('report', $comment))
            <div class="comment-actions">
                @if ($viewer?->can('update', $comment))
                    <details>
                        <summary>Edit</summary>
                        <form method="POST" action="{{ route('comments.update', [$series, $comment]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="comment_id" value="{{ $comment->id }}">
                            <label class="r-sr-only" for="edit-comment-{{ $comment->id }}">Edit comment</label>
                            <textarea id="edit-comment-{{ $comment->id }}" name="body" rows="4" required maxlength="{{ config('koma.comments.body_max_length', 2000) }}">{{ (string) old('comment_id') === (string) $comment->id ? old('body') : $comment->body }}</textarea>
                            <button class="btn" type="submit">Save edit</button>
                        </form>
                    </details>
                @endif
                @if ($viewer?->can('delete', $comment))
                    <form method="POST" action="{{ route('comments.destroy', [$series, $comment]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="comment-delete" type="submit">Delete</button>
                    </form>
                @endif
                @if ($viewer?->can('report', $comment))
                    @if ($comment->viewer_has_reported ?? false)
                        <span class="comment-reported">Reported</span>
                    @else
                        <details class="comment-report">
                            <summary>Report</summary>
                            <form method="POST" action="{{ route('comments.reports.store', [$series, $comment]) }}">
                                @csrf
                                <input type="hidden" name="report_comment_id" value="{{ $comment->id }}">
                                <label for="report-reason-{{ $comment->id }}">Reason</label>
                                <select id="report-reason-{{ $comment->id }}" name="report_reason" required>
                                    @foreach (\App\Models\CommentReport::REASONS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label for="report-details-{{ $comment->id }}">Details <span>(optional)</span></label>
                                <textarea id="report-details-{{ $comment->id }}" name="report_details" rows="3" maxlength="{{ config('koma.comments.report_details_max_length', 1000) }}"></textarea>
                                @if ((string) old('report_comment_id') === (string) $comment->id && $errors->has('report_reason'))
                                    <span class="comment-report-error" role="alert">{{ $errors->first('report_reason') }}</span>
                                @endif
                                <button class="btn" type="submit">Send report</button>
                            </form>
                        </details>
                    @endif
                @endif
            </div>
        @endif
    @endif
</li>
