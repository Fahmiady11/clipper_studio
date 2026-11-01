<?php

namespace App\Services;

use App\Models\MetaAccount;
use App\Models\MetaTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Threads login: its own OAuth on threads.com, separate from Facebook. The
 * code becomes a 60-day token that refresh() renews before it runs out; one
 * login is one Threads profile, so it gets exactly one target.
 */
class ThreadsLogin
{
    // Refresh once less than this is left; Meta only refreshes tokens at least a day old
    public const REFRESH_DAYS_LEFT = 30;

    public function __construct(private ThreadsGraph $graph) {}

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function authorizeUrl(string $state): string
    {
        return config('services.threads.authorize_url').'?'.http_build_query([
            'client_id' => config('services.threads.app_id'),
            'redirect_uri' => config('services.threads.redirect_uri'),
            'scope' => config('services.threads.scopes'),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function connect(int $userId, string $code): MetaAccount
    {
        $short = Http::asForm()->acceptJson()->timeout(30)->post($this->graph->root('oauth/access_token'), [
            'client_id' => config('services.threads.app_id'),
            'client_secret' => config('services.threads.app_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.threads.redirect_uri'),
        ]);
        $this->graph->check($short, 'token');

        $long = Http::acceptJson()->timeout(30)->get($this->graph->root('access_token'), [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.app_secret'),
            'access_token' => $short->json('access_token') ?? throw new RuntimeException('Threads tidak mengirim access token.'),
        ]);
        $this->graph->check($long, 'token jangka panjang');
        $token = $long->json('access_token') ?? throw new RuntimeException('Threads tidak mengirim token jangka panjang.');

        $me = $this->graph->get('me', $token, ['fields' => 'id,username,name,threads_profile_picture_url'], 'profil');

        return DB::transaction(function () use ($userId, $me, $token, $long) {
            $account = MetaAccount::updateOrCreate(
                ['user_id' => $userId, 'provider' => 'threads', 'provider_user_id' => (string) $me['id']],
                [
                    'name' => $me['username'] ?? $me['name'] ?? null,
                    'access_token' => $token,
                    'token_expires_at' => now()->addSeconds((int) ($long->json('expires_in') ?? 60 * 86400)),
                    'scopes' => config('services.threads.scopes'),
                ]
            );
            MetaTarget::updateOrCreate(
                ['user_id' => $userId, 'platform' => 'threads', 'external_id' => (string) $me['id']],
                [
                    'meta_account_id' => $account->id,
                    'name' => $me['name'] ?? $me['username'] ?? null,
                    'username' => $me['username'] ?? null,
                    'picture_url' => $me['threads_profile_picture_url'] ?? null,
                    'access_token' => null, // posts use the login's token, kept fresh by refresh()
                ]
            );

            return $account;
        });
    }

    /**
     * Renew Threads tokens with under REFRESH_DAYS_LEFT days to go. An expired
     * one can't be renewed: publishing then fails with a reconnect link.
     *
     * @return array{refreshed: int, failed: int}
     */
    public function refreshDue(): array
    {
        $due = MetaAccount::where('provider', 'threads')
            ->where('token_expires_at', '>', now())
            ->where('token_expires_at', '<=', now()->addDays(self::REFRESH_DAYS_LEFT))
            ->get();

        $refreshed = $failed = 0;
        foreach ($due as $account) {
            try {
                $res = Http::acceptJson()->timeout(30)->get($this->graph->root('refresh_access_token'), [
                    'grant_type' => 'th_refresh_token',
                    'access_token' => $account->access_token,
                ]);
                $this->graph->check($res, 'perpanjang token');
                $account->update([
                    'access_token' => $res->json('access_token') ?? throw new RuntimeException('Threads tidak mengirim token baru.'),
                    'token_expires_at' => now()->addSeconds((int) ($res->json('expires_in') ?? 60 * 86400)),
                ]);
                $refreshed++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('clipper_jobs')->warning('Threads token refresh failed', ['meta_account_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }

        return ['refreshed' => $refreshed, 'failed' => $failed];
    }
}
