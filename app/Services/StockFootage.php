<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stock clips for B-roll, from Pexels (PEXELS_API_KEY) or Pixabay
 * (PIXABAY_API_KEY), whichever key is set; Pexels first when both are.
 * Search results and downloaded files are cached, so a query costs one API
 * call ever and a re-render downloads nothing.
 */
class StockFootage
{
    private const PEXELS = 'https://api.pexels.com/videos/search';

    private const PIXABAY = 'https://pixabay.com/api/videos/';

    private const JUDGED = 4; // candidates the AI looks at

    public function configured(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * A local MP4 showing $query, or null when nothing fits or the provider is
     * unreachable. Results for $query, $alt and the $must words are pooled and
     * ranked by the words their tags (Pixabay) or page slug (Pexels) share with
     * the query; one missing every $must word (the subject) or shot on green
     * screen is never used. With $judge, an AI looks at the best few frames and
     * picks one or none, since no B-roll beats unrelated B-roll.
     * $skip: ids already used in this clip (no repeats).
     *
     * @param  list<string>  $must
     * @param  (callable(list<array{mime: string, data: string}>): ?int)|null  $judge
     * @return array{id: string, path: string, offset: float}|null
     */
    public function clip(string $query, array $skip = [], string $alt = '', array $must = [], ?callable $judge = null): ?array
    {
        $provider = $this->provider();
        if (! $provider || trim($query) === '') {
            return null;
        }

        try {
            $pool = [];
            foreach (array_unique(array_filter([trim($query), trim($alt), trim(implode(' ', $must))])) as $q) {
                foreach ($this->search($provider, $q) as $v) {
                    $pool[$v['id']] ??= $v;
                }
            }
            $ranked = $this->rank(array_values($pool), $query.' '.$alt, self::terms(implode(' ', $must)), $skip, $judge === null);
            $video = $judge ? $this->judged(array_slice($ranked, 0, self::JUDGED), $query, $judge) : ($ranked[0] ?? null);
            if (! $video) {
                return null;
            }

            $path = $this->dir().'/'.$video['id'].'.mp4';
            if (is_file($path)) {
                touch($path); // in use: cleanup keeps it
            } else {
                $tmp = $path.'.part';
                Http::timeout(120)->sink($tmp)->get($video['url'])->throw();
                rename($tmp, $path);
            }

            // Stock clips often open on a fade or a still frame: start a second in when there is room
            return ['id' => $video['id'], 'path' => $path, 'offset' => ($video['duration'] ?? 0) >= 6 ? 1.0 : 0.0];
        } catch (Throwable $e) {
            Log::channel('clipper_jobs')->warning('Stock footage unavailable', ['provider' => $provider, 'query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Words that tell results apart; "boxer" and "boxing" both count as "box".
     * Words found on half of all stock clips ("people", "man", "work") are dropped.
     */
    public static function terms(string $text): array
    {
        $stop = ['a', 'an', 'the', 'in', 'on', 'of', 'with', 'at', 'and', 'to', 'for', 'by', 'from', 'into', 'video', 'stock', 'footage',
            'people', 'person', 'man', 'men', 'woman', 'women', 'two', 'one', 'group', 'young', 'work', 'day', 'life', 'close', 'view', 'slow', 'motion'];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (in_array($w, $stop, true)) {
                continue;
            }
            foreach (['ing', 'ers', 'er', 'es', 'ed', 's'] as $suffix) {
                if (str_ends_with($w, $suffix) && strlen($w) - strlen($suffix) >= 3) {
                    $w = substr($w, 0, -strlen($suffix));
                    break;
                }
            }
            $out[] = $w;
        }

        return array_values(array_unique(array_filter($out, fn ($w) => strlen($w) >= 3 || is_numeric($w))));
    }

    /**
     * Usable results, most relevant first (ties keep the provider's order), each
     * with its score. $strict (no AI to look) asks more: without $must words a
     * query of 3+ words needs two of them matched, since one shared word
     * ("hands") is how a tap washing hands turns up for "couple holding hands".
     *
     * @return list<array>
     */
    private function rank(array $results, string $query, array $must, array $skip, bool $strict): array
    {
        $want = self::terms($query);
        $min = $strict && ! $must && count($want) >= 3 ? 2 : 1;
        $out = [];
        foreach ($results as $video) {
            $tags = (string) ($video['tags'] ?? '');
            if (in_array($video['id'], $skip, true) || ! empty($video['poor']) || preg_match('/green ?(screen|box)|chroma/i', $tags)) {
                continue;
            }
            $have = self::terms($tags);
            if ($must && ! array_intersect($must, $have)) {
                continue;
            }
            $score = count(array_intersect($want, $have)) + count(array_intersect($must, $have));
            if ($score >= $min) {
                $out[] = ['score' => $score] + $video;
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * The candidate the AI picks from their thumbnails, remembered per query and
     * candidate set so a re-render asks nothing. If the AI cannot answer, only a
     * strong tag match (2+ words) is used.
     */
    private function judged(array $candidates, string $query, callable $judge): ?array
    {
        $candidates = array_values(array_filter($candidates, fn ($v) => ($v['thumb'] ?? '') !== ''));
        if (! $candidates) {
            return null;
        }
        $cache = $this->dir().'/j_'.md5(mb_strtolower(trim($query)).'|'.implode(',', array_column($candidates, 'id'))).'.json';
        if (is_file($cache)) {
            $id = json_decode(file_get_contents($cache), true)['id'] ?? null;

            return collect($candidates)->firstWhere('id', $id);
        }

        try {
            $images = array_map(function ($v) {
                $res = Http::timeout(20)->get($v['thumb'])->throw();

                return ['mime' => explode(';', $res->header('Content-Type') ?: 'image/jpeg')[0], 'data' => base64_encode($res->body())];
            }, $candidates);
            $pick = $judge($images);
        } catch (Throwable $e) {
            Log::channel('clipper_jobs')->warning('B-roll judge unavailable', ['query' => $query, 'error' => $e->getMessage()]);

            return $candidates[0]['score'] >= 2 ? $candidates[0] : null;
        }

        $video = $pick === null ? null : ($candidates[$pick] ?? null);
        file_put_contents($cache, json_encode(['id' => $video['id'] ?? null]));

        return $video;
    }

    private function provider(): ?string
    {
        return match (true) {
            (string) config('services.pexels.key') !== '' => 'pexels',
            (string) config('services.pixabay.key') !== '' => 'pixabay',
            default => null,
        };
    }

    /** @return list<array{id: string, url: string, tags: string, duration: float, thumb: string, poor?: bool}> in the provider's order */
    private function search(string $provider, string $query): array
    {
        $cache = $this->dir().'/q3_'.$provider.'_'.md5(mb_strtolower(trim($query))).'.json';
        if (is_file($cache)) {
            touch($cache);

            return json_decode(file_get_contents($cache), true) ?: [];
        }

        $out = $provider === 'pexels' ? $this->searchPexels($query) : $this->searchPixabay($query);
        file_put_contents($cache, json_encode($out));

        return $out;
    }

    /** Portrait files about 1080 wide */
    private function searchPexels(string $query): array
    {
        $res = Http::withHeaders(['Authorization' => (string) config('services.pexels.key')])
            ->timeout(20)
            ->get(self::PEXELS, ['query' => $query, 'orientation' => 'portrait', 'size' => 'medium', 'per_page' => 10])
            ->throw()
            ->json();

        $out = [];
        foreach ($res['videos'] ?? [] as $v) {
            if (($v['duration'] ?? 0) < 3) {
                continue; // too short to fill a cut-in
            }
            $files = array_filter($v['video_files'] ?? [], fn ($f) => ($f['file_type'] ?? '') === 'video/mp4'
                && ($f['height'] ?? 0) > ($f['width'] ?? 0) && ($f['width'] ?? 0) >= 720);
            // the smallest file that is still at least 1080 wide, else the widest
            usort($files, fn ($a, $b) => [($a['width'] < 1080), $a['width']] <=> [($b['width'] < 1080), $b['width']]);
            $best = $files ? (($files[0]['width'] >= 1080) ? $files[0] : end($files)) : null;
            if ($best) {
                // Pexels has no tags; its page slug names the shot ("man-boxing-in-a-gym-854982")
                $slug = basename(parse_url((string) ($v['url'] ?? ''), PHP_URL_PATH) ?: '');
                $out[] = ['id' => 'px'.$v['id'], 'url' => (string) $best['link'], 'tags' => str_replace('-', ' ', $slug),
                    'duration' => (float) $v['duration'], 'thumb' => (string) ($v['image'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Pixabay has no orientation filter and is mostly landscape: the render
     * crops the middle 9:16, so a file needs 1080 of height (portrait ones first).
     * Real footage only: its animations (3D renders) look like stock, and AI-made or
     * low-quality clips are marked poor.
     */
    private function searchPixabay(string $query): array
    {
        $res = Http::timeout(20)
            ->get(self::PIXABAY, ['key' => (string) config('services.pixabay.key'), 'q' => $query, 'video_type' => 'film', 'per_page' => 20, 'safesearch' => 'true'])
            ->throw()
            ->json();

        $portrait = [];
        $landscape = [];
        foreach ($res['hits'] ?? [] as $v) {
            if (($v['duration'] ?? 0) < 3 || ($v['type'] ?? 'film') !== 'film') {
                continue;
            }
            // the smallest rendition with enough height for a 1920-high frame crop
            $fits = array_filter($v['videos'] ?? [], fn ($f) => ($f['url'] ?? '') !== '' && min($f['width'] ?? 0, $f['height'] ?? 0) >= 1080);
            usort($fits, fn ($a, $b) => ($a['width'] * $a['height']) <=> ($b['width'] * $b['height']));
            if (! $fits) {
                continue;
            }
            $hit = ['id' => 'pb'.$v['id'], 'url' => (string) $fits[0]['url'], 'tags' => (string) ($v['tags'] ?? ''), 'duration' => (float) $v['duration'],
                'thumb' => (string) ($v['videos']['medium']['thumbnail'] ?? $fits[0]['thumbnail'] ?? ''),
                'poor' => ! empty($v['isAiGenerated']) || ! empty($v['isLowQuality'])];
            if ($fits[0]['height'] > $fits[0]['width']) {
                $portrait[] = $hit;
            } else {
                $landscape[] = $hit;
            }
        }

        return array_merge($portrait, $landscape);
    }

    private function dir(): string
    {
        $dir = (string) (config('services.broll_cache') ?: storage_path('app/broll'));
        @mkdir($dir, 0755, true);

        return $dir;
    }
}
