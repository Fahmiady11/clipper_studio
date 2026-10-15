<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\UploadToTikTokJob;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Services\TikTokService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TikTokController extends Controller
{
    use EnsuresQueueWorker;

    public function __construct(private TikTokService $tiktok) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->tiktok->isConfigured()) {
            return redirect('/?tiktok=not_configured');
        }

        $state = Str::random(40);
        $request->session()->put('tiktok_oauth_state', $state);

        return redirect()->away($this->tiktok->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('tiktok_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/?tiktok=invalid_state');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect('/?tiktok=denied');
        }

        try {
            $this->tiktok->connect(Auth::id(), (string) $request->query('code'));
        } catch (Throwable $e) {
            Log::channel('clipper_api')->error('TikTok connect failed', ['error' => $e->getMessage()]);

            return redirect('/?tiktok=error');
        }

        return redirect('/?tiktok=connected');
    }

    public function accounts(): JsonResponse
    {
        return response()->json([
            'configured' => $this->tiktok->isConfigured(),
            'accounts' => TiktokAccount::where('user_id', Auth::id())
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'avatar_url']),
        ]);
    }

    public function disconnect(int $accountId): JsonResponse
    {
        TiktokAccount::where('user_id', Auth::id())->findOrFail($accountId)->delete();

        return response()->json(['success' => true]);
    }

    public function creatorInfo(int $accountId): JsonResponse
    {
        $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($accountId);
        abort_unless($account->hasScope('video.publish'), 422, TiktokAccount::scopeMessage('video.publish'));

        try {
            return response()->json($this->tiktok->creatorInfo($account));
        } catch (Throwable $e) {
            abort(502, $e->getMessage());
        }
    }

    public function upload(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'mode' => ['sometimes', 'in:inbox,direct'],
            'title' => ['exclude_unless:mode,direct', 'nullable', 'string', 'max:2200'],
            'privacy_level' => ['exclude_unless:mode,direct', 'required', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY'],
            'disable_comment' => ['exclude_unless:mode,direct', 'boolean'],
            'disable_duet' => ['exclude_unless:mode,direct', 'boolean'],
            'disable_stitch' => ['exclude_unless:mode,direct', 'boolean'],
            // Commercial content disclosure: promoting your own brand / a paid partnership
            'brand_organic_toggle' => ['exclude_unless:mode,direct', 'boolean'],
            'brand_content_toggle' => ['exclude_unless:mode,direct', 'boolean'],
        ]);
        // TikTok rejects branded content that only the creator can see
        if (! empty($data['brand_content_toggle']) && ($data['privacy_level'] ?? null) === 'SELF_ONLY') {
            throw ValidationException::withMessages(['privacy_level' => 'Branded content tidak bisa diposting sebagai "Hanya saya".']);
        }
        $mode = $data['mode'] ?? 'inbox';
        $clip = $this->ownedClip($clipId);
        $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($data['account_id']);

        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');
        $scope = $mode === 'direct' ? 'video.publish' : 'video.upload';
        abort_unless($account->hasScope($scope), 422, TiktokAccount::scopeMessage($scope));

        if (in_array($clip->tiktok_status, ['queued', 'uploading', 'processing'], true)) {
            return $this->statusResponse($clip);
        }

        $clip->update([
            'tiktok_account_id' => $account->id,
            'tiktok_publish_id' => null,
            'tiktok_status' => 'queued',
            'tiktok_error' => null,
        ]);
        $postInfo = $mode === 'direct' ? [
            'title' => $data['title'] ?? '',
            'privacy_level' => $data['privacy_level'],
            'disable_comment' => (bool) ($data['disable_comment'] ?? false),
            'disable_duet' => (bool) ($data['disable_duet'] ?? false),
            'disable_stitch' => (bool) ($data['disable_stitch'] ?? false),
            'brand_organic_toggle' => (bool) ($data['brand_organic_toggle'] ?? false),
            'brand_content_toggle' => (bool) ($data['brand_content_toggle'] ?? false),
        ] : [];
        UploadToTikTokJob::dispatch($clip->id, $account->id, $mode, $postInfo);
        $this->ensureQueueWorkerRunning();

        return $this->statusResponse($clip);
    }

    public function status(string $clipId): JsonResponse
    {
        $clip = $this->ownedClip($clipId);

        // The job stops polling after ~1 minute; pick up the final state here
        if ($clip->tiktok_status === 'processing' && $clip->tiktok_publish_id && $clip->tiktok_account_id) {
            try {
                // Scoped to the user too: never use someone else's tokens, even via a stale id
                $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($clip->tiktok_account_id);
                $status = $this->tiktok->publishStatus($account, $clip->tiktok_publish_id);
                $clip->update([
                    'tiktok_status' => UploadToTikTokJob::mapStatus($status['status']),
                    'tiktok_error' => $status['fail_reason'],
                ]);
            } catch (Throwable $e) {
                Log::channel('clipper_api')->warning('TikTok status refresh failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->statusResponse($clip);
    }

    private function ownedClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }

    private function statusResponse(GeneratedClip $clip): JsonResponse
    {
        return response()->json([
            'clip_id' => $clip->id,
            'status' => $clip->tiktok_status,
            'error' => $clip->tiktok_error,
        ]);
    }
}
