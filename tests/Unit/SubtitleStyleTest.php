<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Support\ClipSettings;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SubtitleStyleTest extends TestCase
{
    private const HL = '{\1c&H15CCFA&}';

    private function texts(string $style, string $words = 'kecil setiap hari 180 derajat', int $size = 42): array
    {
        $timed = [];
        foreach (explode(' ', $words) as $i => $w) {
            $timed[] = ['start' => $i * 0.5, 'end' => $i * 0.5 + 0.45, 'text' => $w];
        }
        $ass = (new FFmpegService)->buildAssFile(
            [['start' => 0, 'end' => 3, 'text' => $words, 'words' => $timed]],
            ['highlight_color' => '#facc15', 'background_style' => 'none', 'style' => $style, 'font_size' => $size],
        );
        preg_match_all('/^Dialogue: 1,[^,]+,[^,]+,Default,,0,0,0,,(.*)$/m', $ass, $m);

        return $m[1];
    }

    public function test_karaoke_keeps_case_and_only_colours_the_spoken_word(): void
    {
        $texts = $this->texts('karaoke', 'satu dua');

        $this->assertSame(['{\1c&H15CCFA&}satu{\r} dua', 'satu {\1c&H15CCFA&}dua{\r}'], $texts);
    }

    public function test_pop_is_uppercase_and_grows_the_spoken_word(): void
    {
        $texts = $this->texts('pop', 'satu dua');

        $this->assertSame([
            self::HL.'{\t(0,90,\fscx115\fscy115)}SATU{\r} DUA',
            'SATU '.self::HL.'{\t(0,90,\fscx115\fscy115)}DUA{\r}',
        ], $texts);
    }

    public function test_word_shows_one_word_at_a_time_and_highlights_numbers_and_long_words(): void
    {
        $texts = $this->texts('word');
        $pop = '{\fscx70\fscy70\t(0,110,\fscx100\fscy100)}';

        $this->assertSame([
            $pop.'KECIL',
            $pop.'SETIAP',
            $pop.'HARI',
            $pop.self::HL.'180',
            $pop.self::HL.'DERAJAT',
        ], $texts);
    }

    public function test_long_line_without_box_is_shrunk_to_fit(): void
    {
        $texts = $this->texts('pop', 'pengembangan kepribadian menyeluruh sekarang', 70);

        $this->assertMatchesRegularExpression('/^\{\\\\fscx(\d+)\\\\fscy\1\}/', $texts[0]);
        preg_match('/fscx(\d+)/', $texts[0], $m);
        $this->assertLessThan(100, (int) $m[1]);
    }

    public function test_unknown_style_is_rejected_and_defaults_to_karaoke(): void
    {
        $rules = ClipSettings::rules();

        $this->assertTrue(Validator::make(['subtitle' => ['style' => 'word']], $rules)->passes());
        $this->assertFalse(Validator::make(['subtitle' => ['style' => 'bounce']], $rules)->passes());
        $this->assertSame('karaoke', ClipSettings::normalize([])['subtitle']['style']);
    }
}
