<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Services\TikTokService;
use App\Services\TikTokStatusPoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadToTikTokJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a retry would create a second draft

    public int $timeout = 600;

    private const POLL_ATTEMPTS = 10;

    public int $pollSeconds = 6;

    /**
     * @param  string  $mode  'inbox' (draft) or 'direct' (post to profile)
     * @param  array  $postInfo  direct only: title, privacy_level, disable_* flags
     */
    public function __construct(
        public string $clipId,
        public int $accountId,
        public string $mode = 'inbox',
        public array $postInfo = [],
    ) {}

    public function handle(TikTokService $tiktok): void
    {
        $log = Log::channel('clipper_jobs');
        $clip = GeneratedClip::with('clipProject')->find($this->clipId);
        if (! $clip) {
            $log->info('TikTok upload dropped: clip deleted', ['clip_id' => $this->clipId]);

            return; // deleted from history, or its account was deleted, while waiting in the queue
        }
        $account = TiktokAccount::findOrFail($this->accountId);

        // Re-check at run time: the clip may have been rejected/re-rendered/deleted since
        // dispatch, and an account must only ever post its owner's clips
        if ($account->user_id !== $clip->clipProject?->user_id) {
            throw new \RuntimeException('Akun TikTok bukan milik pemilik clip.');
        }
        if ($clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new \RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }

        $clip->update(['tiktok_status' => 'uploading', 'tiktok_error' => null]);
        $log->info('TikTok upload started', ['clip_id' => $clip->id, 'account' => $account->display_name, 'mode' => $this->mode]);

        $path = Storage::path($clip->output_path);
        $publishId = $this->mode === 'direct'
            ? $tiktok->directPost($account, $path, $this->postInfo)
            : $tiktok->uploadToInbox($account, $path);
        $clip->update([
            'tiktok_publish_id' => $publishId,
            'tiktok_status' => 'processing',
            'tiktok_uploaded_at' => now(),
            'tiktok_checked_at' => null,
        ]);

        // TikTok processes the upload asynchronously; wait briefly for a final
        // state. If it's still processing, clipper:tiktok-status keeps polling.
        $poller = app(TikTokStatusPoller::class);
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            sleep($this->pollSeconds);
            try {
                $status = $tiktok->publishStatus($account, $publishId);
            } catch (Throwable $e) {
                // The file is with TikTok already: failing the job here would hide a draft that did arrive
                $log->warning('TikTok status check failed, left to the poller', ['clip_id' => $clip->id, 'error' => $e->getMessage()]);

                return;
            }
            if ($poller->apply($clip, $status)) {
                return;
            }
        }

        $log->info('TikTok upload still processing', ['clip_id' => $clip->id, 'publish_id' => $publishId]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('TikTok upload failed', [
            'clip_id' => $this->clipId,
            'error' => $e->getMessage(),
        ]);

        GeneratedClip::where('id', $this->clipId)->update([
            'tiktok_status' => 'failed',
            'tiktok_error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
