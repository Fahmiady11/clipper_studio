<?php

namespace App\Http\Controllers;

use App\Models\GameplayClip;
use App\Services\FFmpegService;
use App\Services\GameplayFootage;
use App\Support\InvalidFootage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user's own gameplay footage for the split-screen layout: list, upload
 * (one file per request, like the music library) and delete. Every lookup is
 * scoped to the signed-in user, so another user's id is just a 404.
 */
class GameplayClipController extends Controller
{
    public function page(): View
    {
        return view('settings.gameplay', ['maxFiles' => GameplayFootage::MAX_FILES, 'maxMb' => GameplayFootage::MAX_KB / 1024]);
    }

    public function index(): JsonResponse
    {
        $clips = GameplayClip::where('user_id', Auth::id())->orderByDesc('id')->get();

        return response()->json([
            'clips' => $clips->map(fn (GameplayClip $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'size' => $c->size_bytes,
                'duration' => round($c->duration, 1),
                'width' => $c->width,
                'height' => $c->height,
                'thumb' => $c->thumb_path ? route('gameplay.thumb', $c->id, absolute: false) : null,
                'created_at' => $c->created_at->toIso8601String(),
            ])->values(),
            'max' => GameplayFootage::MAX_FILES,
            'remaining' => GameplayFootage::for(Auth::id())->remaining(),
        ]);
    }

    public function store(Request $request, FFmpegService $ffmpeg): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.GameplayFootage::MAX_KB, 'extensions:'.implode(',', GameplayFootage::EXTENSIONS)],
        ], [
            'file.required' => 'Pilih file video dulu.',
            'file.uploaded' => 'Upload gagal. Cek ukuran file (maksimal '.(GameplayFootage::MAX_KB / 1024).' MB) dan koneksimu.',
            'file.max' => 'Ukuran file maksimal '.(GameplayFootage::MAX_KB / 1024).' MB.',
            'file.extensions' => 'Format harus MP4, MOV, M4V, WebM, atau MKV.',
        ]);

        // The count check and the insert must not interleave with a second upload
        $clip = Cache::lock('gameplay-upload:'.Auth::id(), 300)->block(10, function () use ($request, $ffmpeg) {
            try {
                return GameplayFootage::for(Auth::id())->store($request->file('file'), $ffmpeg);
            } catch (InvalidFootage $e) {
                return $e;
            }
        });
        if ($clip instanceof InvalidFootage) {
            return response()->json(['message' => $clip->getMessage(), 'errors' => ['file' => [$clip->getMessage()]]], 422);
        }

        return response()->json(['id' => $clip->id, 'name' => $clip->name], 201);
    }

    /**
     * Deleting never breaks a render: the renderer picks its footage when the
     * render starts, so a clip deleted while its render waits in the queue
     * simply renders as a plain reframe.
     */
    public function destroy(int $clipId): JsonResponse
    {
        GameplayClip::where('user_id', Auth::id())->findOrFail($clipId)->delete();

        return response()->json(['success' => true]);
    }

    public function thumb(int $clipId): Response
    {
        $clip = GameplayClip::where('user_id', Auth::id())->findOrFail($clipId);
        abort_unless($clip->thumb_path && Storage::disk('local')->exists($clip->thumb_path), 404);

        return Storage::disk('local')->response($clip->thumb_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
