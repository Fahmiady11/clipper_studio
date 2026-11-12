<?php

namespace App\Services;

use App\Exceptions\CannotPost;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Models\TiktokAccount;
use App\Models\YoutubeAccount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a ScheduledPost carries for each platform, checked the same way
 * whether it comes from the calendar or the Studio publish panel.
 * Problems come back as ValidationException (field) or CannotPost (the
 * clip can't go to that platform at all).
 */
class PostOptions
{
    // The scheduler ticks once a minute, so anything sooner than this would be late by design
    public const MIN_LEAD_MINUTES = 2;

    public const MAX_LEAD_DAYS = 365;

    public function __construct(private MetaPublishing $meta, private YouTubeAutopost $youtube) {}

    /** Validation rules for the per-platform fields. */
    public static function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:2200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['nullable', 'in:public,unlisted,private'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'mode' => ['nullable', 'in:inbox,direct'],
            'allow_comment' => ['sometimes', 'boolean'],
            'allow_duet' => ['sometimes', 'boolean'],
            'allow_stitch' => ['sometimes', 'boolean'],
            'privacy_level' => ['nullable', Rule::in(TikTokService::PRIVACY_LEVELS)],
            'brand_organic_toggle' => ['sometimes', 'boolean'],
            'brand_content_toggle' => ['sometimes', 'boolean'],
        ];
    }

    /** Why this rendered clip can't go to the platform at all, or null. */
    public function refusal(string $platform, GeneratedClip $clip): ?string
    {
        return match ($platform) {
            'youtube' => ($reason = $this->youtube->refusal($clip)) ? "YouTube {$reason} Pendekkan clip dulu." : null,
            'facebook', 'instagram', 'threads' => $this->meta->publisher($platform)->refusal($clip),
            default => null,
        };
    }

    /** @throws ValidationException|CannotPost */
    public function build(string $platform, array $data, GeneratedClip $clip): array
    {
        return match ($platform) {
            'youtube' => $this->youtube($data, $clip),
            'facebook', 'instagram', 'threads' => $this->metaOptions($platform, $data, $clip),
            default => $this->tiktok($data),
        };
    }

    public function scheduledTime(string $value): CarbonImmutable
    {
        $when = CarbonImmutable::parse($value)->utc();
        if ($when->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Waktu publish minimal '.self::MIN_LEAD_MINUTES.' menit dari sekarang.']);
        }
        if ($when->gt(now()->addDays(self::MAX_LEAD_DAYS))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Jadwal maksimal '.self::MAX_LEAD_DAYS.' hari ke depan.']);
        }

        return $when;
    }

    /** The account must be the user's; TikTok also needs the scope its mode posts with. */
    public function ownedAccountId(int $userId, string $platform, int $accountId, ?string $mode): int
    {
        if ($platform === 'youtube') {
            return YoutubeAccount::where('user_id', $userId)->findOrFail($accountId)->id;
        }
        if (in_array($platform, MetaPublishing::PLATFORMS, true)) {
            return MetaTarget::ownedBy($userId, $platform)->findOrFail($accountId)->id;
        }

        $account = TiktokAccount::where('user_id', $userId)->findOrFail($accountId);
        $scope = $mode === 'direct' ? 'video.publish' : 'video.upload';
        if (! $account->hasScope($scope)) {
            throw new CannotPost(TiktokAccount::scopeMessage($scope));
        }

        return $account->id;
    }

    private function youtube(array $data, GeneratedClip $clip): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100 || preg_match('/[<>]/', $title)) {
            throw ValidationException::withMessages(['title' => 'Judul YouTube wajib diisi, maksimal 100 karakter, tanpa < atau >.']);
        }
        if ($reason = $this->refusal('youtube', $clip)) {
            throw new CannotPost($reason);
        }

        return [
            'title' => $title,
            'description' => (string) ($data['description'] ?? ''),
            'privacy' => $data['privacy'] ?? 'private',
            'tags' => $data['tags'] ?? [],
        ];
    }

    /** The post text arrives as `description` for every Meta platform and is checked against its limits. */
    private function metaOptions(string $platform, array $data, GeneratedClip $clip): array
    {
        $text = trim((string) ($data['description'] ?? ''));
        $problem = match ($platform) {
            'instagram' => InstagramReels::captionProblem($text),
            'threads' => ThreadsPosts::textProblem($text),
            default => null,
        };
        if ($problem) {
            throw ValidationException::withMessages(['description' => $problem]);
        }
        if ($reason = $this->refusal($platform, $clip)) {
            throw new CannotPost($reason);
        }

        return [MetaPublishing::textOption($platform) => $text];
    }

    private function tiktok(array $data): array
    {
        $options = [
            'mode' => $data['mode'] ?? 'inbox',
            'title' => (string) ($data['title'] ?? ''),
            'allow_comment' => (bool) ($data['allow_comment'] ?? false),
            'allow_duet' => (bool) ($data['allow_duet'] ?? false),
            'allow_stitch' => (bool) ($data['allow_stitch'] ?? false),
        ];
        if ($options['mode'] !== 'direct') {
            return $options;
        }

        // TikTok wants the viewer audience chosen by the user, never preset
        $privacy = $data['privacy_level'] ?? null;
        if (! in_array($privacy, TikTokService::PRIVACY_LEVELS, true)) {
            throw ValidationException::withMessages(['privacy_level' => 'Pilih siapa yang bisa menonton video ini di TikTok.']);
        }
        $branded = (bool) ($data['brand_content_toggle'] ?? false);
        if ($branded && $privacy === 'SELF_ONLY') {
            throw ValidationException::withMessages(['privacy_level' => TikTokService::BRANDED_SELF_ONLY]);
        }

        return $options + [
            'privacy_level' => $privacy,
            'brand_organic_toggle' => (bool) ($data['brand_organic_toggle'] ?? false),
            'brand_content_toggle' => $branded,
        ];
    }
}
