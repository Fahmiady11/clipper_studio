<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * 120 requests/minute per account. An MCP tool call re-enters the kernel as an
 * internal sub-request (McpController::dispatch); the outer POST /mcp already
 * counted, so the inner one is skipped. The flag is a request attribute, which
 * a client cannot set.
 */
class ThrottleAgent
{
    public const INTERNAL = 'agent_internal';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::INTERNAL)) {
            return $next($request);
        }

        // Prefixed: unprefixed it shares one counter with every per-route throttle:N,1
        return app(ThrottleRequests::class)->handle($request, $next, 120, 1, 'agent');
    }
}
