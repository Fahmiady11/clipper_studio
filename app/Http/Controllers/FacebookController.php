<?php

namespace App\Http\Controllers;

use App\Services\MetaLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Facebook Login for Business. After the callback the user picks Pages and Instagram accounts on Settings → Akun terhubung. */
class FacebookController extends Controller
{
    public function __construct(private MetaLogin $login) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->login->isConfigured()) {
            return redirect('/settings/accounts?facebook=not_configured');
        }

        $state = Str::random(40);
        $request->session()->put('facebook_oauth_state', $state);

        return redirect()->away($this->login->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('facebook_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/settings/accounts?facebook=invalid_state');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect('/settings/accounts?facebook=denied');
        }

        try {
            $account = $this->login->connect(Auth::id(), (string) $request->query('code'));
        } catch (Throwable $e) {
            Log::channel('clipper_api')->error('Facebook connect failed', ['error' => $e->getMessage()]);

            return redirect('/settings/accounts?facebook=error');
        }

        return redirect('/settings/accounts?facebook=connected&account='.$account->id);
    }
}
