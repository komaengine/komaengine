<?php

namespace App\Http\Controllers;

use App\Engine\Analytics\CommunityAnalyticsDispatcher;
use App\Models\Series;
use App\Support\Facades\Hook;

class ReaderController extends Controller
{
    public function show(Series $series, string $number, CommunityAnalyticsDispatcher $analytics)
    {
        $chapter = $series->chapters()
            ->published()
            ->where('number', $number)
            ->firstOrFail();

        $chapter->load('pages');

        $prev = $series->chapters()->published()
            ->where('number', '<', $chapter->number)
            ->orderByDesc('number')
            ->first();

        $next = $series->chapters()->published()
            ->where('number', '>', $chapter->number)
            ->orderBy('number')
            ->first();

        // Best-effort view counters (no need to block the response on failure).
        $chapter->incrementQuietly('views');
        $series->incrementQuietly('views');

        Hook::doAction('reader.view', $chapter);

        if ($series->is_public) {
            $analytics->recordReaderView($series, $chapter, request()->user() !== null);
        }

        $readerMode = $series->readerModeFor(request()->user());

        $comments = $chapter->comments()
            ->withTrashed()
            ->visibleTo(request()->user())
            ->when(request()->user(), fn ($query, $viewer) => $query->withExists([
                'reports as viewer_has_reported' => fn ($reports) => $reports->where('user_id', $viewer->getKey()),
            ]))
            ->with('user')
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) config('koma.comments.per_page', 20), ['*'], 'comments_page')
            ->withQueryString()
            ->fragment('comments');

        return view('theme::reader', compact('series', 'chapter', 'prev', 'next', 'readerMode', 'comments'));
    }
}
