<?php

namespace App\Support;

/**
 * Picks emoji to pop in over a clip from the words being said (Indonesian and
 * English keywords). Images are Noto Emoji PNGs in resources/emoji, named by
 * code point.
 */
final class EmojiCues
{
    public const SHOW_FOR = 1.0; // seconds an emoji stays on screen

    private const MIN_GAP = 3.0; // seconds between two emoji

    private const MAX_PER_CLIP = 6;

    /** Endings a keyword may carry: Indonesian -nya/-mu/-ku/-kan/-lah/-an/-in, English plural */
    private const SUFFIXES = ['nya', 'mu', 'ku', 'kan', 'lah', 'an', 'in', 's'];

    /** code point => keywords; a word matches exactly, or as a 4+ letter keyword plus a SUFFIX */
    private const KEYWORDS = [
        '1f4b0' => ['uang', 'duit', 'gaji', 'cuan', 'kaya', 'miliar', 'milyar', 'juta', 'rupiah', 'dollar', 'money', 'cash', 'salary', 'rich', 'million', 'billion'],
        '1f525' => ['keren', 'mantap', 'mantul', 'gila', 'panas', 'viral', 'fire', 'hot', 'amazing', 'insane'],
        '1f602' => ['lucu', 'ketawa', 'ngakak', 'haha', 'hahaha', 'wkwk', 'wkwkwk', 'kocak', 'funny', 'lol', 'joke'],
        '1f631' => ['kaget', 'takut', 'serem', 'seram', 'ngeri', 'horor', 'shock', 'shocked', 'scary', 'scared'],
        '2764' => ['cinta', 'sayang', 'pacar', 'nikah', 'menikah', 'istri', 'suami', 'love', 'married', 'wife', 'husband'],
        '1f914' => ['kenapa', 'mikir', 'bingung', 'mengapa', 'gimana', 'bagaimana', 'why', 'think', 'confused', 'wonder'],
        '1f4a1' => ['ide', 'tips', 'rahasia', 'trik', 'solusi', 'idea', 'ideas', 'secret', 'trick', 'hack', 'solution'],
        '1f680' => ['sukses', 'berhasil', 'meledak', 'launch', 'success', 'successful', 'boom', 'skyrocket'],
        '1f4c8' => ['bisnis', 'investasi', 'saham', 'profit', 'untung', 'omzet', 'omset', 'business', 'invest', 'investing', 'stock', 'stocks', 'revenue', 'growth'],
        '1f4aa' => ['kuat', 'semangat', 'olahraga', 'gym', 'disiplin', 'strong', 'workout', 'discipline', 'motivation', 'motivasi'],
        '23f0' => ['waktu', 'pagi', 'subuh', 'telat', 'deadline', 'time', 'morning', 'late'],
        '1f3af' => ['target', 'fokus', 'tujuan', 'goal', 'goals', 'focus', 'mimpi', 'dream'],
        '274c' => ['salah', 'gagal', 'stop', 'wrong', 'never', 'mistake', 'failed', 'fail'],
        '2705' => ['benar', 'betul', 'setuju', 'correct', 'agree'],
        '1f9e0' => ['otak', 'pintar', 'cerdas', 'belajar', 'mental', 'brain', 'smart', 'learn', 'learning', 'genius'],
        '1f440' => ['lihat', 'liat', 'perhatiin', 'perhatikan', 'nonton', 'look', 'watch'],
        '1f62d' => ['sedih', 'nangis', 'menangis', 'patah', 'cry', 'crying', 'sad', 'heartbreak'],
        '1f354' => ['makan', 'makanan', 'lapar', 'kuliner', 'food', 'eat', 'eating', 'hungry'],
        '1f4f1' => ['hp', 'handphone', 'aplikasi', 'tiktok', 'instagram', 'youtube', 'phone', 'app', 'apps'],
        '1f3e0' => ['rumah', 'keluarga', 'home', 'house', 'family'],
        '2708' => ['liburan', 'jalan-jalan', 'traveling', 'travel', 'vacation', 'holiday', 'trip'],
        '1f389' => ['selamat', 'pesta', 'menang', 'party', 'congrats', 'celebrate', 'winner', 'won'],
        '1f451' => ['raja', 'juara', 'terbaik', 'king', 'queen', 'champion', 'best'],
        '26a0' => ['hati-hati', 'bahaya', 'awas', 'waspada', 'warning', 'danger', 'careful'],
        '1f4af' => ['100', 'serius', 'beneran', 'sumpah', 'pasti', 'seriously', 'literally', 'definitely'],
    ];

    /**
     * @param  array<int, array{text: string, start: float}>  $words  clip-relative, in order
     * @return list<array{code: string, start: float}>
     */
    public static function pick(array $words, float $clipDuration): array
    {
        $index = self::index();
        $out = [];
        $last = -INF;
        $used = [];
        foreach ($words as $w) {
            $start = (float) $w['start'];
            if (count($out) >= self::MAX_PER_CLIP || $start + self::SHOW_FOR > $clipDuration) {
                break;
            }
            if ($start - $last < self::MIN_GAP) {
                continue;
            }
            $code = self::match(mb_strtolower(trim((string) $w['text'], " \t\n.,!?;:\"'()…")), $index);
            // The same emoji twice in a row looks like a glitch; skip the repeat
            if ($code === null || ($used[$code] ?? -INF) > $start - 10) {
                continue;
            }
            $out[] = ['code' => $code, 'start' => $start];
            $used[$code] = $start;
            $last = $start;
        }

        return $out;
    }

    public static function image(string $code): string
    {
        return resource_path('emoji/'.$code.'.png');
    }

    /** @return array<string, string> keyword => code point */
    private static function index(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::KEYWORDS as $code => $words) {
                foreach ($words as $k) {
                    $index[$k] ??= $code;
                }
            }
        }

        return $index;
    }

    private static function match(string $word, array $index): ?string
    {
        if ($word === '') {
            return null;
        }
        if (isset($index[$word])) {
            return $index[$word];
        }
        // "uangnya", "belajarnya", "kenapain" — but not "kayak" (kaya) or "ideal" (idea)
        foreach (self::SUFFIXES as $suffix) {
            $stem = mb_substr($word, 0, -mb_strlen($suffix));
            if (str_ends_with($word, $suffix) && mb_strlen($stem) >= 4 && isset($index[$stem])) {
                return $index[$stem];
            }
        }

        return null;
    }
}
