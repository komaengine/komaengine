<?php

namespace App\Community;

use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use App\Support\Facades\Hook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class CommentModerationService
{
    public const ACTION_APPROVE = 'approve';

    public const ACTION_HIDE = 'hide';

    public const ACTION_DELETE = 'delete';

    public const ACTION_RESTORE = 'restore';

    public const BULK_ACTIONS = [
        self::ACTION_APPROVE,
        self::ACTION_HIDE,
        self::ACTION_DELETE,
        self::ACTION_RESTORE,
    ];

    public function __construct(protected CommentContextAccess $context) {}

    public function moderate(User $moderator, Comment $comment, string $action, ?string $note = null): Comment
    {
        $this->authorize($moderator, $comment);
        $this->assertAction($action);
        $this->validateNote($note);

        DB::transaction(fn () => $this->applyAction($moderator, $comment, $action, $note));
        $this->emitAction('comment.moderated', $comment, $action, $moderator);

        return $comment;
    }

    /**
     * @param  array<int, int|string>  $commentIds
     * @return Collection<int, Comment>
     */
    public function bulk(User $moderator, array $commentIds, string $action, ?string $note = null): Collection
    {
        Gate::forUser($moderator)->authorize('moderate_comments');
        $this->assertAction($action);
        $this->validateNote($note);

        $ids = array_values(array_unique(array_filter(array_map('intval', $commentIds))));
        $comments = Comment::withTrashed()->whereKey($ids)->orderBy('id')->get();

        if ($ids === [] || $comments->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'selected' => 'Choose valid comments before applying a bulk action.',
            ]);
        }

        foreach ($comments as $comment) {
            $this->context->authorize($moderator, $comment);
        }

        DB::transaction(function () use ($moderator, $comments, $action, $note): void {
            foreach ($comments as $comment) {
                $this->applyAction($moderator, $comment, $action, $note);
            }
        });

        foreach ($comments as $comment) {
            $this->emitAction('comment.moderated', $comment, $action, $moderator);
        }

        return $comments;
    }

    public function purge(
        User $moderator,
        Comment $comment,
        string $confirmation,
        ?string $note = null,
    ): void {
        $this->authorize($moderator, $comment);
        $this->validateNote($note);

        if (! $comment->trashed()) {
            throw ValidationException::withMessages([
                'purgeConfirmation' => 'Soft-delete this comment before permanently removing it.',
            ]);
        }

        if ($confirmation !== 'DELETE') {
            throw ValidationException::withMessages([
                'purgeConfirmation' => 'Type DELETE to permanently remove this comment.',
            ]);
        }

        DB::transaction(function () use ($moderator, $comment, $note): void {
            $this->audit($moderator, $comment, $note);
            $this->reviewOpenReports($moderator, $comment, CommentReport::STATUS_RESOLVED, $note);
            $comment->saveQuietly();
            $comment->forceDelete();
        });

        $this->emitAction('comment.moderated', $comment, 'purge', $moderator);
    }

    protected function authorize(User $moderator, Comment $comment): void
    {
        Gate::forUser($moderator)->authorize('moderate_comments');
        $this->context->authorize($moderator, $comment);
    }

    protected function assertAction(string $action): void
    {
        if (! in_array($action, self::BULK_ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported comment moderation action.');
        }
    }

    protected function validateNote(?string $note): void
    {
        if (mb_strlen((string) $note) > 1000) {
            throw ValidationException::withMessages([
                'auditNote' => 'Audit notes may not exceed 1,000 characters.',
            ]);
        }
    }

    protected function applyAction(User $moderator, Comment $comment, string $action, ?string $note): void
    {
        if ($action === self::ACTION_RESTORE) {
            if (! $comment->trashed()) {
                throw ValidationException::withMessages(['selected' => 'Only deleted comments can be restored.']);
            }

            $comment->restore();
        } elseif ($comment->trashed()) {
            throw ValidationException::withMessages(['selected' => 'Restore this comment before changing its status.']);
        }

        if ($action === self::ACTION_APPROVE) {
            $comment->status = Comment::STATUS_APPROVED;
        } elseif ($action === self::ACTION_HIDE) {
            $comment->status = Comment::STATUS_HIDDEN;
        }

        $this->audit($moderator, $comment, $note);
        $comment->saveQuietly();

        if ($action === self::ACTION_DELETE) {
            $comment->delete();
        }

        $reportStatus = in_array($action, [self::ACTION_APPROVE, self::ACTION_RESTORE], true)
            ? CommentReport::STATUS_DISMISSED
            : CommentReport::STATUS_RESOLVED;

        $this->reviewOpenReports($moderator, $comment, $reportStatus, $note);
    }

    protected function audit(User $moderator, Comment $comment, ?string $note): void
    {
        $comment->forceFill([
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
            'moderation_note' => filled($note) ? trim((string) $note) : null,
        ]);
    }

    protected function reviewOpenReports(
        User $moderator,
        Comment $comment,
        string $status,
        ?string $note,
    ): void {
        $comment->reports()
            ->where('status', CommentReport::STATUS_OPEN)
            ->update([
                'status' => $status,
                'reviewed_by' => $moderator->getKey(),
                'reviewed_at' => now(),
                'moderation_note' => filled($note) ? trim((string) $note) : null,
                'updated_at' => now(),
            ]);
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
