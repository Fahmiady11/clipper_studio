<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls to the Threads API (graph.threads.com) with the Threads app id and
 * secret. Same error style as MetaGraph: an expired or revoked token says
 * "Hubungkan ulang Threads", which the UI turns into a reconnect link.
 */
class ThreadsGraph
{
    private const TOKEN_ERRORS = [190, 102, 463, 467];

    private const RATE_LIMITS = [4, 17, 32, 613];

    public function isConfigured(): bool
    {
        return (bool) (config('services.threads.app_id')
            && config('services.threads.app_secret')
            && config('services.threads.redirect_uri'));
    }

    /** Versioned API path: /v1.0/me, /v1.0/{user-id}/threads */
    public function url(string $path): string
    {
        return $this->root(config('services.threads.graph_version', 'v1.0').'/'.ltrim($path, '/'));
    }

    /** Token endpoints sit outside the version: /oauth/access_token, /access_token, /refresh_access_token */
    public function root(string $path): string
    {
        return rtrim((string) config('services.threads.graph_url', 'https://graph.threads.com'), '/').'/'.ltrim($path, '/');
    }

    public function get(string $path, string $token, array $query = [], string $what = 'request'): array
    {
        $res = Http::acceptJson()->timeout(30)->get($this->url($path), $query + ['access_token' => $token]);
        $this->check($res, $what);

        return $res->json() ?? [];
    }

    public function post(string $path, string $token, array $params = [], string $what = 'request'): array
    {
        $res = Http::acceptJson()->asForm()->timeout(30)->post($this->url($path), $params + ['access_token' => $token]);
        $this->check($res, $what);

        return $res->json() ?? [];
    }

    public function check(Response $res, string $what): void
    {
        $error = $res->json('error');
        if ($res->successful() && ! $error) {
            return;
        }
        $error = is_array($error) ? $error : [];
        $code = (int) ($error['code'] ?? 0);
        $detail = $error['error_user_msg'] ?? $error['message'] ?? $res->body();

        $message = match (true) {
            in_array($code, self::TOKEN_ERRORS, true) => 'Akses Threads kedaluwarsa atau dicabut. Hubungkan ulang Threads di halaman Akun Meta.',
            $code === 10 || ($code >= 200 && $code < 300) => "Izin Threads kurang ({$detail}). Hubungkan ulang Threads dan centang semua izin.",
            in_array($code, self::RATE_LIMITS, true) => 'Batas panggilan API Threads tercapai. Coba lagi nanti.',
            default => $detail,
        };

        throw new RuntimeException("Threads {$what} gagal ({$res->status()}): {$message}");
    }
}
