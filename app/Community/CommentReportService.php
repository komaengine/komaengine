<?php

namespace App\Community;

use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use App\Support\Facades\Hook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommentReportService
{
    public function __construct(protected CommentContextAccess $context) {}

    public function report(User $reporter, Comment $comment, string $reason, ?string $details = null): CommentReport
    {
        Gate::forUser($reporter)->authorize('report', $comment);
        $this->context->authorize($reporter, $comment);
        $this->validateContent($reason, $details);

        if ($comment->reports()->where('user_id', $reporter->getKey())->exists()) {
            throw ValidationException::withMessages([
                'report_reason' => 'You have already reported this comment.',
            ]);
        }

        try {
            $report = DB::transaction(function () use ($reporter, $comment, $reason, $details): CommentReport {
                return $comment->reports()->create([
                    'user_id' => $reporter->getKey(),
                    'reason' => $reason,
                    'details' => filled($details) ? trim((string) $details) : null,
                    'status' => CommentReport::STATUS_OPEN,
                    'comment_author_id' => $comment->user_id,
                    'comment_author_name' => $comment->user?->name ?? 'Former member',
                    'commentable_type' => $comment->commentable_type,
                    'commentable_id' => $comment->commentable_id,
                    'comment_body' => $comment->body,
                ]);
            });
        } catch (QueryException $exception) {
            if ($comment->reports()->where('user_id', $reporter->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'report_reason' => 'You have already reported this comment.',
                ]);
            }

            throw $exception;
        }

        $this->emitAction('comment.reported', $report, $comment);

        return $report;
    }

    protected function validateContent(string $reason, ?string $details): void
    {
        $errors = [];

        if (! array_key_exists($reason, CommentReport::REASONS)) {
            $errors['report_reason'] = 'Choose a valid report reason.';
        }

        if (mb_strlen((string) $details) > (int) config('koma.comments.report_details_max_length', 1000)) {
            $errors['report_details'] = 'Report details are too long.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function emitAction(string $hook, mixed ...$payload): void
    {
        try {
            Hook::doAction($hook, ...$payload);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
