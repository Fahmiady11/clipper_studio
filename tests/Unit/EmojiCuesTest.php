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

    public function test_keyword_fallback_keeps_six_seconds_between_emoji(): void
    {
        // sukses @0.5, uangnya @1.5 and takut @4.0 too close, keren @7.0
        $words = [['text' => 'sukses', 'start' => 0.5], ['text' => 'uangnya', 'start' => 1.5], ['text' => 'takut', 'start' => 4.0], ['text' => 'keren', 'start' => 7.0]];

        $this->assertSame([
            ['code' => '1f680', 'start' => 0.5],
            ['code' => '1f525', 'start' => 7.0],
        ], EmojiCues::pick($words, 30));
    }

    public function test_stems_and_punctuation_match_but_lookalikes_and_everyday_words_do_not(): void
    {
        $codes = fn (string $text) => array_column(EmojiCues::pick($this->words($text, 8), 60), 'code');

        $this->assertSame(['1f4b0', '1f9e0'], $codes('Uangnya, belajarnya!'));
        $this->assertSame([], $codes('ideal kayak sayangi')); // not a keyword plus a known ending
        $this->assertSame([], $codes('kenapa waktu serius lihat')); // said all the time, so no emoji
    }

    public function test_one_emoji_per_twelve_seconds_at_most_four_never_repeated(): void
    {
        $many = implode(' ', array_fill(0, 20, 'uang keren lucu kaget'));
        $this->assertCount(4, EmojiCues::pick($this->words($many, 7), 600));
        $this->assertCount(2, EmojiCues::pick($this->words('uang keren lucu kaget', 7), 30));
        $this->assertCount(1, EmojiCues::pick($this->words('uang uang uang', 7), 60));

        $this->assertSame([], EmojiCues::pick([['text' => 'uang', 'start' => 9.5]], 10));
    }

    public function test_spacing_skips_the_hook_and_moments_covered_by_broll(): void
    {
        $emoji = [['code' => '1f602', 'start' => 2.0], ['code' => '1f525', 'start' => 10.0], ['code' => '1f4b0', 'start' => 20.0]];

        $this->assertSame([['code' => '1f4b0', 'start' => 20.0]], EmojiCues::space($emoji, 60, 4.0, [[9.5, 12.0]]));
    }

    public function test_ai_emoji_map_to_images_we_have(): void
    {
        $this->assertSame('1f602', EmojiCues::codeFor('😂'));
        $this->assertSame('2764', EmojiCues::codeFor("\u{2764}\u{FE0F}")); // with the emoji presentation selector
        $this->assertNull(EmojiCues::codeFor('🦩'));                      // no image
        $this->assertNull(EmojiCues::codeFor('😂😂'));
        $this->assertNull(EmojiCues::codeFor('lol'));

        $choices = EmojiCues::choices();
        $this->assertContains('🥊', $choices);
        foreach ($choices as $c) {
            $this->assertNotNull(EmojiCues::codeFor($c), $c);
        }
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
        $this->assertSame(179, $low['size']);                            // sized with the 56px font
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
