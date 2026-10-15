<?php

namespace App\Services;

use App\Support\Cancellation;
use App\Support\ClipSettings;
use App\Support\EmojiCues;
use App\Support\FontMetrics;
use RuntimeException;

class FFmpegService
{
    private const MAX_CUE_WORDS = 4;    // words per subtitle cue (TikTok-style short lines)

    private const CUE_BREAK_GAP = 0.6;  // seconds of silence that starts a new cue

    private const CUE_HOLD = 0.25; // seconds a cue lingers after its last word

    private const MAX_SPREAD_WORD = 1.0; // seconds an evenly spread (untimed) word may last

    private const VOICE_LUFS = -14; // speech loudness after mixing music (TikTok/Reels level)

    /**
     * The layout pass only feeds the subtitle pass, so it encodes fast at near
     * transparent quality; the final pass spends the effort. 60fps sources are
     * capped at 30 (what TikTok plays): half the frames to filter and encode.
     */
    private const ENCODE_INTERMEDIATE = '-fpsmax 30 -c:v libx264 -preset veryfast -crf 14 -pix_fmt yuv420p';

    private const ENCODE_FINAL = '-fpsmax 30 -c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p';

    private string $bin;

    private string $posTag = ''; // ASS override placing every subtitle line (set by buildAssFile)

    /** @var array{alpha: string, font: string, size: int, cy: float}|null rounded background box (set by buildAssFile) */
    private ?array $box = null;

    /** karaoke | pop | word (set by buildAssFile) */
    private string $style = 'karaoke';

    private int $cueWords = self::MAX_CUE_WORDS;

    /** @var array{path: string, size: int, outline: int} font used to measure cues so they fit the frame */
    private array $font = ['path' => '', 'size' => 42, 'outline' => 0];

    /** Vertical centre of the subtitle line in the 1920px frame (set by buildAssFile) */
    private float $subtitleY = 1920 - 200;

    /** @var array<int, array{text: string, start: float}> clip-relative words of the last ASS built */
    private array $cueWordList = [];

    private VideoLayoutService $layout;

    public function __construct()
    {
        $this->bin = config('services.ffmpeg.path', 'ffmpeg');
        $this->layout = new VideoLayoutService;
    }

    public function cut(string $input, float $start, float $end, string $output): void
    {
        $duration = $end - $start;
        $cmd = sprintf(
            '%s -y -ss %s -i %s -t %s -c copy -avoid_negative_ts make_zero %s 2>&1',
            $this->bin,
            escapeshellarg((string) $start),
            escapeshellarg($input),
            escapeshellarg((string) $duration),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'cut');
    }

    /**
     * @param  float|null  $start  When given (with $end), trim the input in the same
     *                             re-encode pass. Seeking while transcoding is
     *                             frame-accurate, unlike cut()'s stream copy which
     *                             snaps back to the previous keyframe and shifts
     *                             the clip's t=0 away from $start (subtitle drift).
     * @param  array|null  $track  FaceTracker plan: the reframe crop follows faces
     * @param  array|null  $pieces  SmartCut pieces to keep (clip-relative); the rest is cut out
     * @param  array  $zoom  SmartCut spans (on the cut timeline) shown punched in
     * @param  array{ass?: ?string, hook?: ?array, hook_duration?: float, emojis?: array}|null  $overlay
     *                                                                                                    Subtitles, hook and emoji drawn in the same pass,
     *                                                                                                    which then is the final encode (one generation, no second pass)
     */
    public function applyLayout(string $input, string $layoutType, string $output, ?float $start = null, ?float $end = null, ?array $track = null, ?array $pieces = null, array $zoom = [], ?array $overlay = null): void
    {
        // Map new layout IDs to VideoLayoutService's filter method
        $layoutMap = [
            'reframe' => 'auto_reframe',
            'gaussian' => 'gaussian_blur',
        ];

        $mode = $layoutMap[$layoutType] ?? 'gaussian_blur';
        $filterGraph = $this->layout->layoutFilter($mode, '[lay]', $track);
        // Drop to 30fps before the per-frame crop/scale/sharpen, not after it
        if ($this->probeFps($input) > 30.5) {
            $filterGraph = preg_replace('/^\[0:v\]/', '[0:v]fps=30,', $filterGraph);
        }
        $audio = $this->hasAudio($input);
        [$filterGraph, $video, $audioOut] = $this->jumpCuts($filterGraph, '[lay]', $audio ? '[0:a]' : null, $pieces ?? []);
        if ($zoom) {
            $filterGraph .= ';'.$this->punchZoom($video, $zoom, '[zoomed]');
            $video = '[zoomed]';
        }
        $extraInputs = '';
        if ($overlay !== null) {
            [$extraInputs, $chain] = $this->overlayChain($video, '[out]', 1, $overlay['ass'] ?? null,
                $overlay['hook'] ?? null, (float) ($overlay['hook_duration'] ?? 0), $overlay['emojis'] ?? []);
            $filterGraph .= ';'.$chain;
            $video = '[out]';
        }

        // -ss/-t before -i: they bound the source, not the hook/emoji inputs that follow
        $trim = '';
        if ($start !== null && $end !== null) {
            $trim = ' -ss '.escapeshellarg(sprintf('%.3f', $start)).' -t '.escapeshellarg(sprintf('%.3f', $end - $start));
        }

        $cmd = sprintf(
            '%s -y%s -i %s%s -filter_complex %s -map %s%s '.($overlay !== null ? self::ENCODE_FINAL : self::ENCODE_INTERMEDIATE).' -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
            $this->bin,
            $trim,
            escapeshellarg($input),
            $extraInputs,
            escapeshellarg($filterGraph),
            escapeshellarg($video),
            ' -map '.escapeshellarg($audioOut ?? '0:a?'),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'applyLayout');
    }

    /**
     * Keep only $pieces of the laid-out video (and its audio), joined back to
     * back. Each piece is trimmed from the same stream and concatenated, so
     * audio and picture restart together at every cut and never drift apart.
     *
     * @return array{0: string, 1: string, 2: string|null} [filter graph, video label, audio label]
     */
    private function jumpCuts(string $graph, string $video, ?string $audio, array $pieces): array
    {
        $n = count($pieces);
        if ($n < 2) {
            return [$graph, $video, null]; // audio is mapped straight from the input
        }

        $vSplit = implode('', array_map(fn ($i) => "[jv{$i}]", range(0, $n - 1)));
        $parts = [$graph, "{$video}split={$n}{$vSplit}"];
        if ($audio) {
            $parts[] = "{$audio}asplit={$n}".implode('', array_map(fn ($i) => "[ja{$i}]", range(0, $n - 1)));
        }
        $concatIn = '';
        foreach (array_values($pieces) as $i => [$a, $b]) {
            $range = sprintf('start=%.3f:end=%.3f', $a, $b);
            $parts[] = "[jv{$i}]trim={$range},setpts=PTS-STARTPTS[cv{$i}]";
            $concatIn .= "[cv{$i}]";
            if ($audio) {
                $parts[] = "[ja{$i}]atrim={$range},asetpts=PTS-STARTPTS[ca{$i}]";
                $concatIn .= "[ca{$i}]";
            }
        }
        $parts[] = $concatIn.sprintf('concat=n=%d:v=1:a=%d', $n, $audio ? 1 : 0).($audio ? '[cutv][cuta]' : '[cutv]');

        return [implode(';', $parts), '[cutv]', $audio ? '[cuta]' : null];
    }

    /** Show a punched-in copy of the frame (zoomed toward the upper middle, where faces sit) during $spans. */
    private function punchZoom(string $video, array $spans, string $label): string
    {
        $w = (int) round(1080 * SmartCut::ZOOM / 2) * 2;
        $h = (int) round(1920 * SmartCut::ZOOM / 2) * 2;
        $on = implode('+', array_map(fn ($s) => sprintf('between(t,%.3f,%.3f)', $s[0], $s[1] - 0.001), $spans));

        return "{$video}split=2[zbase][zsrc];"
            ."[zsrc]scale={$w}:{$h}:flags=bicubic,crop=1080:1920:(iw-ow)/2:(ih-oh)*0.4[zin];"
            ."[zbase][zin]overlay=0:0:enable='{$on}'{$label}";
    }

    public function burnSubtitles(string $input, string $assPath, string $output): void
    {
        $fontsdir = $this->layout->fontsDir();
        $assFilter = 'ass='.$assPath.':fontsdir='.$fontsdir;

        $cmd = sprintf(
            '%s -y -i %s -vf %s '.self::ENCODE_FINAL.' -c:a copy -movflags +faststart %s 2>&1',
            $this->bin,
            escapeshellarg($input),
            escapeshellarg($assFilter),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'burnSubtitles');
    }

    /**
     * @param  float  $clipStart  Absolute start of the clip in the source video.
     *                            subtitle_json holds absolute times; subtract this
     *                            so subtitles land at clip-relative t (starting 0).
     * @param  float  $hookDuration  Seconds the hook overlay covers at the start.
     *                               Segments fully inside the hook window are dropped;
     *                               overlapping ones are clamped to start after it.
     */
    public function buildAssFile(array $segments, array $subtitleSettings, float $clipStart = 0.0, float $hookDuration = 0.0): string
    {
        $fontFamily = $subtitleSettings['font_family'] ?? 'Montserrat';
        $fontSize = (int) ($subtitleSettings['font_size'] ?? 42);
        $textColor = $this->hexToAss($subtitleSettings['text_color'] ?? '#ffffff');
        $hlColor = $this->hexToAss($subtitleSettings['highlight_color'] ?? '#facc15');
        $position = $subtitleSettings['position'] ?? 'bottom';
        $bgStyle = $subtitleSettings['background_style'] ?? 'semi';
        $this->style = in_array($subtitleSettings['style'] ?? null, ClipSettings::SUBTITLE_STYLES, true)
            ? $subtitleSettings['style'] : 'karaoke';
        $this->cueWords = $this->style === 'word' ? 1 : self::MAX_CUE_WORDS;

        $positionY = $subtitleSettings['position_y'] ?? null;

        $alignment = match ($position) {
            'top' => 8,
            'center' => 5,
            default => 2,
        };

        // Fix 3: bottom margin raised so subtitles clear TikTok UI (~200px from bottom)
        $marginV = match ($position) {
            'top' => 60,
            'center' => 0,
            default => 200,
        };

        // Background box is drawn as a rounded rectangle on layer 0 behind each
        // cue (libass's BorderStyle=3 box is always square). Text sits on layer 1.
        $this->box = match ($bgStyle) {
            'semi' => ['alpha' => '66'], // 60% opaque black, matches the studio preview
            'full' => ['alpha' => '1A'], // 90% opaque black
            default => null,
        };
        $this->subtitleY = $positionY === null
            ? match ($alignment) {
                8 => $marginV + $fontSize / 2,
                5 => 960,
                default => 1920 - $marginV - $fontSize / 2,
            }
        : (int) round(max(ClipSettings::Y_MIN, min(ClipSettings::Y_MAX, (float) $positionY)) / 100 * 1920);
        // Every line is centred on that height (\an5 = middle-centre), like the
        // studio preview: a line scaled down to fit shrinks around its centre,
        // so it stays inside its rounded box, and libass never shifts a line
        // out of the way of another.
        $this->posTag = sprintf('{\\an5\\pos(540,%d)}', (int) round($this->subtitleY));
        if ($this->box) {
            $this->box += [
                'font' => $this->layout->resolveFontByFamily($fontFamily),
                'size' => $fontSize,
                'cy' => $this->subtitleY,
            ];
        }
        // pop/word sit on the picture without a box, so they get a heavy outline
        $outlineW = $this->box ? 0 : ($this->style === 'karaoke' ? 2 : 6);
        $shadowW = $this->box ? 0 : ($this->style === 'karaoke' ? 2 : 3);
        $this->font = ['path' => $this->layout->resolveFontByFamily($fontFamily), 'size' => $fontSize, 'outline' => $outlineW];

        $ass = "[Script Info]\n";
        $ass .= "ScriptType: v4.00+\n";
        $ass .= "PlayResX: 1080\nPlayResY: 1920\n";
        $ass .= "WrapStyle: 2\n\n"; // no wrapping: cues are short and scaled to fit (fitTag)
        $ass .= "[V4+ Styles]\n";
        $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass .= "Style: Default,{$fontFamily},{$fontSize},{$textColor},{$hlColor},&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,{$outlineW},{$shadowW},{$alignment},60,60,{$marginV},1\n";
        $ass .= "Style: Highlight,{$fontFamily},{$fontSize},{$hlColor},{$textColor},&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,{$outlineW},{$shadowW},{$alignment},60,60,{$marginV},1\n\n";
        $ass .= "[Events]\n";
        $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        // Segments with word timing (ASR captions / Groq) are cued from the real
        // word timestamps; the rest get words spread evenly over the line. Both
        // kinds go through one cue list, so two lines are never on screen at
        // once (YouTube ASR mixes one-word lines without timing into timed ones).
        $timedWords = [];
        $untimed = [];
        foreach ($segments as $seg) {
            if (! empty($seg['words'])) {
                // Caption sound tags ("[tertawa]", "[Musik]", ♪) are not speech
                array_push($timedWords, ...array_filter($seg['words'], fn ($w) => self::stripSoundTags($w['text'] ?? '') !== ''));
            } else {
                $untimed[] = ['text' => self::stripSoundTags($seg['text'] ?? '')] + $seg;
            }
        }
        $cues = $this->wordCues(array_merge($timedWords, $this->spreadWords($untimed)), $clipStart, $hookDuration);
        $this->cueWordList = array_merge(...array_map(fn ($c) => $c['words'], $cues ?: [['words' => []]]));
        foreach ($cues as $cue) {
            if ($this->style !== 'karaoke') {
                foreach ($cue['words'] as &$w) {
                    $w['text'] = mb_strtoupper($w['text']);
                }
                unset($w);
            }
            $ass .= $this->highlightDialogues($cue, $hlColor);
        }

        return $ass;
    }

    private static function stripSoundTags(string $text): string
    {
        return trim(preg_replace(['/\[[^\]]*\]/u', '/[♪♫♬]+/u', '/\s+/u'], ['', '', ' '], $text));
    }

    /**
     * Emoji to pop in over the clip, from the words of the last buildAssFile():
     * centred, just above the subtitle (below it when the subtitle sits high).
     *
     * @return list<array{image: string, start: float, x: int, y: int, size: int}>
     */
    public function emojiOverlays(float $clipDuration): array
    {
        $size = 210;
        $gap = $this->font['size'] * 0.75 + 40 + $size / 2;
        $cy = $this->subtitleY > 1100 ? $this->subtitleY - $gap : $this->subtitleY + $gap;

        return array_map(fn ($e) => [
            'image' => EmojiCues::image($e['code']),
            'start' => $e['start'],
            'x' => 540 - intdiv($size, 2),
            'y' => (int) round($cy - $size / 2),
            'size' => $size,
        ], EmojiCues::pick($this->cueWordList, $clipDuration));
    }

    private function dialogue(float $start, float $end, string $text, int $layer = 1): string
    {
        return "Dialogue: {$layer},".$this->toAssTime($start).','.$this->toAssTime($end).",Default,,0,0,0,,{$text}\n";
    }

    /** Percent scale that makes a cue without a box fit the frame width (100 when it already fits). */
    private function fitScale(string $text, float $extra = 0.0): int
    {
        $maxW = 1080 - 2 * 60 - 2 * $this->font['outline'];
        $textW = FontMetrics::textWidth($this->font['path'], $text, $this->font['size']);

        return (int) floor(min(1.0, $maxW / max(1.0, $textW + $extra)) * 100);
    }

    private function scaleTag(int $percent): string
    {
        return $percent < 100 ? sprintf('{\\fscx%1$d\\fscy%1$d}', $percent) : '';
    }

    /**
     * Rounded rectangle behind a cue, drawn with ASS vector commands on layer 0.
     * Sized from the measured text width; text and box share the same centre,
     * so any small measuring error just shifts padding evenly to both sides.
     *
     * @return array{0: string, 1: int} [box dialogue, percent scale that makes the text fit]
     */
    private function boxFor(float $start, float $end, string $text, float $extra = 0.0): array
    {
        $padX = 30;
        $padY = 14;
        $maxW = 1080 - 2 * 60;

        $textW = FontMetrics::textWidth($this->box['font'], $text, $this->box['size']);
        $scale = min(1.0, ($maxW - 2 * $padX) / max(1.0, $textW + $extra));

        $w = (int) round($textW * $scale + 2 * $padX);
        $h = (int) round($this->box['size'] * $scale + 2 * $padY);
        $r = min(24, intdiv($h, 2));
        $k = (int) round($r * 0.448); // bezier offset approximating a quarter circle (1 - 0.552)

        $path = "m {$r} 0 l ".($w - $r).' 0 b '.($w - $k)." 0 {$w} {$k} {$w} {$r}"
            .' l '.$w.' '.($h - $r).' b '.$w.' '.($h - $k).' '.($w - $k).' '.$h.' '.($w - $r).' '.$h
            .' l '.$r.' '.$h.' b '.$k.' '.$h.' 0 '.($h - $k).' 0 '.($h - $r)
            .' l 0 '.$r.' b 0 '.$k.' '.$k.' 0 '.$r.' 0';

        $x = (int) round(540 - $w / 2);
        $y = (int) round($this->box['cy'] - $h / 2);
        $tag = sprintf('{\\an7\\pos(%d,%d)\\bord0\\shad0\\1c&H000000&\\1a&H%s&\\p1}', $x, $y, $this->box['alpha']);

        return [$this->dialogue($start, $end, $tag.$path, 0), (int) floor($scale * 100)];
    }

    /**
     * One event per word: the whole cue stays on screen and only the word being
     * spoken takes the highlight colour; words before and after it stay the
     * base text colour (matches the studio preview). Events are back to back,
     * so the line never flickers or stacks.
     *
     * @param  array{start: float, end: float, words: array<int, array{text: string, start: float}>}  $cue
     */
    private function highlightDialogues(array $cue, string $hlColor): string
    {
        $words = $cue['words'];
        $hl = '{\\1c&H'.substr($hlColor, 4).'&}'; // &H00BBGGRR -> \1c&HBBGGRR&
        $line = implode(' ', array_column($words, 'text'));

        // pop grows the spoken word 15%: leave room for the widest one grown
        $extra = 0.0;
        if ($this->style === 'pop') {
            $font = $this->box['font'] ?? $this->font['path'];
            $extra = 0.15 * max(array_map(fn ($w) => FontMetrics::textWidth($font, $w['text'], $this->font['size']), $words));
        }
        [$out, $fit] = $this->box ? $this->boxFor($cue['start'], $cue['end'], $line, $extra) : ['', $this->fitScale($line, $extra)];
        $fitTag = $this->scaleTag($fit);

        if ($this->style === 'word') {
            // One word per cue: it pops in (70% -> 100%); numbers and long words take the highlight
            $word = $words[0]['text'];
            $color = preg_match('/\d/u', $word) || mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $word)) >= 7 ? $hl : '';
            $pop = sprintf('{\\fscx%1$d\\fscy%1$d\\t(0,110,\\fscx%2$d\\fscy%2$d)}', (int) round($fit * 0.7), $fit);

            return $out.$this->dialogue($cue['start'], $cue['end'], $this->posTag.$pop.$color.$word);
        }

        // pop: the spoken word grows a little as it is said
        $grow = $this->style === 'pop'
            ? sprintf('{\\t(0,90,\\fscx%1$d\\fscy%1$d)}', (int) round($fit * 1.15))
            : '';

        foreach ($words as $i => $active) {
            $from = $i === 0 ? $cue['start'] : $active['start'];
            $to = $words[$i + 1]['start'] ?? $cue['end'];
            if ($to <= $from) {
                continue;
            }

            $text = implode(' ', array_map(
                fn ($w, $j) => $j === $i ? $hl.$grow.$w['text'].'{\\r}'.$fitTag : $w['text'],
                $words,
                array_keys($words)
            ));
            $out .= $this->dialogue($from, $to, $this->posTag.$fitTag.$text);
        }

        return $out;
    }

    /**
     * Group timed words into short cues. A cue breaks at MAX_CUE_WORDS, at a
     * pause longer than CUE_BREAK_GAP, or after sentence-ending punctuation.
     * Each word keeps its real start, so the highlight follows the speaker.
     *
     * @return array<int, array{start: float, end: float, words: array}>
     */
    private function wordCues(array $words, float $clipStart, float $hookDuration): array
    {
        $rel = [];
        foreach ($words as $w) {
            $start = (float) $w['start'] - $clipStart;
            $end = (float) $w['end'] - $clipStart;
            $text = trim($w['text'] ?? '');
            if ($text === '' || $end <= $hookDuration) {
                continue; // before the clip or hidden behind the hook
            }
            $rel[] = ['start' => max($start, $hookDuration), 'end' => $end, 'text' => $text];
        }
        usort($rel, fn ($a, $b) => $a['start'] <=> $b['start']);

        $groups = [];
        $group = [];
        foreach ($rel as $w) {
            if ($group) {
                $prev = $group[count($group) - 1];
                $break = count($group) >= $this->cueWords
                    || $w['start'] - $prev['end'] > self::CUE_BREAK_GAP
                    || preg_match('/[.?!]$/u', $prev['text']);
                if ($break) {
                    $groups[] = $group;
                    $group = [];
                }
            }
            $group[] = $w;
        }
        if ($group) {
            $groups[] = $group;
        }

        $cues = [];
        foreach ($groups as $gi => $g) {
            $start = $g[0]['start'];
            $nextStart = $groups[$gi + 1][0]['start'] ?? INF;
            // Hold briefly after the last word so short cues stay readable,
            // but never into the next cue (ASS would stack them as two rows).
            $end = min(max($g[count($g) - 1]['end'] + self::CUE_HOLD, $start + 0.3), $nextStart);

            $cues[] = ['start' => $start, 'end' => $end, 'words' => $g];
        }

        return $cues;
    }

    /**
     * Words for segments without word timing: spread evenly over the line,
     * which ends no later than the next line starts (caption lines overlap),
     * and no word longer than MAX_SPREAD_WORD.
     *
     * @return array<int, array{text: string, start: float, end: float}>
     */
    private function spreadWords(array $segments): array
    {
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
        $words = [];
        foreach ($segments as $i => $seg) {
            $start = (float) $seg['start'];
            $end = min((float) $seg['end'], (float) ($segments[$i + 1]['start'] ?? INF));
            $texts = preg_split('/\s+/', trim($seg['text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            if (! $texts || $end <= $start) {
                continue;
            }
            $step = ($end - $start) / count($texts);
            foreach ($texts as $k => $text) {
                $at = $start + $k * $step;
                $words[] = ['text' => $text, 'start' => $at, 'end' => $at + min($step, self::MAX_SPREAD_WORD)];
            }
        }

        return $words;
    }

    /**
     * Subtitles, the hook block and emoji drawn over $from. The hook and emoji
     * images are extra inputs numbered from $firstInput.
     *
     * @return array{0: string, 1: string} [input arguments, filter graph ending in $label]
     */
    private function overlayChain(string $from, string $label, int $firstInput, ?string $assPath, ?array $hook, float $hookDuration, array $emojis): array
    {
        $subs = $assPath ? 'ass='.$assPath.':fontsdir='.$this->layout->fontsDir() : 'null';
        $inputs = '';
        $parts = ["{$from}{$subs}[b0]"];
        $n = 0; // overlays drawn so far
        $in = $firstInput;

        if ($hook && $hookDuration > 0) {
            $fadeOut = min(0.25, $hookDuration / 4);
            $pop = 'if(lt(t,0.14),0.82+t*1.857,if(lt(t,0.26),1.08-(t-0.14)*0.6667,1))';
            $inputs .= sprintf(' -loop 1 -framerate 30 -t %.3f -i %s', $hookDuration, escapeshellarg($hook['image']));
            $n++;
            $parts[] = sprintf(
                "[%d:v]format=rgba,scale=w='trunc(iw*%s)':h=-1:eval=frame,"
                .'fade=t=in:st=0:d=0.12:alpha=1,fade=t=out:st=%.3f:d=%.2f:alpha=1[hook];'
                // keep the block centred on its rendered spot while it scales
                ."[b%d][hook]overlay=x='%d+(%d-overlay_w)/2':y='%d+(%d-overlay_h)/2':eof_action=pass[b%d]",
                $in++, $pop, $hookDuration - $fadeOut, $fadeOut,
                $n - 1, $hook['x'], $hook['w'], $hook['y'], $hook['h'], $n
            );
        }

        // Emoji: each image is shifted to its moment, pops in (50% -> 105% -> 100%) and fades out
        foreach ($emojis as $e) {
            $s = (float) $e['start'];
            $inputs .= sprintf(' -loop 1 -framerate 30 -t %.3f -i %s', EmojiCues::SHOW_FOR, escapeshellarg($e['image']));
            $n++;
            $pop = sprintf('if(lt(t-%1$.3f,0.12),0.5+(t-%1$.3f)*4.583,if(lt(t-%1$.3f,0.22),1.05-(t-%1$.3f-0.12)*0.5,1))', $s);
            $parts[] = sprintf(
                "[%d:v]setpts=PTS+%.3f/TB,format=rgba,scale=w='trunc(%d*%s)':h=-1:eval=frame,"
                .'fade=t=in:st=%.3f:d=0.1:alpha=1,fade=t=out:st=%.3f:d=0.15:alpha=1[e%d];'
                ."[b%d][e%d]overlay=x='%d-overlay_w/2':y='%d-overlay_h/2':eof_action=pass[b%d]",
                $in++, $s, $e['size'], $pop, $s, $s + EmojiCues::SHOW_FOR - 0.15, $n,
                $n - 1, $n, $e['x'] + intdiv($e['size'], 2), $e['y'] + intdiv($e['size'], 2), $n
            );
        }

        $filter = implode(';', $parts);

        return [$inputs, substr($filter, 0, strrpos($filter, "[b{$n}]")).$label];
    }

    /**
     * Burn subtitles (ASS) and overlay the hook image onto the video in a single
     * pass. The hook is a transparent PNG from HookRenderer, cropped to the text
     * block; it pops in (scales 82% → 108% → 100% around its own centre), holds
     * for $hookDuration seconds and fades out. Audio is stream-copied.
     *
     * @param  string|null  $assPath  null/empty to skip subtitles
     * @param  array{image: string, x: int, y: int, w: int, h: int}|null  $hook  null to skip the hook overlay
     */
    public function burnSubtitlesAndHook(
        string $input,
        ?string $assPath,
        ?array $hook,
        float $hookDuration,
        string $output,
        array $emojis = []
    ): void {
        $hasHook = $hook && $hookDuration > 0;

        if (! $assPath && ! $hasHook && ! $emojis) {
            // Nothing to burn — just re-mux video as-is
            $cmd = sprintf(
                '%s -y -i %s -c copy -movflags +faststart %s 2>&1',
                $this->bin, escapeshellarg($input), escapeshellarg($output)
            );
            $this->exec($cmd, 'burnSubtitlesAndHook');

            return;
        }

        [$extraInputs, $filter] = $this->overlayChain('[0:v]', '[v]', 1, $assPath, $hook, $hookDuration, $emojis);
        $inputs = '-i '.escapeshellarg($input).$extraInputs;

        $cmd = sprintf(
            '%s -y %s -filter_complex %s -map %s -map 0:a? '.self::ENCODE_FINAL.' -c:a copy -movflags +faststart %s 2>&1',
            $this->bin,
            $inputs,
            escapeshellarg($filter),
            escapeshellarg('[v]'),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'burnSubtitlesAndHook');
    }

    /**
     * Lay a looped music bed under the clip's audio. The voice is brought up to
     * −14 LUFS (TikTok/Reels loudness) and the track normalised to the same
     * level, so the slider is the music's level relative to the voice whatever
     * the source: 100% = as loud as the voice, 30% ≈ −10 dB, 15% ≈ −16 dB
     * (a clear bed behind speech). While someone speaks the music ducks a
     * further few dB (sidechain), it fades in/out at the clip edges, and a
     * limiter keeps the sum from clipping. Video is stream-copied.
     *
     * @param  int  $volumePercent  Music level, 0–100, as a share of the voice level.
     */
    public function mixMusic(string $input, string $musicPath, int $volumePercent, string $output): void
    {
        $duration = $this->probeDuration($input);
        $gain = max(0, min(100, $volumePercent)) / 100;
        $fadeOut = max(0, $duration - 1.5);
        $norm = 'aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo';
        $loud = 'loudnorm=I='.self::VOICE_LUFS.':TP=-1.5:LRA=11';

        $music = sprintf(
            '[1:a]%s,%s,volume=%.3f,afade=t=in:d=1,afade=t=out:st=%.3f:d=1.5',
            $loud, $norm, $gain, $fadeOut
        );

        $filter = $this->hasAudio($input)
            ? "{$music}[m];[0:a]{$loud},{$norm},asplit=2[voice][key];"
              // gentle duck (~3–5 dB) while someone talks: the level gap does most of the work
              .'[m][key]sidechaincompress=threshold=0.05:ratio=3:attack=15:release=350[duck];'
              .'[voice][duck]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[aout]'
            : "{$music},alimiter=limit=0.95[aout]";

        $cmd = sprintf(
            '%s -y -i %s -stream_loop -1 -i %s -filter_complex %s -map 0:v -map %s -t %s -c:v copy -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
            $this->bin,
            escapeshellarg($input),
            escapeshellarg($musicPath),
            escapeshellarg($filter),
            escapeshellarg('[aout]'),
            escapeshellarg(sprintf('%.3f', $duration)),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'mixMusic');
    }

    /**
     * Bring the voice to VOICE_LUFS (TikTok/Reels loudness) when no music mix
     * does it: quiet uploads otherwise play noticeably softer than the feed.
     * Video is stream-copied; returns false when there is no audio to level.
     */
    public function normalizeVoice(string $input, string $output): bool
    {
        if (! $this->hasAudio($input)) {
            return false;
        }

        $cmd = sprintf(
            '%s -y -i %s -map 0:v -map 0:a -c:v copy -af %s -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
            $this->bin,
            escapeshellarg($input),
            // loudnorm works at 192 kHz internally; bring it back to 48 kHz
            escapeshellarg('loudnorm=I='.self::VOICE_LUFS.':TP=-1.5:LRA=11,aresample=48000'),
            escapeshellarg($output)
        );
        $this->exec($cmd, 'normalizeVoice');

        return true;
    }

    private function probeDuration(string $input): float
    {
        exec(sprintf(
            '%s -v error -show_entries format=duration -of default=nw=1:nk=1 %s 2>&1',
            $this->probeBin(),
            escapeshellarg($input)
        ), $out, $code);

        $duration = (float) ($out[0] ?? 0);
        if ($code !== 0 || $duration <= 0) {
            throw new RuntimeException('ffprobe duration failed: '.implode("\n", $out));
        }

        return $duration;
    }

    private function probeFps(string $input): float
    {
        exec(sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=avg_frame_rate -of csv=p=0 %s 2>&1',
            $this->probeBin(),
            escapeshellarg($input)
        ), $out, $code);

        [$num, $den] = array_map('floatval', explode('/', trim($out[0] ?? '0').'/1'));

        return $code === 0 && $den > 0 ? $num / $den : 0.0;
    }

    private function hasAudio(string $input): bool
    {
        exec(sprintf(
            '%s -v error -select_streams a -show_entries stream=index -of csv=p=0 %s 2>&1',
            $this->probeBin(),
            escapeshellarg($input)
        ), $out, $code);

        return $code === 0 && trim(implode('', $out)) !== '';
    }

    private function probeBin(): string
    {
        // ffprobe ships next to ffmpeg; FFMPEG_PATH may be an absolute path
        return preg_replace('/ffmpeg(\.exe)?$/', 'ffprobe$1', $this->bin);
    }

    public function concatVideos(array $inputs, string $output): void
    {
        $listFile = tempnam(sys_get_temp_dir(), 'ffconcat_');
        $content = '';
        foreach ($inputs as $f) {
            $content .= "file '".addslashes($f)."'\n";
        }
        file_put_contents($listFile, $content);

        $cmd = sprintf(
            '%s -y -f concat -safe 0 -i %s '.self::ENCODE_FINAL.' -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
            $this->bin,
            escapeshellarg($listFile),
            escapeshellarg($output)
        );

        try {
            $this->exec($cmd, 'concatVideos');
        } finally {
            @unlink($listFile);
        }
    }

    private function exec(string $cmd, string $context): void
    {
        [$code, $output] = Cancellation::exec($cmd);
        if ($code !== 0) {
            throw new RuntimeException("FFmpeg {$context} failed:\n".implode("\n", $output));
        }
    }

    private function toAssTime(float $seconds): string
    {
        // Round once on the total: rounding only the fraction turned 1.996s into "0:00:01.100"
        $total = (int) round(max($seconds, 0) * 100);
        $h = intdiv($total, 360000);
        $m = intdiv($total % 360000, 6000);
        $s = intdiv($total % 6000, 100);
        $cs = $total % 100;

        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    private function hexToAss(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('&H00%02X%02X%02X', $b, $g, $r);
    }
}
