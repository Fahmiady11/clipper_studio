<?php

namespace App\Services;

use App\Models\YoutubeAccount;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google OAuth 2.0 + YouTube Data API v3 uploads. A vertical video of up to
 * 3 minutes becomes a Short by itself; nothing in the API marks it as one.
 *
 * Until the Google Cloud project passes YouTube's API audit, every upload is
 * locked to private, whatever privacy is asked for.
 */
class YouTubeService
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    // upload: post videos; readonly: the channel name shown in the picker
    public const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    private const CATEGORY_PEOPLE_BLOGS = '22';

    public function isConfigured(): bool
    {
        return (bool) (config('services.youtube.client_id')
            && config('services.youtube.client_secret')
            && config('services.youtube.redirect_uri'));
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline', // a refresh token, so uploads work after the first hour
            'prompt' => 'consent',      // Google only sends the refresh token on a consent screen
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Exchange the OAuth code and store/refresh the connected channel. */
    public function connect(int $userId, string $code): YoutubeAccount
    {
        $token = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.youtube.redirect_uri'),
        ]);
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google tidak mengirim refresh token. Cabut akses app di myaccount.google.com/permissions lalu hubungkan ulang.');
        }

        $channel = $this->api($token['access_token'], 'get', 'channels', ['part' => 'snippet', 'mine' => 'true'])['items'][0] ?? null;
        if (! $channel) {
            throw new RuntimeException('Akun Google ini belum punya channel YouTube. Buat channel dulu di youtube.com.');
        }

        return YoutubeAccount::updateOrCreate(
            ['user_id' => $userId, 'channel_id' => $channel['id']],
            $this->tokenAttributes($token) + [
                'refresh_token' => $token['refresh_token'],
                'title' => $channel['snippet']['title'] ?? null,
                'thumbnail_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
            ]
        );
    }

    /** Access tokens live an hour; refresh a few minutes before expiry. */
    public function freshToken(YoutubeAccount $account): string
    {
        if ($account->access_expires_at->isAfter(now()->addMinutes(5))) {
            return $account->access_token;
        }

        $account->update($this->tokenAttributes($this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ])));

        return $account->access_token;
    }

    /**
     * Resumable upload: one request with the metadata returns an upload URL,
     * the file goes to it in one PUT (clips are well under the 256 GB limit).
     *
     * @param  array{title: string, description: string, privacy: string, tags?: list<string>}  $meta
     * @return string video id
     */
    public function upload(YoutubeAccount $account, string $path, array $meta): string
    {
        $size = filesize($path);
        if (! $size) {
            throw new RuntimeException("Video tidak ditemukan / kosong: {$path}");
        }
        $token = $this->freshToken($account);

        $init = Http::withToken($token)
            ->timeout(60)
            ->withHeaders(['X-Upload-Content-Length' => (string) $size, 'X-Upload-Content-Type' => 'video/mp4'])
            ->withBody(json_encode(self::videoResource($meta)), 'application/json; charset=UTF-8')
            ->post(self::UPLOAD_URL.'?'.http_build_query(['uploadType' => 'resumable', 'part' => 'snippet,status']));
        $this->check($init, 'upload init');
        $uploadUrl = $init->header('Location');
        if (! $uploadUrl) {
            throw new RuntimeException('YouTube tidak mengembalikan URL upload.');
        }

        // Streamed from disk, not read into memory; the stream closes the file when it goes
        $res = Http::withToken($token)
            ->timeout(900)
            ->withBody(Utils::streamFor(fopen($path, 'rb')), 'video/mp4')
            ->put($uploadUrl);
        $this->check($res, 'upload');

        return (string) ($res->json('id') ?: throw new RuntimeException('YouTube tidak mengembalikan id video.'));
    }

    /**
     * Where YouTube is with an uploaded video.
     *
     * @return array{status: string, privacy: ?string, reason: ?string} status: processing | published | failed
     */
    public function videoStatus(YoutubeAccount $account, string $videoId): array
    {
        $item = $this->api($this->freshToken($account), 'get', 'videos', ['part' => 'status', 'id' => $videoId])['items'][0] ?? null;
        if (! $item) {
            return ['status' => 'failed', 'privacy' => null, 'reason' => 'Video tidak ditemukan di YouTube (mungkin dihapus).'];
        }
        $s = $item['status'] ?? [];

        return match ($s['uploadStatus'] ?? '') {
            'processed' => ['status' => 'published', 'privacy' => $s['privacyStatus'] ?? null, 'reason' => null],
            'failed', 'rejected', 'deleted' => ['status' => 'failed', 'privacy' => $s['privacyStatus'] ?? null,
                'reason' => 'YouTube menolak video: '.($s['rejectionReason'] ?? $s['failureReason'] ?? $s['uploadStatus'])],
            default => ['status' => 'processing', 'privacy' => $s['privacyStatus'] ?? null, 'reason' => null],
        };
    }

    /**
     * The videos.insert body. Titles are cut to YouTube's 100 characters and
     * lose < and >, which YouTube refuses; the description gets #Shorts.
     */
    public static function videoResource(array $meta): array
    {
        $clean = fn (string $s) => trim(str_replace(['<', '>'], '', $s));
        $title = mb_substr($clean((string) ($meta['title'] ?? '')), 0, 100) ?: 'Clip';
        $description = $clean((string) ($meta['description'] ?? ''));
        if (! preg_match('/#shorts\b/i', $title.' '.$description)) {
            $description = trim($description."\n\n#Shorts");
        }
        $tags = array_values(array_filter(array_map(fn ($t) => ltrim(trim((string) $t), '#'), $meta['tags'] ?? [])));

        return [
            'snippet' => array_filter([
                'title' => $title,
                'description' => mb_substr($description, 0, 5000),
                'tags' => $tags ?: null,
                'categoryId' => self::CATEGORY_PEOPLE_BLOGS,
            ]),
            'status' => [
                'privacyStatus' => in_array($meta['privacy'] ?? '', ['public', 'unlisted', 'private'], true) ? $meta['privacy'] : 'private',
                'selfDeclaredMadeForKids' => false,
                'embeddable' => true,
            ],
        ];
    }

    private function api(string $token, string $method, string $endpoint, array $query): array
    {
        $res = Http::withToken($token)->timeout(30)->{$method}(self::API.'/'.$endpoint, $query);
        $this->check($res, $endpoint);

        return $res->json() ?? [];
    }

    /** Google errors carry {error: {code, message, errors: [{reason}]}} */
    private function check(Response $res, string $what): void
    {
        if ($res->successful()) {
            return;
        }
        $error = $res->json('error') ?? [];
        $reason = $error['errors'][0]['reason'] ?? $error['status'] ?? '';
        $message = match ($reason) {
            'quotaExceeded' => 'Kuota harian YouTube API habis. Coba lagi besok.',
            'uploadLimitExceeded' => 'Channel ini sudah mencapai batas upload harian YouTube.',
            'youtubeSignupRequired' => 'Akun Google ini belum punya channel YouTube.',
            default => is_array($error) ? ($error['message'] ?? $res->body()) : $res->body(),
        };

        throw new RuntimeException("YouTube {$what} gagal ({$res->status()}): {$message}");
    }

    private function tokenRequest(array $params): array
    {
        $res = Http::asForm()->timeout(30)->post(self::TOKEN_URL, $params + [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
        ]);

        $json = $res->json() ?? [];
        if (! $res->successful() || empty($json['access_token'])) {
            if (($json['error'] ?? '') === 'invalid_grant') {
                throw new RuntimeException('Sesi YouTube kedaluwarsa atau dicabut — hubungkan ulang channel YouTube.');
            }
            $msg = $json['error_description'] ?? $json['error'] ?? $res->body();
            throw new RuntimeException("Google token gagal ({$res->status()}): {$msg}");
        }

        return $json;
    }

    private function tokenAttributes(array $token): array
    {
        return array_filter([
            'access_token' => $token['access_token'],
            'access_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scope' => $token['scope'] ?? null,
        ]);
    }
}
