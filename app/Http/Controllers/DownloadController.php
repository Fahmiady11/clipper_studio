<?php

namespace App\Http\Controllers;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Every rendered clip of a project in one ZIP, named by rank and topic,
     * with a captions.txt of the captions and hashtags generated so far.
     * Videos are stored uncompressed (MP4 does not shrink further).
     */
    public function project(string $projectId): BinaryFileResponse
    {
        $project = ClipProject::where('user_id', Auth::id())->findOrFail($projectId);
        $clips = GeneratedClip::where('clip_project_id', $project->id)->where('status', 'done')
            ->orderBy('ranking')->get()
            ->filter(fn ($c) => $c->output_path && Storage::exists($c->output_path));
        abort_if($clips->isEmpty(), 404, 'Belum ada clip yang selesai dirender.');

        $zipPath = tempnam(sys_get_temp_dir(), 'clips_');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $captions = [];
        foreach ($clips as $c) {
            $name = sprintf('%02d - %s.mp4', $c->ranking, Str::limit(Str::slug((string) $c->topic, ' ') ?: 'clip', 60, ''));
            $zip->addFile(Storage::path($c->output_path), $name);
            $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            if ($c->caption) {
                $captions[] = $name."\n".$c->caption.($c->hashtags_json ? "\n\n".implode(' ', $c->hashtags_json) : '');
            }
        }
        if ($captions) {
            $zip->addFromString('captions.txt', implode("\n\n---\n\n", $captions)."\n");
        }
        $zip->close();

        $title = Str::limit(Str::slug((string) ($project->video_title ?: 'clipper')), 50, '');

        return response()->download($zipPath, "clips-{$title}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
