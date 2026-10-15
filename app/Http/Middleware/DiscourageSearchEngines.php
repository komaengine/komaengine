<?php

namespace App\Http\Middleware;

use App\Engine\Settings\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a noindex/nofollow X-Robots-Tag header to every response when the
 * "discourage search engines" setting is on. A header covers every theme and
 * page without editing individual layouts; well-behaved crawlers honor it.
 */
class DiscourageSearchEngines
{
    public function __construct(protected Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ((bool) $this->settings->get('discourage_search_engines', false)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
