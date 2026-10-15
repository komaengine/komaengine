<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

class RevokeApiToken extends Command
{
    protected $signature = 'koma:api-token:revoke {id : Token ID shown when the token was issued}';

    protected $description = 'Revoke a Koma API bearer token';

    public function handle(): int
    {
        $token = ApiToken::query()->find($this->argument('id'));

        if ($token === null) {
            $this->error('No API token exists with that ID.');

            return self::FAILURE;
        }

        $name = $token->name;
        $token->delete();

        $this->info("API token {$name} was revoked.");

        return self::SUCCESS;
    }
}
