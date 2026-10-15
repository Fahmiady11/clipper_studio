<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Portrait stock clips from Pexels for B-roll. Search results and the
 * downloaded files are cached, so a query costs one API call ever and a
 * re-render downloads nothing.
 */
class PexelsService
{
    private const API = 'https://api.pexels.com/videos/search';

    public function configured(): bool
    {
        return (string) config('services.pexels.key') !== '';
    }

    /**
     * A local MP4 for $query, or null when nothing fits or Pexels is unreachable.
     * $skip: Pexels video ids already used in this clip (no repeats).
     *
     * @return array{id: int, path: string}|null
     */
    public function clip(string $query, array $skip = []): ?array
    {
        if (! $this->configured() || trim($query) === '') {
            return null;
        }

        try {
            foreach ($this->search($query) as $video) {
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
            Log::channel('clipper_jobs')->warning('Pexels B-roll unavailable', ['query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** @return list<array{id: int, url: string}> portrait files about 1080 wide, best first */
    private function search(string $query): array
    {
        $cache = $this->dir().'/q_'.md5(mb_strtolower(trim($query))).'.json';
        if (is_file($cache)) {
            touch($cache);

            return json_decode(file_get_contents($cache), true) ?: [];
        }

        $res = Http::withHeaders(['Authorization' => (string) config('services.pexels.key')])
            ->timeout(20)
            ->get(self::API, ['query' => $query, 'orientation' => 'portrait', 'size' => 'medium', 'per_page' => 10])
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
                $out[] = ['id' => (int) $v['id'], 'url' => (string) $best['link']];
            }
        }
        file_put_contents($cache, json_encode($out));

        return $out;
    }

    private function dir(): string
    {
        $dir = (string) (config('services.pexels.cache') ?: storage_path('app/broll'));
        @mkdir($dir, 0755, true);

        return $dir;
    }
}
