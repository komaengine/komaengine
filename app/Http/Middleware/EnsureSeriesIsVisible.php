<?php

namespace App\Http\Middleware;

use App\Models\Series;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSeriesIsVisible
{
    /**
     * Keep private series and their metadata out of public responses while
     * allowing editorial staff to preview exactly what will be published.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $series = $request->route('series');

        if ($series instanceof Series
            && ! $series->is_public
            && ! $request->user()?->can('edit_series')) {
            abort(404);
        }

        return $next($request);
    }
}
