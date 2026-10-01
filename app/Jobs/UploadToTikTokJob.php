<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Services\TikTokService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadToTikTokJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1; // a retry would create a second draft
    public int $timeout = 600;

    private const POLL_ATTEMPTS = 10;

    public int $pollSeconds = 6;

    /**
     * @param string $mode     'inbox' (draft) or 'direct' (post to profile)
     * @param array  $postInfo direct only: title, privacy_level, disable_* flags
     */
    public function __construct(
        public string $clipId,
        public int $accountId,
        public string $mode = 'inbox',
        public array $postInfo = [],
    ) {}

    public function handle(TikTokService $tiktok): void
    {
        $log     = Log::channel('clipper_jobs');
        $clip    = GeneratedClip::with('clipProject')->findOrFail($this->clipId);
        $account = TiktokAccount::findOrFail($this->accountId);

        // Re-check at run time: the clip may have been rejected/re-rendered/deleted since
        // dispatch, and an account must only ever post its owner's clips
        if ($account->user_id !== $clip->clipProject?->user_id) {
            throw new \RuntimeException('Akun TikTok bukan milik pemilik clip.');
        }
        if ($clip->status !== 'done' || !$clip->output_path || !Storage::exists($clip->output_path)) {
            throw new \RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }

        $clip->update(['tiktok_status' => 'uploading', 'tiktok_error' => null]);
        $log->info('TikTok upload started', ['clip_id' => $clip->id, 'account' => $account->display_name, 'mode' => $this->mode]);

        $path      = Storage::path($clip->output_path);
        $publishId = $this->mode === 'direct'
            ? $tiktok->directPost($account, $path, $this->postInfo)
            : $tiktok->uploadToInbox($account, $path);
        $clip->update(['tiktok_publish_id' => $publishId, 'tiktok_status' => 'processing']);

        // TikTok processes the upload asynchronously; wait briefly for a final
        // state. If it's still processing, the status endpoint keeps polling.
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            sleep($this->pollSeconds);
            $status = $tiktok->publishStatus($account, $publishId);
            $mapped = self::mapStatus($status['status']);
            if ($mapped !== 'processing') {
                $clip->update(['tiktok_status' => $mapped, 'tiktok_error' => $status['fail_reason']]);
                $log->info('TikTok upload finished', ['clip_id' => $clip->id, 'status' => $status]);
                return;
            }
        }

        $log->info('TikTok upload still processing', ['clip_id' => $clip->id, 'publish_id' => $publishId]);
    }

    public static function mapStatus(string $tiktokStatus): string
    {
        return match ($tiktokStatus) {
            'SEND_TO_USER_INBOX' => 'inbox',
            'PUBLISH_COMPLETE'   => 'published',
            'FAILED'             => 'failed',
            default              => 'processing',
        };
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('TikTok upload failed', [
            'clip_id' => $this->clipId,
            'error'   => $e->getMessage(),
        ]);

        GeneratedClip::where('id', $this->clipId)->update([
            'tiktok_status' => 'failed',
            'tiktok_error'  => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
