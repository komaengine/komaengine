<?php

namespace App\Community;

class CommentText
{
    public function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\t", '    ', $body);
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $body = preg_replace('/[ \x{00A0}]+$/mu', '', $body) ?? '';
        $body = preg_replace('/\n{4,}/', "\n\n\n", $body) ?? '';

        return trim($body);
    }

    public function validationError(string $body): ?string
    {
        if (preg_match('/\b(?:javascript|data|vbscript):/iu', $body) === 1) {
            return 'Comments may contain only plain-text HTTP or HTTPS links.';
        }

        preg_match_all('/\b[a-z][a-z0-9+.-]*:\/\/[^\s<>]+/iu', $body, $schemedLinks);

        foreach ($schemedLinks[0] as $link) {
            if (! in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return 'Comments may contain only plain-text HTTP or HTTPS links.';
            }
        }

        preg_match_all('/(?:https?:\/\/|www\.)[^\s<>]+/iu', $body, $links);

        if (count($links[0]) > (int) config('koma.comments.max_links', 5)) {
            return 'Comments may contain at most '.config('koma.comments.max_links', 5).' links.';
        }

        return null;
    }
}
