<?php

namespace Tests\Feature;

use App\Community\CommentService;
use App\Community\CommentSpamGuard;
use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use App\Models\User;
use App\Support\MatureContentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicCommentTest extends TestCase
{
    use RefreshDatabase;

    private Series $series;

    private Chapter $chapter;

    private User $member;

    private User $otherMember;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->series = Series::create([
            'title' => 'Comment Workshop',
            'slug' => 'comment-workshop',
            'content_rating' => 'safe',
            'is_public' => true,
        ]);
        $this->chapter = Chapter::create([
            'series_id' => $this->series->id,
            'number' => 1,
            'status' => Chapter::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->member = User::factory()->create(['name' => 'Mika Reader']);
        $this->member->assignRole('Member');
        $this->otherMember = User::factory()->create(['name' => 'Other Reader']);
        $this->otherMember->assignRole('Member');
        $this->moderator = User::factory()->create(['name' => 'Haru Moderator']);
        $this->moderator->assignRole('Moderator');
    }

    #[Test]
    public function approved_series_and_chapter_comments_are_public_while_pending_comments_stay_scoped(): void
    {
        $this->makeComment($this->series, $this->member, 'Approved series thought.');
        $this->makeComment($this->series, $this->member, 'Pending series thought.', Comment::STATUS_PENDING);
        $this->makeComment($this->chapter, $this->member, 'Approved chapter thought.');
        $this->makeComment($this->chapter, $this->member, 'Pending chapter thought.', Comment::STATUS_PENDING);

        $this->get(route('series.show', $this->series))
            ->assertOk()
            ->assertSee('Series discussion')
            ->assertSee('Approved series thought.')
            ->assertDontSee('Pending series thought.')
            ->assertSee('Sign in')
            ->assertSee('Comments remain readable without an account.');

        $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
            ->assertOk()
            ->assertSee('Chapter discussion')
            ->assertSee('Approved chapter thought.')
            ->assertDontSee('Pending chapter thought.')
            ->assertSee('Jump to chapter discussion');
    }

    #[Test]
    public function members_can_post_normalized_plain_text_comments_to_series_and_published_chapters(): void
    {
        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), $this->cleanPayload("  First line  \r\nSecond line\t  "))
            ->assertRedirect(route('series.show', $this->series).'#comments')
            ->assertSessionHas('comment_status', 'Your comment was posted.');

        $seriesComment = $this->series->comments()->sole();

        $this->assertSame("First line\nSecond line", $seriesComment->body);
        $this->assertSame(Comment::STATUS_APPROVED, $seriesComment->status);
        $this->assertTrue($seriesComment->user->is($this->member));

        $this->actingAs($this->member)
            ->post(route('comments.chapter.store', [$this->series, $this->chapter->number]), $this->cleanPayload('The final page landed well.'))
            ->assertRedirect(route('reader', [$this->series, $this->chapter->displayNumber()]).'#comments');

        $this->assertSame('The final page landed well.', $this->chapter->comments()->sole()->body);
    }

    #[Test]
    public function anonymous_and_capability_less_accounts_cannot_post_at_either_boundary(): void
    {
        $this->post(route('comments.series.store', $this->series), ['body' => 'Anonymous'])
            ->assertRedirect(route('login'));

        $withoutCapability = User::factory()->create();

        $this->actingAs($withoutCapability)
            ->post(route('comments.series.store', $this->series), ['body' => 'Not allowed'])
            ->assertForbidden();

        try {
            app(CommentService::class)->create($withoutCapability, $this->series, 'Still not allowed');
            $this->fail('The service boundary accepted a user without the comment capability.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('comments', 0);
    }

    #[Test]
    public function owners_can_edit_and_soft_delete_during_the_documented_window(): void
    {
        $comment = $this->makeComment($this->series, $this->member, 'Original body');

        $this->actingAs($this->member)
            ->patch(route('comments.update', [$this->series, $comment]), ['body' => "Updated body\r\nwith detail"])
            ->assertRedirect(route('series.show', $this->series).'#comments');

        $comment->refresh();
        $this->assertSame("Updated body\nwith detail", $comment->body);
        $this->assertNotNull($comment->edited_at);

        $this->actingAs($this->member)
            ->delete(route('comments.destroy', [$this->series, $comment]))
            ->assertRedirect(route('series.show', $this->series).'#comments');

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);

        $this->get(route('series.show', $this->series))
            ->assertOk()
            ->assertSee('This comment was deleted.')
            ->assertDontSee('Updated body')
            ->assertDontSee($this->member->name);
    }

    #[Test]
    public function owner_editing_and_deletion_close_after_the_configured_window(): void
    {
        $comment = $this->makeComment($this->series, $this->member, 'Window closes');
        $this->travel((int) config('koma.comments.owner_edit_window_minutes') + 1)->minutes();

        $this->actingAs($this->member)
            ->patch(route('comments.update', [$this->series, $comment]), ['body' => 'Too late'])
            ->assertForbidden();
        $this->actingAs($this->member)
            ->delete(route('comments.destroy', [$this->series, $comment]))
            ->assertForbidden();

        $this->assertSame('Window closes', $comment->fresh()->body);
        $this->assertFalse($comment->fresh()->trashed());
    }

    #[Test]
    public function other_members_are_denied_while_moderators_can_override_with_audit_metadata(): void
    {
        $comment = $this->makeComment($this->series, $this->member, 'Needs correction');

        $this->actingAs($this->otherMember)
            ->patch(route('comments.update', [$this->series, $comment]), ['body' => 'Hijacked'])
            ->assertForbidden();
        $this->actingAs($this->otherMember)
            ->delete(route('comments.destroy', [$this->series, $comment]))
            ->assertForbidden();

        $this->actingAs($this->moderator)
            ->patch(route('comments.update', [$this->series, $comment]), ['body' => 'Moderator correction'])
            ->assertRedirect();

        $comment->refresh();
        $this->assertSame('Moderator correction', $comment->body);
        $this->assertSame($this->moderator->id, $comment->moderated_by);
        $this->assertNotNull($comment->moderated_at);

        $this->actingAs($this->moderator)
            ->delete(route('comments.destroy', [$this->series, $comment]))
            ->assertRedirect();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    #[Test]
    public function pending_comments_are_visible_only_to_their_author_and_moderators(): void
    {
        $pending = $this->makeComment($this->series, $this->member, 'Awaiting a decision.', Comment::STATUS_PENDING);

        $this->get(route('series.show', $this->series))->assertDontSee($pending->body);
        $this->actingAs($this->otherMember)
            ->get(route('series.show', $this->series))
            ->assertDontSee($pending->body);
        $this->actingAs($this->member)
            ->get(route('series.show', $this->series))
            ->assertSee($pending->body)
            ->assertSee('Pending review');
        $this->actingAs($this->moderator)
            ->get(route('series.show', $this->series))
            ->assertSee($pending->body)
            ->assertSee('Pending review');
    }

    #[Test]
    public function private_series_middleware_prevents_comment_reads_and_writes_from_leaking_content(): void
    {
        $this->series->update(['is_public' => false]);
        $privateBody = 'Private editorial discussion.';
        $this->makeComment($this->series, $this->member, $privateBody);

        $this->actingAs($this->member)
            ->get(route('series.show', $this->series))
            ->assertNotFound()
            ->assertDontSee($privateBody);
        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), ['body' => 'Should not post'])
            ->assertNotFound();

        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        $this->actingAs($editor)
            ->get(route('series.show', $this->series))
            ->assertOk()
            ->assertSee($privateBody);
        $this->actingAs($editor)
            ->post(route('comments.series.store', $this->series), $this->cleanPayload('Authorized preview note'))
            ->assertRedirect();
    }

    #[Test]
    public function mature_access_is_required_before_comments_can_be_read_or_changed(): void
    {
        app(Settings::class)->set('mature_gate', true);
        $this->series->update(['content_rating' => 'mature']);
        $body = 'Mature discussion content.';
        $this->makeComment($this->series, $this->member, $body);

        $this->actingAs($this->member)
            ->get(route('series.show', $this->series))
            ->assertRedirect(route('mature.confirmation'))
            ->assertDontSee($body);
        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), ['body' => 'Blocked until confirmed'])
            ->assertRedirect(route('mature.confirmation'));
        $this->actingAs($this->member)
            ->withSession([MatureContentAccess::CONFIRMED_SESSION_KEY => true])
            ->post(route('comments.series.store', $this->series), $this->cleanPayload('Confirmed reader comment'))
            ->assertRedirect(route('series.show', $this->series).'#comments');
    }

    #[Test]
    public function comment_output_is_escaped_and_link_validation_rejects_abuse_without_losing_line_breaks(): void
    {
        $body = "<script>alert('xss')</script>\nA second line.\nhttps://example.test/reference";

        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), $this->cleanPayload($body))
            ->assertRedirect();

        $this->get(route('series.show', $this->series))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('xss')</script>", false)
            ->assertSee("A second line.\nhttps://example.test/reference", false);

        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), ['body' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('body');

        $tooManyLinks = collect(range(1, 6))->map(fn (int $i) => "https://example.test/{$i}")->implode(' ');
        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), ['body' => $tooManyLinks])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('comments', 1);
    }

    #[Test]
    public function public_threads_paginate_newest_comments_without_duplicating_content(): void
    {
        config()->set('koma.comments.per_page', 2);
        $this->makeComment($this->series, $this->member, 'Oldest comment')
            ->forceFill(['created_at' => now()->subMinutes(3)])->saveQuietly();
        $this->makeComment($this->series, $this->member, 'Middle comment')
            ->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
        $this->makeComment($this->series, $this->member, 'Newest comment')
            ->forceFill(['created_at' => now()->subMinute()])->saveQuietly();

        $this->get(route('series.show', $this->series))
            ->assertOk()
            ->assertSee('Newest comment')
            ->assertSee('Middle comment')
            ->assertDontSee('Oldest comment')
            ->assertSee('comments_page=2');

        $this->get(route('series.show', [$this->series, 'comments_page' => 2]))
            ->assertOk()
            ->assertSee('Oldest comment')
            ->assertDontSee('Newest comment');
    }

    #[Test]
    public function comment_mutations_are_rate_limited_per_signed_in_account(): void
    {
        $limit = (int) config('koma.comments.rate_limit_per_minute');

        foreach (range(1, $limit) as $attempt) {
            $this->actingAs($this->member)
                ->post(route('comments.series.store', $this->series), $this->cleanPayload("Allowed comment {$attempt}"))
                ->assertRedirect();
        }

        $this->actingAs($this->member)
            ->post(route('comments.series.store', $this->series), ['body' => 'One too many'])
            ->assertTooManyRequests();
        $this->actingAs($this->otherMember)
            ->post(route('comments.series.store', $this->series), $this->cleanPayload('Independent account'))
            ->assertRedirect();
    }

    #[Test]
    public function cross_series_and_unpublished_chapter_mutations_fail_closed(): void
    {
        $otherSeries = Series::create([
            'title' => 'Other Series',
            'slug' => 'other-series',
            'is_public' => true,
        ]);
        $foreign = $this->makeComment($otherSeries, $this->member, 'Belongs elsewhere');

        $this->actingAs($this->member)
            ->patch(route('comments.update', [$this->series, $foreign]), ['body' => 'Cross-boundary edit'])
            ->assertNotFound();

        $draft = Chapter::create([
            'series_id' => $this->series->id,
            'number' => 2,
            'status' => Chapter::STATUS_DRAFT,
        ]);

        $this->actingAs($this->member)
            ->post(route('comments.chapter.store', [$this->series, $draft->number]), ['body' => 'Draft leak'])
            ->assertNotFound();

        $this->assertSame('Belongs elsewhere', $foreign->fresh()->body);
        $this->assertSame(0, $draft->comments()->count());
    }

    private function makeComment(
        Series|Chapter $target,
        User $user,
        string $body,
        string $status = Comment::STATUS_APPROVED,
    ): Comment {
        return $target->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'status' => $status,
        ]);
    }

    /** @return array<string, string> */
    private function cleanPayload(string $body): array
    {
        $minimum = (int) config('koma.comments.minimum_submit_seconds', 2);

        return [
            'body' => $body,
            'comment_proof' => app(CommentSpamGuard::class)->issueToken(now()->subSeconds($minimum + 1)),
        ];
    }
}
