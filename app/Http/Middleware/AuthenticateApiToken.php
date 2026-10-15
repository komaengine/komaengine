<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return $this->unauthenticated('A bearer token is required.');
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($token === null || $token->user === null || $token->expired()) {
            return $this->unauthenticated('The bearer token is invalid or expired.');
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    protected function unauthenticated(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 401);
    }
}
