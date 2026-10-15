<?php

namespace App\Http\Middleware;

use App\Models\Series;
use App\Support\MatureContentAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMatureContentAccess
{
    public function __construct(protected MatureContentAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $series = $request->route('series');

        if (! $series instanceof Series || ! $this->access->requiresConfirmation($series, $request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Age confirmation is required to access this series.',
                'confirmation_url' => route('mature.confirmation'),
            ], 403);
        }

        $this->access->rememberIntended($request, $request->getRequestUri());

        return redirect()->route('mature.confirmation');
    }
}
