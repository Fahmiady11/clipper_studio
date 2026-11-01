<?php

namespace App\Services;

use App\Http\Controllers\YouTubeController;
use App\Models\AutopostSetting;
use App\Models\GeneratedClip;
use App\Models\YoutubeAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Autopilot to YouTube Shorts: the platform side of Autopost. */
class YouTubeAutopost implements AutopostPlatform
{
    public function __construct(private YouTubeService $youtube) {}

    public function name(): string
    {
        return 'YouTube';
    }

    public function isConfigured(): bool
    {
        return $this->youtube->isConfigured();
    }

    public function accounts(int $userId): Collection
    {
        return YoutubeAccount::where('user_id', $userId)->orderBy('title')->get(['id', 'title'])
            ->map(fn (YoutubeAccount $a) => ['id' => $a->id, 'name' => $a->title]);
    }

    public function account(AutopostSetting $settings): ?Model
    {
        return $settings->account_id ? YoutubeAccount::where('user_id', $settings->user_id)->find($settings->account_id) : null;
    }

    public function missingAccount(): string
    {
        return 'Channel YouTube untuk autopilot belum dipilih atau sudah diputus. Pilih channel di pengaturan YouTube.';
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        // Jump cuts make the rendered clip shorter than end - start
        try {
            $seconds = app(FFmpegService::class)->probeDuration(Storage::path($clip->output_path));
        } catch (Throwable) {
            $seconds = $clip->durationSeconds();
        }

        return $seconds > YouTubeController::MAX_SHORT_SECONDS + 0.5
            ? 'Shorts maksimal 3 menit, clip ini '.round($seconds).' detik.'
            : null;
    }

    /** Title, description and tags: topic, AI caption, hashtags. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array
    {
        return [
            'title' => mb_substr(trim(str_replace(['<', '>'], '', (string) $clip->topic)), 0, 100) ?: 'Clip',
            'description' => mb_substr(trim($caption."\n\n".implode(' ', $hashtags)), 0, 5000),
            'privacy' => 'public', // YouTube keeps it private until the API project passes its audit
            'tags' => array_slice(array_values(array_filter(array_map(fn ($h) => ltrim($h, '#'), $hashtags))), 0, 30),
            'autopilot' => true,
        ];
    }

    public function recordSkip(GeneratedClip $clip, ?string $reason): void
    {
        $clip->update(['youtube_autopost_error' => $reason === null ? null : mb_substr($reason, 0, 1000)]);
    }

    public function skipReason(GeneratedClip $clip): ?string
    {
        return $clip->youtube_autopost_error;
    }
}
