<?php

namespace Tests\Feature;

use App\Community\CommentModerationService;
use App\Community\CommentReportService;
use App\Community\CommentSpamGuard;
use App\Engine\Settings\Settings;
use App\Livewire\Admin\Moderation;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\Series;
use App\Models\User;
use App\Support\Facades\Hook;
use App\Support\MatureContentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommentModerationTest extends TestCase
{
    use RefreshDatabase;

    private Series $series;

    private Chapter $chapter;

    private User $author;

    private User $reporter;

    private User $otherReporter;

    private User $moderator;

    private User $editor;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->series = Series::create([
            'title' => 'Moderation Workshop',
            'slug' => 'moderation-workshop',
            'content_rating' => 'safe',
            'is_public' => true,
        ]);
        $this->chapter = Chapter::create([
            'series_id' => $this->series->id,
            'number' => 1,
            'status' => Chapter::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->author = $this->userWithRole('Member', 'Comment Author');
        $this->reporter = $this->userWithRole('Member', 'First Reporter');
        $this->otherReporter = $this->userWithRole('Member', 'Second Reporter');
        $this->moderator = $this->userWithRole('Moderator', 'Queue Moderator');
        $this->editor = $this->userWithRole('Editor', 'Series Editor');
        $this->uploader = $this->userWithRole('Uploader', 'Chapter Uploader');
    }

    #[Test]
    public function authenticated_readers_can_report_once_while_anonymous_self_and_invalid_reports_are_denied(): void
    {
        $comment = $this->makeComment('Reportable comment');
        $reportedHooks = 0;
        Hook::addAction('comment.reported', function (CommentReport $report, Comment $reported) use (&$reportedHooks, $comment): void {
            $this->assertTrue($reported->is($comment));
            $this->assertSame('spam', $report->reason);
            $reportedHooks++;
        });

        $this->post(route('comments.reports.store', [$this->series, $comment]), [
            'report_reason' => 'spam',
        ])->assertRedirect(route('login'));

        $this->get(route('series.show', $this->series))->assertDontSee('Send report');
        $this->actingAs($this->author)
            ->get(route('series.show', $this->series))
            ->assertDontSee('Send report');
        $this->actingAs($this->reporter)
            ->get(route('series.show', $this->series))
            ->assertSee('Send report');

        $this->actingAs($this->author)
            ->post(route('comments.reports.store', [$this->series, $comment]), [
                'report_reason' => 'spam',
            ])->assertForbidden();

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$this->series, $comment]), [
                'report_reason' => 'not-a-reason',
            ])->assertSessionHasErrors('report_reason');

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$this->series, $comment]), [
                'report_reason' => 'spam',
                'report_details' => 'Repeated promotion links.',
            ])->assertRedirect(route('series.show', $this->series).'#comment-'.$comment->id)
            ->assertSessionHas('comment_status');

        $report = CommentReport::query()->sole();
        $this->assertSame($this->reporter->id, $report->user_id);
        $this->assertSame(CommentReport::STATUS_OPEN, $report->status);
        $this->assertSame($comment->body, $report->comment_body);
        $this->assertSame($this->author->name, $report->comment_author_name);
        $this->assertSame($comment->commentable_type, $report->commentable_type);
        $this->assertSame(1, $reportedHooks);

        $this->actingAs($this->reporter)
            ->get(route('series.show', $this->series))
            ->assertSee('Reported')
            ->assertDontSee('Send report');

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$this->series, $comment]), [
                'report_reason' => 'other',
            ])->assertSessionHasErrors('report_reason');

        $this->assertDatabaseCount('comment_reports', 1);
    }

    #[Test]
    public function report_throttling_is_per_account(): void
    {
        config()->set('koma.comments.report_rate_limit_per_minute', 2);
        $comments = collect(range(1, 4))->map(fn (int $index) => $this->makeComment("Report target {$index}"));

        foreach ($comments->take(2) as $comment) {
            $this->actingAs($this->reporter)
                ->post(route('comments.reports.store', [$this->series, $comment]), ['report_reason' => 'spam'])
                ->assertRedirect();
        }

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$this->series, $comments[2]]), ['report_reason' => 'spam'])
            ->assertTooManyRequests();

        $this->actingAs($this->otherReporter)
            ->post(route('comments.reports.store', [$this->series, $comments[3]]), ['report_reason' => 'spam'])
            ->assertRedirect();

        $this->assertDatabaseCount('comment_reports', 3);
    }

    #[Test]
    public function chapter_reports_redirect_to_the_reader_and_cross_series_or_draft_contexts_fail_closed(): void
    {
        $chapterComment = $this->chapter->comments()->create([
            'user_id' => $this->author->id,
            'body' => 'Chapter-specific report target',
            'status' => Comment::STATUS_APPROVED,
        ]);

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$this->series, $chapterComment]), [
                'report_reason' => 'spoiler',
            ])->assertRedirect(
                route('reader', [$this->series, $this->chapter->displayNumber()]).'#comment-'.$chapterComment->id,
            );

        $otherSeries = Series::create([
            'title' => 'Wrong Report Context',
            'slug' => 'wrong-report-context',
            'is_public' => true,
            'content_rating' => 'safe',
        ]);

        $this->actingAs($this->otherReporter)
            ->post(route('comments.reports.store', [$otherSeries, $chapterComment]), [
                'report_reason' => 'spoiler',
            ])->assertNotFound();

        $draft = Chapter::create([
            'series_id' => $this->series->id,
            'number' => 2,
            'status' => Chapter::STATUS_DRAFT,
        ]);
        $draftComment = $draft->comments()->create([
            'user_id' => $this->author->id,
            'body' => 'Draft context report target',
            'status' => Comment::STATUS_APPROVED,
        ]);

        $this->actingAs($this->otherReporter)
            ->post(route('comments.reports.store', [$this->series, $draftComment]), [
                'report_reason' => 'other',
            ])->assertNotFound();

        $this->assertDatabaseCount('comment_reports', 1);
    }

    #[Test]
    public function honeypot_missing_and_too_fast_proofs_enter_pending_without_changing_the_public_response(): void
    {
        $guard = app(CommentSpamGuard::class);
        $minimum = (int) config('koma.comments.minimum_submit_seconds');
        $cleanProof = $guard->issueToken(now()->subSeconds($minimum + 1));

        $this->actingAs($this->reporter)
            ->post(route('comments.series.store', $this->series), [
                'body' => 'Normal reader response',
                'comment_proof' => $cleanProof,
            ])->assertRedirect(route('series.show', $this->series).'#comments')
            ->assertSessionHas('comment_status', 'Your comment was posted.');

        foreach ([
            ['body' => 'Missing proof'],
            ['body' => 'Submitted too quickly', 'comment_proof' => $guard->issueToken()],
            ['body' => 'Filled hidden field', 'comment_proof' => $cleanProof, 'website' => 'https://spam.test'],
        ] as $payload) {
            $this->actingAs($this->reporter)
                ->post(route('comments.series.store', $this->series), $payload)
                ->assertRedirect(route('series.show', $this->series).'#comments')
                ->assertSessionHas('comment_status', 'Your comment was posted.');
        }

        $this->assertDatabaseHas('comments', [
            'body' => 'Normal reader response',
            'status' => Comment::STATUS_APPROVED,
        ]);
        $this->assertSame(3, Comment::query()->where('status', Comment::STATUS_PENDING)->count());

        auth()->logout();
        $this->get(route('series.show', $this->series))
            ->assertSee('Normal reader response')
            ->assertDontSee('Missing proof');
        $this->actingAs($this->reporter)
            ->get(route('series.show', $this->series))
            ->assertSee('Missing proof')
            ->assertSee('Pending review');
    }

    #[Test]
    public function only_comment_moderators_can_open_or_invoke_the_queue(): void
    {
        $this->get(route('admin.comments'))->assertRedirect(route('login'));
        $this->actingAs($this->author)->get(route('admin.comments'))->assertForbidden();
        $this->actingAs($this->uploader)->get(route('admin.comments'))->assertForbidden();
        Livewire::actingAs($this->uploader)->test(Moderation::class)->assertForbidden();

        $this->actingAs($this->moderator)
            ->get(route('admin.comments'))
            ->assertOk()
            ->assertSee('Comment moderation')
            ->assertSee('Hide is the safe default');
        $this->actingAs($this->editor)->get(route('admin.comments'))->assertOk();

        try {
            app(CommentModerationService::class)->moderate(
                $this->author,
                $this->makeComment('Action boundary'),
                CommentModerationService::ACTION_HIDE,
            );
            $this->fail('A Member crossed the moderation service boundary.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function approve_hide_delete_and_restore_transitions_update_comment_report_and_hook_audits(): void
    {
        $approval = $this->makeComment('Pending after report');
        $approvalReport = $this->makeReport($approval);
        $approval->update(['status' => Comment::STATUS_PENDING]);

        $hidden = $this->makeComment('Hide this body');
        $hiddenReport = $this->makeReport($hidden, $this->otherReporter);

        $deleted = $this->makeComment('Delete then restore');
        $deletedReport = $this->makeReport($deleted);

        $actions = [];
        Hook::addAction('comment.moderated', function (Comment $comment, string $action, User $moderator) use (&$actions): void {
            $this->assertSame($this->moderator->id, $moderator->id);
            $actions[] = [$comment->id, $action];
        });

        $service = app(CommentModerationService::class);
        $service->moderate($this->moderator, $approval, CommentModerationService::ACTION_APPROVE, 'False positive.');
        $service->moderate($this->moderator, $hidden, CommentModerationService::ACTION_HIDE, 'Confirmed abuse.');
        $service->moderate($this->moderator, $deleted, CommentModerationService::ACTION_DELETE, 'Temporary removal.');
        $service->moderate($this->moderator, $deleted, CommentModerationService::ACTION_RESTORE, 'Restored after review.');

        $this->assertSame(Comment::STATUS_APPROVED, $approval->fresh()->status);
        $approvalReport->refresh();
        $this->assertSame(CommentReport::STATUS_DISMISSED, $approvalReport->status);
        $this->assertSame($this->moderator->id, $approvalReport->reviewed_by);
        $this->assertNotNull($approvalReport->reviewed_at);
        $this->assertSame('False positive.', $approvalReport->moderation_note);
        $this->assertSame(Comment::STATUS_HIDDEN, $hidden->fresh()->status);
        $this->assertSame(CommentReport::STATUS_RESOLVED, $hiddenReport->fresh()->status);
        $this->assertFalse($deleted->fresh()->trashed());
        $this->assertSame(CommentReport::STATUS_RESOLVED, $deletedReport->fresh()->status);
        $this->assertSame($this->moderator->id, $deleted->fresh()->moderated_by);
        $this->assertSame('Restored after review.', $deleted->fresh()->moderation_note);
        $this->assertNotNull($deleted->fresh()->moderated_at);
        $this->assertSame([
            [$approval->id, 'approve'],
            [$hidden->id, 'hide'],
            [$deleted->id, 'delete'],
            [$deleted->id, 'restore'],
        ], $actions);

        $this->get(route('series.show', $this->series))->assertDontSee('Hide this body');
        $this->actingAs($this->otherReporter)
            ->get(route('series.show', $this->series))
            ->assertDontSee('Hide this body');
        $this->actingAs($this->author)
            ->get(route('series.show', $this->series))
            ->assertSee('Hide this body')
            ->assertSee('Not approved');
    }

    #[Test]
    public function bulk_queue_actions_are_atomic_and_audited(): void
    {
        $first = $this->makeComment('Bulk one');
        $second = $this->makeComment('Bulk two');
        $this->makeReport($first);
        $this->makeReport($second, $this->otherReporter);

        $component = Livewire::actingAs($this->moderator)
            ->test(Moderation::class)
            ->set('selected', [$first->id, $second->id])
            ->set('auditNote', 'Bulk review note')
            ->call('applyBulk')
            ->assertHasNoErrors()
            ->assertSee('2 comments updated.');

        $this->assertSame(2, Comment::query()->where('status', Comment::STATUS_HIDDEN)->count());
        $this->assertSame(2, CommentReport::query()->where('status', CommentReport::STATUS_RESOLVED)->count());

        foreach (['approve', 'delete', 'restore'] as $action) {
            $component
                ->set('selected', [$first->id, $second->id])
                ->set('bulkAction', $action)
                ->set('auditNote', "Bulk {$action}")
                ->call('applyBulk')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, Comment::query()->where('status', Comment::STATUS_APPROVED)->count());
        $this->assertSame(0, Comment::onlyTrashed()->count());
        $this->assertSame('Bulk restore', $first->fresh()->moderation_note);
    }

    #[Test]
    public function permanent_deletion_requires_exact_confirmation_and_preserves_report_snapshots(): void
    {
        $comment = $this->makeComment('Body retained only in report audit');
        $report = $this->makeReport($comment);
        app(CommentModerationService::class)->moderate(
            $this->moderator,
            $comment,
            CommentModerationService::ACTION_DELETE,
            'Soft-delete first.',
        );

        $component = Livewire::actingAs($this->moderator)
            ->test(Moderation::class)
            ->call('beginPurge', $comment->id)
            ->set('purgeConfirmation', 'delete')
            ->call('purge')
            ->assertHasErrors('purgeConfirmation');

        $this->assertNotNull(Comment::withTrashed()->find($comment->id));

        $component
            ->set('purgeConfirmation', 'DELETE')
            ->call('purge')
            ->assertHasNoErrors()
            ->assertSee('Comment permanently deleted. Report snapshots were retained.');

        $this->assertNull(Comment::withTrashed()->find($comment->id));
        $report->refresh();
        $this->assertNull($report->comment_id);
        $this->assertSame('Body retained only in report audit', $report->comment_body);
        $this->assertSame($this->author->name, $report->comment_author_name);
        $this->assertSame($this->series->id, $report->commentable_id);
        $this->assertSame(CommentReport::STATUS_RESOLVED, $report->status);
    }

    #[Test]
    public function private_and_unconfirmed_mature_contexts_do_not_leak_into_moderator_queue_or_actions(): void
    {
        $private = Series::create([
            'title' => 'Private Moderation Context',
            'slug' => 'private-moderation-context',
            'is_public' => false,
            'content_rating' => 'safe',
        ]);
        $privateComment = $private->comments()->create([
            'user_id' => $this->editor->id,
            'body' => 'Private editorial comment',
            'status' => Comment::STATUS_PENDING,
        ]);

        $mature = Series::create([
            'title' => 'Mature Moderation Context',
            'slug' => 'mature-moderation-context',
            'is_public' => true,
            'content_rating' => 'mature',
        ]);
        $matureComment = $mature->comments()->create([
            'user_id' => $this->author->id,
            'body' => 'Mature pending comment',
            'status' => Comment::STATUS_PENDING,
        ]);
        app(Settings::class)->set('mature_gate', true);

        $this->actingAs($this->moderator)
            ->get(route('admin.comments', ['filter' => 'pending']))
            ->assertOk()
            ->assertDontSee($privateComment->body)
            ->assertDontSee($matureComment->body);

        try {
            app(CommentModerationService::class)->moderate(
                $this->moderator,
                $privateComment,
                CommentModerationService::ACTION_APPROVE,
            );
            $this->fail('A Moderator saw a private-series comment through the service boundary.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->editor)
            ->get(route('admin.comments', ['filter' => 'pending']))
            ->assertOk()
            ->assertSee($privateComment->body)
            ->assertDontSee($matureComment->body);

        $this->actingAs($this->moderator)
            ->withSession([MatureContentAccess::CONFIRMED_SESSION_KEY => true])
            ->get(route('admin.comments', ['filter' => 'pending']))
            ->assertOk()
            ->assertSee($matureComment->body)
            ->assertDontSee($privateComment->body);

        $this->actingAs($this->reporter)
            ->post(route('comments.reports.store', [$private, $privateComment]), ['report_reason' => 'other'])
            ->assertNotFound();
    }

    #[Test]
    public function queue_filters_show_context_reports_and_escape_reader_supplied_details(): void
    {
        $reported = $this->makeComment('Reported queue body');
        app(CommentReportService::class)->report(
            $this->reporter,
            $reported,
            'harassment',
            '<script>alert("report")</script>',
        );
        $pending = $this->makeComment('Pending queue body', Comment::STATUS_PENDING);
        $hidden = $this->makeComment('Hidden queue body', Comment::STATUS_HIDDEN);
        $deleted = $this->makeComment('Deleted queue body');
        $deleted->delete();

        $this->actingAs($this->moderator)
            ->get(route('admin.comments', ['filter' => 'reported']))
            ->assertOk()
            ->assertSee('Reported queue body')
            ->assertSee('Harassment or abuse')
            ->assertSee('&lt;script&gt;alert(&quot;report&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("report")</script>', false)
            ->assertSee('Moderation Workshop · Series discussion');

        Livewire::actingAs($this->moderator)
            ->test(Moderation::class, ['filter' => 'pending'])
            ->assertSee($pending->body)
            ->assertDontSee($hidden->body)
            ->set('filter', 'hidden')
            ->assertSee($hidden->body)
            ->assertDontSee($pending->body)
            ->set('filter', 'deleted')
            ->assertSee($deleted->body);
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function makeComment(string $body, string $status = Comment::STATUS_APPROVED): Comment
    {
        return $this->series->comments()->create([
            'user_id' => $this->author->id,
            'body' => $body,
            'status' => $status,
        ]);
    }

    private function makeReport(Comment $comment, ?User $reporter = null): CommentReport
    {
        return app(CommentReportService::class)->report(
            $reporter ?? $this->reporter,
            $comment,
            'spam',
            'Needs review.',
        );
    }
}
