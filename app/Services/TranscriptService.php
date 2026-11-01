<?php

namespace App\Services;

use App\Support\Cancellation;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TranscriptService
{
    private const MAX_WORD_SECONDS = 1.2;

    public function fetch(string $youtubeUrl, ?string $videoPath = null): array
    {
        $videoId = $this->extractVideoId($youtubeUrl);

        if (! $videoId) {
            throw new RuntimeException("Cannot extract video ID from: {$youtubeUrl}");
        }

        $log = Log::channel('clipper_jobs');

        // Primary: yt-dlp handles YouTube anti-bot (pot token / signature).
        // Direct timedtext scraping returns 200 + empty body since ~2024.
        $log->info('[TRANSCRIPT] Trying primary: yt-dlp subtitles', ['video_id' => $videoId]);
        $result = $this->fetchViaYtDlp($youtubeUrl, $videoId);

        if (! empty($result['items'])) {
            $log->info('[TRANSCRIPT] PRIMARY used: yt-dlp subtitles', [
                'language' => $result['language'],
                'segments' => count($result['items']),
            ]);

            return ['language' => $result['language'], 'segments' => $result['items']];
        }

        $log->warning('[TRANSCRIPT] PRIMARY failed (no subtitles), trying FALLBACK: Groq Whisper');

        // Fallback: Groq Whisper on the downloaded video file
        if ($videoPath && file_exists($videoPath)) {
            $groqResult = $this->fallbackGroq($videoPath);
            if (! empty($groqResult['segments'])) {
                $log->info('[TRANSCRIPT] FALLBACK used: Groq whisper-large-v3-turbo', [
                    'language' => $groqResult['language'],
                    'segments' => count($groqResult['segments']),
                ]);
            } else {
                $log->error('[TRANSCRIPT] FALLBACK also failed: Groq returned no segments');
            }

            return $groqResult;
        }

        $log->error('[TRANSCRIPT] All methods failed: no subtitles, no video file for Groq');

        return ['language' => 'id', 'segments' => []];
    }

    private function fetchViaYtDlp(string $url, string $videoId): array
    {
        set_time_limit(0); // yt-dlp subtitle fetch must not hit PHP max_execution_time
        $bin = config('services.ytdlp.path', 'yt-dlp');
        $browser = config('services.ytdlp.cookies_from_browser', '');
        $cookie = $browser ? ' --cookies-from-browser '.escapeshellarg($browser) : '';
        $tmpDir = storage_path('app/temp/subs_'.$videoId);
        @mkdir($tmpDir, 0755, true);

        // Clean stale subtitle files from prior runs
        foreach (glob("{$tmpDir}/*.json3") ?: [] as $f) {
            @unlink($f);
        }

        // yt-dlp inserts the lang code: out template "sub.%(ext)s" -> "sub.id.json3"
        $outTpl = $tmpDir.'/sub.%(ext)s';
        $cmd = sprintf(
            '%s%s --skip-download --write-auto-subs --write-subs --sub-langs %s'
            .' --sub-format json3 --no-playlist -o %s -- %s 2>&1',
            $bin,
            $cookie,
            escapeshellarg('id,en'),
            escapeshellarg($outTpl),
            escapeshellarg($url)
        );

        // Don't hard-fail on non-zero exit: a 429 on the 2nd lang still leaves
        // the 1st lang's file written. We validate by looking for output files.
        [$code, $output] = Cancellation::exec($cmd);
        Log::channel('clipper_jobs')->info('yt-dlp subtitle fetch', ['code' => $code, 'out' => implode("\n", $output)]);

        // Prefer Indonesian, then English, then anything written
        $file = null;
        $lang = 'id';
        foreach (['id', 'en'] as $l) {
            $hit = glob("{$tmpDir}/*.{$l}.json3") ?: [];
            if (! empty($hit)) {
                $file = $hit[0];
                $lang = $l;
                break;
            }
        }
        if (! $file) {
            $any = glob("{$tmpDir}/*.json3") ?: [];
            if (! empty($any)) {
                $file = $any[0];
                if (preg_match('/\.([a-z-]+)\.json3$/', $file, $m)) {
                    $lang = $m[1];
                }
            }
        }

        if (! $file) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true) ?: [];
        $items = $this->parseJson3($data);

        // Cleanup
        foreach (glob("{$tmpDir}/*.json3") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        return ['language' => $lang, 'items' => $items];
    }

    /**
     * Segments keep the caption line shape Gemini expects ({start, end, text}).
     * Auto (ASR) captions also carry per-word offsets (segs[].tOffsetMs); those
     * are kept as `words` so subtitles can follow real speech timing instead of
     * spreading a line's duration evenly over its words.
     */
    private function parseJson3(array $data): array
    {
        $events = $data['events'] ?? [];
        $segments = [];

        foreach ($events as $event) {
            if (! isset($event['segs'])) {
                continue;
            }
            $text = collect($event['segs'])->pluck('utf8')->join('');
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if (! $text) {
                continue;
            }

            $startMs = (int) ($event['tStartMs'] ?? 0);
            $endMs = $startMs + (int) ($event['dDurationMs'] ?? 3000);

            $segment = [
                'start' => round($startMs / 1000, 2),
                'end' => round($endMs / 1000, 2),
                'text' => $text,
            ];

            // Manual captions have one seg per line and no offsets — no word timing
            $wordSegs = array_values(array_filter($event['segs'], fn ($seg) => trim($seg['utf8'] ?? '') !== ''));
            $hasWordTiming = count($wordSegs) > 1
                || collect($wordSegs)->contains(fn ($seg) => isset($seg['tOffsetMs']));

            if ($hasWordTiming) {
                $segment['words'] = array_map(fn ($seg) => [
                    'start' => ($startMs + (int) ($seg['tOffsetMs'] ?? 0)) / 1000,
                    'end' => $endMs / 1000, // tightened below
                    'text' => trim($seg['utf8']),
                ], $wordSegs);
            }

            $segments[] = $segment;
        }

        return $this->tightenWordEnds($segments);
    }

    /**
     * json3 only gives word starts. A word ends when the next one starts, but
     * never later than its caption event, nor more than MAX_WORD_SECONDS after
     * it started (so the last word before a pause doesn't linger).
     */
    private function tightenWordEnds(array $segments): array
    {
        $refs = [];
        foreach ($segments as $si => $seg) {
            foreach ($seg['words'] ?? [] as $wi => $_) {
                $refs[] = [$si, $wi];
            }
        }

        $n = count($refs);
        for ($i = 0; $i < $n; $i++) {
            [$si, $wi] = $refs[$i];
            $word = $segments[$si]['words'][$wi];
            $end = min($word['end'], $word['start'] + self::MAX_WORD_SECONDS);
            if ($i + 1 < $n) {
                [$nsi, $nwi] = $refs[$i + 1];
                $end = min($end, $segments[$nsi]['words'][$nwi]['start']);
            }
            $segments[$si]['words'][$wi]['start'] = round($word['start'], 3);
            $segments[$si]['words'][$wi]['end'] = round(max($end, $word['start'] + 0.05), 3);
        }

        return $segments;
    }

    private function fallbackGroq(string $videoPath): array
    {
        $log = Log::channel('clipper_jobs');

        $groqKey = config('services.groq.api_key');
        if (! $groqKey) {
            $log->warning('fallbackGroq: GROQ_API_KEY not set');

            return ['language' => 'id', 'segments' => []];
        }

        $ffmpeg = config('services.ffmpeg.path', 'ffmpeg');
        // MP3 at 32kbps mono ≈ 14MB/hr — fits Groq's 25MB limit even for long videos
        $tmpAudio = sys_get_temp_dir().'/ts_audio_'.uniqid().'.mp3';

        $log->info('fallbackGroq: extracting audio', ['video' => $videoPath, 'tmp' => $tmpAudio]);
        [$code, $out] = Cancellation::exec(sprintf('%s -i %s -ar 16000 -ac 1 -b:a 32k %s -y 2>&1',
            $ffmpeg, escapeshellarg($videoPath), escapeshellarg($tmpAudio)));

        if ($code !== 0 || ! file_exists($tmpAudio)) {
            $log->error('fallbackGroq: ffmpeg failed', ['code' => $code, 'out' => implode("\n", $out)]);

            return ['language' => 'id', 'segments' => []];
        }

        $audioSize = filesize($tmpAudio);
        $log->info('fallbackGroq: sending to Groq', ['audio_size_mb' => round($audioSize / 1048576, 1)]);

        if ($audioSize > 24 * 1024 * 1024) {
            $log->error('fallbackGroq: audio too large for Groq API', ['size_mb' => round($audioSize / 1048576, 1)]);
            @unlink($tmpAudio);

            return ['language' => 'id', 'segments' => []];
        }

        // Both granularities: segments feed Gemini, words drive subtitle timing.
        // The field repeats, which a PHP array can't express, so build the body.
        [$body, $contentType] = $this->multipartBody([
            ['model', 'whisper-large-v3-turbo'],
            ['response_format', 'verbose_json'],
            ['timestamp_granularities[]', 'segment'],
            ['timestamp_granularities[]', 'word'],
            ['language', 'id'],
        ], 'file', $tmpAudio, 'audio.mp3', 'audio/mpeg');

        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$groqKey, 'Content-Type: '.$contentType],
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmpAudio);

        if ($httpCode !== 200) {
            $log->error('fallbackGroq: Groq API error', ['http' => $httpCode, 'body' => $response]);

            return ['language' => 'id', 'segments' => []];
        }

        $data = json_decode($response, true);
        $segments = collect($data['segments'] ?? [])
            ->map(fn ($seg) => [
                'start' => round((float) $seg['start'], 2),
                'end' => round((float) $seg['end'], 2),
                'text' => trim($seg['text']),
            ])
            ->filter(fn ($s) => $s['text'] !== '')
            ->values()
            ->all();

        $segments = $this->attachGroqWords($segments, $data['words'] ?? []);

        $log->info('fallbackGroq: done', ['segments' => count($segments)]);

        return [
            'language' => $data['language'] ?? 'id',
            'segments' => $segments,
        ];
    }

    /**
     * Groq returns words as one flat list; hang each on the segment whose
     * window contains its start (words and segments are both time-ordered).
     */
    private function attachGroqWords(array $segments, array $words): array
    {
        $si = 0;
        $count = count($segments);
        foreach ($words as $w) {
            $text = trim($w['word'] ?? '');
            if ($text === '' || $count === 0) {
                continue;
            }
            $start = (float) $w['start'];
            while ($si + 1 < $count && $start >= $segments[$si + 1]['start']) {
                $si++;
            }
            $segments[$si]['words'][] = [
                'start' => round($start, 3),
                'end' => round(max((float) $w['end'], $start + 0.05), 3),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /** @return array{0: string, 1: string} [body, content-type header value] */
    private function multipartBody(array $fields, string $fileField, string $filePath, string $fileName, string $mime): array
    {
        $boundary = '----clipper'.bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as [$name, $value]) {
            $body .= "--{$boundary}\r\n"
                ."Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                ."{$value}\r\n";
        }
        $body .= "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$fileName}\"\r\n"
            ."Content-Type: {$mime}\r\n\r\n"
            .file_get_contents($filePath)."\r\n"
            ."--{$boundary}--\r\n";

        return [$body, 'multipart/form-data; boundary='.$boundary];
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('/(?:watch\?v=|youtu\.be\/|shorts\/|embed\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
