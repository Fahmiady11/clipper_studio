<?php

namespace App\Services;

use App\Support\Cancellation;
use App\Support\ClipSettings;
use App\Support\FontMetrics;
use RuntimeException;

class FFmpegService
{
    private const MAX_CUE_WORDS = 4;    // words per subtitle cue (TikTok-style short lines)

    private const CUE_BREAK_GAP = 0.6;  // seconds of silence that starts a new cue

    private const CUE_HOLD = 0.25; // seconds a cue lingers after its last word

    private const VOICE_LUFS = -14; // speech loudness after mixing music (TikTok/Reels level)

    private string $bin;

    private string $posTag = ''; // ASS override placing every subtitle line (set by buildAssFile)

    /** @var array{alpha: string, font: string, size: int, cy: float}|null rounded background box (set by buildAssFile) */
    private ?array $box = null;

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
     */
    public function applyLayout(string $input, string $layoutType, string $output, ?float $start = null, ?float $end = null): void
    {
        // Map new layout IDs to VideoLayoutService's filter method
        $layoutMap = [
            'reframe' => 'auto_reframe',
            'gaussian' => 'gaussian_blur',
        ];

        $mode = $layoutMap[$layoutType] ?? 'gaussian_blur';
        $filterLabel = '[out]';
        $filterGraph = $this->layout->layoutFilter($mode, $filterLabel);

        $trimIn = '';
        $trimOut = '';
        if ($start !== null && $end !== null) {
            $trimIn = ' -ss '.escapeshellarg(sprintf('%.3f', $start));
            $trimOut = ' -t '.escapeshellarg(sprintf('%.3f', $end - $start));
        }

        $cmd = sprintf(
            '%s -y%s -i %s%s -filter_complex %s -map %s -map 0:a? -c:v libx264 -preset slow -crf 18 -pix_fmt yuv420p -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
            $this->bin,
            $trimIn,
            escapeshellarg($input),
            $trimOut,
            escapeshellarg($filterGraph),
            escapeshellarg($filterLabel),
            escapeshellarg($output)
        );

        $this->exec($cmd, 'applyLayout');
    }

    public function burnSubtitles(string $input, string $assPath, string $output): void
    {
        $fontsdir = $this->layout->fontsDir();
        $assFilter = 'ass='.$assPath.':fontsdir='.$fontsdir;

        $cmd = sprintf(
            '%s -y -i %s -vf %s -c:v libx264 -preset slow -crf 18 -pix_fmt yuv420p -c:a copy -movflags +faststart %s 2>&1',
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

        // Dragged position: every line is centred on that height (\an5 = middle-centre),
        // matching the studio preview which centres the subtitle box on the same %.
        $positionY = $subtitleSettings['position_y'] ?? null;
        $this->posTag = $positionY === null ? '' : sprintf('{\\an5\\pos(540,%d)}',
            (int) round(max(ClipSettings::Y_MIN, min(ClipSettings::Y_MAX, (float) $positionY)) / 100 * 1920));

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
        if ($this->box) {
            $this->box += [
                'font' => $this->layout->resolveFontByFamily($fontFamily),
                'size' => $fontSize,
                'cy' => $positionY === null
                    ? match ($alignment) {
                        8 => $marginV + $fontSize / 2,
                        5 => 960,
                        default => 1920 - $marginV - $fontSize / 2,
                    }
                    : (int) round(max(ClipSettings::Y_MIN, min(ClipSettings::Y_MAX, (float) $positionY)) / 100 * 1920),
            ];
        }
        $outlineW = $this->box ? 0 : 2;
        $shadowW = $this->box ? 0 : 2;

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
        // word timestamps; the rest fall back to proportional splitting.
        $timedWords = [];
        $untimed = [];
        foreach ($segments as $seg) {
            if (! empty($seg['words'])) {
                array_push($timedWords, ...$seg['words']);
            } else {
                $untimed[] = $seg;
            }
        }

        $cues = array_merge(
            $this->wordCues($timedWords, $clipStart, $hookDuration),
            $this->proportionalCues($untimed, $clipStart, $hookDuration)
        );
        foreach ($cues as $cue) {
            $ass .= $this->highlightDialogues($cue, $hlColor);
        }

        return $ass;
    }

    private function dialogue(float $start, float $end, string $text, int $layer = 1): string
    {
        return "Dialogue: {$layer},".$this->toAssTime($start).','.$this->toAssTime($end).",Default,,0,0,0,,{$text}\n";
    }

    /**
     * Rounded rectangle behind a cue, drawn with ASS vector commands on layer 0.
     * Sized from the measured text width; text and box share the same centre,
     * so any small measuring error just shifts padding evenly to both sides.
     *
     * @return array{0: string, 1: string} [box dialogue, override tag that shrinks the text to fit]
     */
    private function boxFor(float $start, float $end, string $text): array
    {
        $padX = 30;
        $padY = 14;
        $maxW = 1080 - 2 * 60;

        $textW = FontMetrics::textWidth($this->box['font'], $text, $this->box['size']);
        $scale = min(1.0, ($maxW - 2 * $padX) / max(1.0, $textW));
        $fitTag = $scale < 1.0 ? sprintf('{\\fscx%1$d\\fscy%1$d}', (int) floor($scale * 100)) : '';

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

        return [$this->dialogue($start, $end, $tag.$path, 0), $fitTag];
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
        $out = '';
        $fitTag = '';

        if ($this->box) {
            [$out, $fitTag] = $this->boxFor($cue['start'], $cue['end'], implode(' ', array_column($words, 'text')));
        }

        foreach ($words as $i => $active) {
            $from = $i === 0 ? $cue['start'] : $active['start'];
            $to = $words[$i + 1]['start'] ?? $cue['end'];
            if ($to <= $from) {
                continue;
            }

            $text = implode(' ', array_map(
                fn ($w, $j) => $j === $i ? $hl.$w['text'].'{\\r}'.$fitTag : $w['text'],
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
                $break = count($group) >= self::MAX_CUE_WORDS
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

    /** Fallback for segments without word timing: split evenly by word count. */
    private function proportionalCues(array $segments, float $clipStart, float $hookDuration): array
    {
        $cues = [];
        // Normalize: clip-relative times + hook-window clamp
        $norm = [];
        foreach ($segments as $seg) {
            $relStart = $seg['start'] - $clipStart;
            $relEnd = $seg['end'] - $clipStart;

            if ($relEnd <= $hookDuration) {
                continue; // fully hidden behind hook
            }
            if ($relStart < $hookDuration) {
                $relStart = $hookDuration;
            }
            if ($relEnd <= $relStart) {
                continue;
            }
            $norm[] = ['start' => $relStart, 'end' => $relEnd, 'text' => trim($seg['text'])];
        }

        // Prevent overlap: a segment must end before the next begins, else
        // ASS stacks both events into separate rows (the "berantakan" bug).
        $n = count($norm);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($norm[$i]['end'] > $norm[$i + 1]['start']) {
                $norm[$i]['end'] = $norm[$i + 1]['start'];
            }
        }

        // Split each segment into short single-line cues (≤ MAX_CUE_WORDS),
        // timed proportionally to word count so captions track the speech and
        // never sprawl across multiple rows.
        foreach ($norm as $seg) {
            $words = preg_split('/\s+/', $seg['text'], -1, PREG_SPLIT_NO_EMPTY);
            $total = count($words);
            if ($total === 0) {
                continue;
            }

            $dur = $seg['end'] - $seg['start'];
            $cursor = $seg['start'];

            $perWord = $dur / $total;
            foreach (array_chunk($words, self::MAX_CUE_WORDS) as $chunk) {
                $cueStart = $cursor;
                $cueWords = [];
                foreach ($chunk as $word) {
                    $cueWords[] = ['text' => $word, 'start' => $cursor];
                    $cursor += $perWord;
                }
                $cues[] = ['start' => $cueStart, 'end' => $cursor, 'words' => $cueWords];
            }
        }

        return $cues;
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
        string $output
    ): void {
        $hasHook = $hook && $hookDuration > 0;

        if (! $assPath && ! $hasHook) {
            // Nothing to burn — just re-mux video as-is
            $cmd = sprintf(
                '%s -y -i %s -c copy -movflags +faststart %s 2>&1',
                $this->bin, escapeshellarg($input), escapeshellarg($output)
            );
            $this->exec($cmd, 'burnSubtitlesAndHook');

            return;
        }

        $subs = $assPath ? 'ass='.$assPath.':fontsdir='.$this->layout->fontsDir() : 'null';
        $inputs = '-i '.escapeshellarg($input);

        if ($hasHook) {
            $fadeOut = min(0.25, $hookDuration / 4);
            $pop = 'if(lt(t,0.14),0.82+t*1.857,if(lt(t,0.26),1.08-(t-0.14)*0.6667,1))';
            $inputs .= sprintf(' -loop 1 -framerate 30 -t %.3f -i %s', $hookDuration, escapeshellarg($hook['image']));
            $filter = sprintf(
                '[0:v]%s[base];'
                ."[1:v]format=rgba,scale=w='trunc(iw*%s)':h=-1:eval=frame,"
                .'fade=t=in:st=0:d=0.12:alpha=1,fade=t=out:st=%.3f:d=%.2f:alpha=1[hook];'
                // keep the block centred on its rendered spot while it scales
                ."[base][hook]overlay=x='%d+(%d-overlay_w)/2':y='%d+(%d-overlay_h)/2':eof_action=pass[v]",
                $subs, $pop, $hookDuration - $fadeOut, $fadeOut,
                $hook['x'], $hook['w'], $hook['y'], $hook['h']
            );
        } else {
            $filter = "[0:v]{$subs}[v]";
        }

        $cmd = sprintf(
            '%s -y %s -filter_complex %s -map %s -map 0:a? -c:v libx264 -preset slow -crf 18 -pix_fmt yuv420p -c:a copy -movflags +faststart %s 2>&1',
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
            '%s -y -f concat -safe 0 -i %s -c:v libx264 -preset slow -crf 18 -pix_fmt yuv420p -c:a aac -b:a 192k -movflags +faststart %s 2>&1',
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
