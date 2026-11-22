<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Support\HookText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** The AI caption + hashtags of a clip, written once and kept on the clip. */
class ClipCaption
{
    // Longer than a slow Gemini fallback chain, so the lock never frees while a call is still running
    private const LOCK_SECONDS = 600;

    // How long a second caller waits for the first one's answer before giving up
    private const WAIT_SECONDS = 90;

    public function __construct(private GeminiService $gemini) {}

    /** @return array{caption: string, hashtags: list<string>} caption is '' when the AI call failed */
    public function ensure(GeneratedClip $clip): array
    {
        if ($stored = $this->stored($clip)) {
            return $stored;
        }

        // The render's background job and the page's lazy fetch can ask at the same moment: one asks Gemini, the other waits for that answer
        try {
            return Cache::lock('clip-caption:'.$clip->id, self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($clip) {
                $fresh = $clip->fresh();

                return ($fresh ? $this->stored($fresh) : null) ?? $this->generate($clip);
            });
        } catch (LockTimeoutException) {
            return ['caption' => '', 'hashtags' => []];
        }
    }

    /** @return array{caption: string, hashtags: list<string>}|null null when no caption was written yet (an empty one was a failed call) */
    private function stored(GeneratedClip $clip): ?array
    {
        return $clip->caption !== null && $clip->caption !== ''
            ? ['caption' => $clip->caption, 'hashtags' => $clip->hashtags_json ?? []]
            : null;
    }

    /** @return array{caption: string, hashtags: list<string>} */
    private function generate(GeneratedClip $clip): array
    {
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
