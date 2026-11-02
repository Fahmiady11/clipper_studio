<?php

namespace Tests\Unit;

use App\Support\BrollCues;
use App\Support\EditPlan;
use Tests\TestCase;

class EditPlanTest extends TestCase
{
    /** Words of "text" said back to back from $at, 0.4s each */
    private function words(string $text, float $at): array
    {
        return array_map(fn ($w, $i) => ['text' => $w, 'start' => $at + $i * 0.4, 'end' => $at + $i * 0.4 + 0.35],
            explode(' ', $text), array_keys(explode(' ', $text)));
    }

    private function phrases(): array
    {
        return EditPlan::phrases(array_merge(
            $this->words('Pertama kali gue ke gym boxing.', 100.0),            // #0 100.0-102.35
            $this->words('Pelatihnya langsung nyuruh sparing.', 110.0),       // #1 110.0-111.55
            $this->words('Gue dipukul sampai jatuh ngakak semua orang.', 120.0), // #2 120.0-122.75
        ));
    }

    public function test_speech_splits_into_phrases_at_sentence_ends_pauses_and_length(): void
    {
        $phrases = EditPlan::phrases(array_merge(
            $this->words('satu dua tiga', 0.0),          // a pause follows
            $this->words('empat lima.', 2.0),            // a sentence end
            $this->words('a b c d e f g h i j k l', 4.0) // ten words max
        ));

        $this->assertSame(['satu dua tiga', 'empat lima.', 'a b c d e f g h i j', 'k l'], array_column($phrases, 'text'));
        $this->assertSame([0.0, 1.15], [$phrases[0]['start'], $phrases[0]['end']]);
        $this->assertSame("#0 [0.0] satu dua tiga\n#1 [2.0] empat lima.", implode("\n", array_slice(explode("\n", EditPlan::transcript($phrases, 0.0)), 0, 2)));
    }

    public function test_the_ai_answer_becomes_source_times_anchored_on_the_named_word(): void
    {
        $plan = EditPlan::fromAi([
            'broll' => [
                ['phrase' => 0, 'word' => 'gym', 'query' => 'boxing gym training', 'alt' => 'boxing', 'must' => ['boxing', '', 3]],
                ['phrase' => 9, 'query' => 'no such phrase'],
                ['phrase' => 1, 'query' => '  '],
            ],
            'emoji' => [
                ['phrase' => 2, 'word' => 'Ngakak', 'emoji' => '😂'],
                ['phrase' => 1, 'word' => 'nyuruh', 'emoji' => '🦩'], // no image for it
            ],
            'punchlines' => [2, 'x', 7],
        ], $this->phrases(), 95.0, 130.0);

        $this->assertSame('95.00-130.00', $plan['key']);
        $this->assertSame([['time' => 101.6, 'end' => 102.55, 'query' => 'boxing gym training', 'alt' => 'boxing', 'must' => ['boxing']]], $plan['broll']);
        $this->assertSame([['code' => '1f602', 'time' => 121.6]], $plan['emoji']);
        $this->assertSame([['start' => 120.0, 'end' => 122.75]], $plan['punch']);
    }

    public function test_a_word_the_ai_misspells_falls_back_to_the_phrase_start(): void
    {
        $plan = EditPlan::fromAi(['emoji' => [['phrase' => 1, 'word' => 'xyz', 'emoji' => '🔥']]], $this->phrases(), 95.0, 130.0);

        $this->assertSame(110.0, $plan['emoji'][0]['time']);
    }

    public function test_on_the_timeline_broll_avoids_punchlines_and_emoji_avoid_broll(): void
    {
        $plan = [
            'broll' => [
                ['time' => 101.6, 'end' => 102.55, 'query' => 'boxing gym', 'alt' => '', 'must' => ['boxing']],
                ['time' => 120.5, 'end' => 122.0, 'query' => 'man falling', 'alt' => ''], // over the punchline
            ],
            'emoji' => [
                ['code' => '1f94a', 'time' => 102.0], // while the B-roll shows
                ['code' => '1f602', 'time' => 122.0],
                ['code' => '1f525', 'time' => 96.0],  // under the hook
            ],
            'punch' => [['start' => 120.0, 'end' => 122.75]],
        ];
        // clip 95..135; 4s cut out at clip time 10..14 (source 105..109)
        $pieces = [[0.0, 10.0], [14.0, 40.0]];

        $moments = EditPlan::onTimeline($plan, $pieces, 95.0, 36.0, 4.0);

        $this->assertSame([['time' => 6.6, 'duration' => BrollCues::MIN_DURATION, 'query' => 'boxing gym', 'alt' => '', 'must' => ['boxing']]], $moments['broll']);
        $this->assertSame([['code' => '1f602', 'start' => 23.0]], $moments['emoji']);
        $this->assertSame([23.75], $moments['punch']);
    }

    public function test_moments_before_a_trimmed_start_drop_out(): void
    {
        $plan = ['broll' => [['time' => 101.6, 'end' => 102.5, 'query' => 'gym', 'alt' => '']], 'emoji' => [['code' => '1f602', 'time' => 101.0]], 'punch' => []];

        $moments = EditPlan::onTimeline($plan, [[0.0, 30.0]], 105.0, 30.0, 0.0);

        $this->assertSame(['broll' => [], 'emoji' => [], 'punch' => []], $moments);
    }
}
