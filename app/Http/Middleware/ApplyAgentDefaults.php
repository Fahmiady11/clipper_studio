<?php

namespace App\Http\Middleware;

use App\Support\ClipSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An agent only has to send youtube_url; the studio's required settings fall back to the defaults. */
class ApplyAgentDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($request->all() + array_intersect_key(
            ClipSettings::DEFAULTS,
            array_flip(['layout_type', 'clip_count', 'duration_mode']),
        ));

        return $next($request);
    }
}
