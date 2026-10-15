<?php

namespace App\Http\Controllers;

use App\Engine\Analytics\CommunityAnalyticsDispatcher;
use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Series;
use App\Support\MatureContentAccess;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function home(Request $request, MatureContentAccess $access, Settings $settings)
    {
        $hideMature = $access->hidesMature($request);

        $safe = fn ($query) => $query->when($hideMature, fn ($q) => $q->where('content_rating', 'safe'));

        // The Reading settings choose what the home page centers on: the most
        // recently updated title, the most-viewed one, or a pinned series
        // (ideal for single-series themes such as Tankōbon).
        $featured = null;
        if ($settings->get('front_page') === 'series' && $settings->get('front_page_series')) {
            $featured = Series::published()->tap($safe)
                ->whereKey((int) $settings->get('front_page_series'))
                ->first();
        }

        $featured = $featured ?? Series::published()->tap($safe)
            ->orderByDesc('views')
            ->first();

        $latest = Chapter::published()
            ->whereHas(
                'series',
                fn ($series) => $series
                    ->where('is_public', true)
                    ->when($hideMature, fn ($query) => $query->where('content_rating', 'safe')),
            )
            ->with('series')
            ->orderByDesc('published_at')
            ->take(12)
            ->get();

        $popular = Series::published()
            ->when($hideMature, fn ($query) => $query->where('content_rating', 'safe'))
            ->withCount('publishedChapters')
            ->orderByDesc('views')
            ->take(10)
            ->get();

        return view('theme::home', compact('featured', 'latest', 'popular'));
    }

    public function browse(Request $request, MatureContentAccess $access)
    {
        $q = $request->query('q');
        $genre = $request->query('genre');
        $status = $request->query('status');

        $series = Series::query()
            ->published()
            ->when($access->hidesMature($request), fn ($query) => $query->where('content_rating', 'safe'))
            ->search($q)
            ->when($genre, fn ($query) => $query->whereHas('genres', fn ($g) => $g->where('slug', $genre)))
            ->when(in_array($status, Series::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->withCount('publishedChapters')
            ->orderBy('title')
            ->paginate((int) config('koma.reading.series_per_page', 24))
            ->withQueryString();

        return view('theme::browse', [
            'series' => $series,
            'genres' => Genre::orderBy('name')->get(),
            'q' => $q,
            'activeGenre' => $genre,
            'activeStatus' => $status,
        ]);
    }

    public function show(Series $series, CommunityAnalyticsDispatcher $analytics)
    {
        $series->load('genres');

        $chapters = $series->chapters()
            ->published()
            ->withCount('pages')
            ->orderByDesc('number')
            ->get();

        $firstChapter = $chapters->last();

        $comments = $series->comments()
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

        if ($series->is_public) {
            $analytics->recordSeriesView($series, request()->user() !== null);
        }

        return view('theme::series', compact('series', 'chapters', 'firstChapter', 'comments'));
    }
}
