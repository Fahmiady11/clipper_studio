<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Support\HookText;

/** The AI caption + hashtags of a clip, written once and kept on the clip. */
class ClipCaption
{
    public function __construct(private GeminiService $gemini) {}

    /** @return array{caption: string, hashtags: list<string>} caption is '' when the AI call failed */
    public function ensure(GeneratedClip $clip): array
    {
        // An empty caption was a failed call: retry
        if ($clip->caption !== null && $clip->caption !== '') {
            return ['caption' => $clip->caption, 'hashtags' => $clip->hashtags_json ?? []];
        }

        $result = $this->gemini->generateCaption(
            $clip->topic,
            HookText::plain($clip->hook_text_override ?? $clip->hook_text ?? ''),
            $clip->subtitle_json ?? [],
            $clip->clipProject->transcript?->language ?? 'id',
        );

        if ($result['caption'] !== '') {
            $clip->update([
                'caption' => $result['caption'],
                'hashtags_json' => $result['hashtags'],
            ]);
        }

        return $result;
    }
}
