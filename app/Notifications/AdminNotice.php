<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A single system notice addressed to the site's administration email.
 *
 * There is one notification class rather than one per event because every
 * administrative notice has the same shape — a subject, a sentence or two of
 * context, and one link into the admin screen that resolves it. The event
 * wording lives in {@see \App\Engine\Notifications\AdminNotifier}.
 */
class AdminNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines = [],
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('['.\App\Support\Koma::siteTitle().'] '.$this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        if ($this->actionLabel !== null && $this->actionUrl !== null) {
            $message->action($this->actionLabel, $this->actionUrl);
        }

        return $message->line('You are receiving this because it is switched on under Settings → Mail.');
    }
}
