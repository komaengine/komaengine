<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class IssueApiToken extends Command
{
    protected $signature = 'koma:api-token
        {email : Email address of the Koma user}
        {--name=Bulk uploader : Label used to identify the token}
        {--expires= : Optional ISO-8601 expiration date/time}';

    protected $description = 'Issue a bulk-upload API bearer token for an authorized user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        if (! $user->can('upload_chapters')) {
            $this->error('That user does not have the upload_chapters capability.');

            return self::FAILURE;
        }

        try {
            $expiresAt = $this->option('expires')
                ? CarbonImmutable::parse((string) $this->option('expires'))
                : null;
        } catch (Throwable) {
            $this->error('The expiration must be a valid ISO-8601 date/time.');

            return self::FAILURE;
        }

        if ($expiresAt?->isPast()) {
            $this->error('The expiration must be in the future.');

            return self::FAILURE;
        }

        $issued = ApiToken::issue(
            $user,
            trim((string) $this->option('name')) ?: 'Bulk uploader',
            $expiresAt,
        );

        $this->info('API token created. Copy it now; Koma stores only its hash.');
        $this->line($issued['plain_text_token']);
        $this->newLine();
        $this->line("Token ID: {$issued['token']->id}");

        return self::SUCCESS;
    }
}
