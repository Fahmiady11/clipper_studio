<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the agent API. The token's owner becomes the current
 * user, so the controllers shared with the web app keep scoping by Auth::id().
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? ApiToken::findByPlain($plain) : null;

        if (! $token || ! $token->user) {
            return response()->json(['message' => 'Token API tidak valid.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if (! $token->user->isApproved()) {
            return response()->json(['message' => 'Akun kamu belum disetujui admin.'], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        Auth::guard('web')->setUser($token->user);
        $request->attributes->set('api_token', $token);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
