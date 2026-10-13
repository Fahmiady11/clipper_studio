<?php

namespace App\Services;

use App\Support\Cancellation;
use Illuminate\Support\Facades\Log;

/**
 * Plans a moving 9:16 crop that follows faces (and, on a two-shot, whoever is
 * talking) by running resources/facetrack/face_track.py (OpenCV YuNet).
 * Optional: without Python/OpenCV the reframe layout keeps its centre crop.
 */
class FaceTracker
{
    /**
     * Pieces [t0, t1, x0, x1] in clip time; x is the crop centre as a share of
     * the frame width. Null means keep the centre crop.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>|null
     */
    public function plan(string $video, float $start, float $end): ?array
    {
        $python = (string) config('services.facetrack.python');
        if ($python === '') {
            return null;
        }

        $cmd = sprintf('%s %s --ffmpeg %s --model %s %s %s %s 2>/dev/null',
            escapeshellarg($python),
            escapeshellarg(resource_path('facetrack/face_track.py')),
            escapeshellarg((string) config('services.ffmpeg.path', 'ffmpeg')),
            escapeshellarg(resource_path('facetrack/face_detection_yunet_2023mar.onnx')),
            escapeshellarg($video),
            escapeshellarg(sprintf('%.3f', $start)),
            escapeshellarg(sprintf('%.3f', $end)),
        );

        [$code, $output] = Cancellation::exec($cmd);
        $data = $code === 0 ? json_decode(implode("\n", $output), true) : null;
        if (! is_array($data)) {
            // Missing Python/OpenCV or an unreadable video: render with the centre crop
            Log::channel('clipper_jobs')->warning('Face tracking unavailable, using centre crop', ['exit' => $code]);

            return null;
        }
        if (empty($data['faces']) || empty($data['segments'])) {
            return null;
        }

        return array_values(array_map(
            fn ($s) => [(float) $s[0], (float) $s[1], (float) $s[2], (float) $s[3]],
            array_filter($data['segments'], fn ($s) => is_array($s) && count($s) === 4)
        )) ?: null;
    }
}
