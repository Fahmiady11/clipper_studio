<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LLM calls for clip analysis and captions. One path for all of them:
 * an OpenAI-compatible gateway first when configured (e.g. OmniRoute), then
 * each Gemini model in services.gemini.models. Every model has its own quota,
 * so a model whose free daily quota is spent is skipped at once; overloaded
 * ones (503) are retried with growing pauses before moving on.
 */
class GeminiService
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $apiKey;

    /** @var list<string> */
    private array $models;

    /** @var array{url: string, key: string, models: list<string>}|null */
    private ?array $gateway;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.key', '');
        $this->models = array_values(array_filter(array_map('trim', (array) config('services.gemini.models', []))));
        $gw = config('services.llm_gateway');
        // LLM_GATEWAY_MODEL may list several models, comma separated, tried in order
        $gwModels = array_values(array_filter(array_map('trim', explode(',', (string) ($gw['model'] ?? '')))));
        $this->gateway = ! empty($gw['url']) && $gwModels ? [
            'url' => rtrim($gw['url'], '/'), 'key' => (string) ($gw['key'] ?? ''), 'models' => $gwModels,
        ] : null;
    }

    public function analyzeWithTranscript(
        array $transcript,
        int $clipCount,
        string $layoutType,
        string $durationMode = 'auto',
        int $minDuration = 15,
        int $maxDuration = 60
    ): array {
        $prompt = $this->buildPromptFromTranscript($transcript, $clipCount, $layoutType, $durationMode, $minDuration, $maxDuration);

        $clips = $this->generate($prompt, 'analyzeTranscript', 0.3, 300, function ($data) {
            // Some models wrap the array: {"clips": [...]}
            if (is_array($data) && ! array_is_list($data)) {
                $data = collect($data)->first(fn ($v) => is_array($v) && array_is_list($v));
            }

            return is_array($data) && $data ? $data : null;
        });

        return array_slice($clips, 0, $clipCount);
    }

    public function generateCaption(string $topic, string $hookText, array $subtitleSegments, string $language = 'id'): array
    {
        $segText = implode(' ', array_column($subtitleSegments, 'text'));
        $segText = mb_substr($segText, 0, 500);

        $prompt = <<<PROMPT
        Kamu adalah copywriter TikTok profesional. Buat caption viral untuk konten TikTok ini:

        Topik: {$topic}
        Hook pembuka: {$hookText}
        Isi konten: {$segText}
        Bahasa output: {$language}

        Buat:
        1. Caption menarik 2-3 kalimat, pakai emoji relevan, akhiri dengan ajakan interaksi (pertanyaan atau CTA)
        2. 6-8 hashtag (campuran trending dan niche, relevan dengan topik)

        Kembalikan JSON:
        {"caption": "...", "hashtags": ["#tag1", "#tag2"]}
        PROMPT;

        try {
            $data = $this->generate($prompt, 'caption', 0.7, 30, fn ($d) => isset($d['caption'], $d['hashtags']) ? $d : null);
        } catch (RuntimeException) {
            return ['caption' => '', 'hashtags' => []];
        }

        return [
            'caption' => (string) $data['caption'],
            'hashtags' => array_values(array_filter((array) $data['hashtags'], 'is_string')),
        ];
    }

    /**
     * Ask each provider in turn until one returns JSON that $accept turns into a value.
     *
     * @template T
     *
     * @param  callable(mixed): (T|null)  $accept  null = unusable answer, try the next model
     * @return T
     */
    private function generate(string $prompt, string $tag, float $temperature, int $timeout, callable $accept): mixed
    {
        $log = Log::channel('clipper_jobs');
        $failures = [];

        foreach ($this->targets() as $target) {
            $name = $target['name'];
            // Seconds before the 2nd and 3rd try on 5xx/timeouts
            foreach ([0, ...(array) config('services.gemini.retry_pauses', [4, 12])] as $try => $pause) {
                if ($pause) {
                    sleep($pause);
                }
                try {
                    $log->info("[LLM:{$tag}] {$name}", ['try' => $try + 1]);
                    $response = $target['call']($prompt, $temperature, $timeout);
                } catch (\Throwable $e) {
                    $failures[$name] = 'koneksi gagal';
                    $log->warning("[LLM:{$tag}] {$name} exception", ['error' => $e->getMessage()]);

                    continue; // timeouts/connection resets: worth another try
                }

                $status = $response->status();
                if ($status === 429) {
                    $wait = $this->parseRetryDelay($response->body());
                    $daily = str_contains($response->body(), 'PerDay') || str_contains($response->body(), 'free_tier_requests');
                    $failures[$name] = $daily ? 'kuota harian habis' : 'rate limit';
                    $log->warning("[LLM:{$tag}] {$name} 429", ['daily' => $daily, 'wait' => $wait]);
                    if ($daily || $try > 0 || $wait > 30) {
                        break; // its quota won't come back in time: next model
                    }
                    sleep(min(max(1, $wait), 30));

                    continue;
                }
                if ($status >= 500) {
                    $failures[$name] = "server sibuk ({$status})";
                    $log->warning("[LLM:{$tag}] {$name} {$status}");

                    continue;
                }
                if (! $response->ok()) {
                    $failures[$name] = "HTTP {$status}";
                    $log->error("[LLM:{$tag}] {$name} HTTP {$status}", ['body' => mb_substr($response->body(), 0, 300)]);
                    break;
                }

                $text = $target['text']($response);
                $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim((string) $text));
                $result = $accept(json_decode($clean, true));
                if ($result === null) {
                    $failures[$name] = 'jawaban tidak valid';
                    $log->warning("[LLM:{$tag}] {$name} returned unusable JSON");
                    break;
                }

                $log->info("[LLM:{$tag}] SUCCESS {$name}", ['try' => $try + 1]);

                return $result;
            }
        }

        if (! $failures) {
            throw new RuntimeException('Tidak ada AI yang dikonfigurasi: isi LLM_GATEWAY_* atau GEMINI_API_KEY di .env.');
        }

        throw new RuntimeException($this->failureMessage($failures));
    }

    /** @return list<array{name: string, call: callable, text: callable}> */
    private function targets(): array
    {
        $targets = [];

        foreach ($this->gateway['models'] ?? [] as $model) {
            $gw = $this->gateway;
            $targets[] = [
                'name' => 'gateway:'.$model,
                'call' => fn (string $prompt, float $t, int $timeout): Response => Http::timeout(max($timeout, 120))
                    ->withToken($gw['key'])
                    ->post($gw['url'].'/chat/completions', [
                        'model' => $model,
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                        'temperature' => $t,
                    ]),
                'text' => fn (Response $r) => $r->json('choices.0.message.content'),
            ];
        }

        // No API key = gateway only; skip Gemini instead of sending keyless requests
        foreach ($this->apiKey !== '' ? $this->models : [] as $model) {
            $targets[] = [
                'name' => $model,
                // Key in a header, not the URL: URLs end up in exception messages and logs
                'call' => fn (string $prompt, float $t, int $timeout): Response => Http::timeout($timeout)
                    ->withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->post(self::BASE."{$model}:generateContent", [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => $t],
                    ]),
                'text' => fn (Response $r) => $r->json('candidates.0.content.parts.0.text'),
            ];
        }

        return $targets;
    }

    /** One line a user can act on, instead of the last model's raw HTTP error. */
    private function failureMessage(array $failures): string
    {
        $values = array_values($failures);
        $hint = match (true) {
            $values && count(array_filter($values, fn ($v) => str_contains($v, 'kuota'))) === count($values) => 'Kuota harian Gemini habis di semua model. Coba lagi besok, atau pakai API key berbayar / gateway.',
            $values && count(array_filter($values, fn ($v) => str_contains($v, 'sibuk') || str_contains($v, 'kuota') || str_contains($v, 'rate'))) === count($values) => 'Server Gemini sedang sibuk/terbatas. Coba lagi beberapa menit lagi.',
            default => 'AI gagal menganalisis.',
        };
        $detail = implode(', ', array_map(fn ($m, $why) => "{$m}: {$why}", array_keys($failures), $values));

        return "Gemini analisa gagal — {$hint}".($detail ? " ({$detail})" : '');
    }

    private function buildPromptFromTranscript(
        array $transcript,
        int $clipCount,
        string $layoutType,
        string $durationMode,
        int $minDuration,
        int $maxDuration
    ): string {
        $langHint = $transcript['language'] ?? 'id';
        $segments = $transcript['segments'] ?? [];

        // Sample evenly to max 1000 segments — keeps full coverage, reduces prompt from ~200KB to ~80KB
        if (count($segments) > 1000) {
            $total = count($segments);
            $step = $total / 1000;
            $picked = [];
            for ($i = 0; $i < 1000; $i++) {
                $picked[] = $segments[(int) round($i * $step)];
            }
            $segments = $picked;
        }

        $transcriptText = '';
        foreach ($segments as $seg) {
            $transcriptText .= sprintf("[%.1f-%.1f] %s\n", $seg['start'], $seg['end'], $seg['text']);
        }

        $durHint = $durationMode === 'manual'
            ? "Durasi per clip: {$minDuration}–{$maxDuration} detik."
            : 'Durasi per clip: 30–90 detik (pilih optimal per momen, boleh lebih panjang jika topik belum selesai).';

        $layoutHint = match ($layoutType) {
            'reframe' => 'Subjek pusat/wajah dominan, cocok crop vertikal.',
            'gaussian' => 'Pembicara tunggal atau subjek fokus.',
            default => '',
        };

        return <<<PROMPT
        Kamu adalah editor TikTok senior dengan rekam jejak video viral jutaan views. Berikut transcript video YouTube dengan timestamp (bahasa: {$langHint}).

        {$transcriptText}

        == TUGAS ==
        Pilih TEPAT {$clipCount} segmen dengan potensi viral TERTINGGI untuk TikTok/Reels/Shorts.
        {$durHint}
        Layout hint: {$layoutHint}

        == KRITERIA VIRAL (urutkan prioritas) ==
        1. HOOK KUAT di detik pertama — kalimat pembuka yang langsung menarik perhatian, buat penonton penasaran atau terkejut
        2. MOMEN EMOSI PUNCAK — tawa, kaget, marah, haru, euforia — emosi = retention
        3. INSIGHT atau FAKTA MENGEJUTKAN — informasi yang membuat orang berpikir "wow, aku tidak tahu ini"
        4. KONFLIK atau KETEGANGAN — permasalahan, perdebatan, momen dramatis
        5. NILAI PRAKTIS PADAT — tips/cara/solusi yang langsung bisa diterapkan, tanpa basa-basi
        6. QUOTABLE MOMENT — kalimat singkat bertenaga yang layak jadi caption atau subtitle highlight

        == ATURAN KRITIS — WAJIB DIPATUHI ==
        - DILARANG KERAS memotong di tengah kalimat, di tengah argumen, atau di tengah topik yang belum selesai
        - end_seconds HARUS di titik jeda alami: setelah kalimat selesai, setelah poin tuntas, setelah momen klimaks berakhir
        - Jika sebuah momen bagus tapi topiknya baru selesai di luar durasi maksimal, PANJANGKAN end_seconds sampai topik benar-benar tuntas (lebih baik clip panjang sempurna daripada clip pendek terpotong)
        - start_seconds HARUS tepat sebelum hook/kalimat pembuka segmen (bukan di tengah kalimat)
        - Antar clip TIDAK boleh tumpang tindih timestamp
        - Hanya pilih segmen dengan viral_score ≥ 7
        - Urutkan dari viral_score tertinggi ke terendah

        == HOOK_TEXT — teks besar di layar selama 3 detik pertama ==
        Tujuannya satu: bikin orang BERHENTI scroll karena PENASARAN. Ini judul, BUKAN kutipan transcript dan BUKAN ringkasan.
        - MAKSIMAL 5 KATA (idealnya 3–4), maksimal 30 karakter. Hitung katanya.
        - Buka "celah rasa ingin tahu": janjikan jawaban/kejutan yang baru terungkap di dalam clip. JANGAN bocorkan jawabannya.
        - Teknik penasaran yang ampuh:
          · rahasia/hal tersembunyi ("Rahasia yang *disembunyikan* bank")
          · kalimat menggantung ("Ternyata *penyebabnya* ini…")
          · kontradiksi/kontras ("Gaji 50 juta, tetap *bokek*")
          · pertanyaan tajam ("Kenapa kamu *susah kaya*?")
          · klaim berani ("Kerja keras itu *overrated*")
          · angka spesifik ("3 tanda kamu *burnout*")
          · taruhan pribadi/pengakuan ("Aku ditipu *sahabat sendiri*")
          · peringatan ("Jangan *resign* dulu!")
        - Bahasa santai mengikuti gaya pembicara (gue/lo atau aku/kamu), bahasa {$langHint}. Tanpa titik di akhir; boleh "?", "!", atau "…".
        - Apit 1–2 kata terpenting dengan bintang untuk diberi warna sorotan, contoh: "Gaji 50 juta, tetap *bokek*".
        - DILARANG: lebih dari 5 kata, emoji, hashtag, tanda kutip, kalimat terpotong, nama orang yang tidak dikenal penonton,
          kalimat generik ("Wajib nonton!", "Tonton sampai habis"), clickbait yang tidak dijawab clip.
        Contoh BURUK → BAIK:
        - "Tapi kalau kemudian hanya menjadi sebagai korban, lalu berharap orang lain yang mengubah.." → "Berhenti jadi *korban*!"
        - "Kakek ini menikah 60 tahun, tapi kenapa masih terlihat sangat mencintai? Rahasianya: dia…" → "Rahasia nikah *60 tahun*"
        - "Di usia dewasa pentingnya kita punya respons flexibility." → "Skill dewasa yang *langka*"
        - "Daripada nanya 'gimana caranya viral?', ubah pertanyaannya jadi…" → "Berhenti ngejar *viral*"
        - "Gue takut di New York. Ada berita disamperin, ditusuk, dibakar di subway?" → "Sisi *gelap* New York"

        == FORMAT OUTPUT ==
        Kembalikan array JSON {$clipCount} item:
        [
          {
            "ranking": int (1=terbaik),
            "start_seconds": float,
            "end_seconds": float,
            "topic": string (judul singkat topik segmen),
            "reason": string (mengapa segmen ini viral — sebutkan sinyal spesifik dari transcript),
            "viral_score": int (1-10, berdasarkan kriteria di atas),
            "viral_potential": "tinggi"|"sedang"|"rendah",
            "hook_text": string (judul overlay MAKSIMAL 5 kata, bikin penasaran, ikuti aturan HOOK_TEXT di atas),
            "completion_check": string (konfirmasi bahwa topik selesai di end_seconds, bukan terpotong),
            "music_mood": "energetic"|"chill"|"inspiring"|"dramatic"|"funny"|"sad" (suasana musik latar yang paling cocok dengan emosi segmen),
            "subtitle_segments": [{"start": float, "end": float, "text": string}]
          }
        ]

        Kembalikan HANYA array JSON, tanpa teks tambahan.
        PROMPT;
    }

    private function parseRetryDelay(string $body): int
    {
        $data = json_decode($body, true);
        $delay = $data['error']['details'][2]['retryDelay'] ?? '0s';

        return (int) preg_replace('/[^0-9]/', '', $delay);
    }
}
