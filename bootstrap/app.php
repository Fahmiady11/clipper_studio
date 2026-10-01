<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a tunnel/proxy (ngrok, cloudflared) the app sees plain http;
        // trusting X-Forwarded-* makes it generate https URLs (no mixed-content CSS).
        // Only the local tunnel agent is trusted: it connects from loopback. Trusting
        // '*' let any client fake its IP (rate limits) and scheme via headers.
        // (Middleware config runs before .env is loaded, so no env() here.)
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Allow API routes to read the web session so Auth::user() works
        // for browser-initiated fetch() calls (SPA pattern without Sanctum).
        $middleware->group('api', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // Session-cookie auth means API calls need the same CSRF check as forms
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419 = the form's CSRF token died with the session (tab left open too
        // long, or logged out in another tab). Instead of Laravel's bare
        // "Page Expired" screen, send people somewhere that works.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 419);
            }
            if ($request->is('logout')) {
                return redirect()->route('login')->with('status', 'Kamu sudah keluar.');
            }

            return redirect()->back(fallback: route('login'))
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('status', 'Sesi kedaluwarsa. Silakan coba lagi.');
        });
    })->create();
