<?php

namespace App\Jobs;

use App\Models\GeneratedClip;
use App\Models\SocialAccount;
use App\Services\Social\InstagramPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class UploadToInstagramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a retry could publish the same Reel twice

    public int $timeout = 1200;

    /** Seconds to wait before each look at the container: quick at first, then every 30 s (about 7 minutes in all). */
    public const POLL_PAUSES = [3, 5, 8, 12, 20, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30];

    /**
     * @param  array{caption: string, share_to_feed: bool}  $options
     */
    public function __construct(
        public string $clipId,
        public int $accountId,
        public array $options,
    ) {}

    public function handle(InstagramPublisher $instagram): void
    {
        $log = Log::channel('clipper_jobs');
        $clip = GeneratedClip::with('clipProject')->findOrFail($this->clipId);
        $account = SocialAccount::findOrFail($this->accountId);

        // Re-check at run time: the clip may have been rejected, re-rendered or deleted since dispatch,
        // and an account must only ever post its owner's clips
        if ($account->platform !== InstagramPublisher::PLATFORM || $account->user_id !== $clip->clipProject?->user_id) {
            throw new RuntimeException('Akun Instagram bukan milik pemilik clip.');
        }
        if ($clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new RuntimeException('File clip tidak tersedia (mungkin ditolak atau sedang dirender ulang).');
        }

        $clip->update(['instagram_status' => 'uploading', 'instagram_error' => null]);
        $log->info('Instagram upload started', ['clip_id' => $clip->id, 'account' => $account->display_name]);

        $left = $instagram->remainingQuota($account);
        if ($left === 0) {
            throw new RuntimeException('Batas posting Instagram per 24 jam untuk akun ini sudah habis. Coba lagi besok.');
        }

        $container = $instagram->createContainer($account, $this->options['caption'] ?? '', (bool) ($this->options['share_to_feed'] ?? true));
        $instagram->uploadVideo($account, $container['uri'], Storage::path($clip->output_path));
        $clip->update(['instagram_status' => 'processing']);

        $this->waitUntilProcessed($instagram, $account, $container['id']);

        // Not retried by design: see InstagramPublisher::publishContainer
        $mediaId = $instagram->publishContainer($account, $container['id']);
        $clip->update([
            'instagram_media_id' => $mediaId,
            'instagram_url' => $instagram->permalink($account, $mediaId),
            'instagram_status' => 'published',
        ]);
        $log->info('Instagram upload finished', ['clip_id' => $clip->id, 'media_id' => $mediaId]);
    }

    private function waitUntilProcessed(InstagramPublisher $instagram, SocialAccount $account, string $containerId): void
    {
        foreach (self::POLL_PAUSES as $pause) {
            Sleep::for($pause)->seconds();
            $state = $instagram->containerStatus($account, $containerId);

            if ($state['code'] === 'FINISHED') {
                return;
            }
            if (in_array($state['code'], ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram menolak video ini: '.($state['detail'] ?: $state['code']).'.');
            }
        }

        throw new RuntimeException('Instagram belum selesai memproses video setelah sekitar 7 menit. Coba kirim ulang nanti.');
    }

    public function failed(Throwable $e): void
    {
        Log::channel('clipper_jobs')->error('Instagram upload failed', [
            'clip_id' => $this->clipId,
            'error' => $e->getMessage(),
        ]);

        GeneratedClip::where('id', $this->clipId)->update([
            'instagram_status' => 'failed',
            'instagram_error' => mb_substr(InstagramPublisher::friendlyError($e), 0, 1000),
        ]);
    }
}
