<?php

namespace App\Services;

use App\Models\GeneratedClip;
use Illuminate\Support\Facades\URL;

/**
 * Expiring links to a rendered clip for platforms that download the video
 * themselves (Instagram Reels, Threads). The link needs no login; the clip's
 * UUID and the signature make it unguessable, the expiry keeps it short-lived.
 * APP_URL has to be reachable from the internet for Meta to fetch it.
 */
class PublicMediaUrl
{
    /**
     * Why Meta could not fetch our links, or null when APP_URL looks public:
     * it has to be HTTPS on a name or address the internet can reach.
     */
    public function problem(): ?string
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $public = parse_url($url, PHP_URL_SCHEME) === 'https' && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! preg_match('/\.(localhost|local|test|internal|lan)$/', $host)
            && (! filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));

        return $public ? null : "APP_URL ({$url}) bukan alamat HTTPS publik, jadi Meta tidak bisa mengambil videonya. Isi APP_URL dengan domain atau tunnel HTTPS.";
    }

    public function clip(GeneratedClip $clip, ?int $minutes = null): string
    {
        $minutes ??= (int) config('services.clipper.public_media_minutes', 120);
        $path = URL::temporarySignedRoute('media.clip', now()->addMinutes($minutes), ['clipId' => $clip->id], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }
}
