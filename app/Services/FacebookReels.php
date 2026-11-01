<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Support\RenderedVideo;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Page Reels through /{page-id}/video_reels in three phases: start (Meta
 * hands out a video id), the file bytes to rupload.facebook.com, then finish
 * with video_state=PUBLISHED and the description. Reels on a Page are always
 * public. Everything Reels-specific lives here so an API change stays in one file.
 *
 * The file is sent from disk rather than as file_url: Meta would have to reach
 * this server by a public URL that lets facebookexternalhit in, while a direct
 * upload works from any host, tunnel or not.
 */
class FacebookReels implements MetaPublisher
{
    // developers.facebook.com/docs/video-api/guides/reels-publishing → Video requirements
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 90;

    private const MIN_WIDTH = 540;

    private const MIN_HEIGHT = 960;

    private const ASPECT_TOLERANCE = 0.02;

    private const DESCRIPTION_MAX = 5000;

    public function __construct(private MetaGraph $graph) {}

    public function name(): string
    {
        return 'Facebook';
    }

    /** Why this rendered clip can't be a Facebook Reel, or null when it can. */
    public function refusal(GeneratedClip $clip): ?string
    {
        $video = RenderedVideo::of($clip);

        if ($video->seconds < self::MIN_SECONDS) {
            return 'Reels Facebook minimal '.self::MIN_SECONDS.' detik, clip ini '.round($video->seconds, 1).' detik.';
        }
        if ($video->seconds > self::MAX_SECONDS + 0.5) {
            return 'Reels Facebook maksimal '.self::MAX_SECONDS.' detik, clip ini '.round($video->seconds).' detik. Pendekkan clip dulu.';
        }
        if ($video->hasFrame() && abs($video->aspect() - 9 / 16) > self::ASPECT_TOLERANCE) {
            return "Reels Facebook harus vertikal 9:16, clip ini {$video->frame()}.";
        }
        if ($video->hasFrame() && ($video->width < self::MIN_WIDTH || $video->height < self::MIN_HEIGHT)) {
            return 'Resolusi Reels Facebook minimal '.self::MIN_WIDTH.'×'.self::MIN_HEIGHT.", clip ini {$video->frame()}.";
        }

        return null;
    }

    public function start(MetaTarget $target, GeneratedClip $clip, string $text): array
    {
        return ['external_id' => $this->publish($target, Storage::path($clip->output_path), $text)];
    }

    public function advance(MetaTarget $target, ClipPublication $publication): array
    {
        return $this->status($target, $publication->external_id);
    }

    public function giveUpHours(): int
    {
        return 48;
    }

    /** Like YouTube: the Reel went up and finish succeeded, Meta just never said "published". */
    public function givenUp(): array
    {
        return ['status' => 'published', 'reason' => 'Status akhir dari Facebook belum terkonfirmasi setelah 48 jam. Reel kemungkinan sudah tayang, cek di Page.'];
    }

    /** @return string the Reel's video id */
    public function publish(MetaTarget $page, string $path, string $description): string
    {
        $size = @filesize($path);
        if (! $size) {
            throw new RuntimeException("Video tidak ditemukan / kosong: {$path}");
        }
        $token = $page->token();

        $start = $this->graph->post("{$page->external_id}/video_reels", $token, ['upload_phase' => 'start'], 'mulai upload Reel');
        $videoId = (string) ($start['video_id'] ?? throw new RuntimeException('Facebook tidak mengembalikan video id.'));

        // Streamed from disk, not read into memory
        $res = Http::timeout(900)
            ->withHeaders(['Authorization' => 'OAuth '.$token, 'offset' => '0', 'file_size' => (string) $size])
            ->withBody(Utils::streamFor(fopen($path, 'rb')), 'application/octet-stream')
            ->post($start['upload_url'] ?? 'https://rupload.facebook.com/video-upload/'.$this->graph->version().'/'.$videoId);
        $this->graph->check($res, 'upload Reel');
        if ($res->json('success') !== true) {
            throw new RuntimeException('Facebook tidak mengonfirmasi upload Reel.');
        }

        $this->graph->post("{$page->external_id}/video_reels", $token, [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => mb_substr(trim($description), 0, self::DESCRIPTION_MAX),
        ], 'publish Reel');

        return $videoId;
    }

    /**
     * Where Meta is with a published Reel.
     *
     * @return array{status: string, reason: ?string, url: ?string} status: processing | published | failed
     */
    public function status(MetaTarget $page, string $videoId): array
    {
        $item = $this->graph->get($videoId, $page->token(), ['fields' => 'status,permalink_url'], 'cek status Reel');

        return self::statusFromItem($item, $videoId);
    }

    /** One video node (fields=status,permalink_url) to our status. */
    public static function statusFromItem(array $item, string $videoId): array
    {
        $s = $item['status'] ?? [];
        $phaseError = collect(['uploading_phase', 'processing_phase', 'publishing_phase'])
            ->map(fn ($phase) => ($s[$phase]['status'] ?? null) === 'error' ? ($s[$phase]['errors'][0]['message'] ?? $s[$phase]['error']['message'] ?? 'tanpa alasan') : null)
            ->filter()->first();
        $publish = $s['publishing_phase']['publish_status'] ?? null;
        $permalink = $item['permalink_url'] ?? null;
        $url = $permalink ? (str_starts_with($permalink, 'http') ? $permalink : 'https://www.facebook.com'.$permalink) : "https://www.facebook.com/reel/{$videoId}";
        $failed = fn (string $reason) => ['status' => 'failed', 'reason' => $reason, 'url' => null];

        return match (true) {
            $phaseError !== null => $failed("Facebook gagal memproses Reel: {$phaseError}."),
            in_array($s['video_status'] ?? '', ['error', 'upload_failed'], true) => $failed('Facebook gagal memproses Reel.'),
            ($s['video_status'] ?? '') === 'expired' => $failed('Upload Reel kedaluwarsa sebelum selesai.'),
            $publish === 'published' => ['status' => 'published', 'reason' => null, 'url' => $url],
            default => ['status' => 'processing', 'reason' => null, 'url' => null],
        };
    }
}
