# Plan: Subtitle & Hook Fix

## Ringkasan Issues

| # | Status | Issue | Root Cause | File |
|---|--------|-------|------------|------|
| 1 | ✅ | Font pilihan tidak diterapkan | Font tidak ter-install di sistem; libass fallback ke default | `VideoLayoutService.php`, `FFmpegService.php` |
| 2 | ✅ | Font besar tembus layar | `WrapStyle: 2` (no auto-wrap) | `FFmpegService::buildAssFile()` |
| 3 | ✅ | Bottom terlalu bawah untuk TikTok | `MarginV: 60` tidak cukup | `FFmpegService::buildAssFile()` |
| 4 | ✅ | Timing subtitle tidak sinkron | Timestamps dari Gemini (AI approximation) + proporsional per-kata kasar | `RenderClipJob.php` |
| 5 | ✅ | Background semi: 2 kotak + shadow | `BorderStyle=3, Outline=2, Shadow=4` → box + shadow box terpisah | `FFmpegService::buildAssFile()` |
| 6 | ✅ | Hook font besar tembus layar | Hardcoded `fontSize=60`, no wrapping, no font_size dari settings | `FFmpegService::hookDrawtext()` |
| 7 | ✅ | Caption ngikut project sebelumnya | `clipCaption` tidak di-reset di `restoreFromHistory()`; `$watch('done')` skip fetch | `_script.blade.php` |

---

## Fix Detail

### Fix 1 — Font Family: Bundle + Resolve Path

**Root cause:**  
`buildAssFile()` memasukkan nama font (`Montserrat`, `Roboto`, dll) ke ASS Style. libass lookup by name dari sistem font database. Font-font tersebut **tidak ter-install di macOS**. libass fallback ke Arial. Semua terlihat sama.

Untuk `drawtext` (hook): `VideoLayoutService::resolveFontPath()` hardcoded ke Arial — tidak peduli font pilihan user sama sekali.

**Fix:**
1. Buat `resources/fonts/` — download dan simpan font files: `Montserrat-Bold.ttf`, `Roboto-Bold.ttf`, `Oswald-Bold.ttf`, `BebasNeue-Regular.ttf`, `Arial-Bold.ttf`
2. Tambah method `VideoLayoutService::resolveFontByFamily(string $family): string` — map nama → path `.ttf`
3. Di `FFmpegService::burnSubtitlesAndHook()`: tambah `fontsdir` ke ass filter:
   ```
   ass=/path/to.ass:fontsdir=/path/to/resources/fonts
   ```
4. Di `FFmpegService::hookDrawtext()`: gunakan `resolveFontByFamily($hookSettings['font_family'])` bukan `fontConfig()['path']`

**Files:**
- `resources/fonts/` (new directory + font files)
- `app/Services/VideoLayoutService.php` → tambah `resolveFontByFamily()`
- `app/Services/FFmpegService.php` → `burnSubtitlesAndHook()`, `hookDrawtext()`

---

### Fix 2 — Font Besar Tidak Overflow

**Root cause:**  
`WrapStyle: 2` = no auto-wrap. Teks panjang dengan font besar melampaui canvas width (1080px). `MarginL/MarginR: 40` terlalu kecil.

**Fix:**
```php
// Ganti WrapStyle: 2 → 0 (smart wrap)
$ass .= "WrapStyle: 0\n\n";

// MarginL/MarginR naik dari 40 ke 60
$ass .= "Style: Default,...,60,60,{$marginV},1\n";
```

**Files:** `app/Services/FFmpegService.php:107-108`

---

### Fix 3 — Bottom Margin untuk TikTok

**Root cause:**  
`MarginV: 60` → subtitle bottom berada ~60px dari bawah frame. TikTok overlay (like/comment/share + username) menutupi sekitar 200-250px dari bawah.

**Fix:**
```php
$marginV = match ($position) {
    'top'    => 60,
    'center' => 60,
    'bottom' => 200,  // was 60 — TikTok UI cover ~200px bottom
    default  => 200,
};

// Lalu di Style line gunakan $marginV
```

**Files:** `app/Services/FFmpegService.php` — `buildAssFile()`

---

### Fix 4 — Subtitle Timing Akurat dari Transcript

**Root cause:**  
`clip->subtitle_json` = `subtitle_segments` dari Gemini → AI approximation timestamps, tidak akurat.  
Kemudian `buildKaraokeText()` distribusi waktu per-kata secara proporsional → dobel inakurat.  
Pembicara cepat = tidak bisa mengejar; lambat = subtitle lari duluan.

**Fix (ganti source data):**  
Gunakan `Transcript` dari DB (`transcript->content`) — ini timestamps real dari YouTube captions, bukan AI estimate. Segment-segment yang masuk dalam window `clip->start_seconds` s/d `clip->end_seconds` diambil dan dipass ke `buildAssFile()`.

Di `RenderClipJob::handle()`:
```php
// Ganti: $assContent = $ffmpeg->buildAssFile($clip->subtitle_json, ...)
// Dengan: ambil transcript segments dari DB

$transcript = $project->transcript;
$rawSegments = $transcript?->content ?? $clip->subtitle_json;

// Filter hanya segment dalam range clip
$clipSegments = array_filter($rawSegments, fn($s) =>
    $s['end'] > $clip->start_seconds && $s['start'] < $clip->end_seconds
);
$clipSegments = array_values($clipSegments);

$assContent = $ffmpeg->buildAssFile($clipSegments, [...], $clip->start_seconds, $hookDuration);
```

Dengan transcript real, timing per-segment sudah akurat. Tidak perlu proporsi per-kata lagi — cukup show segment penuh.

**Bonus fix timing:** kurangi split per-kata dari `MAX_CUE_WORDS = 4` ke `2-3` untuk teks yang lebih pendek tapi lebih responsif terhadap pembicara cepat.

**Files:**
- `app/Jobs/RenderClipJob.php` — ganti source subtitle_json ke transcript
- `app/Services/FFmpegService.php` — pertimbangkan kurangi `MAX_CUE_WORDS`

---

### Fix 5 — Background Semi: Satu Kotak, Padding Proper

**Root cause:**  
```php
"Style: Default,...,&H80000000,...,BorderStyle=3,Outline=2,Shadow=4,..."
```
- `BorderStyle=3` = opaque box (pakai BackColour)
- `Shadow=4` = shadow box tersendiri offset 4px → tampak sebagai kotak ke-2
- `Outline=2` = padding hanya 2px → kotak terlihat sempit

ASS **tidak support rounded corners** secara native. Workaround: kotak flat dengan padding besar + shadow dihilangkan.

**Fix:**
```php
// BorderStyle=3: box mode
// Shadow=0: hilangkan shadow box
// Outline dinaikkan ke 16-20 untuk padding
// BackColour lebih transparan: &HA0000000 (37.5% opaque)

$ass .= "Style: Default,{$fontFamily},{$fontSize},{$textColor},{$hlColor},&H00000000,&HA0000000,-1,0,0,0,100,100,0,0,{$boxStyle},16,0,{$alignment},60,60,{$marginV},1\n";
$ass .= "Style: Highlight,{$fontFamily},{$fontSize},{$hlColor},{$textColor},&H00000000,&HA0000000,-1,0,0,0,100,100,0,0,{$boxStyle},16,0,{$alignment},60,60,{$marginV},1\n\n";
```

`Outline=16` pada `BorderStyle=3` = border/padding 16px di semua sisi. No shadow.

**Files:** `app/Services/FFmpegService.php:107-108`

---

### Fix 6 — Hook: Font Size dari Settings + Wrapping

**Root cause:**  
`hookDrawtext()` — `$fontSize = 60` hardcoded. Teks panjang/font besar tembus canvas. Tidak ada wrapping di `drawtext`.

**Fix:**
```php
private function hookDrawtext(string $text, float $duration, array $settings): string
{
    $textColor  = $settings['text_color'] ?? '#ffffff';
    $bgStyle    = $settings['background_style'] ?? 'none';
    $position   = $settings['position'] ?? 'center';
    $fontFamily = $settings['font_family'] ?? 'Montserrat';
    $fontSize   = (int) ($settings['font_size'] ?? 60);  // dari settings, bukan hardcoded

    // Cap font size: max 80, min 24
    $fontSize = max(24, min(80, $fontSize));

    $fontPath  = $this->layout->resolveFontByFamily($fontFamily);  // Font 1 fix
    $fontColor = '0x' . ltrim($textColor, '#');

    // Wrap teks panjang: potong manual per ~30 karakter
    $wrapped = $this->wrapText($text, 30);  // method baru

    // fix_bounds=1 mencegah drawtext keluar canvas
    // line_spacing untuk multi-line
    ...
}

private function wrapText(string $text, int $maxChars): string
{
    // Pecah per kata, tambah \n setelah maxChars karakter
    $words = explode(' ', $text);
    $lines = [];
    $line  = '';
    foreach ($words as $word) {
        if (strlen($line) + strlen($word) + 1 > $maxChars && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $line === '' ? $word : "{$line} {$word}";
        }
    }
    if ($line) $lines[] = $line;
    // FFmpeg drawtext gunakan \n untuk newline (di dalam escape context: \\n)
    return implode('\n', $lines);
}
```

Juga perlu pass `font_size` dan `font_family` dari `hookSetting` ke `$hookSettings` array di `RenderClipJob`.

**Files:**
- `app/Services/FFmpegService.php` → `hookDrawtext()`, tambah `wrapText()`
- `app/Jobs/RenderClipJob.php` → tambah `font_size`, `font_family` ke `$hookSettings`

---

## Urutan Implementasi

```
1. Fix 1 (font bundle)          → prerequisite untuk fix 4 & 6
2. Fix 5 (background 2 kotak)   → pure ASS param change, paling simple
3. Fix 3 (bottom margin)        → satu baris change
4. Fix 2 (wrap + margin L/R)    → satu baris change
5. Fix 6 (hook font + wrap)     → FFmpegService + RenderClipJob
6. Fix 4 (timing dari transcript) → RenderClipJob + FFmpegService, paling impactful
```

## Files yang Berubah

| File | Perubahan |
|------|-----------|
| `resources/fonts/` | Download & simpan font .ttf files (Montserrat, Roboto, Oswald, Bebas Neue, Arial) |
| `app/Services/VideoLayoutService.php` | Tambah `resolveFontByFamily()` |
| `app/Services/FFmpegService.php` | `buildAssFile()`, `burnSubtitlesAndHook()`, `hookDrawtext()`, tambah `wrapText()` |
| `app/Jobs/RenderClipJob.php` | Ganti subtitle source ke transcript, pass font_size/font_family ke hookSettings |
