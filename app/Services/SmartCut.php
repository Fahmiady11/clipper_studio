<?php

namespace App\Services;

use App\Support\Cancellation;

/**
 * Editor-style tightening of a clip: silent pauses and filler words ("eh",
 * "hmm") are cut out (jump cuts), and the picture punches in and out at
 * cuts and sentence ends so the edit reads as deliberate.
 *
 * Times are clip-relative: 0 is the clip start in the source.
 */
class SmartCut
{
    private const SILENCE_BELOW = 10; // dB under the clip's mean loudness that counts as a pause

    private const MIN_SILENCE = 0.3;  // shorter pauses are speech rhythm, kept

    private const PAD = 0.1;          // seconds of each pause kept on either side of a cut

    private const MIN_PIECE = 0.25;   // a shorter scrap between two cuts is dropped

    public const ZOOM = 1.12;         // punch-in scale

    private const MIN_SHOT = 2.5;     // seconds between two zoom changes

    private const MAX_SHOT = 6.0;     // change zoom at least this often while someone talks

    private const FILLERS = ['eh', 'ehm', 'em', 'emm', 'eee', 'ee', 'hmm', 'hm', 'mm', 'uh', 'um', 'umm', 'uhm', 'er', 'erm'];

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words  absolute (source) times
     * @return list<array{0: float, 1: float}> pieces to keep, clip-relative
     */
    public function pieces(string $video, float $start, float $end, array $words): array
    {
        $duration = $end - $start;

        return self::onFrames(self::keep(array_merge(
            $this->silences($video, $start, $duration),
            self::fillers($words, $start, $duration),
        ), $duration), app(FFmpegService::class)->outputFps($video));
    }

    /**
     * Piece edges moved to the nearest frame edge. A piece then holds a whole
     * number of frames and its sound is exactly as long; otherwise concat pads
     * the shorter one at every join (up to a frame of silence each) and the
     * subtitles, timed on the exact pieces, fall behind cut after cut.
     */
    public static function onFrames(array $pieces, float $fps): array
    {
        if ($fps <= 0 || count($pieces) < 2) {
            return $pieces;
        }
        $snap = fn ($t) => round(round($t * $fps) / $fps, 3);
        $out = [];
        foreach ($pieces as [$a, $b]) {
            [$a, $b] = [$snap($a), $snap($b)];
            if ($b > $a) {
                $out[] = [$a, $b];
            }
        }

        return $out ?: $pieces;
    }

    /**
     * Pause spans from the clip audio, already shrunk by PAD where speech
     * borders them.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function silences(string $video, float $start, float $duration): array
    {
        // Mixes differ by 20 dB or more, so "quiet" is measured against this clip
        [$code, $output] = Cancellation::exec($this->audioPass($video, $start, $duration, 'volumedetect'));
        if ($code !== 0 || ! preg_match('/mean_volume: (-?[\d.]+) dB/', implode("\n", $output), $m)) {
            return [];
        }
        $noise = max(-50, min(-20, (int) round((float) $m[1] - self::SILENCE_BELOW)));

        [$code, $output] = Cancellation::exec($this->audioPass($video, $start, $duration,
            sprintf('silencedetect=noise=%ddB:d=%.2f', $noise, self::MIN_SILENCE)));

        return $code === 0 ? self::parseSilences(implode("\n", $output), $duration) : [];
    }

    private function audioPass(string $video, float $start, float $duration, string $filter): string
    {
        return sprintf('%s -hide_banner -nostats -ss %s -t %s -i %s -vn -af %s -f null - 2>&1',
            config('services.ffmpeg.path', 'ffmpeg'),
            escapeshellarg(sprintf('%.3f', $start)),
            escapeshellarg(sprintf('%.3f', $duration)),
            escapeshellarg($video),
            escapeshellarg($filter),
        );
    }

    /** @return list<array{0: float, 1: float}> */
    public static function parseSilences(string $log, float $duration): array
    {
        preg_match_all('/silence_(start|end): (-?[\d.]+)/', $log, $m, PREG_SET_ORDER);
        $spans = [];
        $open = null;
        foreach ($m as [, $kind, $t]) {
            if ($kind === 'start') {
                $open = max(0.0, (float) $t);
            } elseif ($open !== null) {
                $spans[] = [$open, min($duration, (float) $t)];
                $open = null;
            }
        }
        if ($open !== null) {
            $spans[] = [$open, $duration]; // still silent when the clip ends
        }

        // Keep a little air next to speech; a pause at the clip edge goes entirely
        return array_map(fn ($s) => [
            $s[0] <= 0.01 ? 0.0 : $s[0] + self::PAD,
            $s[1] >= $duration - 0.01 ? $duration : $s[1] - self::PAD,
        ], $spans);
    }

    /** @return list<array{0: float, 1: float}> */
    public static function fillers(array $words, float $start, float $duration): array
    {
        $spans = [];
        foreach ($words as $w) {
            $text = mb_strtolower(trim((string) ($w['text'] ?? ''), " \t\n.,!?;:\"'()…-"));
            if (in_array($text, self::FILLERS, true)) {
                $spans[] = [max(0.0, (float) $w['start'] - $start), min($duration, (float) $w['end'] - $start)];
            }
        }

        return $spans;
    }

    /**
     * What is left of [0, $duration] after the cuts.
     *
     * @param  array<int, array{0: float, 1: float}>  $cuts
     * @return list<array{0: float, 1: float}>
     */
    public static function keep(array $cuts, float $duration): array
    {
        $cuts = array_values(array_filter($cuts, fn ($c) => $c[1] - $c[0] > 0.05));
        usort($cuts, fn ($a, $b) => $a[0] <=> $b[0]);

        $pieces = [];
        $at = 0.0;
        foreach ($cuts as [$a, $b]) {
            if ($a - $at >= self::MIN_PIECE) {
                $pieces[] = [round($at, 3), round($a, 3)];
            }
            $at = max($at, $b);
        }
        if ($duration - $at >= self::MIN_PIECE) {
            $pieces[] = [round($at, 3), round($duration, 3)];
        }

        return $pieces ?: [[0.0, round($duration, 3)]];
    }

    public static function outputDuration(array $pieces): float
    {
        return array_sum(array_map(fn ($p) => $p[1] - $p[0], $pieces));
    }

    /** Clip-relative source time -> time in the cut clip (a cut-out moment maps to where the next piece starts). */
    public static function mapTime(array $pieces, float $t): float
    {
        $out = 0.0;
        foreach ($pieces as [$a, $b]) {
            if ($t < $a) {
                return $out;
            }
            if ($t <= $b) {
                return $out + $t - $a;
            }
            $out += $b - $a;
        }

        return $out;
    }

    /**
     * Transcript segments (absolute times) moved onto the cut clip's timeline,
     * still absolute (offset by $clipStart) so subtitle building is unchanged.
     * Words said entirely inside a cut (fillers) are dropped.
     */
    public static function remapSegments(array $segments, array $pieces, float $clipStart): array
    {
        $map = fn ($t) => $clipStart + self::mapTime($pieces, (float) $t - $clipStart);
        $out = [];
        foreach ($segments as $seg) {
            if (! empty($seg['words'])) {
                $words = [];
                foreach ($seg['words'] as $w) {
                    $s = $map($w['start']);
                    $e = $map($w['end'] ?? $w['start']);
                    if ($e - $s > 0.02) {
                        $words[] = ['start' => $s, 'end' => $e] + $w;
                    }
                }
                if (! $words) {
                    continue;
                }
                $seg['words'] = $words;
            }
            $seg['start'] = $map($seg['start']);
            $seg['end'] = $map($seg['end']);
            if ($seg['end'] > $seg['start']) {
                $out[] = $seg;
            }
        }

        return $out;
    }

    /**
     * Spans of the cut clip shown punched in. Zoom changes at jump cuts (which
     * hides them) and sentence ends, no closer than MIN_SHOT apart, and at a
     * word start when nothing else changed it for MAX_SHOT.
     *
     * @param  array<int, array{text: string, start: float, end: float}>  $words  clip-relative, on the cut timeline
     * @return list<array{0: float, 1: float}>
     */
    public static function zoomPlan(array $pieces, array $words, float $duration): array
    {
        $cuts = [];
        $at = 0.0;
        foreach (array_slice($pieces, 0, -1) as [$a, $b]) {
            $at += $b - $a;
            $cuts[] = $at;
        }
        $sentenceEnds = array_map(fn ($w) => (float) $w['end'],
            array_filter($words, fn ($w) => preg_match('/[.?!]$/u', (string) $w['text'])));
        $preferred = array_merge($cuts, $sentenceEnds);
        sort($preferred);
        $starts = array_map(fn ($w) => (float) $w['start'], $words);
        sort($starts);

        $switches = [];
        $last = 0.0;
        while (true) {
            $next = null;
            foreach ($preferred as $c) {
                if ($c - $last >= self::MIN_SHOT && $c - $last <= self::MAX_SHOT) {
                    $next = $c;
                    break;
                }
            }
            if ($next === null) {
                foreach ($starts as $s) {
                    if ($s - $last >= self::MAX_SHOT) {
                        $next = $s;
                        break;
                    }
                }
            }
            if ($next === null || $duration - $next < 1.5) {
                break;
            }
            $switches[] = $next;
            $last = $next;
        }

        // Even switches zoom in, odd ones zoom back out
        $spans = [];
        foreach (array_chunk($switches, 2) as $pair) {
            $spans[] = [round($pair[0], 3), round($pair[1] ?? $duration, 3)];
        }

        return $spans;
    }
}
