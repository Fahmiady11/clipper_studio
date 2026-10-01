<?php

namespace App\Services\Transcribers;

class GroqTranscriber
{
    use SrtHelper;

    public function __construct(
        private string $ffmpeg,
        private string $jobId
    ) {}

    public function transcribe(string $cutFileArg, string $destSrt, string $lang): void
    {
        echo "Using Groq API for transcription\n";
        $audioFile = "/tmp/{$this->jobId}_audio.wav";
        $audioOut  = escapeshellarg($audioFile);

        exec("{$this->ffmpeg} -i $cutFileArg -ar 16000 -ac 1 -f wav $audioOut -y 2>&1", $o, $c);
        if ($c !== 0) {
            throw new \RuntimeException('FFmpeg audio extract failed: ' . implode("\n", $o));
        }

        $fields = [
            'file'                      => new \CURLFile($audioFile, 'audio/wav', 'audio.wav'),
            'model'                     => 'whisper-large-v3-turbo',
            'response_format'           => 'verbose_json',
            'timestamp_granularities[]' => 'segment',
        ];
        if ($lang && $lang !== 'auto') {
            $fields['language'] = $lang;
        }

        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . config('services.groq.api_key')],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($audioFile);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Groq API error ' . $httpCode . ': ' . $response);
        }

        $data     = json_decode($response, true);
        $segments = $data['segments'] ?? [];

        if (empty($segments)) {
            throw new \RuntimeException('Groq returned no segments');
        }

        $srt = '';
        foreach ($segments as $i => $seg) {
            $srt .= ($i + 1) . "\n";
            $srt .= $this->msToSrtTime((int) ($seg['start'] * 1000))
                . ' --> '
                . $this->msToSrtTime((int) ($seg['end'] * 1000)) . "\n";
            $srt .= trim($seg['text']) . "\n\n";
        }

        file_put_contents($destSrt, trim($srt));
    }
}
