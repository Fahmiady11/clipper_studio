<?php

namespace App\Http\Controllers;

use App\Models\MusicTrack;
use App\Services\MusicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Background-music library: bulk upload (one file per request so the browser
 * can show progress and PHP's max_file_uploads never bites), credits for
 * CC BY tracks, moving between moods, deleting.
 */
class MusicController extends Controller
{
    public function __construct(private MusicService $music) {}

    public function index(): JsonResponse
    {
        $tracks = $this->music->library();
        $counts = array_fill_keys(MusicService::MOODS, 0);
        foreach ($tracks as $t) {
            if ($t['mood']) {
                $counts[$t['mood']]++;
            }
        }

        return response()->json(['tracks' => $tracks, 'counts' => $counts, 'total' => count($tracks)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'extensions:'.implode(',', MusicService::EXTENSIONS)],
            'mood' => ['required', Rule::in(MusicService::MOODS)],
            'license' => ['required', Rule::in(MusicTrack::LICENSES)],
            'credit' => ['nullable', 'string', 'max:500', 'required_if:license,cc-by'],
        ], [
            'credit.required_if' => 'Lagu CC BY wajib diberi kredit (salin teks atribusi dari YouTube Audio Library).',
            'file.extensions' => 'Format harus mp3, m4a, aac, wav, atau ogg.',
            'file.max' => 'Ukuran file maksimal 50 MB.',
        ]);

        abort_unless($this->music->isAudio($data['file']->getRealPath()), 422, 'File ini bukan audio yang bisa dibaca.');

        $path = $this->music->store($data['file'], $data['mood']);
        MusicTrack::updateOrCreate(['path' => $path], [
            'license' => $data['license'],
            'credit' => isset($data['credit']) ? trim($data['credit']) : null,
        ]);

        return response()->json(['path' => $path], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'mood' => ['sometimes', Rule::in(MusicService::MOODS)],
            'license' => ['sometimes', Rule::in(MusicTrack::LICENSES)],
            'credit' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $path = $this->resolve($data['path']);
        if (isset($data['mood'])) {
            $path = $this->music->move($path, $data['mood']);
        }

        $fields = array_intersect_key($data, array_flip(['license', 'credit']));
        if ($fields) {
            $track = MusicTrack::firstOrNew(['path' => $path]);
            $track->fill($fields);
            abort_if($track->license !== 'free' && ! trim((string) $track->credit), 422, 'Lagu CC BY wajib diberi kredit.');
            $track->save();
        }

        return response()->json(['path' => $path]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->music->delete($this->resolve($request->validate(['path' => ['required', 'string']])['path']));

        return response()->json(['success' => true]);
    }

    public function file(Request $request): BinaryFileResponse
    {
        try {
            $file = $this->music->absolute((string) $request->query('path'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->file($file);
    }

    /** Validate a library path from the client; 404 for anything outside the library. */
    private function resolve(string $path): string
    {
        try {
            return $this->music->relative($this->music->absolute($path));
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
    }
}
