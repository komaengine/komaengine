<?php

namespace App\Console\Commands;

use App\Models\Chapter;
use Illuminate\Console\Command;

class PublishScheduledChapters extends Command
{
    protected $signature = 'koma:publish-scheduled';

    protected $description = 'Publish chapters whose scheduled publication time has arrived';

    public function handle(): int
    {
        $published = 0;

        Chapter::query()
            ->due()
            ->orderBy('id')
            ->chunkById(100, function ($chapters) use (&$published) {
                foreach ($chapters as $chapter) {
                    if ($chapter->publishDue()) {
                        $published++;
                    }
                }
            });

        $this->info($published === 1
            ? 'Published 1 scheduled chapter.'
            : "Published {$published} scheduled chapters.");

        return self::SUCCESS;
    }
}
