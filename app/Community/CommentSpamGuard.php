<?php

namespace App\Community;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class CommentSpamGuard
{
    public function issueToken(?CarbonInterface $startedAt = null): string
    {
        return Crypt::encryptString((string) ($startedAt ?? now())->getTimestamp());
    }

    public function isSuspicious(?string $token, mixed $honeypot): bool
    {
        if (filled($honeypot) || blank($token)) {
            return true;
        }

        try {
            $startedAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return true;
        }

        $elapsed = now()->getTimestamp() - $startedAt;

        return $startedAt <= 0
            || $elapsed < (int) config('koma.comments.minimum_submit_seconds', 2)
            || $elapsed > (int) config('koma.comments.form_token_max_age_seconds', 7200);
    }
}
