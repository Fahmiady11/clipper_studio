<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\TranscriptService;
use Tests\TestCase;

class SubtitleTimingTest extends TestCase
{
    private function dialogues(string $ass): array
    {
        preg_match_all('/^Dialogue: 1,([^,]+),([^,]+),Default,,0,0,0,,(.*)$/m', $ass, $m, PREG_SET_ORDER);

        // every line carries the same position tag; tests look at the rest
        return array_map(fn ($d) => ['start' => $d[1], 'end' => $d[2], 'text' => preg_replace('/^\{\\\\an5\\\\pos\(\d+,\d+\)\}/', '', $d[3])], $m);
    }

    private function callPrivate(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);

        return $ref->invokeArgs($obj, $args);
    }

    public function test_cues_follow_real_word_timestamps(): void
    {
        // Words with a long pause in the middle of one segment: an even split
        // would place "tiga" well before it is actually spoken.
        $segments = [[
            'start' => 100.0, 'end' => 108.0, 'text' => 'satu dua tiga empat',
            'words' => [
                ['start' => 100.0, 'end' => 100.4, 'text' => 'satu'],
                ['start' => 100.5, 'end' => 100.9, 'text' => 'dua'],
                ['start' => 106.0, 'end' => 106.4, 'text' => 'tiga'],
                ['start' => 106.5, 'end' => 107.0, 'text' => 'empat'],
            ],
        ]];

        $cues = $this->dialogues((new FFmpegService)->buildAssFile($segments, ['highlight_color' => '#facc15'], 100.0));

        // One event per word; the cue text stays, only the spoken word is coloured
        $hl = '{\1c&H15CCFA&}';
        $r = '{\r}';
        $this->assertSame([
            ['start' => '0:00:00.00', 'end' => '0:00:00.50', 'text' => "{$hl}satu{$r} dua"],
            ['start' => '0:00:00.50', 'end' => '0:00:01.15', 'text' => "satu {$hl}dua{$r}"],
            ['start' => '0:00:06.00', 'end' => '0:00:06.50', 'text' => "{$hl}tiga{$r} empat"],
            ['start' => '0:00:06.50', 'end' => '0:00:07.25', 'text' => "tiga {$hl}empat{$r}"],
        ], $cues);
    }

    public function test_words_before_clip_or_under_hook_are_dropped(): void
    {
        $segments = [[
            'start' => 9.0, 'end' => 13.0, 'text' => 'a b c',
            'words' => [
                ['start' => 9.0, 'end' => 9.5, 'text' => 'a'],   // before clip
                ['start' => 10.5, 'end' => 11.0, 'text' => 'b'], // under 2s hook
                ['start' => 12.5, 'end' => 13.0, 'text' => 'c'],
            ],
        ]];

        $cues = $this->dialogues((new FFmpegService)->buildAssFile($segments, [], 10.0, 2.0));

        $this->assertCount(1, $cues);
        $this->assertSame('0:00:02.50', $cues[0]['start']);
        $this->assertStringContainsString('c', $cues[0]['text']);
    }

    public function test_segments_without_words_still_render_proportionally(): void
    {
        $segments = [['start' => 0.0, 'end' => 2.0, 'text' => 'halo semua']];

        $cues = $this->dialogues((new FFmpegService)->buildAssFile($segments, []));

        $this->assertCount(2, $cues); // one event per word
        $this->assertSame('0:00:01.00', $cues[1]['start']);
        $this->assertSame('0:00:02.25', $cues[1]['end']); // held briefly, like timed cues
    }

    public function test_ass_time_never_rolls_centiseconds_to_100(): void
    {
        $this->assertSame('0:00:02.00', $this->callPrivate(new FFmpegService, 'toAssTime', [1.996]));
        $this->assertSame('0:01:00.00', $this->callPrivate(new FFmpegService, 'toAssTime', [59.999]));
    }

    public function test_json3_asr_captions_keep_word_offsets(): void
    {
        $json3 = ['events' => [
            ['tStartMs' => 1000, 'dDurationMs' => 4000, 'segs' => [
                ['utf8' => 'halo'],
                ['utf8' => ' teman', 'tOffsetMs' => 400],
                ['utf8' => ' semua', 'tOffsetMs' => 3000],
            ]],
            ['tStartMs' => 3500, 'dDurationMs' => 10, 'aAppend' => 1, 'segs' => [['utf8' => "\n"]]],
            ['tStartMs' => 4200, 'dDurationMs' => 3000, 'segs' => [
                ['utf8' => 'apa'],
                ['utf8' => ' kabar', 'tOffsetMs' => 300],
            ]],
        ]];

        $segments = $this->callPrivate(new TranscriptService, 'parseJson3', [$json3]);

        $this->assertCount(2, $segments);
        $this->assertSame('halo teman semua', $segments[0]['text']);
        $words = $segments[0]['words'];
        $this->assertSame([1.0, 1.4, 4.0], array_column($words, 'start'));
        $this->assertSame(1.4, $words[0]['end']);  // ends when next word starts
        $this->assertSame(2.6, $words[1]['end']);  // capped: no lingering over a pause
        $this->assertSame(4.2, $words[2]['end']);  // ends at next event's first word
        $this->assertSame([4.2, 4.5], array_column($segments[1]['words'], 'start'));
    }

    public function test_json3_manual_captions_have_no_word_timing(): void
    {
        $json3 = ['events' => [
            ['tStartMs' => 0, 'dDurationMs' => 2000, 'segs' => [['utf8' => 'kalimat manual lengkap']]],
        ]];

        $segments = $this->callPrivate(new TranscriptService, 'parseJson3', [$json3]);

        $this->assertArrayNotHasKey('words', $segments[0]);
    }

    public function test_groq_words_attach_to_their_segments(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'satu dua'],
            ['start' => 2.0, 'end' => 4.0, 'text' => 'tiga'],
        ];
        $words = [
            ['word' => 'satu', 'start' => 0.1, 'end' => 0.5],
            ['word' => 'dua', 'start' => 0.6, 'end' => 1.0],
            ['word' => 'tiga', 'start' => 2.2, 'end' => 2.6],
        ];

        $out = $this->callPrivate(new TranscriptService, 'attachGroqWords', [$segments, $words]);

        $this->assertSame(['satu', 'dua'], array_column($out[0]['words'], 'text'));
        $this->assertSame(['tiga'], array_column($out[1]['words'], 'text'));
    }
}
