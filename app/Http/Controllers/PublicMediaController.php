<?php

namespace App\Http\Controllers;

use App\Models\GeneratedClip;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves a rendered clip behind a signed, expiring link (see PublicMediaUrl). */
class PublicMediaController extends Controller
{
    public function clip(string $clipId): BinaryFileResponse
    {
        $clip = GeneratedClip::where('status', 'done')->whereNotNull('output_path')->find($clipId);
        abort_unless($clip && Storage::exists($clip->output_path), 404);

        // BinaryFileResponse answers Range requests, which video fetchers use
        return response()->file(Storage::path($clip->output_path), [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
