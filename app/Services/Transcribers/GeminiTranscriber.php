<?php

namespace App\Services\Transcribers;

class GeminiTranscriber
{
    use SrtHelper;

    public function __construct(
        private string $ffmpeg,
        private string $jobId
    ) {}

    public function transcribe(string $cutFileArg, string $destSrt, string $lang, int $duration): void
    {
        echo "Using Gemini API for transcription\n";
        $apiKey    = config('services.gemini.key'); // env() is null once config is cached
        $audioFile = "/tmp/{$this->jobId}_gemini.wav";
        $audioOut  = escapeshellarg($audioFile);

        exec("{$this->ffmpeg} -i $cutFileArg -ar 22050 -ac 1 -f wav $audioOut -y 2>&1", $o, $c);
        if ($c !== 0) {
            throw new \RuntimeException('FFmpeg audio extract failed: ' . implode("\n", $o));
        }

        $audioB64 = base64_encode(file_get_contents($audioFile));
        @unlink($audioFile);

        $langName = match ($lang) {
            'id'    => 'Bahasa Indonesia',
            'en'    => 'English',
            default => $lang,
        };

        $maxFmt = sprintf('%02d:%02d:%02d,000', intdiv($duration, 3600), intdiv($duration % 3600, 60), $duration % 60);

        $prompt = "You are a professional subtitle creator. Transcribe the speech in this audio clip into {$langName} subtitles.\n\n"
            . "STRICT RULES:\n"
            . "- Return ONLY valid SRT format. No markdown, no explanation, nothing else.\n"
            . "- Timestamps are 0-based (audio starts at 00:00:00,000, ends at {$maxFmt}).\n"
            . "- DO NOT create subtitle entries for silence, music, or non-speech. Only real spoken words.\n"
            . "- Timestamp must precisely match when each word is spoken — not before, not after.\n"
            . "- Max 8 words per entry.\n"
            . "- If no speech exists, return exactly: 1\n00:00:00,000 --> 00:00:00,100\n \n\n"
            . "Example output:\n"
            . "1\n00:00:00,500 --> 00:00:02,300\nHalo selamat datang\n\n"
            . "2\n00:00:03,100 --> 00:00:05,800\nDi video ini kita akan bahas";

        $payload = json_encode([
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => 'audio/wav', 'data' => $audioB64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0],
        ]);

        // Key in a header, never in the URL (URLs leak into logs and error messages)
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 180,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error ' . $httpCode . ': ' . $response);
        }

        $data = json_decode($response, true);
        $srt  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (!$srt) {
            throw new \RuntimeException('Gemini returned empty response');
        }

        $srt = preg_replace('/^```[a-z]*\n?/m', '', $srt);
        $srt = preg_replace('/^```$/m', '', $srt);
        $srt = $this->filterSrtByDuration(trim($srt), $duration);

        file_put_contents($destSrt, $srt);
    }

    private function filterSrtByDuration(string $srt, int $maxSeconds): string
    {
        $maxMs   = $maxSeconds * 1000;
        $blocks  = preg_split('/\n\s*\n/', $srt);
        $entries = [];
        $idx     = 1;

        foreach ($blocks as $block) {
            $lines = array_values(array_filter(explode("\n", trim($block))));
            if (count($lines) < 2) continue;

            foreach ($lines as $line) {
                if (!preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $line, $m)) continue;

                $startMs = $this->srtTimeToMs($m[1]);
                $endMs   = min($this->srtTimeToMs($m[2]), $maxMs);
                if ($startMs >= $maxMs || $startMs >= $endMs) break;

                $text = trim(implode("\n", array_filter($lines, fn($l) => !is_numeric(trim($l)) && !str_contains($l, '-->'))));
                if (!$text || $text === ' ') break;

                $entries[] = $idx++ . "\n" . $this->msToSrtTime($startMs) . " --> " . $this->msToSrtTime($endMs) . "\n" . $text;
                break;
            }
        }

        return implode("\n\n", $entries);
    }
}
