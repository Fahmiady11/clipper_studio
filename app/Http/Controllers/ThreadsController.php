<?php

namespace App\Http\Controllers;

use App\Services\ThreadsLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Threads login (separate from Facebook). One login is one Threads profile; no picking needed. */
class ThreadsController extends Controller
{
    public function __construct(private ThreadsLogin $login) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->login->isConfigured()) {
            return redirect('/settings/meta?threads=not_configured');
        }

        $state = Str::random(40);
        $request->session()->put('threads_oauth_state', $state);

        return redirect()->away($this->login->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('threads_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/settings/meta?threads=invalid_state');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect('/settings/meta?threads=denied');
        }

        try {
            // Meta appends #_ to the code; a browser keeps the fragment, a pasted URL may not
            $this->login->connect(Auth::id(), preg_replace('/#_$/', '', (string) $request->query('code')));
        } catch (Throwable $e) {
            Log::channel('clipper_api')->error('Threads connect failed', ['error' => $e->getMessage()]);

            return redirect('/settings/meta?threads=error');
        }

        return redirect('/settings/meta?threads=connected');
    }
}
