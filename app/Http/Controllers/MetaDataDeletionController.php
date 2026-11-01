<?php

namespace App\Http\Controllers;

use App\Models\DataDeletionRequest;
use App\Models\MetaAccount;
use App\Support\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Callbacks Meta calls when someone removes Clipper on Facebook or Threads, or asks
 * for their data to be deleted. Only what came from that platform login goes: the
 * meta_accounts row with its tokens and every Page, Instagram account or Threads
 * profile under it. The Clipper account itself signs in with email and password and
 * holds the user's own projects, so it stays; deleting that is the owner's call in
 * Settings → Akun.
 */
class MetaDataDeletionController extends Controller
{
    /** Facebook Login → Data Deletion Request URL. Answers with the status link and code Meta shows the user. */
    public function facebookDelete(Request $request): JsonResponse
    {
        return $this->deletionRequest($request, 'facebook', config('services.facebook.app_secret'));
    }

    /** Facebook Login → Deauthorize Callback URL. The grant is gone, so the stored tokens are useless. */
    public function facebookDeauthorize(Request $request): JsonResponse
    {
        return $this->deauthorized($request, 'facebook', config('services.facebook.app_secret'));
    }

    /** Threads use case → Delete Callback URL. Signed with the Threads app secret. */
    public function threadsDelete(Request $request): JsonResponse
    {
        return $this->deletionRequest($request, 'threads', config('services.threads.app_secret'));
    }

    /** Threads use case → Uninstall Callback URL. */
    public function threadsUninstall(Request $request): JsonResponse
    {
        return $this->deauthorized($request, 'threads', config('services.threads.app_secret'));
    }

    /** Public: what happened to a request, looked up by the code Meta gave the user. */
    public function status(string $code): View
    {
        return view('public.data-deletion', [
            'deletion' => DataDeletionRequest::where('confirmation_code', strtoupper($code))->firstOrFail(),
        ]);
    }

    private function deletionRequest(Request $request, string $provider, ?string $secret): JsonResponse
    {
        $userId = $this->platformUserId($request, $secret);
        if ($userId === null) {
            return $this->rejected($provider);
        }

        $deletion = DB::transaction(fn () => DataDeletionRequest::create([
            'confirmation_code' => DataDeletionRequest::newCode(),
            'provider' => $provider,
            'status' => DataDeletionRequest::STATUS_COMPLETED,
            'connections_removed' => $this->removeConnections($provider, $userId),
            'completed_at' => now(),
        ]));
        Log::channel('clipper_api')->info('Meta data deletion request handled', [
            'provider' => $provider, 'code' => $deletion->confirmation_code, 'removed' => $deletion->connections_removed,
        ]);

        return response()->json([
            'url' => route('data-deletion.status', $deletion->confirmation_code),
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    private function deauthorized(Request $request, string $provider, ?string $secret): JsonResponse
    {
        $userId = $this->platformUserId($request, $secret);
        if ($userId === null) {
            return $this->rejected($provider);
        }

        $removed = $this->removeConnections($provider, $userId);
        Log::channel('clipper_api')->info('Meta app removed by user', ['provider' => $provider, 'removed' => $removed]);

        return response()->json(['success' => true]);
    }

    /** The same Facebook or Threads person may be connected by more than one Clipper user: all of them go. */
    private function removeConnections(string $provider, string $platformUserId): int
    {
        $accounts = MetaAccount::where('provider', $provider)->where('provider_user_id', $platformUserId)->get();
        $accounts->each->delete(); // meta_targets cascade

        return $accounts->count();
    }

    /** The app-scoped user id from a valid signed_request. Some deliveries put it in the query string. */
    private function platformUserId(Request $request, ?string $secret): ?string
    {
        $payload = SignedRequest::parse($request->input('signed_request', $request->query('signed_request')), $secret);
        $userId = $payload['user_id'] ?? null;

        return is_scalar($userId) && (string) $userId !== '' ? (string) $userId : null;
    }

    private function rejected(string $provider): JsonResponse
    {
        Log::channel('clipper_api')->warning('Meta callback with an invalid signed_request', ['provider' => $provider]);

        return response()->json(['error' => 'invalid signed_request'], 400);
    }
}
