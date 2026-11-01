<?php

namespace App\Services;

use App\Models\MetaAccount;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\YoutubeAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Takes Clipper's access back at each platform when an account is deleted, so the
 * app leaves the user's "connected apps" lists too. Best effort: a platform that is
 * down or refuses must not keep the account alive, so every failure is only logged.
 *
 * An external account that another Clipper user also connected is left alone:
 * Google and Meta revoke the whole grant, which would cut that user off too.
 * Threads has no revoke endpoint; its token simply expires.
 */
class PlatformRevoker
{
    public const GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const TIKTOK_REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    private const TIMEOUT = 10;

    public function __construct(private TikTokService $tiktok, private MetaGraph $graph) {}

    /** @return array{revoked: int, failed: int, skipped: int} */
    public function revokeAll(User $user): array
    {
        $tally = ['revoked' => 0, 'failed' => 0, 'skipped' => 0];

        foreach (YoutubeAccount::where('user_id', $user->id)->get() as $account) {
            $shared = YoutubeAccount::where('channel_id', $account->channel_id)->where('user_id', '!=', $user->id)->exists();
            $this->attempt($tally, 'youtube', $user, $account->id, $shared, fn () => $this->revokeGoogle($account));
        }

        foreach (TiktokAccount::where('user_id', $user->id)->get() as $account) {
            $shared = TiktokAccount::where('open_id', $account->open_id)->where('user_id', '!=', $user->id)->exists();
            $this->attempt($tally, 'tiktok', $user, $account->id, $shared, fn () => $this->revokeTikTok($account));
        }

        foreach (MetaAccount::where('user_id', $user->id)->where('provider', 'facebook')->get() as $account) {
            $shared = MetaAccount::where('provider', 'facebook')->where('provider_user_id', $account->provider_user_id)
                ->where('user_id', '!=', $user->id)->exists();
            $this->attempt($tally, 'facebook', $user, $account->id, $shared, fn () => $this->revokeFacebook($account));
        }

        return $tally;
    }

    /** Revoking the refresh token ends the whole grant, access tokens included. */
    public function revokeGoogle(YoutubeAccount $account): void
    {
        $token = $account->refresh_token ?: $account->access_token;
        $res = Http::asForm()->timeout(self::TIMEOUT)->post(self::GOOGLE_REVOKE_URL, ['token' => $token]);
        $this->check($res, 'Google');
    }

    /** TikTok revokes by access token, so an expired one is refreshed first when the refresh token still works. */
    public function revokeTikTok(TiktokAccount $account): void
    {
        try {
            $token = $this->tiktok->freshToken($account);
        } catch (Throwable) {
            $token = $account->access_token;
        }

        $res = Http::asForm()->timeout(self::TIMEOUT)->post(self::TIKTOK_REVOKE_URL, [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'token' => $token,
        ]);
        $this->check($res, 'TikTok');
        // Success is an empty body; OAuth errors come as {error, error_description} with HTTP 200 at times
        $error = $res->json('error');
        $code = is_array($error) ? ($error['code'] ?? null) : $error;
        if ($code && $code !== 'ok') {
            throw new RuntimeException("TikTok revoke: {$code} ".$res->json('error_description'));
        }
    }

    /** DELETE /me/permissions removes the app from the user's Business Integrations. */
    public function revokeFacebook(MetaAccount $account): void
    {
        $query = http_build_query([
            'access_token' => $account->access_token,
            'appsecret_proof' => $this->graph->proof($account->access_token),
        ]);
        $res = Http::acceptJson()->timeout(self::TIMEOUT)->delete($this->graph->url('me/permissions').'?'.$query);
        $this->check($res, 'Facebook');
    }

    private function attempt(array &$tally, string $platform, User $user, int $accountId, bool $shared, callable $revoke): void
    {
        $context = ['user_id' => $user->id, 'platform' => $platform, 'account_id' => $accountId];
        if ($shared) {
            $tally['skipped']++;
            Log::channel('clipper_api')->info('Revoke skipped: account also connected by another user', $context);

            return;
        }

        try {
            $revoke();
            $tally['revoked']++;
            Log::channel('clipper_api')->info('Platform access revoked', $context);
        } catch (Throwable $e) {
            $tally['failed']++;
            Log::channel('clipper_api')->warning('Platform revoke failed', $context + ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    private function check(Response $res, string $platform): void
    {
        if (! $res->successful()) {
            throw new RuntimeException("{$platform} revoke ({$res->status()}): ".mb_substr($res->body(), 0, 200));
        }
    }
}
