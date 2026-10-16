<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Models\YoutubeAccount;
use App\Services\YouTubeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadToYouTubeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a retry would post the video twice

    public int $timeout = 1200;

    /**
     * @param  array{title: string, description: string, privacy: string, tags?: list<string>}  $meta
     */
    public function __construct(
        public string $clipId,
        public int $accountId,
        public array $meta,
    ) {}

    public function handle(YouTubeService $youtube): void
    {
        $log = Log::channel('clipper_jobs');
        $clip = GeneratedClip::with('clipProject')->findOrFail($this->clipId);
        $account = YoutubeAccount::findOrFail($this->accountId);

        // Re-check at run time: the clip may have been rejected/re-rendered/deleted since
        // dispatch, and a channel must only ever post its owner's clips
        if ($account->user_id !== $clip->clipProject?->user_id) {
            throw new \RuntimeException('Channel YouTube bukan milik pemilik clip.');
        }
        if ($clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new \RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }

        $clip->update(['youtube_status' => 'uploading', 'youtube_error' => null]);
        $log->info('YouTube upload started', ['clip_id' => $clip->id, 'channel' => $account->title, 'privacy' => $this->meta['privacy'] ?? null]);

        $videoId = $youtube->upload($account, Storage::path($clip->output_path), $this->meta);
        // YouTube processes the video for a minute or two; the status endpoint follows it up
        $clip->update(['youtube_video_id' => $videoId, 'youtube_status' => 'processing']);
        $log->info('YouTube upload finished', ['clip_id' => $clip->id, 'video_id' => $videoId]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('YouTube upload failed', [
            'clip_id' => $this->clipId,
            'error' => $e->getMessage(),
        ]);

        GeneratedClip::where('id', $this->clipId)->update([
            'youtube_status' => 'failed',
            'youtube_error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
