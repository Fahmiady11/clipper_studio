<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 5 wrong passwords per email+IP, then a cool-down
        $key = 'login:' . Str::lower($credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return back()->withErrors([
                'email' => "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit.",
            ])->onlyInput('email');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();

            // Only return to a page; a video/API URL that bounced to login would open raw
            $intended = $request->session()->pull('url.intended');
            $path     = (string) parse_url((string) $intended, PHP_URL_PATH);

            return redirect()->to($intended && !str_starts_with($path, '/api/') ? $intended : route('home'));
        }

        RateLimiter::hit($key, 60);

        return back()->withErrors([
            'email' => 'Email atau password salah.',
        ])->onlyInput('email');
    }

    // TODO: Google OAuth via Socialite
    // public function redirectToGoogle() { ... }
    // public function handleGoogleCallback() { ... }
}
