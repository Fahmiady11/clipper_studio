<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Models\TiktokAccount;
use App\Support\CaptionCredit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Autopilot to TikTok: the post waits for its calendar slot, then goes to the inbox. */
class TiktokAutopost implements AutopostPlatform
{
    private const BUSY = ['queued', 'uploading', 'processing'];

    public function __construct(private TikTokService $tiktok) {}

    public function name(): string
    {
        return 'TikTok';
    }

    public function isConfigured(): bool
    {
        return $this->tiktok->isConfigured();
    }

    public function accounts(int $userId): Collection
    {
        return TiktokAccount::where('user_id', $userId)->orderBy('id')->get(['id', 'display_name'])
            ->map(fn (TiktokAccount $a) => ['id' => $a->id, 'name' => $a->display_name]);
    }

    public function account(PlatformSetting $settings): ?Model
    {
        if ($settings->account_id) {
            return TiktokAccount::where('user_id', $settings->user_id)->find($settings->account_id);
        }

        // An approve-time pick rides on the in-memory settings; it must still be the owner's account
        $picked = $settings->isDirty('account_id')
            ? TiktokAccount::where('user_id', $settings->user_id)->find($settings->account_id)
            : null;

        return $picked ?? app(ConnectedAccounts::class)->default($settings->user_id, 'tiktok');
    }

    public function missingAccount(): string
    {
        return 'Belum ada akun TikTok yang terhubung. Hubungkan di Akun terhubung.';
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        return in_array($clip->tiktok_status, self::BUSY, true)
            ? 'Clip ini sedang diupload ke TikTok.'
            : null;
    }

    /** The inbox draft gets the caption when it is sent; TikTok itself stores no caption. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags, ?string $credit = null): array
    {
        unset($clip);

        return [
            'mode' => 'inbox',
            'title' => CaptionCredit::compose($caption, $credit, implode(' ', $hashtags), 2200),
            'allow_comment' => false,
            'allow_duet' => false,
            'allow_stitch' => false,
            'autopilot' => true,
        ];
    }

    /**
     * TikTok's booking state lives on the clip: "skipped" is only why autopilot
     * could not book it, never a real upload result.
     */
    public function recordSkip(GeneratedClip $clip, ?string $reason): void
    {
        if ($reason === null) {
            if ($clip->tiktok_status === 'skipped') {
                $clip->update(['tiktok_account_id' => null, 'tiktok_publish_id' => null, 'tiktok_status' => null, 'tiktok_error' => null]);
            }

            return;
        }

        if (! in_array($clip->tiktok_status, [null, 'skipped'], true)) {
            return; // Never over a real upload: a clip already sent keeps its status
        }

        $clip->update([
            'tiktok_account_id' => null,
            'tiktok_publish_id' => null,
            'tiktok_status' => 'skipped',
            'tiktok_error' => mb_substr($reason, 0, 1000),
        ]);
    }

    public function skipReason(GeneratedClip $clip): ?string
    {
        return $clip->tiktok_status === 'skipped' ? $clip->tiktok_error : null;
    }
}
