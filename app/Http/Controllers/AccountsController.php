<?php

namespace App\Http\Controllers;

use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Models\PlatformSetting;
use App\Services\ConnectedAccounts;
use App\Services\MetaLogin;
use App\Services\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Settings → Akun terhubung: every publishing account, the default per platform, and autopost. */
class AccountsController extends Controller
{
    public function __construct(private ConnectedAccounts $connected) {}

    public function page(): View
    {
        return view('settings.accounts');
    }

    public function index(MetaLogin $meta, PublicMediaUrl $media): JsonResponse
    {
        $userId = Auth::id();
        $settings = PlatformSetting::where('user_id', $userId)->get()->keyBy('platform');

        return response()->json([
            // Instagram and Threads download the video from us: null when APP_URL can serve them
            'media_problem' => $media->problem(),
            'platforms' => collect(ConnectedAccounts::PLATFORMS)->map(function (string $platform) use ($userId, $settings) {
                $accounts = $this->connected->accounts($userId, $platform);
                $setting = $settings[$platform] ?? PlatformSetting::for($userId, $platform);

                return [
                    'id' => $platform,
                    'name' => ConnectedAccounts::NAMES[$platform],
                    'configured' => $this->connected->isConfigured($platform),
                    'connect_url' => ConnectedAccounts::CONNECT_URLS[$platform],
                    'default_id' => $this->connected->default($userId, $platform)?->id,
                    // The default was disconnected and others remain: autopilot skips this platform until one is picked
                    'needs_default' => $this->connected->needsDefault($userId, $platform),
                    'accounts' => $accounts->map(fn ($a) => $this->connected->present($platform, $a))->values(),
                    'autopost' => [
                        'enabled' => (bool) $setting->enabled,
                        'max_per_day' => $setting->max_per_day,
                        'slot_times' => $setting->slot_times,
                        'timezone' => $setting->timezone,
                        'mode' => $platform === 'tiktok' ? $setting->tiktokMode() : null,
                        'direct' => $platform === 'tiktok' ? $setting->tiktokDirect() : null,
                        'saved' => $setting->exists,
                    ],
                ];
            })->values(),
            // Facebook logins: Pages and Instagram accounts are picked per login
            'facebook_logins' => MetaAccount::where('user_id', $userId)->where('provider', 'facebook')->orderBy('id')->get()
                ->map(fn (MetaAccount $a) => ['id' => $a->id, 'name' => $a->name, 'missing_scopes' => $meta->missingScopes($a)])->values(),
        ]);
    }

    public function setDefault(Request $request, string $platform): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $this->connected->setDefault(Auth::id(), $platform, $data['account_id']);

        return response()->json(['default_id' => $data['account_id']]);
    }

    /**
     * Disconnect one account. A Threads login holds exactly one profile, so the
     * login goes with it; a Facebook login stays for its other Pages.
     */
    public function destroy(string $platform, int $accountId): JsonResponse
    {
        $account = $this->connected->find(Auth::id(), $platform, $accountId);
        if ($account instanceof MetaTarget && $platform === 'threads') {
            $account->account()->delete();
        } else {
            $account->delete();
        }

        return response()->json(['default_id' => $this->connected->default(Auth::id(), $platform)?->id]);
    }
}
