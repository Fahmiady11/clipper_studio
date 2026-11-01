<?php

namespace App\Services\Social;

use App\Jobs\UploadToTikTokJob;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Services\TikTokService;
use Illuminate\Support\Collection;

/** TikTok through the shared contract; tokens stay in tiktok_accounts and the upload in UploadToTikTokJob. */
class TikTokPublisher implements SocialPublisher
{
    public function __construct(private TikTokService $tiktok) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function isConfigured(): bool
    {
        return $this->tiktok->isConfigured();
    }

    public function connectUrl(): string
    {
        return route('tiktok.connect');
    }

    public function manageUrl(): ?string
    {
        return null;
    }

    public function authorizeUrl(string $state): string
    {
        return $this->tiktok->authorizeUrl($state);
    }

    public function callback(int $userId, string $code): ConnectedAccount
    {
        return $this->summary($this->tiktok->connect($userId, $code));
    }

    public function accounts(int $userId): Collection
    {
        return TiktokAccount::where('user_id', $userId)->orderBy('display_name')->get()
            ->map(fn (TiktokAccount $a) => $this->summary($a));
    }

    public function disconnect(int $userId, int $accountId): void
    {
        TiktokAccount::where('user_id', $userId)->findOrFail($accountId)->delete();
    }

    /** @param  string  $mode  inbox (draft) or direct (post to profile); options are TikTok's post_info */
    public function publish(GeneratedClip $clip, int $accountId, string $mode = 'inbox', array $options = []): void
    {
        $clip->update([
            'tiktok_account_id' => $accountId,
            'tiktok_publish_id' => null,
            'tiktok_status' => 'queued',
            'tiktok_error' => null,
        ]);
        UploadToTikTokJob::dispatch($clip->id, $accountId, $mode === 'direct' ? 'direct' : 'inbox', $options);
    }

    public function status(GeneratedClip $clip): PublishStatus
    {
        return new PublishStatus($clip->tiktok_status, $clip->tiktok_error);
    }

    private function summary(TiktokAccount $a): ConnectedAccount
    {
        return new ConnectedAccount($a->id, 'tiktok', $a->display_name ?: 'Akun #'.$a->id, $a->avatar_url);
    }
}
