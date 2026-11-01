<?php

namespace App\Services\Social;

use App\Jobs\UploadToInstagramJob;
use App\Models\GeneratedClip;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Instagram Reels through "Instagram API with Instagram Login": OAuth for a
 * professional (Business or Creator) account, then the content publishing
 * flow: create a REELS container, upload the file to it (resumable, so the
 * server needs no public URL), wait until Instagram finished processing it,
 * publish the container.
 *
 * Calls that are safe to repeat (token, status, container, upload) are retried
 * with growing pauses on 429, 5xx and connection errors. media_publish never
 * is: a retry after an unclear failure could post the Reel twice.
 */
class InstagramPublisher implements SocialPublisher
{
    public const PLATFORM = 'instagram';

    public const SCOPES = 'instagram_business_basic,instagram_business_content_publish';

    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    private const SHORT_TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    private const GRAPH = 'https://graph.instagram.com';

    private const VERSION = 'v21.0';

    /** Pauses (ms) before the 2nd, 3rd and 4th try of a repeatable call. */
    public const RETRY_PAUSES_MS = [1000, 3000, 9000];

    /** Refresh a long-lived token once it has this long left. */
    private const REFRESH_WITHIN_DAYS = 7;

    public function platform(): string
    {
        return self::PLATFORM;
    }

    public function label(): string
    {
        return 'Instagram Reels';
    }

    public function isConfigured(): bool
    {
        return (bool) (config('services.instagram.app_id')
            && config('services.instagram.app_secret')
            && config('services.instagram.redirect_uri'));
    }

    public function connectUrl(): string
    {
        return route('instagram.connect');
    }

    public function manageUrl(): ?string
    {
        return null;
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.instagram.app_id'),
            'redirect_uri' => config('services.instagram.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    /** Exchange the OAuth code for a long-lived token and store the account. */
    public function callback(int $userId, string $code): ConnectedAccount
    {
        $short = Http::asForm()->timeout(30)->post(self::SHORT_TOKEN_URL, [
            'client_id' => config('services.instagram.app_id'),
            'client_secret' => config('services.instagram.app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.instagram.redirect_uri'),
            'code' => $code,
        ]);
        $json = $short->json() ?? [];
        $row = $json['data'][0] ?? $json; // newer API versions wrap the answer in data[]
        if (! $short->successful() || empty($row['access_token'])) {
            $reason = $json['error_message'] ?? $json['error']['message'] ?? $short->body();
            throw new RuntimeException("Instagram menolak kode otorisasi ({$short->status()}): {$reason}");
        }
        $permissions = (string) ($row['permissions'] ?? '');
        if ($permissions !== '' && ! str_contains($permissions, 'instagram_business_content_publish')) {
            throw new RuntimeException('Izin instagram_business_content_publish belum diberikan. Hubungkan ulang dan setujui semua izin.');
        }

        $long = $this->unwrap($this->repeatable()->get(self::GRAPH.'/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => config('services.instagram.app_secret'),
            'access_token' => $row['access_token'],
        ]), 'access_token');
        $token = (string) ($long['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Instagram tidak mengirim token jangka panjang.');
        }

        $me = $this->unwrap($this->repeatable($token)->get(self::GRAPH.'/'.self::VERSION.'/me', [
            'fields' => 'user_id,username,profile_picture_url',
        ]), 'me');
        $externalId = (string) ($me['user_id'] ?? $me['id'] ?? $row['user_id'] ?? '');
        if ($externalId === '') {
            throw new RuntimeException('Instagram tidak mengembalikan ID akun. Pastikan ini akun Business atau Creator.');
        }

        $account = SocialAccount::updateOrCreate(
            ['user_id' => $userId, 'platform' => self::PLATFORM, 'external_id' => $externalId],
            [
                'display_name' => isset($me['username']) ? '@'.$me['username'] : null,
                'avatar_url' => $me['profile_picture_url'] ?? null,
                'access_token' => $token,
                'access_expires_at' => now()->addSeconds((int) ($long['expires_in'] ?? 5_184_000)),
                'scope' => $permissions !== '' ? $permissions : self::SCOPES,
            ]
        );

        return $this->summary($account);
    }

    public function accounts(int $userId): Collection
    {
        return SocialAccount::where('user_id', $userId)->where('platform', self::PLATFORM)
            ->orderBy('display_name')->get()->map(fn (SocialAccount $a) => $this->summary($a));
    }

    public function disconnect(int $userId, int $accountId): void
    {
        $this->ownedAccount($userId, $accountId)->delete();
    }

    /**
     * @param  array{caption?: string, share_to_feed?: bool}  $options
     */
    public function publish(GeneratedClip $clip, int $accountId, string $mode = 'default', array $options = []): void
    {
        $userId = (int) $clip->clipProject->user_id;
        if ($clip->status !== 'done' || ! $clip->output_path) {
            throw new PublishRefused('Clip belum selesai dirender.');
        }
        $account = SocialAccount::where('user_id', $userId)->where('platform', self::PLATFORM)->find($accountId)
            ?? throw new PublishRefused('Akun Instagram tidak ditemukan. Hubungkan ulang di Akun terhubung.');

        $clip->update([
            'instagram_account_id' => $account->id,
            'instagram_media_id' => null,
            'instagram_url' => null,
            'instagram_status' => 'queued',
            'instagram_error' => null,
        ]);
        UploadToInstagramJob::dispatch($clip->id, $account->id, [
            'caption' => mb_substr(trim((string) ($options['caption'] ?? '')), 0, 2200),
            'share_to_feed' => (bool) ($options['share_to_feed'] ?? true),
        ]);
    }

    public function status(GeneratedClip $clip): PublishStatus
    {
        return new PublishStatus($clip->instagram_status, $clip->instagram_error, $clip->instagram_url);
    }

    /** Access tokens live 60 days; a still-valid one is renewed in its last week. */
    public function freshToken(SocialAccount $account): string
    {
        $expires = $account->access_expires_at;
        if (! $expires || $expires->isAfter(now()->addDays(self::REFRESH_WITHIN_DAYS))) {
            return $account->access_token;
        }
        if ($expires->isPast()) {
            throw new RuntimeException('Sesi Instagram kedaluwarsa. Hubungkan ulang akun Instagram di Akun terhubung.');
        }

        $data = $this->unwrap($this->repeatable()->get(self::GRAPH.'/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]), 'refresh_access_token');
        $account->update([
            'access_token' => $data['access_token'] ?? $account->access_token,
            'access_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 5_184_000)),
        ]);

        return $account->access_token;
    }

    /**
     * Posts left in Instagram's rolling 24 h publishing allowance, or null when
     * it cannot be read (the publish itself will then say so).
     */
    public function remainingQuota(SocialAccount $account): ?int
    {
        try {
            $data = $this->unwrap($this->repeatable($this->freshToken($account))->get($this->node($account->external_id, 'content_publishing_limit'), [
                'fields' => 'quota_usage,config',
            ]), 'content_publishing_limit');
            $row = $data['data'][0] ?? null;
            if (! $row || ! isset($row['config']['quota_total'])) {
                return null;
            }

            return max(0, (int) $row['config']['quota_total'] - (int) ($row['quota_usage'] ?? 0));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{id: string, uri: string} container id and the URL the file is uploaded to */
    public function createContainer(SocialAccount $account, string $caption, bool $shareToFeed): array
    {
        $data = $this->unwrap($this->repeatable($this->freshToken($account))->asForm()->post($this->node($account->external_id, 'media'), [
            'media_type' => 'REELS',
            'upload_type' => 'resumable',
            'caption' => $caption,
            'share_to_feed' => $shareToFeed ? 'true' : 'false',
        ]), 'media');
        if (empty($data['id']) || empty($data['uri'])) {
            throw new RuntimeException('Instagram tidak mengembalikan container atau alamat upload.');
        }

        return ['id' => (string) $data['id'], 'uri' => (string) $data['uri']];
    }

    public function uploadVideo(SocialAccount $account, string $uri, string $path): void
    {
        $size = filesize($path);
        if (! $size) {
            throw new RuntimeException("Video tidak ditemukan / kosong: {$path}");
        }

        $res = Http::timeout(600)
            ->retry(self::RETRY_PAUSES_MS, when: fn (Throwable $e) => $this->transient($e), throw: false)
            ->withHeaders([
                'Authorization' => 'OAuth '.$this->freshToken($account),
                'offset' => '0',
                'file_size' => (string) $size,
            ])
            ->withBody(file_get_contents($path), 'application/octet-stream')
            ->post($uri);

        $json = $res->json() ?? [];
        if (! $res->successful() || ($json['success'] ?? true) === false) {
            throw new RuntimeException("Upload ke Instagram gagal ({$res->status()}): ".$this->errorText($res));
        }
    }

    /** @return array{code: string, detail: ?string} code: IN_PROGRESS, FINISHED, ERROR, EXPIRED or PUBLISHED */
    public function containerStatus(SocialAccount $account, string $containerId): array
    {
        $data = $this->unwrap($this->repeatable($this->freshToken($account))->get($this->node($containerId), [
            'fields' => 'status_code,status',
        ]), 'container status');

        return ['code' => (string) ($data['status_code'] ?? 'IN_PROGRESS'), 'detail' => $data['status'] ?? null];
    }

    /**
     * Never retried: if the answer is lost the Reel may already be live.
     *
     * @return string media id
     */
    public function publishContainer(SocialAccount $account, string $containerId): string
    {
        $res = Http::withToken($this->freshToken($account))->timeout(60)->asForm()
            ->post($this->node($account->external_id, 'media_publish'), ['creation_id' => $containerId]);
        $data = $this->unwrap($res, 'media_publish');
        if (empty($data['id'])) {
            throw new RuntimeException('Instagram tidak mengembalikan ID media setelah publish.');
        }

        return (string) $data['id'];
    }

    /** The public link of a published Reel, or null when Instagram does not give it yet. */
    public function permalink(SocialAccount $account, string $mediaId): ?string
    {
        try {
            $data = $this->unwrap($this->repeatable($this->freshToken($account))->get($this->node($mediaId), ['fields' => 'permalink']), 'permalink');

            return $data['permalink'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The message to show a user: a network failure is not worth quoting cURL for. */
    public static function friendlyError(Throwable $e): string
    {
        return $e instanceof ConnectionException
            ? 'Tidak bisa terhubung ke Instagram. Coba lagi beberapa menit lagi.'
            : $e->getMessage();
    }

    private function node(string $id, string $edge = ''): string
    {
        return self::GRAPH.'/'.self::VERSION.'/'.$id.($edge !== '' ? '/'.$edge : '');
    }

    /** A client that retries what is safe to repeat; no token for the calls that carry it in the query. */
    private function repeatable(?string $token = null): PendingRequest
    {
        $request = Http::timeout(60)->retry(self::RETRY_PAUSES_MS, when: fn (Throwable $e) => $this->transient($e), throw: false);

        return $token ? $request->withToken($token) : $request;
    }

    private function transient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true);
    }

    private function unwrap(Response $res, string $what): array
    {
        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            throw new RuntimeException("Instagram {$what} gagal ({$res->status()}): ".$this->errorText($res));
        }

        return $json;
    }

    private function errorText(Response $res): string
    {
        $error = $res->json('error') ?? [];

        return trim((string) ($error['error_user_msg'] ?? $error['message'] ?? mb_substr($res->body(), 0, 300)));
    }

    private function ownedAccount(int $userId, int $accountId): SocialAccount
    {
        return SocialAccount::where('user_id', $userId)->where('platform', self::PLATFORM)->findOrFail($accountId);
    }

    private function summary(SocialAccount $a): ConnectedAccount
    {
        return new ConnectedAccount($a->id, self::PLATFORM, $a->display_name ?: 'Akun #'.$a->id, $a->avatar_url);
    }
}
