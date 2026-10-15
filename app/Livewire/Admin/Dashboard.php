<?php

namespace App\Livewire\Admin;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\Page;
use App\Models\Series;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Dashboard extends Component
{
    private const WIDGET_LABELS = [
        'at_a_glance' => 'At a glance',
        'quick_actions' => 'Quick actions',
        'content_status' => 'Content status',
        'recent_activity' => 'Recent activity',
        'popular_series' => 'Most-read series',
        'moderation' => 'Comment moderation',
        'archive_activity' => 'Archive activity',
    ];

    private const DEFAULT_COLUMNS = [
        ['at_a_glance', 'content_status', 'popular_series'],
        ['quick_actions', 'recent_activity', 'moderation', 'archive_activity'],
    ];

    /** @var array{columns: array<int, array<int, string>>, hidden: array<int, string>, collapsed: array<int, string>} */
    public array $dashboardPreferences = [];

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);

        $this->dashboardPreferences = $this->preferencesFor($user);
    }

    /**
     * Persist only known widgets that the current staff account may see.
     * Client-provided IDs and duplicates are intentionally discarded.
     *
     * @param  array<string, mixed>  $preferences
     */
    public function saveDashboardPreferences(array $preferences): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);

        $normalized = $this->normalizePreferences($preferences, $this->availableWidgetIds($user));

        $user->forceFill(['admin_dashboard_preferences' => $normalized])->save();
        $this->dashboardPreferences = $normalized;
    }

    public function resetDashboardPreferences(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);

        $user->forceFill(['admin_dashboard_preferences' => null])->save();
        $this->dashboardPreferences = $this->preferencesFor($user->fresh());
    }

    public function render()
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $stats = [
            ['label' => 'Series', 'value' => number_format(Series::count())],
            ['label' => 'Published chapters', 'value' => number_format(Chapter::published()->count())],
            ['label' => 'Pages hosted', 'value' => number_format(Page::count())],
            ['label' => 'Registered users', 'value' => number_format(User::count())],
        ];

        $contentStatus = [
            ['label' => 'Draft chapters', 'value' => Chapter::where('status', Chapter::STATUS_DRAFT)->count()],
            ['label' => 'Scheduled chapters', 'value' => Chapter::where('status', Chapter::STATUS_SCHEDULED)->count()],
            ['label' => 'Private series', 'value' => Series::where('is_public', false)->count()],
            ['label' => 'Missing covers', 'value' => Series::whereNull('cover_path')->count()],
        ];

        $recentChapters = Chapter::query()
            ->with('series')
            ->latest()
            ->take(6)
            ->get();

        $topSeries = Series::query()->orderByDesc('views')->take(5)->get();
        $maxViews = max(1, (int) $topSeries->max('views'));

        $moderationCounts = [
            'pending' => 0,
            'reported' => 0,
        ];

        if ($user->can('moderate_comments')) {
            $moderationCounts = [
                'pending' => Comment::query()
                    ->whereNull('deleted_at')
                    ->where('status', Comment::STATUS_PENDING)
                    ->count(),
                'reported' => CommentReport::query()
                    ->where('status', CommentReport::STATUS_OPEN)
                    ->count(),
            ];
        }

        $recentImports = $user->can('edit_series')
            ? SeriesArchiveImport::query()
                ->where('user_id', $user->getKey())
                ->latest()
                ->take(4)
                ->get()
            : collect();

        $widgetLabels = collect(self::WIDGET_LABELS)
            ->only($this->availableWidgetIds($user))
            ->all();

        return view('livewire.admin.dashboard', [
            'stats' => $stats,
            'contentStatus' => $contentStatus,
            'recentChapters' => $recentChapters,
            'topSeries' => $topSeries,
            'maxViews' => $maxViews,
            'moderationCounts' => $moderationCounts,
            'recentImports' => $recentImports,
            'widgetLabels' => $widgetLabels,
        ]);
    }

    /** @return array<int, string> */
    private function availableWidgetIds(User $user): array
    {
        $available = ['at_a_glance'];

        if ($user->can('upload_chapters') || $user->can('edit_series') || $user->can('moderate_comments')) {
            $available[] = 'quick_actions';
        }

        if ($user->can('edit_series')) {
            $available[] = 'content_status';
        }

        $available[] = 'recent_activity';

        if ($user->can('view_analytics')) {
            $available[] = 'popular_series';
        }

        if ($user->can('moderate_comments')) {
            $available[] = 'moderation';
        }

        if ($user->can('edit_series')) {
            $available[] = 'archive_activity';
        }

        return $available;
    }

    /**
     * @return array{columns: array<int, array<int, string>>, hidden: array<int, string>, collapsed: array<int, string>}
     */
    private function preferencesFor(User $user): array
    {
        $saved = is_array($user->admin_dashboard_preferences)
            ? $user->admin_dashboard_preferences
            : [];

        return $this->normalizePreferences($saved, $this->availableWidgetIds($user));
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @param  array<int, string>  $available
     * @return array{columns: array<int, array<int, string>>, hidden: array<int, string>, collapsed: array<int, string>}
     */
    private function normalizePreferences(array $preferences, array $available): array
    {
        $columns = [[], []];
        $seen = [];
        $requestedColumns = is_array($preferences['columns'] ?? null)
            ? array_slice($preferences['columns'], 0, 2)
            : [];

        foreach ($requestedColumns as $columnIndex => $widgetIds) {
            if (! is_array($widgetIds)) {
                continue;
            }

            foreach ($widgetIds as $widgetId) {
                if (! is_string($widgetId)
                    || ! in_array($widgetId, $available, true)
                    || in_array($widgetId, $seen, true)) {
                    continue;
                }

                $columns[$columnIndex][] = $widgetId;
                $seen[] = $widgetId;
            }
        }

        foreach (self::DEFAULT_COLUMNS as $columnIndex => $widgetIds) {
            foreach ($widgetIds as $widgetId) {
                if (in_array($widgetId, $available, true) && ! in_array($widgetId, $seen, true)) {
                    $columns[$columnIndex][] = $widgetId;
                    $seen[] = $widgetId;
                }
            }
        }

        return [
            'columns' => $columns,
            'hidden' => $this->allowedPreferenceList($preferences['hidden'] ?? [], $available),
            'collapsed' => $this->allowedPreferenceList($preferences['collapsed'] ?? [], $available),
        ];
    }

    /**
     * @param  array<int, string>  $available
     * @return array<int, string>
     */
    private function allowedPreferenceList(mixed $requested, array $available): array
    {
        if (! is_array($requested)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $requested,
            fn ($widgetId) => is_string($widgetId) && in_array($widgetId, $available, true),
        )));
    }
}
