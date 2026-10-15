<?php

namespace App\Http\Controllers;

use App\Community\CommentService;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function storeSeries(StoreCommentRequest $request, Series $series, CommentService $comments): RedirectResponse
    {
        $comments->create(
            $request->user(),
            $series,
            $request->validated('body'),
            $request->isSuspicious(),
        );

        return $this->redirectTo($series)->with('comment_status', 'Your comment was posted.');
    }

    public function storeChapter(
        StoreCommentRequest $request,
        Series $series,
        Chapter $chapter,
        CommentService $comments,
    ): RedirectResponse {
        $this->ensurePublishedChapter($series, $chapter);
        $comments->create(
            $request->user(),
            $chapter,
            $request->validated('body'),
            $request->isSuspicious(),
        );

        return $this->redirectTo($series, $chapter)->with('comment_status', 'Your comment was posted.');
    }

    public function update(
        UpdateCommentRequest $request,
        Series $series,
        Comment $comment,
        CommentService $comments,
    ): RedirectResponse {
        $chapter = $this->chapterForSeries($series, $comment);
        $comments->update($request->user(), $comment, $request->validated('body'));

        return $this->redirectTo($series, $chapter)->with('comment_status', 'Your comment was updated.');
    }

    public function destroy(Request $request, Series $series, Comment $comment, CommentService $comments): RedirectResponse
    {
        $chapter = $this->chapterForSeries($series, $comment);
        $comments->delete($request->user(), $comment);

        return $this->redirectTo($series, $chapter)->with('comment_status', 'The comment was deleted.');
    }

    protected function chapterForSeries(Series $series, Comment $comment): ?Chapter
    {
        $commentable = $comment->commentable;

        if ($commentable instanceof Series && $commentable->is($series)) {
            return null;
        }

        if ($commentable instanceof Chapter && (int) $commentable->series_id === (int) $series->getKey()) {
            $this->ensurePublishedChapter($series, $commentable);

            return $commentable;
        }

        abort(404);
    }

    protected function ensurePublishedChapter(Series $series, Chapter $chapter): void
    {
        abort_unless(
            (int) $chapter->series_id === (int) $series->getKey() && $chapter->isPublished(),
            404,
        );
    }

    protected function redirectTo(Series $series, ?Chapter $chapter = null): RedirectResponse
    {
        $url = $chapter === null
            ? route('series.show', $series)
            : route('reader', [$series, $chapter->displayNumber()]);

        return redirect()->to($url.'#comments');
    }
}
