<?php

namespace App\Http\Controllers;

use App\Models\GeneratedClip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function download(Request $request, string $clipId): BinaryFileResponse
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);
        abort_if($clip->status !== 'done', 404, 'Clip not ready.');
        abort_if(! $clip->output_path || ! Storage::exists($clip->output_path), 404, 'File not found.');

        // BinaryFileResponse answers Range requests, so a <video> preview can
        // seek and start playing without fetching the whole file first.
        $path = Storage::path($clip->output_path);

        return $request->boolean('inline')
            ? response()->file($path, ['Content-Type' => 'video/mp4'])
            : response()->download($path, 'clip_'.$clipId.'.mp4');
    }
}
