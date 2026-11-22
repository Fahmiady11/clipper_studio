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

    /**
     * A yt-dlp that writes sub.<lang>.json3 only for the languages in $available, appends every
     * requested --sub-langs value to calls.log, and exits $exit (1 mimics a 429 on a request).
     *
     * @param  list<string>  $available
     */
    private function fakeYtDlp(array $available, int $exit = 0): void
    {
        $json3 = json_encode(['events' => [['tStartMs' => 0, 'dDurationMs' => 2000, 'segs' => [['utf8' => 'halo semua']]]]]);
        $binary = $this->dir.'/yt-dlp';
        file_put_contents($binary, sprintf(<<<'SH'
#!/bin/sh
while [ $# -gt 0 ]; do
    case "$1" in
        --sub-langs) langs="$2" ;;
        -o) out="$2" ;;
    esac
    shift
done
echo "$langs" >> %s
for l in %s; do
    [ "$l" = "$langs" ] && echo %s > "$(echo "$out" | sed "s/%%(ext)s/$l.json3/")"
done
exit %d
SH,
            escapeshellarg($this->dir.'/calls.log'),
            $available ? implode(' ', array_map('escapeshellarg', $available)) : "''",
            escapeshellarg($json3),
            $exit));
        chmod($binary, 0755);
        config(['services.ytdlp.path' => $binary, 'services.ytdlp.cookies_from_browser' => '']);
    }

    /** @return list<string> the --sub-langs value of each yt-dlp call, in order */
    private function langCalls(): array
    {
        $log = $this->dir.'/calls.log';

        return file_exists($log) ? array_values(array_filter(explode("\n", file_get_contents($log)))) : [];
    }

    public function test_captions_found_never_asks_for_the_audio(): void
    {
        $this->fakeYtDlp(['id']);
        config(['services.groq.api_key' => 'gsk_test']);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT01', fn () => $this->fail('audio fetched although captions exist'));

        $this->assertSame('id', $result['language']);
        $this->assertSame('halo semua', $result['segments'][0]['text']);
        $this->assertSame(['id'], $this->langCalls());
    }

    public function test_english_is_requested_only_when_indonesian_is_missing(): void
    {
        $this->fakeYtDlp(['en']);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT04', fn () => $this->fail('audio fetched although captions exist'));

        $this->assertSame('en', $result['language']);
        $this->assertSame('halo semua', $result['segments'][0]['text']);
        $this->assertSame(['id', 'en'], $this->langCalls());
    }

    public function test_a_rate_limited_indonesian_request_falls_through_to_english(): void
    {
        $this->fakeYtDlp(['en'], exit: 1);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT05');

        $this->assertSame('en', $result['language']);
        $this->assertSame(['id', 'en'], $this->langCalls());
    }

    public function test_indonesian_file_wins_even_when_yt_dlp_exits_non_zero(): void
    {
        $this->fakeYtDlp(['id', 'en'], exit: 1);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT06');

        $this->assertSame('id', $result['language']);
        $this->assertSame(['id'], $this->langCalls());
    }

    public function test_no_groq_key_means_no_audio_download(): void
    {
        $this->fakeYtDlp([]);
        config(['services.groq.api_key' => '']);

        $result = (new TranscriptService)->fetch('https://youtu.be/fallbackT02', fn () => $this->fail('audio fetched with no Whisper to send it to'));

        $this->assertSame([], $result['segments']);
    }

    public function test_no_captions_asks_for_the_audio_once(): void
    {
        $this->fakeYtDlp([]);
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
