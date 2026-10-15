<?php

namespace App\Community;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use App\Models\User;
use App\Support\MatureContentAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class CommentContextAccess
{
    public function __construct(
        protected MatureContentAccess $matureContent,
        protected Request $request,
    ) {}

    public function series(Comment $comment): ?Series
    {
        $commentable = $comment->commentable;

        if ($commentable instanceof Series) {
            return $commentable;
        }

        if ($commentable instanceof Chapter) {
            return $commentable->series;
        }

        return null;
    }

    public function authorize(User $user, Comment $comment): Series
    {
        $series = $this->series($comment);

        $matureBlocked = $series?->content_rating === 'mature'
            && (! $this->request->hasSession()
                || $this->matureContent->requiresConfirmation($series, $this->request));

        if ($series === null
            || (! $series->is_public && ! $user->can('edit_series'))
            || $matureBlocked) {
            throw new AuthorizationException;
        }

        return $series;
    }
}
