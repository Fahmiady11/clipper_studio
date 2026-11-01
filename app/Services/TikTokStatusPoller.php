<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follows TikTok uploads after the upload job stops polling (about a minute):
 * a draft lands in the inbox (final until the creator posts it in the app),
 * a direct post completes, or TikTok reports why it failed.
 */
class TikTokStatusPoller
{
    public const GIVE_UP_HOURS = 24;

    // TikTok allows 30 status fetches per minute per user token; leave room for the upload job and Studio
    private const PER_ACCOUNT = 20;

    private const FAIL_REASONS = [
        'file_format_check_failed' => 'Format video tidak didukung TikTok.',
        'duration_check_failed' => 'Durasi video di luar batas TikTok.',
        'frame_rate_check_failed' => 'Frame rate video tidak didukung TikTok.',
        'picture_size_check_failed' => 'Resolusi video tidak didukung TikTok.',
        'internal' => 'Ada gangguan di server TikTok. Kirim ulang nanti.',
        'video_pull_failed' => 'TikTok gagal mengambil file video.',
        'publish_cancelled' => 'Pengiriman dibatalkan di TikTok.',
        'auth_removed' => 'Akses Clipper dicabut dari akun TikTok. Hubungkan ulang akun TikTok.',
        'spam_risk_too_many_posts' => 'Batas posting harian TikTok tercapai. Kirim ulang besok.',
        'spam_risk_user_banned_from_posting' => 'Akun TikTok sedang dibatasi untuk posting.',
        'spam_risk_text' => 'TikTok menandai teks postingan sebagai spam.',
        'spam_risk' => 'TikTok menandai postingan ini sebagai spam.',
    ];

    public function __construct(private TikTokService $tiktok) {}

    public function run(): array
    {
        $due = GeneratedClip::with('clipProject')
            ->where('tiktok_status', 'processing')
            ->whereNotNull('tiktok_publish_id')
            ->get()
            ->filter(fn (GeneratedClip $clip) => $this->isDue($clip));

        $accounts = TiktokAccount::whereIn('id', $due->pluck('tiktok_account_id')->filter()->unique())->get()->keyBy('id');
        $checked = $settled = 0;
        foreach ($due->groupBy(fn (GeneratedClip $clip) => (int) $clip->tiktok_account_id) as $accountId => $clips) {
            // Oldest check first, so a big backlog drains across runs without starving any clip
            foreach ($clips->sortBy(fn (GeneratedClip $clip) => $clip->tiktok_checked_at?->timestamp ?? 0)->take(self::PER_ACCOUNT) as $clip) {
                $checked++;
                $settled += (int) $this->refresh($clip, $accounts[$accountId] ?? null);
            }
        }

        return ['checked' => $checked, 'settled' => $settled];
    }

    public function isDue(GeneratedClip $clip): bool
    {
        $last = $clip->tiktok_checked_at;
        if (! $last) {
            return true;
        }
        $ageMinutes = ($clip->tiktok_uploaded_at ?? $clip->updated_at)->diffInMinutes(now());
        $every = match (true) {
            $ageMinutes < 60 => 0,
            $ageMinutes < 360 => 15,
            default => 60,
        };

        return $last->copy()->addMinutes($every)->lte(now());
    }

    /** Ask TikTok once; true when the clip left 'processing'. */
    public function refresh(GeneratedClip $clip, ?TiktokAccount $account): bool
    {
        // Rows sent before the upload time was kept: pin it now, every check below bumps updated_at
        $clip->tiktok_uploaded_at ??= $clip->updated_at;

        // Never use someone else's tokens, even via a stale account id
        if (! $account || $account->user_id !== $clip->clipProject?->user_id) {
            return $this->settle($clip, 'Akun TikTok sudah diputus, status tidak bisa dicek lagi. Cek inbox TikTok di aplikasi.');
        }

        try {
            $status = $this->tiktok->publishStatus($account, $clip->tiktok_publish_id);
        } catch (Throwable $e) {
            $clip->update([
                'tiktok_checked_at' => now(),
                'tiktok_error' => mb_substr('Status TikTok belum bisa dicek: '.$e->getMessage(), 0, 1000),
            ]);
            Log::channel('clipper_jobs')->warning('TikTok status poll failed', ['clip_id' => $clip->id, 'error' => $e->getMessage()]);

            return $this->giveUpIfOld($clip);
        }

        return $this->apply($clip, $status) || $this->giveUpIfOld($clip);
    }

    /** Record what status/fetch returned; true when it is final. */
    public function apply(GeneratedClip $clip, array $status): bool
    {
        $mapped = self::mapStatus($status['status']);
        $clip->update([
            'tiktok_status' => $mapped,
            'tiktok_error' => $mapped === 'failed' ? self::failMessage($status['fail_reason'] ?? null) : null,
            'tiktok_checked_at' => now(),
        ]);
        if ($mapped === 'processing') {
            return false;
        }
        Log::channel('clipper_jobs')->info('TikTok upload settled', ['clip_id' => $clip->id, 'status' => $status]);

        return true;
    }

    /**
     * SEND_TO_USER_INBOX is final for drafts until the creator posts it in the app.
     * PROCESSING_UPLOAD / PROCESSING_DOWNLOAD (and anything unknown) keep polling.
     */
    public static function mapStatus(string $tiktokStatus): string
    {
        return match ($tiktokStatus) {
            'SEND_TO_USER_INBOX' => 'inbox',
            'PUBLISH_COMPLETE' => 'published',
            'FAILED' => 'failed',
            default => 'processing',
        };
    }

    public static function failMessage(?string $reason): string
    {
        if (! $reason) {
            return 'TikTok menolak video ini.';
        }

        return (self::FAIL_REASONS[$reason] ?? 'TikTok menolak video ini.')." ({$reason})";
    }

    private function giveUpIfOld(GeneratedClip $clip): bool
    {
        if (now()->lt($clip->tiktok_uploaded_at->copy()->addHours(self::GIVE_UP_HOURS))) {
            return false;
        }

        return $this->settle($clip, 'TikTok belum memberi status akhir setelah '.self::GIVE_UP_HOURS.' jam. Cek inbox TikTok di aplikasi; kalau draftnya tidak ada, kirim ulang.');
    }

    private function settle(GeneratedClip $clip, string $reason): bool
    {
        $clip->update(['tiktok_status' => 'failed', 'tiktok_error' => $reason, 'tiktok_checked_at' => now()]);
        Log::channel('clipper_jobs')->warning('TikTok upload given up', ['clip_id' => $clip->id, 'reason' => $reason]);

        return true;
    }
}
