<?php

namespace Tests\Unit;

use App\Support\ViralityScore;
use PHPUnit\Framework\TestCase;

class ViralityScoreTest extends TestCase
{
    public function test_scores_are_rounded_and_clamped_to_the_storage_range(): void
    {
        $this->assertSame(8, ViralityScore::score(8));
        $this->assertSame(9, ViralityScore::score('8.6'));
        $this->assertSame(10, ViralityScore::score(11));
        $this->assertSame(0, ViralityScore::score(-1));
        $this->assertNull(ViralityScore::score(null));
        $this->assertNull(ViralityScore::score('kuat'));
    }

    public function test_reasons_are_mapped_to_the_canonical_allowed_values(): void
    {
        $this->assertSame(
            ['hook', 'flow', 'value'],
            ViralityScore::reasons(['value', 'pacing', 'HOOK', 'tidak relevan'])
        );
        $this->assertSame(['flow', 'value'], ViralityScore::reasons('flow; nilai'));
        $this->assertSame([], ViralityScore::reasons([]));
        $this->assertSame([], ViralityScore::reasons(['tidak relevan']));
        $this->assertNull(ViralityScore::reasons(null));
    }
}
