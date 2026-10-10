<?php

namespace App\Http\Controllers;

use App\Models\GeneratedClip;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    public function download(string $clipId): StreamedResponse
    {
        $clip = GeneratedClip::with('clipProject')->findOrFail($clipId);

        abort_if($clip->clipProject->user_id !== Auth::id(), 403);
        abort_if($clip->status !== 'done', 404, 'Clip not ready.');
        abort_if(! $clip->output_path || ! Storage::exists($clip->output_path), 404, 'File not found.');

        return Storage::download($clip->output_path, 'clip_'.$clipId.'.mp4');
    }
}
