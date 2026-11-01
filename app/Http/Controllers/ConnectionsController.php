<?php

namespace App\Http\Controllers;

use App\Services\Social\SocialPublishers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** One page for every platform clips can be published to: what is connected and how to connect more. */
class ConnectionsController extends Controller
{
    public function show(SocialPublishers $publishers): View
    {
        return view('settings.connections', [
            'platforms' => $publishers->all()->map(fn ($p) => [
                'key' => $p->platform(),
                'label' => $p->label(),
                'configured' => $p->isConfigured(),
                'connectUrl' => $p->connectUrl(),
                'manageUrl' => $p->manageUrl(),
                'accounts' => $p->accounts(Auth::id()),
            ]),
        ]);
    }

    public function disconnect(SocialPublishers $publishers, string $platform, int $accountId): RedirectResponse
    {
        $publisher = $publishers->get($platform);
        // Platforms with their own management page (YouTube: it withdraws video verification) are not disconnected here
        abort_if($publisher->manageUrl() !== null, 404);

        $publisher->disconnect(Auth::id(), $accountId);

        return redirect()->route('connections.index')->with('status', "Akun {$publisher->label()} diputuskan.");
    }
}
