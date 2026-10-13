<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Support\EmojiCues;
use Tests\TestCase;

class EmojiCuesTest extends TestCase
{
    private function words(string $text, float $step = 0.5): array
    {
        return array_map(fn ($w, $i) => ['text' => $w, 'start' => $i * $step], explode(' ', $text), array_keys(explode(' ', $text)));
    }

    public function test_keywords_pick_emoji_with_a_gap_between_them(): void
    {
        // sukses @0.5, uangnya @1.5 (too close), takut @4.0, keren @4.5 (too close)
        $picks = EmojiCues::pick($this->words('mau sukses itu uangnya harus dikelola baik tapi takut keren'), 30);

        $this->assertSame([
            ['code' => '1f680', 'start' => 0.5],
            ['code' => '1f631', 'start' => 4.0],
        ], $picks);
    }

    public function test_stems_and_punctuation_match_but_lookalikes_do_not(): void
    {
        $codes = fn (string $text) => array_column(EmojiCues::pick($this->words($text, 4), 60), 'code');

        $this->assertSame(['1f4b0', '1f9e0'], $codes('Uangnya, belajarnya!'));
        $this->assertSame([], $codes('ideal kayak sayangi')); // not a keyword plus a known ending
    }

    public function test_nothing_starts_too_late_to_finish_and_at_most_six(): void
    {
        $many = implode(' ', array_fill(0, 20, 'uang keren lucu kaget'));
        $this->assertCount(6, EmojiCues::pick($this->words($many, 3), 600));

        $this->assertSame([], EmojiCues::pick([['text' => 'uang', 'start' => 9.5]], 10));
    }

    public function test_emoji_sit_above_a_low_subtitle_and_below_a_high_one(): void
    {
        $ff = new FFmpegService;
        $seg = [['start' => 0, 'end' => 5, 'text' => 'sukses', 'words' => [['text' => 'sukses', 'start' => 1, 'end' => 1.5]]]];

        $ff->buildAssFile($seg, ['position' => 'bottom', 'font_size' => 56]);
        $low = $ff->emojiOverlays(5)[0];
        $ff->buildAssFile($seg, ['position' => 'top', 'font_size' => 56]);
        $high = $ff->emojiOverlays(5)[0];

        $this->assertSame(1.0, $low['start']);
        $this->assertFileExists($low['image']);
        $this->assertLessThan(1920 - 200 - 28, $low['y'] + $low['size']); // ends above the subtitle
        $this->assertGreaterThan(60 + 56, $high['y']);                   // starts below the subtitle
    }

    public function test_every_emoji_has_an_image(): void
    {
        $codes = array_keys((new \ReflectionClassConstant(EmojiCues::class, 'KEYWORDS'))->getValue());

        foreach ($codes as $code) {
            $this->assertFileExists(EmojiCues::image($code));
        }
    }

    public function test_ffmpeg_burns_emoji_overlays(): void
    {
        $dir = sys_get_temp_dir().'/clipper-emoji-'.getmypid();
        @mkdir($dir);
        $src = $dir.'/src.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i color=c=gray:s=1080x1920:r=10:d=3 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')), escapeshellarg($src)), $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $ff = new FFmpegService;
        $ff->buildAssFile([['start' => 0, 'end' => 3, 'text' => 'sukses', 'words' => [['text' => 'sukses', 'start' => 0.5, 'end' => 1]]]], []);
        $ff->burnSubtitlesAndHook($src, null, null, 0.0, $dir.'/out.mp4', $ff->emojiOverlays(3));

        // A pixel inside the emoji differs from the grey background only while it shows
        $at = fn ($t) => md5((string) shell_exec(sprintf('ffmpeg -v error -ss %s -i %s -frames:v 1 -vf crop=40:40:520:%d -f rawvideo -pix_fmt gray -', $t, escapeshellarg($dir.'/out.mp4'), $ff->emojiOverlays(3)[0]['y'] + 85)));
        $grey = $at('0.2');
        $this->assertNotSame($grey, $at('1.0'));
        $this->assertSame($grey, $at('2.5'));
        exec('rm -rf '.escapeshellarg($dir));
    }
}
