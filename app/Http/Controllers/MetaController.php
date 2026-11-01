<?php

namespace App\Http\Controllers;

use App\Services\YouTubeService;
use App\Services\YtDlpService;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaController extends Controller
{
    public function show(Request $request, YtDlpService $ytdlp, YouTubeService $youtube): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:500']]);

        $url = YoutubeUrl::canonical($request->string('url')->toString());
        if (! $url) {
            return response()->json(['error' => 'Link YouTube tidak valid.'], 422);
        }

        // Fail on step 1 instead of after the user picks a layout: only videos
        // from a channel they proved with OAuth can be processed.
        if (! $youtube->verifiedChannelIdForVideo($request->user()->id, $url)) {
            return response()->json(['error' => 'Video ini bukan dari channel YouTube yang sudah kamu verifikasi. Buka /settings/youtube untuk menghubungkan channel, atau unggah file videonya langsung.'], 422);
        }
        $log = Log::channel('clipper_api');

        try {
            $meta = $ytdlp->getMetadata($url);

            $log->info('Meta fetched', [
                'user_id' => $request->user()?->id,
                'url' => $url,
                'title' => $meta['title'],
                'duration' => $meta['duration_seconds'],
            ]);

            return response()->json($meta);
        } catch (\RuntimeException $e) {
            $log->warning('Meta fetch failed', [
                'user_id' => $request->user()?->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Full yt-dlp output stays in the log; it can contain server paths and cookie locations
            $msg = str_contains($e->getMessage(), 'Sign in to confirm')
                ? 'YouTube meminta verifikasi (cookies). Periksa pengaturan YTDLP_COOKIES di server.'
                : (str_contains($e->getMessage(), 'Private video') || str_contains($e->getMessage(), 'unavailable')
                    ? 'Video tidak tersedia atau privat.'
                    : 'Gagal membaca video dari YouTube. Coba lagi nanti.');

            return response()->json(['error' => $msg], 422);
        }
    }
}
