<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Jobs\UploadToYouTubeJob;
use App\Models\GeneratedClip;
use App\Models\YoutubeAccount;
use App\Services\FFmpegService;
use App\Services\YouTubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class YouTubeController extends Controller
{
    use EnsuresQueueWorker;

    // Longer than this is a normal video, not a Short
    public const MAX_SHORT_SECONDS = 180;

    public function __construct(private YouTubeService $youtube) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->youtube->isConfigured()) {
            return redirect('/?youtube=not_configured');
        }

        $state = Str::random(40);
        $request->session()->put('youtube_oauth_state', $state);

        return redirect()->away($this->youtube->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('youtube_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/?youtube=invalid_state');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect('/?youtube=denied');
        }

        try {
            $this->youtube->connect(Auth::id(), (string) $request->query('code'));
        } catch (Throwable $e) {
            Log::channel('clipper_api')->error('YouTube connect failed', ['error' => $e->getMessage()]);

            return redirect('/?youtube=error');
        }

        return redirect('/?youtube=connected');
    }

    public function accounts(): JsonResponse
    {
        return response()->json([
            'configured' => $this->youtube->isConfigured(),
            'accounts' => YoutubeAccount::where('user_id', Auth::id())
                ->orderBy('title')
                ->get(['id', 'title', 'thumbnail_url']),
        ]);
    }

    public function disconnect(int $accountId): JsonResponse
    {
        YoutubeAccount::where('user_id', Auth::id())->findOrFail($accountId)->delete();

        return response()->json(['success' => true]);
    }

    public function upload(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['required', 'in:public,unlisted,private'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
        ], [
            'title.not_regex' => 'Judul YouTube tidak boleh berisi < atau >.',
        ]);
        $clip = $this->ownedClip($clipId);
        $account = YoutubeAccount::where('user_id', Auth::id())->findOrFail($data['account_id']);

        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');
        // The rendered length: jump cuts make it shorter than end - start
        try {
            $seconds = app(FFmpegService::class)->probeDuration(Storage::path($clip->output_path));
        } catch (Throwable) {
            $seconds = $clip->durationSeconds();
        }
        abort_if($seconds > self::MAX_SHORT_SECONDS + 0.5, 422, 'YouTube Shorts maksimal 3 menit, clip ini '.round($seconds).' detik. Pendekkan clip dulu.');

        if (in_array($clip->youtube_status, ['queued', 'uploading'], true)) {
            return $this->statusResponse($clip);
        }

        $clip->update([
            'youtube_account_id' => $account->id,
            'youtube_video_id' => null,
            'youtube_status' => 'queued',
            'youtube_error' => null,
        ]);
        UploadToYouTubeJob::dispatch($clip->id, $account->id, [
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'privacy' => $data['privacy'],
            'tags' => $data['tags'] ?? [],
        ]);
        $this->ensureQueueWorkerRunning();

        return $this->statusResponse($clip);
    }

    public function status(string $clipId): JsonResponse
    {
        $clip = $this->ownedClip($clipId);
        $privacy = null;

        // Uploaded; follow YouTube's processing until it is done
        if ($clip->youtube_status === 'processing' && $clip->youtube_video_id && $clip->youtube_account_id) {
            try {
                // Scoped to the user too: never use someone else's tokens, even via a stale id
                $account = YoutubeAccount::where('user_id', Auth::id())->findOrFail($clip->youtube_account_id);
                $status = $this->youtube->videoStatus($account, $clip->youtube_video_id);
                $privacy = $status['privacy'];
                $clip->update(['youtube_status' => $status['status'], 'youtube_error' => $status['reason']]);
            } catch (Throwable $e) {
                Log::channel('clipper_api')->warning('YouTube status refresh failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->statusResponse($clip, $privacy);
    }

    private function ownedClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }

    private function statusResponse(GeneratedClip $clip, ?string $privacy = null): JsonResponse
    {
        return response()->json([
            'clip_id' => $clip->id,
            'status' => $clip->youtube_status,
            'error' => $clip->youtube_error,
            'video_id' => $clip->youtube_video_id,
            'url' => $clip->youtube_video_id ? 'https://youtube.com/shorts/'.$clip->youtube_video_id : null,
            'privacy' => $privacy,
        ]);
    }
}
