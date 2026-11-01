<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Models\GeneratedClip;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\PublishRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InstagramController extends Controller
{
    use EnsuresQueueWorker;

    public function __construct(private InstagramPublisher $instagram) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->instagram->isConfigured()) {
            return $this->failed('Kredensial Instagram belum diisi admin (INSTAGRAM_APP_ID, INSTAGRAM_APP_SECRET, INSTAGRAM_REDIRECT_URI).');
        }

        $state = Str::random(40);
        $request->session()->put('instagram_oauth_state', $state);

        return redirect()->away($this->instagram->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('instagram_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->failed('Sesi otorisasi Instagram tidak cocok atau sudah kedaluwarsa. Coba hubungkan lagi.');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return $this->failed('Izin Instagram dibatalkan. Akun belum terhubung.');
        }

        try {
            $account = $this->instagram->callback(Auth::id(), (string) $request->query('code'));
        } catch (Throwable $e) {
            Log::channel('clipper_api')->error('Instagram connect failed', ['error' => $e->getMessage()]);

            return $this->failed(InstagramPublisher::friendlyError($e));
        }

        return redirect()->route('connections.index')->with('status', "Instagram {$account->name} terhubung. Reels sekarang bisa dikirim dari hasil render.");
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('connections.index')->with('error', $message);
    }

    public function accounts(): JsonResponse
    {
        return response()->json([
            'configured' => $this->instagram->isConfigured(),
            'accounts' => $this->instagram->accounts(Auth::id())->map->toArray()->values(),
        ]);
    }

    public function disconnect(int $accountId): JsonResponse
    {
        $this->instagram->disconnect(Auth::id(), $accountId);

        return response()->json(['success' => true]);
    }

    public function publish(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'share_to_feed' => ['sometimes', 'boolean'],
        ]);
        $clip = $this->ownedClip($clipId);

        if ($this->instagram->status($clip)->isBusy()) {
            return $this->statusResponse($clip);
        }

        try {
            $this->instagram->publish($clip, $data['account_id'], 'reels', [
                'caption' => $data['caption'] ?? '',
                'share_to_feed' => $data['share_to_feed'] ?? true,
            ]);
        } catch (PublishRefused $e) {
            abort(422, $e->getMessage());
        }
        $this->ensureQueueWorkerRunning();

        return $this->statusResponse($clip->refresh());
    }

    public function status(string $clipId): JsonResponse
    {
        return $this->statusResponse($this->ownedClip($clipId));
    }

    private function ownedClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }

    private function statusResponse(GeneratedClip $clip): JsonResponse
    {
        return response()->json(['clip_id' => $clip->id] + $this->instagram->status($clip)->toArray());
    }
}
