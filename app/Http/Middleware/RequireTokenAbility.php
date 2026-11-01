<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken || ! $token->can($ability)) {
            return response()->json([
                'message' => "Token ini tidak punya izin \"{$ability}\". Buat token baru dengan izin itu di /settings/api.",
                'required_ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
