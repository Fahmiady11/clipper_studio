<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening for every response: no framing (clickjacking),
 * no MIME sniffing, no full URLs leaked in Referer, no camera/mic/geo access,
 * and HSTS once the site is reached over HTTPS (ngrok/cloudflared).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // frame-ancestors/base-uri/form-action only: the pages use inline scripts and
        // Alpine from a CDN, so a script-src policy would need 'unsafe-*' anyway.
        // A stricter policy set by the controller (e.g. for uploaded files) is kept.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://www.tiktok.com; object-src 'none'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $headers->remove('X-Powered-By');

        return $response;
    }
}
