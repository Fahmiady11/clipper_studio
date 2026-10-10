<?php

namespace Tests\Unit;

use App\Support\HookText;
use PHPUnit\Framework\TestCase;

class HookTextTest extends TestCase
{
    public function test_strips_emoji_quotes_and_trailing_full_stop(): void
    {
        $this->assertSame('Berhenti jadi *korban*', HookText::clean('“Berhenti jadi *korban* 🔥.”'));
        $this->assertSame('Kenapa kamu susah kaya?', HookText::clean('  Kenapa   kamu susah kaya?  '));
        $this->assertNull(HookText::clean('🔥🔥'));
    }

    public function test_short_ai_hooks_are_left_alone(): void
    {
        $this->assertSame('Gaji 50 juta, tetap *bokek*', HookText::clean('Gaji 50 juta, tetap *bokek*', shorten: true));
    }

    public function test_long_ai_hook_is_cut_at_a_clause_not_mid_word(): void
    {
        $long = 'Kakek ini menikah 60 tahun, tapi kenapa masih terlihat sangat mencintai? Rahasianya: dia mencintai w';
        $out = HookText::clean($long, shorten: true);

        $this->assertSame('Kakek ini menikah 60 tahun…', $out);
        $this->assertLessThanOrEqual(HookText::AI_MAX_CHARS + 1, mb_strlen($out));
    }

    public function test_ai_hooks_are_capped_at_five_words(): void
    {
        $out = HookText::clean('Ini alasan kamu selalu gagal diet', shorten: true);

        $this->assertSame('Ini alasan kamu selalu gagal…', $out);
        $this->assertSame(5, HookText::wordCount($out));
        // Never ends on a split name or a dangling preposition
        $this->assertSame('Tour paling *aneh*…', HookText::clean('Tour paling *aneh* di New York', shorten: true));
        // A typed (manual) hook is the user's call and is not shortened
        $this->assertSame('Ini alasan kamu selalu gagal diet', HookText::clean('Ini alasan kamu selalu gagal diet'));
    }

    public function test_long_hook_without_clause_breaks_cuts_at_a_word(): void
    {
        $out = HookText::clean('Banyak ibu merasa bersalah saat me time padahal itu bukan mengabaikan anak sama sekali', shorten: true);

        $this->assertStringEndsWith('…', $out);
        $this->assertStringStartsWith('Banyak ibu merasa bersalah', $out);
        $this->assertLessThanOrEqual(HookText::AI_MAX_CHARS + 1, mb_strlen($out));
    }

    public function test_cut_closes_an_open_emphasis(): void
    {
        $out = HookText::clean('Ini *rahasia orang sukses yang jarang dibahas di sekolah maupun kampus mana*', shorten: true);

        $this->assertSame(0, substr_count($out, '*') % 2);
    }

    public function test_tokens_flag_emphasised_words(): void
    {
        $this->assertSame(
            [['Rahasia', false], ['nikah', false], ['60', true], ['tahun', true], ['bahagia', false]],
            HookText::tokens('Rahasia nikah *60 tahun* bahagia')
        );
        $this->assertSame([['Gaji', false], ['bokek!', true]], HookText::tokens('Gaji *bokek*!'));
        $this->assertSame('Gaji bokek!', HookText::plain('Gaji *bokek*!'));
    }
}
