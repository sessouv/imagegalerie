<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Authentifie les appels à l'API par une clé envoyée en en-tête « Authorization: Bearer … ». */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::with('user')->where('token_hash', hash('sha256', $plain))->first() : null;

        if (! $token || ! $token->user) {
            return response()->json(['message' => 'Clé d\'API absente ou invalide.'], 401);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        Auth::setUser($token->user);

        return $next($request);
    }
}
