<?php

namespace App\Livewire\Admin;

use App\Community\CommentModerationService;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\Series;
use App\Support\MatureContentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class Moderation extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'reported';

    /** @var array<int, int|string> */
    public array $selected = [];

    public string $bulkAction = CommentModerationService::ACTION_HIDE;

    public string $auditNote = '';

    public ?int $purgeCommentId = null;

    public string $purgeConfirmation = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        Gate::authorize('moderate_comments');

        if (! in_array($this->filter, $this->filters(), true)) {
            $this->filter = 'reported';
        }
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, $this->filters(), true)) {
            $this->filter = 'reported';
        }

        $this->selected = [];
        $this->resetPage('moderation_page');
    }

    public function moderate(int $commentId, string $action, CommentModerationService $moderation): void
    {
        Gate::authorize('moderate_comments');
        $this->validateAuditNote();

        $comment = Comment::withTrashed()->findOrFail($commentId);
        $moderation->moderate(auth()->user(), $comment, $action, $this->auditNote);

        $this->finishAction("Comment {$action} action completed.");
    }

    public function applyBulk(CommentModerationService $moderation): void
    {
        Gate::authorize('moderate_comments');
        $this->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['integer'],
            'bulkAction' => ['required', Rule::in(CommentModerationService::BULK_ACTIONS)],
            'auditNote' => ['nullable', 'string', 'max:1000'],
        ], [
            'selected.required' => 'Choose at least one comment.',
            'selected.min' => 'Choose at least one comment.',
        ]);

        $comments = $moderation->bulk(auth()->user(), $this->selected, $this->bulkAction, $this->auditNote);

        $this->finishAction($comments->count().' comments updated.');
    }

    public function beginPurge(int $commentId): void
    {
        Gate::authorize('moderate_comments');
        $comment = Comment::onlyTrashed()->findOrFail($commentId);

        $this->purgeCommentId = $comment->getKey();
        $this->purgeConfirmation = '';
        $this->resetErrorBag('purgeConfirmation');
    }

    public function cancelPurge(): void
    {
        $this->purgeCommentId = null;
        $this->purgeConfirmation = '';
        $this->resetErrorBag('purgeConfirmation');
    }

    public function purge(CommentModerationService $moderation): void
    {
        Gate::authorize('moderate_comments');
        $this->validateAuditNote();

        $comment = Comment::onlyTrashed()->findOrFail($this->purgeCommentId);
        $moderation->purge(
            auth()->user(),
            $comment,
            $this->purgeConfirmation,
            $this->auditNote,
        );

        $this->finishAction('Comment permanently deleted. Report snapshots were retained.');
        $this->cancelPurge();
    }

    public function render(MatureContentAccess $matureContent)
    {
        Gate::authorize('moderate_comments');

        $base = $this->accessibleComments($matureContent);
        $counts = [
            'reported' => (clone $base)->whereHas('reports', fn ($reports) => $reports->where('status', CommentReport::STATUS_OPEN))->count(),
            'pending' => (clone $base)->whereNull('deleted_at')->where('status', Comment::STATUS_PENDING)->count(),
            'hidden' => (clone $base)->whereNull('deleted_at')->whereIn('status', [Comment::STATUS_HIDDEN, Comment::STATUS_REJECTED])->count(),
            'deleted' => (clone $base)->onlyTrashed()->count(),
            'all' => (clone $base)->count(),
        ];

        $comments = $this->applyFilter(clone $base)
            ->with([
                'user',
                'moderator',
                'reports' => fn ($reports) => $reports->latest()->with(['reporter', 'reviewer']),
                'commentable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Series::class => [],
                    Chapter::class => ['series'],
                ]),
            ])
            ->withCount([
                'reports as open_reports_count' => fn ($reports) => $reports->where('status', CommentReport::STATUS_OPEN),
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20, ['*'], 'moderation_page');

        return view('livewire.admin.moderation', compact('comments', 'counts'));
    }

    protected function accessibleComments(MatureContentAccess $matureContent): Builder
    {
        $viewer = auth()->user();
        $hidePrivate = ! $viewer->can('edit_series');
        $hideMature = ! request()->hasSession() || $matureContent->hidesMature(request());

        return Comment::withTrashed()->whereHasMorph(
            'commentable',
            [Series::class, Chapter::class],
            function (Builder $context, string $type) use ($hidePrivate, $hideMature): void {
                if ($type === Series::class) {
                    $context
                        ->when($hidePrivate, fn (Builder $series) => $series->where('is_public', true))
                        ->when($hideMature, fn (Builder $series) => $series->where('content_rating', 'safe'));

                    return;
                }

                $context->whereHas('series', function (Builder $series) use ($hidePrivate, $hideMature): void {
                    $series
                        ->when($hidePrivate, fn (Builder $query) => $query->where('is_public', true))
                        ->when($hideMature, fn (Builder $query) => $query->where('content_rating', 'safe'));
                });
            },
        );
    }

    protected function applyFilter(Builder $query): Builder
    {
        return match ($this->filter) {
            'reported' => $query->whereHas('reports', fn ($reports) => $reports->where('status', CommentReport::STATUS_OPEN)),
            'pending' => $query->whereNull('deleted_at')->where('status', Comment::STATUS_PENDING),
            'hidden' => $query->whereNull('deleted_at')->whereIn('status', [Comment::STATUS_HIDDEN, Comment::STATUS_REJECTED]),
            'deleted' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /** @return array<int, string> */
    protected function filters(): array
    {
        return ['reported', 'pending', 'hidden', 'deleted', 'all'];
    }

    protected function validateAuditNote(): void
    {
        $this->validate([
            'auditNote' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    protected function finishAction(string $message): void
    {
        $this->selected = [];
        $this->auditNote = '';
        $this->statusMessage = $message;
        $this->resetValidation();
        $this->resetPage('moderation_page');
    }
}
