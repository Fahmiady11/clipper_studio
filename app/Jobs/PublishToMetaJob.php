<?php

namespace App\Jobs;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Services\MetaPublishing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Sends a clip to Facebook, Instagram or Threads; MetaStatusPoller follows it up from "processing". */
class PublishToMetaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a retry would post twice

    public int $timeout = 1200;

    public function __construct(
        public string $clipId,
        public string $platform,
        public int $targetId,
        public string $text,
    ) {}

    public function handle(MetaPublishing $publishing): void
    {
        $publisher = $publishing->publisher($this->platform);
        $noun = MetaPublishing::targetNoun($this->platform);
        $clip = GeneratedClip::with('clipProject')->find($this->clipId);
        if (! $clip) {
            Log::channel('clipper_jobs')->info('Meta upload dropped: clip deleted', ['clip_id' => $this->clipId, 'platform' => $this->platform]);

            return; // deleted from history, or its account was deleted, while waiting in the queue
        }
        $target = MetaTarget::with('account')->where('platform', $this->platform)->find($this->targetId)
            ?? throw new RuntimeException("{$noun} sudah diputus. Hubungkan lagi lalu jadwalkan ulang.");

        // Re-check at run time: the clip may have been rejected/re-rendered/deleted since
        // dispatch, and an account must only ever post its owner's clips
        if ($target->user_id !== $clip->clipProject?->user_id) {
            throw new RuntimeException("{$noun} bukan milik pemilik clip.");
        }
        if ($clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }
        if ($reason = $publisher->refusal($clip)) {
            throw new RuntimeException($reason);
        }

        $this->publication()->update(['status' => 'uploading', 'target_id' => $target->id, 'error' => null]);
        Log::channel('clipper_jobs')->info('Meta upload started', ['clip_id' => $clip->id, 'platform' => $this->platform, 'target' => $target->name]);

        $sent = $publisher->start($target, $clip, $this->text);
        $this->publication()->update($sent + [
            'status' => 'processing',
            'uploaded_at' => now(),
            'checked_at' => null,
        ]);
        Log::channel('clipper_jobs')->info('Meta upload sent', ['clip_id' => $clip->id, 'platform' => $this->platform] + $sent);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('Meta upload failed', [
            'clip_id' => $this->clipId,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);

        $this->publication()->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }

    private function publication(): ClipPublication
    {
        return ClipPublication::firstOrCreate(
            ['generated_clip_id' => $this->clipId, 'platform' => $this->platform],
            ['target_id' => $this->targetId, 'status' => 'queued'],
        );
    }
}
