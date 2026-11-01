<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls to the Facebook Graph API with the app's credentials, shared by
 * Facebook Pages and Instagram (same graph.facebook.com, Page token). Errors
 * come back as RuntimeException with an Indonesian message; an expired or
 * revoked token says "Hubungkan ulang", which the UI turns into a reconnect link.
 */
class MetaGraph
{
    public const HOST = 'https://graph.facebook.com';

    // Graph error codes: https://developers.facebook.com/docs/graph-api/guides/error-handling
    private const TOKEN_ERRORS = [190, 102, 463, 467];

    private const RATE_LIMITS = [4, 17, 32, 613, 80001, 80002];

    public function isConfigured(): bool
    {
        return (bool) (config('services.facebook.app_id')
            && config('services.facebook.app_secret')
            && config('services.facebook.redirect_uri'));
    }

    public function version(): string
    {
        return (string) config('services.facebook.graph_version', 'v26.0');
    }

    public function url(string $path): string
    {
        return self::HOST.'/'.$this->version().'/'.ltrim($path, '/');
    }

    /** $service names the platform in error messages: "Instagram buat container gagal (400): …" */
    public function get(string $path, string $token, array $query = [], string $what = 'request', string $service = 'Facebook'): array
    {
        return $this->send('get', $path, $token, $query, $what, $service);
    }

    public function post(string $path, string $token, array $params = [], string $what = 'request', string $service = 'Facebook'): array
    {
        return $this->send('post', $path, $token, $params, $what, $service);
    }

    /** OAuth token endpoint: code exchange and the long-lived token swap. */
    public function token(array $params): array
    {
        $res = Http::timeout(30)->get($this->url('oauth/access_token'), $params + [
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
        ]);
        $this->check($res, 'token');

        return $res->json() ?? [];
    }

    /**
     * appsecret_proof proves the call comes from the app's server; Meta requires
     * it once "Require App Secret" is on in the app's advanced settings.
     */
    public function proof(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('services.facebook.app_secret'));
    }

    /** Throw the Indonesian reason when Meta answered with an error. */
    public function check(Response $res, string $what, string $service = 'Facebook'): void
    {
        $error = $res->json('error');
        if ($res->successful() && ! $error) {
            return;
        }
        $error = is_array($error) ? $error : [];
        $code = (int) ($error['code'] ?? 0);
        $detail = $error['error_user_msg'] ?? $error['message'] ?? ($res->json('debug_info.message') ?? $res->body());

        $message = match (true) {
            in_array($code, self::TOKEN_ERRORS, true) => 'Akses Facebook kedaluwarsa atau dicabut. Hubungkan ulang Facebook di halaman Akun Meta.',
            $code === 10 || ($code >= 200 && $code < 300) => "Izin Facebook kurang ({$detail}). Hubungkan ulang Facebook dan centang semua izin.",
            in_array($code, self::RATE_LIMITS, true) => 'Batas panggilan API Facebook tercapai. Coba lagi nanti.',
            $code === 368 => "Facebook memblokir sementara aksi ini: {$detail}",
            // Instagram's daily publishing cap (subcode 2207042)
            $code === 9 => "Batas posting {$service} lewat API untuk 24 jam terakhir sudah tercapai. Coba lagi besok.",
            default => $detail,
        };

        throw new RuntimeException("{$service} {$what} gagal ({$res->status()}): {$message}");
    }

    private function send(string $method, string $path, string $token, array $data, string $what, string $service): array
    {
        $data += ['access_token' => $token, 'appsecret_proof' => $this->proof($token)];
        $res = $method === 'get'
            ? $this->http()->get($this->url($path), $data)
            : $this->http()->asForm()->post($this->url($path), $data);
        $this->check($res, $what, $service);

        return $res->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }
}
