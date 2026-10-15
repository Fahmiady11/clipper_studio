<?php

namespace App\Services;

use App\Models\TiktokAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok Login Kit (OAuth v2) + Content Posting API. Two flows:
 * - inbox (scope video.upload): video lands in the creator's TikTok inbox as a
 *   draft; they finish posting in the app (can add a trending sound there).
 * - direct post (scope video.publish): posted straight to the profile.
 */
class TikTokService
{
    private const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const API = 'https://open.tiktokapis.com/v2';

    // Chunk rules: each chunk 5–64 MB, the last one absorbs the remainder.
    private const MAX_SINGLE_CHUNK = 64 * 1024 * 1024;

    private const CHUNK_SIZE = 10 * 1024 * 1024;

    public function isConfigured(): bool
    {
        return (bool) (config('services.tiktok.client_key')
            && config('services.tiktok.client_secret')
            && config('services.tiktok.redirect_uri'));
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => config('services.tiktok.scopes'),
            'response_type' => 'code',
            'redirect_uri' => config('services.tiktok.redirect_uri'),
            'state' => $state,
        ]);
    }

    /** Exchange the OAuth code and store/refresh the connected account. */
    public function connect(int $userId, string $code): TiktokAccount
    {
        $token = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.tiktok.redirect_uri'),
        ]);

        $info = $this->userInfo($token['access_token']);

        return TiktokAccount::updateOrCreate(
            ['user_id' => $userId, 'open_id' => $token['open_id']],
            $this->tokenAttributes($token) + [
                'display_name' => $info['display_name'] ?? null,
                'avatar_url' => $info['avatar_url'] ?? null,
            ]
        );
    }

    /** Access tokens live ~24h; refresh a few minutes before expiry. */
    public function freshToken(TiktokAccount $account): string
    {
        if ($account->access_expires_at->isAfter(now()->addMinutes(5))) {
            return $account->access_token;
        }

        if ($account->refresh_expires_at && $account->refresh_expires_at->isPast()) {
            throw new RuntimeException('Sesi TikTok kedaluwarsa — hubungkan ulang akun TikTok.');
        }

        $token = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);
        $account->update($this->tokenAttributes($token));

        return $account->access_token;
    }

    /**
     * Upload to the creator's inbox as a draft (scope video.upload).
     *
     * @return string publish_id
     */
    public function uploadToInbox(TiktokAccount $account, string $path): string
    {
        return $this->initAndUpload($account, 'post/publish/inbox/video/init/', [], $path);
    }

    /**
     * Post straight to the profile (scope video.publish). Until the app passes
     * TikTok's audit only privacy_level SELF_ONLY is accepted.
     *
     * @param  array{title: string, privacy_level: string, disable_comment: bool, disable_duet: bool, disable_stitch: bool, brand_organic_toggle?: bool, brand_content_toggle?: bool}  $postInfo
     * @return string publish_id
     */
    public function directPost(TiktokAccount $account, string $path, array $postInfo): string
    {
        return $this->initAndUpload($account, 'post/publish/video/init/', ['post_info' => $postInfo], $path);
    }

    /**
     * Creator settings TikTok requires the post form to honour: privacy
     * options, which interactions are switched off, max video length.
     */
    public function creatorInfo(TiktokAccount $account): array
    {
        return $this->api($this->freshToken($account), 'post/publish/creator_info/query/', []);
    }

    private function initAndUpload(TiktokAccount $account, string $endpoint, array $body, string $path): string
    {
        $size = filesize($path);
        if (! $size) {
            throw new RuntimeException("Video tidak ditemukan / kosong: {$path}");
        }

        [$chunkSize, $chunkCount] = self::chunkPlan($size);

        $data = $this->api($this->freshToken($account), $endpoint, $body + [
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $size,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $chunkCount,
            ],
        ]);

        $publishId = $data['publish_id'] ?? null;
        $uploadUrl = $data['upload_url'] ?? null;
        if (! $publishId || ! $uploadUrl) {
            throw new RuntimeException('TikTok init tidak mengembalikan publish_id/upload_url');
        }

        $fh = fopen($path, 'rb');
        try {
            for ($i = 0; $i < $chunkCount; $i++) {
                $start = $i * $chunkSize;
                $end = $i === $chunkCount - 1 ? $size - 1 : $start + $chunkSize - 1;
                fseek($fh, $start);
                $bytes = fread($fh, $end - $start + 1);

                $res = Http::timeout(300)
                    ->withHeaders([
                        'Content-Type' => 'video/mp4',
                        'Content-Range' => "bytes {$start}-{$end}/{$size}",
                    ])
                    ->withBody($bytes, 'video/mp4')
                    ->put($uploadUrl);

                if (! $res->successful()) {
                    throw new RuntimeException("TikTok upload chunk {$i} gagal ({$res->status()}): ".$res->body());
                }
            }
        } finally {
            fclose($fh);
        }

        return $publishId;
    }

    /** @return array{status: string, fail_reason: ?string} */
    public function publishStatus(TiktokAccount $account, string $publishId): array
    {
        $data = $this->api($this->freshToken($account), 'post/publish/status/fetch/', [
            'publish_id' => $publishId,
        ]);

        return [
            'status' => (string) ($data['status'] ?? 'UNKNOWN'),
            'fail_reason' => $data['fail_reason'] ?? null,
        ];
    }

    /**
     * Files up to 64 MB go as one chunk; larger ones in 10 MB chunks with the
     * remainder folded into the last chunk.
     *
     * @return array{0: int, 1: int} [chunk_size, total_chunk_count]
     */
    public static function chunkPlan(int $size): array
    {
        if ($size <= self::MAX_SINGLE_CHUNK) {
            return [$size, 1];
        }

        return [self::CHUNK_SIZE, intdiv($size, self::CHUNK_SIZE)];
    }

    private function userInfo(string $accessToken): array
    {
        $res = Http::withToken($accessToken)
            ->get(self::API.'/user/info/', ['fields' => 'open_id,display_name,avatar_url']);

        return $this->unwrap($res, 'user/info')['user'] ?? [];
    }

    private function api(string $accessToken, string $endpoint, array $body): array
    {
        // json_encode([]) is "[]"; TikTok expects an object even when empty
        $res = Http::withToken($accessToken)
            ->timeout(60)
            ->withBody($body ? json_encode($body) : '{}', 'application/json')
            ->post(self::API.'/'.$endpoint);

        return $this->unwrap($res, $endpoint);
    }

    /** Content API responses carry {data, error: {code, message}}; code "ok" means success. */
    private function unwrap(Response $res, string $endpoint): array
    {
        $json = $res->json() ?? [];
        $error = $json['error'] ?? [];
        if (! $res->successful() || (($error['code'] ?? 'ok') !== 'ok')) {
            $msg = $error['message'] ?? $res->body();
            throw new RuntimeException("TikTok {$endpoint} gagal ({$res->status()}): ".($error['code'] ?? '').' '.$msg);
        }

        return $json['data'] ?? [];
    }

    private function tokenRequest(array $params): array
    {
        $res = Http::asForm()->post(self::API.'/oauth/token/', $params + [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
        ]);

        $json = $res->json() ?? [];
        if (! $res->successful() || empty($json['access_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? $res->body();
            throw new RuntimeException("TikTok token gagal ({$res->status()}): {$msg}");
        }

        return $json;
    }

    private function tokenAttributes(array $token): array
    {
        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'access_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
            'refresh_expires_at' => isset($token['refresh_expires_in'])
                ? now()->addSeconds((int) $token['refresh_expires_in'])
                : null,
            'scope' => $token['scope'] ?? null,
        ];
    }
}
