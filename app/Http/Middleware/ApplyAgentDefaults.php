<?php

namespace App\Http\Middleware;

use App\Models\StylePreset;
use App\Support\ClipSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An agent only has to send youtube_url. The look comes from preset_id, else
 * the owner's default preset; the studio's required settings fall back to the
 * defaults. Whatever the agent sends itself still wins over both.
 */
class ApplyAgentDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $preset = $request->filled('preset_id')
            ? StylePreset::findVisible($request->input('preset_id'), $user?->id) // unknown ids fail validation later
            : $user?->defaultStylePreset();

        $input = $preset ? $preset->under($request->all(), $user->brandKit) : $request->all();
        $request->replace($input + array_intersect_key(
            ClipSettings::DEFAULTS,
            array_flip(['layout_type', 'clip_count', 'duration_mode']),
        ));

        return $next($request);
    }
}
