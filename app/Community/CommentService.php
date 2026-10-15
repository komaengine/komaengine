<?php

namespace App\Community;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use App\Models\User;
use App\Support\Facades\Hook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class CommentService
{
    public function create(User $author, Model $commentable, string $body, bool $suspicious = false): Comment
    {
        if (! (bool) config('koma.comments.enabled', true)) {
            throw new CommentsDisabledException;
        }

        Gate::forUser($author)->authorize('create', Comment::class);
        $this->ensureSupportedTarget($commentable);

        $hold = $suspicious || (bool) config('koma.comments.hold_for_moderation', false);

        $comment = $commentable->comments()->create([
            'user_id' => $author->getKey(),
            'body' => $body,
            'status' => $hold ? Comment::STATUS_PENDING : Comment::STATUS_APPROVED,
        ]);

        $this->emitAction('comment.created', $comment);

        return $comment;
    }

    public function update(User $actor, Comment $comment, string $body): Comment
    {
        Gate::forUser($actor)->authorize('update', $comment);

        if ($comment->body === $body) {
            return $comment;
        }

        $comment->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ]);

        $this->markModeratorOverride($actor, $comment);
        $comment->save();

        return $comment;
    }

    public function delete(User $actor, Comment $comment): void
    {
        Gate::forUser($actor)->authorize('delete', $comment);

        DB::transaction(function () use ($actor, $comment): void {
            $this->markModeratorOverride($actor, $comment);
            $comment->save();
            $comment->delete();
        });
    }

    /** Side effects of a comment must never take the comment down with them. */
    protected function emitAction(string $hook, mixed ...$payload): void
    {
        try {
            Hook::doAction($hook, ...$payload);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function ensureSupportedTarget(Model $commentable): void
    {
        if (! $commentable instanceof Series && ! $commentable instanceof Chapter) {
            throw new InvalidArgumentException('Comments are supported only on series and chapters.');
        }
    }

    protected function markModeratorOverride(User $actor, Comment $comment): void
    {
        if ((int) $comment->user_id === (int) $actor->getKey()) {
            return;
        }

        $comment->forceFill([
            'moderated_by' => $actor->getKey(),
            'moderated_at' => now(),
        ]);
    }
}
