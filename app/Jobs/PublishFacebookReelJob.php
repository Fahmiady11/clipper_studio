<?php

namespace App\Jobs;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Services\FacebookReels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PublishFacebookReelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a retry would post the Reel twice

    public int $timeout = 1200;

    public function __construct(
        public string $clipId,
        public int $targetId,
        public string $description,
    ) {}

    public function handle(FacebookReels $reels): void
    {
        $log = Log::channel('clipper_jobs');
        $clip = GeneratedClip::with('clipProject')->findOrFail($this->clipId);
        $page = MetaTarget::with('account')->where('platform', 'facebook')->find($this->targetId)
            ?? throw new RuntimeException('Page Facebook sudah diputus. Hubungkan lagi lalu jadwalkan ulang.');

        // Re-check at run time: the clip may have been rejected/re-rendered/deleted since
        // dispatch, and a Page must only ever post its owner's clips
        if ($page->user_id !== $clip->clipProject?->user_id) {
            throw new RuntimeException('Page Facebook bukan milik pemilik clip.');
        }
        if ($clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }
        if ($reason = $reels->refusal($clip)) {
            throw new RuntimeException($reason);
        }

        $this->publication()->update(['status' => 'uploading', 'target_id' => $page->id, 'error' => null]);
        $log->info('Facebook Reel upload started', ['clip_id' => $clip->id, 'page' => $page->name]);

        $videoId = $reels->publish($page, Storage::path($clip->output_path), $this->description);
        // Meta processes the Reel for a while; MetaStatusPoller follows it up
        $this->publication()->update([
            'status' => 'processing',
            'external_id' => $videoId,
            'uploaded_at' => now(),
            'checked_at' => null,
        ]);
        $log->info('Facebook Reel upload finished', ['clip_id' => $clip->id, 'video_id' => $videoId]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('Facebook Reel upload failed', [
            'clip_id' => $this->clipId,
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
            ['generated_clip_id' => $this->clipId, 'platform' => 'facebook'],
            ['target_id' => $this->targetId, 'status' => 'queued'],
        );
    }
}
