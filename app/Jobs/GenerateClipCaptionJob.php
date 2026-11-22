<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Services\ClipCaption;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes an autopilot clip's caption right after its render, so the review page
 * and approve find it stored instead of waiting for Gemini.
 */
class GenerateClipCaptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // A caption call can walk the whole Gemini fallback chain
    public int $timeout = 600;

    public function __construct(public string $clipId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> seconds before the 2nd and 3rd try */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ClipCaption $captions): void
    {
        $clip = GeneratedClip::with('clipProject.transcript')->find($this->clipId);

        // Deleted, cancelled, rejected or not an autopilot clip: nobody will post it
        if (! $clip || $clip->cancelled_at || $clip->status !== 'done' || $clip->review_status === null || $clip->review_status === 'rejected') {
            return;
        }

        if ($captions->ensure($clip)['caption'] !== '') {
            return;
        }

        // The last try just gives up: the lazy fetch and approve still ask again, and a failed job would only add noise
        if ($this->attempts() >= $this->tries) {
            Log::channel('clipper_jobs')->warning('GenerateClipCaptionJob gave up, caption stays lazy', ['clip_id' => $this->clipId]);

            return;
        }

        throw new RuntimeException('Caption generation returned nothing');
    }
}
