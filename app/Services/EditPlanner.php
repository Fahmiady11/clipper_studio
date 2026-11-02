<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Support\BrollCues;
use App\Support\EditPlan;
use App\Support\EmojiCues;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gets a clip its edit plan (EditPlan): asks the AI once, stores the answer on
 * the clip, and reuses it on every re-render until the clip is trimmed.
 */
class EditPlanner
{
    public function __construct(private GeminiService $ai) {}

    /**
     * @param  array<int, array>  $segments  transcript segments (absolute times, with words)
     * @return array{0: array|null, 1: bool} [plan in source time or null when there is none, whether the AI was asked]
     */
    public function plan(GeneratedClip $clip, array $segments, float $hookDuration): array
    {
        $start = (float) $clip->start_seconds;
        $end = (float) $clip->end_seconds;
        $stored = $clip->edit_plan;
        if (is_array($stored) && ($stored['key'] ?? null) === EditPlan::key($start, $end)) {
            return [$stored, false];
        }

        $words = array_values(array_filter(array_merge([], ...array_map(fn ($s) => $s['words'] ?? [], $segments)),
            fn ($w) => ($w['start'] ?? -1) >= $start && ($w['start'] ?? INF) < $end));
        $phrases = EditPlan::phrases($words);
        if (! $phrases) {
            return [null, false];
        }

        try {
            $answer = $this->ai->editPlan((string) $clip->topic, EditPlan::transcript($phrases, $start), $hookDuration + 1.0,
                BrollCues::max($end - $start), EmojiCues::max($end - $start), EmojiCues::choices());
        } catch (RuntimeException $e) {
            Log::channel('clipper_jobs')->warning('Edit plan unavailable, using fallbacks', ['clip_id' => $clip->id, 'error' => $e->getMessage()]);

            return [null, true];
        }

        $plan = EditPlan::fromAi($answer, $phrases, $start, $end);
        $clip->update(['edit_plan' => $plan]);
        Log::channel('clipper_jobs')->info('Edit plan', ['clip_id' => $clip->id, 'broll' => array_column($plan['broll'], 'query'),
            'emoji' => array_column($plan['emoji'], 'code'), 'punch' => count($plan['punch'])]);

        return [$plan, true];
    }
}
