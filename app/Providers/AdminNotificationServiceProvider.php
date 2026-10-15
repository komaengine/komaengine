<?php

namespace App\Providers;

use App\Engine\Hooks\HookManager;
use App\Engine\Notifications\AdminNotifier;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use Illuminate\Support\ServiceProvider;

/**
 * Subscribes the administration-email notices to engine action hooks.
 *
 * The hooks are the same ones plugins use, so a plugin can add its own handler
 * for any of these events without touching the engine.
 */
class AdminNotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $hooks = $this->app->make(HookManager::class);

        $hooks->addAction('comment.created', function (Comment $comment): void {
            $this->notifier()->commentHeldForModeration($comment);
        });

        $hooks->addAction('comment.reported', function (CommentReport $report, Comment $comment): void {
            $this->notifier()->commentReported($report, $comment);
        });

        $hooks->addAction('user.registered', function (User $user): void {
            $this->notifier()->userRegistered($user);
        });

        $hooks->addAction('archive.import.finished', function (SeriesArchiveImport $import): void {
            $this->notifier()->archiveImportFinished($import);
        });
    }

    protected function notifier(): AdminNotifier
    {
        return $this->app->make(AdminNotifier::class);
    }
}
