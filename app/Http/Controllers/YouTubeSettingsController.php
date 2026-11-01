<?php

namespace App\Http\Controllers;

use App\Models\YoutubeAccount;
use App\Services\YouTubeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Why a connected channel is required before any YouTube link is accepted:
 * the service only processes videos the user owns.
 */
class YouTubeSettingsController extends Controller
{
    public function show(YouTubeService $youtube): View
    {
        return view('settings.youtube', [
            'configured' => $youtube->isConfigured(),
            'accounts' => YoutubeAccount::where('user_id', Auth::id())->orderBy('title')->get(),
        ]);
    }

    public function disconnect(int $accountId): RedirectResponse
    {
        YoutubeAccount::where('user_id', Auth::id())->findOrFail($accountId)->delete();

        return redirect()->route('youtube.settings')->with('status', 'Channel diputuskan. Link video dari channel itu sekarang ditolak.');
    }
}
