<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function create(User $user): bool
    {
        return $user->can('comment');
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->mayChange($user, $comment);
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->mayChange($user, $comment);
    }

    public function report(User $user, Comment $comment): bool
    {
        return ! $comment->trashed()
            && $comment->isApproved()
            && (int) $comment->user_id !== (int) $user->getKey();
    }

    protected function mayChange(User $user, Comment $comment): bool
    {
        if ($comment->trashed()) {
            return false;
        }

        if ($user->can('moderate_comments')) {
            return true;
        }

        return (int) $comment->user_id === (int) $user->getKey()
            && $comment->isWithinOwnerWindow();
    }
}
