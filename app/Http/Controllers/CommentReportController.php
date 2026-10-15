<?php

namespace App\Http\Controllers;

use App\Community\CommentReportService;
use App\Http\Requests\StoreCommentReportRequest;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use Illuminate\Http\RedirectResponse;

class CommentReportController extends Controller
{
    public function store(
        StoreCommentReportRequest $request,
        Series $series,
        Comment $comment,
        CommentReportService $reports,
    ): RedirectResponse {
        $chapter = $this->chapterForSeries($series, $comment);

        $reports->report(
            $request->user(),
            $comment,
            $request->validated('report_reason'),
            $request->validated('report_details'),
        );

        return $this->redirectTo($series, $chapter, $comment)
            ->with('comment_status', 'Thank you. The moderation team will review this report.');
    }

    protected function chapterForSeries(Series $series, Comment $comment): ?Chapter
    {
        $commentable = $comment->commentable;

        if ($commentable instanceof Series && $commentable->is($series)) {
            return null;
        }

        if ($commentable instanceof Chapter
            && (int) $commentable->series_id === (int) $series->getKey()
            && $commentable->isPublished()) {
            return $commentable;
        }

        abort(404);
    }

    protected function redirectTo(Series $series, ?Chapter $chapter, Comment $comment): RedirectResponse
    {
        $url = $chapter === null
            ? route('series.show', $series)
            : route('reader', [$series, $chapter->displayNumber()]);

        return redirect()->to($url.'#comment-'.$comment->getKey());
    }
}
