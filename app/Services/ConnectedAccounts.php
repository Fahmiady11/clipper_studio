<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use App\Models\TiktokAccount;
use App\Models\YoutubeAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Every account a user publishes to, whatever the platform: what Settings →
 * Akun terhubung lists, and which one is the default that Studio, the
 * calendar and autopilot use. The default is the platform's PlatformSetting
 * account_id. The first account connected becomes it; once that account is
 * disconnected while others remain, there is no default until the user picks
 * one ("Pilih akun utama"), so nothing moves to another account unasked.
 */
class ConnectedAccounts
{
    public const PLATFORMS = ['tiktok', 'youtube', 'facebook', 'instagram', 'threads'];

    public const NAMES = ['tiktok' => 'TikTok', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads'];

    // Where the OAuth flow starts; the callbacks registered with Google/Meta/TikTok stay as they are
    public const CONNECT_URLS = [
        'tiktok' => '/tiktok/connect',
        'youtube' => '/youtube/connect',
        'facebook' => '/facebook/connect',
        'instagram' => '/facebook/connect', // Instagram accounts come with the Facebook login
        'threads' => '/threads/connect',
    ];

    public function __construct(
        private TikTokService $tiktok,
        private YouTubeService $youtube,
        private MetaLogin $meta,
        private ThreadsLogin $threads,
    ) {}

    /** Settings page, opened at one platform: where errors that need a new login send the user. */
    public static function settingsUrl(?string $platform = null): string
    {
        return '/settings/accounts'.($platform ? '?reconnect='.$platform : '');
    }

    public function isConfigured(string $platform): bool
    {
        return match ($platform) {
            'tiktok' => $this->tiktok->isConfigured(),
            'youtube' => $this->youtube->isConfigured(),
            'facebook', 'instagram' => $this->meta->isConfigured(),
            'threads' => $this->threads->isConfigured(),
        };
    }

    public function query(int $userId, string $platform): Builder
    {
        return match ($platform) {
            'tiktok' => TiktokAccount::where('user_id', $userId),
            'youtube' => YoutubeAccount::where('user_id', $userId),
            'facebook', 'instagram', 'threads' => MetaTarget::ownedBy($userId, $platform)->with('account'),
            default => throw new RuntimeException("Platform tidak dikenal: {$platform}."),
        };
    }

    /** Oldest first, so the fallback default is the account connected first. */
    public function accounts(int $userId, string $platform): Collection
    {
        return $this->query($userId, $platform)->orderBy('id')->get();
    }

    public function find(int $userId, string $platform, int $accountId): Model
    {
        return $this->query($userId, $platform)->findOrFail($accountId);
    }

    /** Null when nothing is connected, or when the default was disconnected and the user has to pick one. */
    public function default(int $userId, string $platform): ?Model
    {
        $accounts = $this->accounts($userId, $platform);
        $settings = PlatformSetting::where('user_id', $userId)->where('platform', $platform)->first();
        $chosen = $settings?->account_id;

        if ($chosen) {
            if ($found = $accounts->firstWhere('id', $chosen)) {
                return $found;
            }
            // The default was disconnected. With nothing left the next account connected takes over
            if ($accounts->isEmpty()) {
                $settings->update(['account_id' => null]);
            }

            return null;
        }
        if ($accounts->isEmpty()) {
            return null;
        }
        // Nothing chosen yet: pin the first account, so disconnecting it later never moves posts silently
        $this->setDefault($userId, $platform, $accounts->first()->id);

        return $accounts->first();
    }

    /** The default was disconnected while other accounts remain: the user must pick one. */
    public function needsDefault(int $userId, string $platform): bool
    {
        return $this->default($userId, $platform) === null && $this->query($userId, $platform)->exists();
    }

    public static function needsDefaultMessage(string $platform): string
    {
        return 'Akun utama '.self::NAMES[$platform].' sudah diputus. Pilih akun utama di Akun terhubung.';
    }

    public function setDefault(int $userId, string $platform, int $accountId): void
    {
        $this->find($userId, $platform, $accountId);
        $settings = PlatformSetting::for($userId, $platform);
        $settings->account_id = $accountId;
        $settings->save();
    }

    public function name(Model $account): ?string
    {
        return $account->display_name ?? $account->title ?? $account->name ?? $account->username;
    }

    /**
     * One account as the settings page shows it. problem: why posting there
     * may fail; reconnect: true when only a new login fixes it.
     *
     * @return array{id: int, name: ?string, username: ?string, picture_url: ?string, problem: ?string, reconnect: bool}
     */
    public function present(string $platform, Model $account): array
    {
        [$problem, $reconnect] = $this->health($platform, $account);

        return [
            'id' => $account->id,
            'name' => $this->name($account),
            'username' => $account->username ?? null,
            'picture_url' => $account->avatar_url ?? $account->thumbnail_url ?? $account->picture_url ?? null,
            'problem' => $problem,
            'reconnect' => $reconnect,
        ];
    }

    /** @return array{0: ?string, 1: bool} */
    private function health(string $platform, Model $account): array
    {
        if ($platform === 'tiktok' && $account->refresh_expires_at?->isPast()) {
            return ['Sesi TikTok habis.', true];
        }
        if ($platform === 'threads' && $account->account?->token_expires_at?->isPast()) {
            return ['Akses Threads sudah lewat 60 hari.', true];
        }
        if ($error = $this->lastLoginError($platform, $account)) {
            return [$error, true];
        }

        $missing = match ($platform) {
            'tiktok' => array_values(array_filter(
                preg_split('/[\s,]+/', (string) config('services.tiktok.scopes')),
                fn ($scope) => $scope !== '' && ! $account->hasScope($scope),
            )),
            'facebook' => $this->meta->missingScopes($account->account),
            'instagram' => $this->meta->missingInstagramScopes($account->account),
            default => [],
        };

        return $missing
            ? ['Izin '.implode(', ', $missing).' belum diberikan, jadi posting bisa gagal. Hubungkan ulang dan centang semua izin.', true]
            : [null, false];
    }

    /**
     * The latest upload error that asked for a new login ("Hubungkan ulang …"),
     * as long as the account has not been reconnected since.
     */
    private function lastLoginError(string $platform, Model $account): ?string
    {
        $since = ($account instanceof MetaTarget ? $account->account?->updated_at : $account->updated_at) ?? $account->updated_at;
        $asksLogin = fn ($q, string $column) => $q->where(fn ($w) => $w->where($column, 'like', '%ubungkan ulang%'));

        return match ($platform) {
            'tiktok', 'youtube' => $asksLogin(GeneratedClip::where("{$platform}_account_id", $account->id)
                ->where("{$platform}_status", 'failed')->where('updated_at', '>', $since), "{$platform}_error")
                ->latest('updated_at')->value("{$platform}_error"),
            default => $asksLogin(ClipPublication::where('platform', $platform)->where('target_id', $account->id)
                ->where('status', 'failed')->where('updated_at', '>', $since), 'error')
                ->latest('updated_at')->value('error'),
        };
    }
}
