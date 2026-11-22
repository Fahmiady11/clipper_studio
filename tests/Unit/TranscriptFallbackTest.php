<?php

namespace Tests\Unit;

use App\Services\TranscriptService;
use Tests\TestCase;

/** The sound for Whisper is fetched only once captions have failed, and only when Whisper can run. */
class TranscriptFallbackTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/transcript-'.getmypid());
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        // fetchViaYtDlp leaves its folder behind when no caption file came
        foreach (glob(storage_path('app/temp/subs_fallbackT0*')) ?: [] as $subs) {
            @rmdir($subs);
        }
        parent::tearDown();
    }

    /** A yt-dlp that writes an Indonesian json3 caption file when $captions, nothing otherwise */
    private function fakeYtDlp(bool $captions): void
    {
        $json3 = json_encode(['events' => [['tStartMs' => 0, 'dDurationMs' => 2000, 'segs' => [['utf8' => 'halo semua']]]]]);
        $write = $captions ? 'echo '.escapeshellarg($json3).' > "$(echo "$2" | sed \'s/%(ext)s/id.json3/\')"' : ':';
        $binary = $this->dir.'/yt-dlp';
        file_put_contents($binary, "#!/bin/sh\nwhile [ \$# -gt 0 ]; do\n    if [ \"\$1\" = \"-o\" ]; then {$write}; fi\n    shift\ndone\n");
        chmod($binary, 0755);
        config(['services.ytdlp.path' => $binary, 'services.ytdlp.cookies_from_browser' => '']);
    }

    public function test_captions_found_never_asks_for_the_audio(): void
    {
        $this->fakeYtDlp(captions: true);
        config(['services.groq.api_key' => 'gsk_test']);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT01', fn () => $this->fail('audio fetched although captions exist'));

        $this->assertSame('id', $result['language']);
        $this->assertSame('halo semua', $result['segments'][0]['text']);
    }

    public function test_no_groq_key_means_no_audio_download(): void
    {
        $this->fakeYtDlp(captions: false);
        config(['services.groq.api_key' => '']);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT02', fn () => $this->fail('audio fetched with no Whisper to send it to'));

        $this->assertSame([], $result['segments']);
    }

    public function test_no_captions_asks_for_the_audio_once(): void
    {
        $this->fakeYtDlp(captions: false);
        config(['services.groq.api_key' => 'gsk_test']);
        $calls = 0;

        // A path that does not exist stops before ffmpeg and Groq
        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT03', function () use (&$calls) {
            $calls++;

            return $this->dir.'/missing.webm';
        });

        $this->assertSame(1, $calls);
        $this->assertSame([], $result['segments']);
    }
}
