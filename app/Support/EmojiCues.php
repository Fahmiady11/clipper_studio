<?php

namespace App\Support;

/**
 * Emoji that pop in over a clip. The edit plan (EditPlan) picks them from what
 * a phrase means; when no AI answers, pick() falls back to keywords
 * (Indonesian and English). Either way they are spaced by space(). Images are
 * Noto Emoji PNGs in resources/emoji, named by code point.
 */
final class EmojiCues
{
    public const SHOW_FOR = 1.2; // seconds an emoji stays on screen

    private const MIN_GAP = 6.0; // seconds between two emoji

    /** Endings a keyword may carry: Indonesian -nya/-mu/-ku/-kan/-lah/-an/-in, English plural */
    private const SUFFIXES = ['nya', 'mu', 'ku', 'kan', 'lah', 'an', 'in', 's'];

    /** code point => keywords; a word matches exactly, or as a 4+ letter keyword plus a SUFFIX. Words said all the time ("kenapa", "waktu", "serius") are left out. */
    private const KEYWORDS = [
        '1f4b0' => ['uang', 'duit', 'gaji', 'cuan', 'kaya', 'miliar', 'milyar', 'juta', 'rupiah', 'dollar', 'money', 'cash', 'salary', 'rich', 'million', 'billion'],
        '1f525' => ['keren', 'mantap', 'mantul', 'gila', 'panas', 'viral', 'fire', 'hot', 'amazing', 'insane'],
        '1f602' => ['lucu', 'ketawa', 'ngakak', 'haha', 'hahaha', 'wkwk', 'wkwkwk', 'kocak', 'funny', 'lol', 'joke'],
        '1f631' => ['kaget', 'takut', 'serem', 'seram', 'ngeri', 'horor', 'shock', 'shocked', 'scary', 'scared'],
        '2764' => ['cinta', 'sayang', 'pacar', 'nikah', 'menikah', 'istri', 'suami', 'love', 'married', 'wife', 'husband'],
        '1f914' => ['mikir', 'bingung', 'confused', 'wonder'],
        '1f4a1' => ['ide', 'tips', 'rahasia', 'trik', 'solusi', 'idea', 'ideas', 'secret', 'trick', 'hack', 'solution'],
        '1f680' => ['sukses', 'berhasil', 'meledak', 'launch', 'success', 'successful', 'boom', 'skyrocket'],
        '1f4c8' => ['bisnis', 'investasi', 'saham', 'profit', 'untung', 'omzet', 'omset', 'business', 'invest', 'investing', 'stock', 'stocks', 'revenue', 'growth'],
        '1f4aa' => ['kuat', 'semangat', 'olahraga', 'gym', 'disiplin', 'strong', 'workout', 'discipline', 'motivation', 'motivasi'],
        '23f0' => ['subuh', 'telat', 'deadline', 'late'],
        '1f3af' => ['target', 'fokus', 'tujuan', 'goal', 'goals', 'focus', 'mimpi', 'dream'],
        '274c' => ['salah', 'gagal', 'stop', 'wrong', 'never', 'mistake', 'failed', 'fail'],
        '2705' => ['benar', 'betul', 'setuju', 'correct', 'agree'],
        '1f9e0' => ['otak', 'pintar', 'cerdas', 'belajar', 'mental', 'brain', 'smart', 'learn', 'learning', 'genius'],
        '1f440' => ['perhatiin', 'perhatikan'],
        '1f62d' => ['sedih', 'nangis', 'menangis', 'patah', 'cry', 'crying', 'sad', 'heartbreak'],
        '1f354' => ['makan', 'makanan', 'lapar', 'kuliner', 'food', 'eat', 'eating', 'hungry'],
        '1f4f1' => ['hp', 'handphone', 'aplikasi', 'tiktok', 'instagram', 'youtube', 'phone', 'app', 'apps'],
        '1f3e0' => ['rumah', 'keluarga', 'home', 'house', 'family'],
        '2708' => ['liburan', 'jalan-jalan', 'traveling', 'travel', 'vacation', 'holiday', 'trip'],
        '1f389' => ['selamat', 'pesta', 'menang', 'party', 'congrats', 'celebrate', 'winner', 'won'],
        '1f451' => ['raja', 'juara', 'terbaik', 'king', 'queen', 'champion', 'best'],
        '26a0' => ['hati-hati', 'bahaya', 'awas', 'waspada', 'warning', 'danger', 'careful'],
        '1f4af' => ['100', 'sumpah'],
    ];

    /**
     * @param  array<int, array{text: string, start: float}>  $words  clip-relative, in order
     * @return list<array{code: string, start: float}>
     */
    public static function pick(array $words, float $clipDuration): array
    {
        $index = self::index();
        $found = [];
        foreach ($words as $w) {
            $code = self::match(mb_strtolower(trim((string) $w['text'], " \t\n.,!?;:\"'()…")), $index);
            if ($code !== null) {
                $found[] = ['code' => $code, 'start' => (float) $w['start']];
            }
        }

        return self::space($found, $clipDuration);
    }

    /** One emoji per 12s of clip, at most four */
    public static function max(float $duration): int
    {
        return max(1, min(4, (int) floor($duration / 12)));
    }

    /**
     * Keeps emoji that can finish inside the clip, after $notBefore and outside
     * the $covered spans (B-roll), MIN_GAP apart and never the same one twice.
     *
     * @param  list<array{code: string, start: float}>  $emoji
     * @param  list<array{0: float, 1: float}>  $covered
     * @return list<array{code: string, start: float}>
     */
    public static function space(array $emoji, float $clipDuration, float $notBefore = 0.0, array $covered = []): array
    {
        usort($emoji, fn ($a, $b) => $a['start'] <=> $b['start']);
        $out = [];
        foreach ($emoji as $e) {
            $start = round((float) $e['start'], 3);
            if (count($out) >= self::max($clipDuration)) {
                break;
            }
            if ($start < $notBefore || $start + self::SHOW_FOR > $clipDuration
                || ($out && $start - end($out)['start'] < self::MIN_GAP)
                || in_array($e['code'], array_column($out, 'code'), true)) {
                continue;
            }
            foreach ($covered as [$a, $b]) {
                if ($start + self::SHOW_FOR > $a && $start < $b) {
                    continue 2;
                }
            }
            $out[] = ['code' => $e['code'], 'start' => $start];
        }

        return $out;
    }

    /** Code point of a single emoji we have an image for ("😂" -> "1f602"), else null. */
    public static function codeFor(string $emoji): ?string
    {
        $chars = mb_str_split(str_replace("\u{FE0F}", '', trim($emoji)));
        if (count($chars) !== 1) {
            return null;
        }
        $code = dechex(mb_ord($chars[0]));

        return is_file(self::image($code)) ? $code : null;
    }

    /** @return list<string> every emoji with an image, as characters */
    public static function choices(): array
    {
        return array_map(fn ($f) => mb_chr(hexdec(basename($f, '.png'))), glob(resource_path('emoji/*.png')));
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
