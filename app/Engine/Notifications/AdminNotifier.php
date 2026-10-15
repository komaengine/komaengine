<?php

namespace App\Engine\Notifications;

use App\Engine\Settings\Settings;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use App\Notifications\AdminNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns engine events into mail addressed to the `admin_email` setting.
 *
 * Every notice is opt-in: the operator picks which events are worth an email
 * under Settings → Mail, and nothing is sent until they do. A missing or blank
 * administration email silently disables the lot — there is nowhere to send.
 *
 * Delivery failures are reported, never rethrown. Posting a comment must not
 * fail because an SMTP host is down.
 */
class AdminNotifier
{
    public function __construct(protected Settings $settings) {}

    public function commentHeldForModeration(Comment $comment): void
    {
        if (! $comment->isPending()) {
            return;
        }

        $this->send('notify_comment_pending', fn () => new AdminNotice(
            subject: 'A comment is waiting for review',
            lines: [
                ($comment->user?->name ?? 'A member').' commented on '.$this->target($comment).'.',
                '“'.$this->excerpt($comment->body).'”',
                'It stays hidden from readers until a moderator approves it.',
            ],
            actionLabel: 'Open the moderation queue',
            actionUrl: $this->route('admin.comments'),
        ));
    }

    public function commentReported(CommentReport $report, Comment $comment): void
    {
        $this->send('notify_comment_reported', fn () => new AdminNotice(
            subject: 'A comment was reported',
            lines: [
                'Reason given: '.(CommentReport::REASONS[$report->reason] ?? $report->reason).'.',
                'Comment #'.$comment->getKey().' by '.($comment->user?->name ?? 'a former member').':',
                '“'.$this->excerpt($comment->body).'”',
            ],
            actionLabel: 'Review the report',
            actionUrl: $this->route('admin.comments'),
        ));
    }

    public function userRegistered(User $user): void
    {
        $this->send('notify_new_user', fn () => new AdminNotice(
            subject: 'New registration: '.$user->name,
            lines: [
                $user->name.' ('.$user->email.') created an account.',
                'Role assigned: '.($user->getRoleNames()->first() ?? 'none').'.',
            ],
            actionLabel: 'Manage users',
            actionUrl: $this->route('admin.users'),
        ));
    }

    public function archiveImportFinished(SeriesArchiveImport $import): void
    {
        $failed = $import->status === SeriesArchiveImport::STATUS_FAILED;

        $this->send('notify_archive_import', fn () => new AdminNotice(
            subject: $failed ? 'An archive import failed' : 'An archive import finished',
            lines: array_values(array_filter([
                blank($import->source_name)
                    ? 'A queued archive import '.($failed ? 'failed.' : 'completed.')
                    : '“'.$import->source_name.'” '.($failed ? 'failed to import.' : 'was imported.'),
                $import->message,
                $failed ? $import->error : null,
            ])),
            actionLabel: 'Open the importer',
            actionUrl: $this->route('admin.archives.import'),
        ));
    }

    /** Is anything at all deliverable right now? */
    public function recipient(): ?string
    {
        $address = trim((string) $this->settings->get('admin_email', ''));

        return $address === '' ? null : $address;
    }

    /**
     * @param  callable(): AdminNotice  $notice
     */
    protected function send(string $toggle, callable $notice): void
    {
        if (! (bool) $this->settings->get($toggle, false)) {
            return;
        }

        $recipient = $this->recipient();

        if ($recipient === null) {
            return;
        }

        try {
            Notification::route('mail', $recipient)->notify($notice());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function target(Comment $comment): string
    {
        $commentable = $comment->commentable;

        return match (true) {
            $commentable instanceof \App\Models\Chapter => trim(
                ($commentable->series?->title ?? 'a series').' chapter '.$commentable->displayNumber()
            ),
            $commentable instanceof \App\Models\Series => $commentable->title,
            default => 'the site',
        };
    }

    protected function excerpt(string $body): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $body) ?? $body), 240);
    }

    /** Route helper that tolerates being called from a context with no routes (console, tests). */
    protected function route(string $name): ?string
    {
        try {
            return route($name);
        } catch (Throwable) {
            return null;
        }
    }
}
