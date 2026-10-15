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

    public function configured(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * A local MP4 for $query, or null when nothing fits or the provider is unreachable.
     * $skip: ids already used in this clip (no repeats).
     *
     * @return array{id: string, path: string}|null
     */
    public function clip(string $query, array $skip = []): ?array
    {
        $provider = $this->provider();
        if (! $provider || trim($query) === '') {
            return null;
        }

        try {
            foreach ($this->search($provider, $query) as $video) {
                if (in_array($video['id'], $skip, true)) {
                    continue;
                }
                $path = $this->dir().'/'.$video['id'].'.mp4';
                if (is_file($path)) {
                    touch($path); // in use: cleanup keeps it
                } else {
                    $tmp = $path.'.part';
                    Http::timeout(120)->sink($tmp)->get($video['url'])->throw();
                    rename($tmp, $path);
                }

                return ['id' => $video['id'], 'path' => $path];
            }
        } catch (Throwable $e) {
            Log::channel('clipper_jobs')->warning('Stock footage unavailable', ['provider' => $provider, 'query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function provider(): ?string
    {
        return match (true) {
            (string) config('services.pexels.key') !== '' => 'pexels',
            (string) config('services.pixabay.key') !== '' => 'pixabay',
            default => null,
        };
    }

    /** @return list<array{id: string, url: string}> best first */
    private function search(string $provider, string $query): array
    {
        $cache = $this->dir().'/q_'.$provider.'_'.md5(mb_strtolower(trim($query))).'.json';
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
                $out[] = ['id' => 'px'.$v['id'], 'url' => (string) $best['link']];
            }
        }

        return $out;
    }

    /**
     * Pixabay has no orientation filter and is mostly landscape: the render
     * crops the middle 9:16, so a file needs 1080 of height (portrait ones first).
     */
    private function searchPixabay(string $query): array
    {
        $res = Http::timeout(20)
            ->get(self::PIXABAY, ['key' => (string) config('services.pixabay.key'), 'q' => $query, 'per_page' => 20, 'safesearch' => 'true'])
            ->throw()
            ->json();

        $portrait = [];
        $landscape = [];
        foreach ($res['hits'] ?? [] as $v) {
            if (($v['duration'] ?? 0) < 3) {
                continue;
            }
            // the smallest rendition with enough height for a 1920-high frame crop
            $fits = array_filter($v['videos'] ?? [], fn ($f) => ($f['url'] ?? '') !== '' && min($f['width'] ?? 0, $f['height'] ?? 0) >= 1080);
            usort($fits, fn ($a, $b) => ($a['width'] * $a['height']) <=> ($b['width'] * $b['height']));
            if (! $fits) {
                continue;
            }
            $hit = ['id' => 'pb'.$v['id'], 'url' => (string) $fits[0]['url']];
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
