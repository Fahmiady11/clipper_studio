<?php

namespace Tests\Unit;

use App\Support\InstructionDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstructionDurationTest extends TestCase
{
    public static function instructions(): array
    {
        return [
            'maks' => ['ambil bagian lucu, maks 30 detik', null, 30.0],
            'maksimal + dtk' => ['maksimal 45 dtk', null, 45.0],
            'max s' => ['max 20s', null, 20.0],
            'di bawah menit' => ['yang di bawah 1 menit saja', null, 60.0],
            'dibawah decimal comma' => ['dibawah 1,5 menit', null, 90.0],
            'tidak lebih dari' => ['tidak lebih dari 40 detik', null, 40.0],
            'kurang dari' => ['kurang dari 25 detik', null, 25.0],
            'minimal' => ['minimal 20 detik', 20.0, null],
            'paling sedikit' => ['paling sedikit 1 menit', 60.0, null],
            'range dash' => ['tips praktis 15-45 detik', 15.0, 45.0],
            'range en dash with units both sides' => ['15 detik – 45 detik', 15.0, 45.0],
            'range sampai' => ['durasinya 30 sampai 60 detik', 30.0, 60.0],
            'range minutes inherit unit' => ['1-2 menit', 60.0, 120.0],
            'antara' => ['antara 20 dan 40 detik', 20.0, 40.0],
            'min and max' => ['minimal 20 detik, maks 50 detik', 20.0, 50.0],
            'sekitar' => ['sekitar 30 detik', 24.0, 36.0],
            'english under' => ['funny parts under 1 minute', null, 60.0],
            'english at most' => ['at most 30 seconds', null, 30.0],
            'english at least' => ['at least 20 seconds', 20.0, null],
            'english between' => ['between 15 and 45 seconds', 15.0, 45.0],
            'english range to' => ['20 to 40 secs', 20.0, 40.0],
            'tightest max wins' => ['maks 1 menit, pokoknya di bawah 40 detik', null, 40.0],
            'too short max raised to a usable clip' => ['maks 2 detik', null, 5.0],
            'longer than allowed is capped' => ['minimal 10 menit', 300.0, null],
        ];
    }

    #[DataProvider('instructions')]
    public function test_parses_limits(string $text, ?float $min, ?float $max): void
    {
        $this->assertSame(['min' => $min, 'max' => $max], InstructionDuration::parse($text));
    }

    public function test_text_without_a_length_sets_nothing(): void
    {
        foreach ([null, '', 'ambil bagian tentang modal usaha', 'yang lucu-lucu', 'menit ke 5 yang seru', 'admin 30 orang', 'cover 30 lagu', '3 tips penting'] as $text) {
            $this->assertNull(InstructionDuration::parse($text), (string) $text);
        }
        // Contradicting limits: trust neither
        $this->assertNull(InstructionDuration::parse('minimal 60 detik maks 30 detik'));
    }

    public function test_label_and_prompt_range(): void
    {
        $this->assertSame('maks 30 detik', InstructionDuration::label(['min' => null, 'max' => 30.0]));
        $this->assertSame('15 detik sampai 1 menit', InstructionDuration::label(['min' => 15.0, 'max' => 60.0]));
        $this->assertSame('minimal 22,5 detik', InstructionDuration::label(['min' => 22.5, 'max' => null]));

        $this->assertSame([15, 30], InstructionDuration::promptRange(['min' => null, 'max' => 30.0], 30, 90));
        $this->assertSame([20, 90], InstructionDuration::promptRange(['min' => 20.0, 'max' => null], 30, 90));
        $this->assertSame([15, 45], InstructionDuration::promptRange(['min' => 15.0, 'max' => 45.0], 30, 90));
    }

    /** Sentences of four 1 s words with 0.2 s gaps, a 0.8 s pause between sentences. */
    private static function speech(float $from, int $sentences): array
    {
        $segments = [];
        $t = $from;
        for ($s = 0; $s < $sentences; $s++) {
            $words = [];
            foreach (['satu', 'dua', 'tiga', 'empat.'] as $w) {
                $words[] = ['start' => $t, 'end' => $t + 1.0, 'text' => $w];
                $t += 1.2;
            }
            $segments[] = ['start' => $words[0]['start'], 'end' => $words[3]['end'], 'text' => 'satu dua tiga empat.', 'words' => $words];
            $t += 0.6; // 0.2 already after the last word: a 0.8 s pause
        }

        return $segments;
    }

    public function test_too_long_clip_ends_on_the_last_sentence_that_fits(): void
    {
        $segments = self::speech(0.0, 10); // a sentence every 5.4 s, each 4.6 s of speech

        [$start, $end] = InstructionDuration::fit(0.0, 50.0, $segments, ['min' => null, 'max' => 20.0]);

        $this->assertSame(0.0, $start);
        // Sentence ends at 4.6, 10.0, 15.4, 20.8: the third is the last within 20 s, plus a breath
        $this->assertEqualsWithDelta(15.75, $end, 0.01);
    }

    public function test_too_long_without_a_sentence_end_cuts_at_a_word(): void
    {
        $words = [];
        for ($i = 0; $i < 40; $i++) {
            $words[] = ['start' => $i * 1.0, 'end' => $i * 1.0 + 0.8, 'text' => 'kata'];
        }
        $segments = [['start' => 0, 'end' => 40, 'text' => 'kata', 'words' => $words]];

        [, $end] = InstructionDuration::fit(0.0, 39.8, $segments, ['min' => null, 'max' => 10.0]);

        $this->assertEqualsWithDelta(10.0, $end, 0.01); // after the word ending at 9.8, never past the limit
    }

    public function test_too_short_clip_runs_on_to_the_next_sentence_end(): void
    {
        $segments = self::speech(0.0, 10);

        [$start, $end] = InstructionDuration::fit(0.0, 4.95, $segments, ['min' => 14.0, 'max' => 30.0]);

        $this->assertSame(0.0, $start);
        $this->assertEqualsWithDelta(15.75, $end, 0.01); // 15.4 is the first sentence end past 14 s
    }

    public function test_clip_that_already_fits_is_left_alone(): void
    {
        $segments = self::speech(0.0, 10);

        $this->assertSame([0.0, 21.15], InstructionDuration::fit(0.0, 21.15, $segments, ['min' => 15.0, 'max' => 30.0]));
    }

    public function test_clip_that_cannot_reach_the_minimum_is_dropped(): void
    {
        $segments = self::speech(0.0, 3); // speech ends at 15.4

        $this->assertNull(InstructionDuration::fit(10.0, 15.4, $segments, ['min' => 30.0, 'max' => null]));
        // Without word timing it may only stretch while the video lasts
        $this->assertSame([0.0, 30.0], InstructionDuration::fit(0.0, 12.0, [], ['min' => 30.0, 'max' => null], 600.0));
        $this->assertNull(InstructionDuration::fit(590.0, 598.0, [], ['min' => 30.0, 'max' => null], 600.0));
    }
}
