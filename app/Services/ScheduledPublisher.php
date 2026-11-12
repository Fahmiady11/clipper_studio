<?php

namespace App\Services;

use App\Jobs\PublishToMetaJob;
use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\YoutubeAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Hands scheduled posts to the same upload jobs the Studio buttons use, once their time has come. */
class ScheduledPublisher
{
    private const BUSY = ['queued', 'uploading', 'processing'];

    private const BATCH = 50;

    /** @return int how many due posts were picked up */
    public function publishDue(): int
    {
        // Accounts whose access was revoked keep their schedule but publish nothing
        $ids = ScheduledPost::where('status', ScheduledPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->whereHas('user', fn ($q) => $q->whereNotNull('approved_at'))
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->pluck('id');

        $picked = 0;
        foreach ($ids as $id) {
            // Claim first: overlapping scheduler runs must not post the same clip twice
            $claimed = ScheduledPost::where('id', $id)
                ->where('status', ScheduledPost::STATUS_SCHEDULED)
                ->update(['status' => ScheduledPost::STATUS_PUBLISHING]);
            if (! $claimed) {
                continue;
            }

            $this->handOff(ScheduledPost::with('clip.clipProject')->findOrFail($id));
            $picked++;
        }

        return $picked;
    }

    public function handOff(ScheduledPost $post): void
    {
        try {
            $this->dispatchUpload($post);
            $post->update(['status' => ScheduledPost::STATUS_QUEUED, 'handed_off_at' => now(), 'error' => null]);
            Log::channel('clipper_jobs')->info('Scheduled post handed to upload', ['post_id' => $post->id, 'platform' => $post->platform]);
        } catch (Throwable $e) {
            $post->update(['status' => ScheduledPost::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::channel('clipper_jobs')->warning('Scheduled post failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);
        }
    }

    /** TikTok direct-post settings. Posts booked before privacy was a choice carry none and stay private. */
    public static function tiktokPostInfo(array $options): array
    {
        $privacy = $options['privacy_level'] ?? null;

        return [
            'title' => (string) ($options['title'] ?? ''),
            'privacy_level' => in_array($privacy, TikTokService::PRIVACY_LEVELS, true) ? $privacy : 'SELF_ONLY',
            'disable_comment' => ! ($options['allow_comment'] ?? false),
            'disable_duet' => ! ($options['allow_duet'] ?? false),
            'disable_stitch' => ! ($options['allow_stitch'] ?? false),
            'brand_organic_toggle' => (bool) ($options['brand_organic_toggle'] ?? false),
            'brand_content_toggle' => (bool) ($options['brand_content_toggle'] ?? false),
        ];
    }

    private function dispatchUpload(ScheduledPost $post): void
    {
        $clip = $post->clip;
        if (! $clip || $clip->status !== 'done' || ! $clip->output_path || ! Storage::exists($clip->output_path)) {
            throw new \RuntimeException('File clip tidak tersedia (mungkin ditolak, dihapus, atau sedang dirender ulang).');
        }
        if ($clip->clipProject?->user_id !== $post->user_id) {
            throw new \RuntimeException('Clip bukan milik pemilik jadwal.');
        }

        match ($post->platform) {
            'tiktok' => $this->dispatchTikTok($post, $clip),
            'youtube' => $this->dispatchYouTube($post, $clip),
            'facebook', 'instagram', 'threads' => $this->dispatchMeta($post, $clip),
            default => throw new \RuntimeException("Platform tidak dikenal: {$post->platform}."),
        };
    }

    private function dispatchTikTok(ScheduledPost $post, GeneratedClip $clip): void
    {
        $account = TiktokAccount::where('user_id', $post->user_id)->find($post->account_id)
            ?? throw new \RuntimeException('Akun TikTok sudah diputus. Hubungkan lagi lalu jadwalkan ulang.');
        if (in_array($clip->tiktok_status, self::BUSY, true)) {
            throw new \RuntimeException('Clip ini sedang diupload ke TikTok.');
        }

        $options = $post->options ?? [];
        $mode = ($options['mode'] ?? 'inbox') === 'direct' ? 'direct' : 'inbox';
        $scope = $mode === 'direct' ? 'video.publish' : 'video.upload';
        if (! $account->hasScope($scope)) {
            throw new \RuntimeException(TiktokAccount::scopeMessage($scope));
        }

        $clip->update([
            'tiktok_account_id' => $account->id,
            'tiktok_publish_id' => null,
            'tiktok_status' => 'queued',
            'tiktok_error' => null,
        ]);
        UploadToTikTokJob::dispatch($clip->id, $account->id, $mode, $mode === 'direct' ? self::tiktokPostInfo($options) : []);
    }

    private function dispatchYouTube(ScheduledPost $post, GeneratedClip $clip): void
    {
        $account = YoutubeAccount::where('user_id', $post->user_id)->find($post->account_id)
            ?? throw new \RuntimeException('Channel YouTube sudah diputus. Hubungkan lagi lalu jadwalkan ulang.');
        if (in_array($clip->youtube_status, ['queued', 'uploading'], true)) {
            throw new \RuntimeException('Clip ini sedang diupload ke YouTube.');
        }

        $options = $post->options ?? [];
        $clip->update([
            'youtube_account_id' => $account->id,
            'youtube_video_id' => null,
            'youtube_status' => 'queued',
            'youtube_error' => null,
        ]);
        UploadToYouTubeJob::dispatch($clip->id, $account->id, [
            'title' => (string) ($options['title'] ?? ''),
            'description' => (string) ($options['description'] ?? ''),
            'privacy' => (string) ($options['privacy'] ?? 'private'),
            'tags' => $options['tags'] ?? [],
        ]);
    }

    private function dispatchMeta(ScheduledPost $post, GeneratedClip $clip): void
    {
        $name = app(MetaPublishing::class)->publisher($post->platform)->name();
        $target = MetaTarget::ownedBy($post->user_id, $post->platform)->find($post->account_id)
            ?? throw new \RuntimeException(MetaPublishing::targetNoun($post->platform).' sudah diputus. Hubungkan lagi lalu jadwalkan ulang.');
        if (in_array($clip->publication($post->platform)?->status, ['queued', 'uploading'], true)) {
            throw new \RuntimeException("Clip ini sedang diupload ke {$name}.");
        }

        ClipPublication::updateOrCreate(['generated_clip_id' => $clip->id, 'platform' => $post->platform], [
            'target_id' => $target->id,
            'status' => 'queued',
            'external_id' => null,
            'container_id' => null,
            'url' => null,
            'error' => null,
            'uploaded_at' => null,
            'checked_at' => null,
        ]);
        $text = (string) (($post->options ?? [])[MetaPublishing::textOption($post->platform)] ?? '');
        PublishToMetaJob::dispatch($clip->id, $post->platform, $target->id, $text);
    }
}
