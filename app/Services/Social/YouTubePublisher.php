<?php

namespace App\Services\Social;

use App\Jobs\UploadToYouTubeJob;
use App\Models\GeneratedClip;
use App\Models\YoutubeAccount;
use App\Services\YouTubeService;
use Illuminate\Support\Collection;

/** YouTube Shorts through the shared contract; channels stay in youtube_accounts (they prove channel ownership). */
class YouTubePublisher implements SocialPublisher
{
    public function __construct(private YouTubeService $youtube) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube Shorts';
    }

    public function isConfigured(): bool
    {
        return $this->youtube->isConfigured();
    }

    public function connectUrl(): string
    {
        return route('youtube.connect');
    }

    /** Disconnecting a channel withdraws its video verification, so that page explains it. */
    public function manageUrl(): ?string
    {
        return route('youtube.settings');
    }

    public function authorizeUrl(string $state): string
    {
        return $this->youtube->authorizeUrl($state);
    }

    public function callback(int $userId, string $code): ConnectedAccount
    {
        return $this->summary($this->youtube->connect($userId, $code));
    }

    public function accounts(int $userId): Collection
    {
        return YoutubeAccount::where('user_id', $userId)->orderBy('title')->get()
            ->map(fn (YoutubeAccount $a) => $this->summary($a));
    }

    public function disconnect(int $userId, int $accountId): void
    {
        YoutubeAccount::where('user_id', $userId)->findOrFail($accountId)->delete();
    }

    /** @param  array{title: string, description: string, privacy: string, tags?: list<string>}  $options */
    public function publish(GeneratedClip $clip, int $accountId, string $mode = 'default', array $options = []): void
    {
        $clip->update([
            'youtube_account_id' => $accountId,
            'youtube_video_id' => null,
            'youtube_status' => 'queued',
            'youtube_error' => null,
        ]);
        UploadToYouTubeJob::dispatch($clip->id, $accountId, $options);
    }

    public function status(GeneratedClip $clip): PublishStatus
    {
        return new PublishStatus(
            $clip->youtube_status,
            $clip->youtube_error,
            $clip->youtube_video_id ? 'https://youtube.com/shorts/'.$clip->youtube_video_id : null,
        );
    }

    private function summary(YoutubeAccount $a): ConnectedAccount
    {
        return new ConnectedAccount($a->id, 'youtube', $a->title ?: 'Channel #'.$a->id, $a->thumbnail_url);
    }
}
