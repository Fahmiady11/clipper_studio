<?php

namespace App\Http\Controllers;

use App\Models\MetaAccount;
use App\Models\MetaTarget;
use App\Services\MetaLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/** Connected Meta logins and the Pages (later Instagram / Threads profiles) under them. */
class MetaAccountController extends Controller
{
    public function __construct(private MetaLogin $login) {}

    public function page(): View
    {
        return view('settings.meta');
    }

    public function index(): JsonResponse
    {
        $accounts = MetaAccount::with(['targets' => fn ($q) => $q->orderBy('platform')->orderBy('name')])
            ->where('user_id', Auth::id())
            ->orderBy('name')
            ->get();

        return response()->json([
            'configured' => $this->login->isConfigured(),
            'accounts' => $accounts->map(fn (MetaAccount $a) => [
                'id' => $a->id,
                'provider' => $a->provider,
                'name' => $a->name,
                'token_expires_at' => $a->token_expires_at?->toISOString(),
                'missing_scopes' => $a->provider === 'facebook' ? $this->login->missingScopes($a) : [],
                'targets' => $a->targets->map(fn (MetaTarget $t) => [
                    'id' => $t->id,
                    'platform' => $t->platform,
                    'name' => $t->name,
                    'username' => $t->username,
                    'picture_url' => $t->picture_url,
                ])->values(),
            ])->values(),
        ]);
    }

    /** The Pages this login can see on Facebook right now. */
    public function pages(int $accountId): JsonResponse
    {
        $account = $this->ownedAccount($accountId);
        try {
            return response()->json(['pages' => $this->login->pages($account)]);
        } catch (Throwable $e) {
            Log::channel('clipper_api')->warning('Facebook pages failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function savePages(Request $request, int $accountId): JsonResponse
    {
        $data = $request->validate([
            'page_ids' => ['present', 'array', 'max:100'],
            'page_ids.*' => ['string', 'regex:/^\d+$/'],
        ]);
        $account = $this->ownedAccount($accountId);
        try {
            $count = $this->login->savePages($account, array_values(array_unique($data['page_ids'])));
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['connected' => $count]);
    }

    /** Facebook Pages to publish to, shaped like /api/youtube/accounts for the calendar. */
    public function facebookPages(): JsonResponse
    {
        return response()->json([
            'configured' => $this->login->isConfigured(),
            'accounts' => MetaTarget::ownedBy(Auth::id(), 'facebook')->orderBy('name')->get(['id', 'name', 'picture_url']),
        ]);
    }

    public function destroyTarget(int $targetId): JsonResponse
    {
        MetaTarget::where('user_id', Auth::id())->findOrFail($targetId)->delete();

        return response()->json(['success' => true]);
    }

    /** The login and every Page under it. Meta keeps the app grant until the user removes it on Facebook. */
    public function destroyAccount(int $accountId): JsonResponse
    {
        $this->ownedAccount($accountId)->delete();

        return response()->json(['success' => true]);
    }

    private function ownedAccount(int $accountId): MetaAccount
    {
        return MetaAccount::where('user_id', Auth::id())->findOrFail($accountId);
    }
}
