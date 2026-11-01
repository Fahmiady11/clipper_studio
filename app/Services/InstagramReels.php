<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Support\RenderedVideo;
use RuntimeException;
use Throwable;

/**
 * Instagram Reels on a professional account linked to a connected Page,
 * through the Instagram API with Facebook Login (graph.facebook.com, the
 * Page token): a REELS container that Meta fills by downloading our signed
 * video URL, then media_publish once the container is FINISHED.
 * developers.facebook.com/docs/instagram-platform/content-publishing
 */
class InstagramReels implements MetaPublisher
{
    // Reels video specifications of POST /{ig-user-id}/media
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 900;

    private const MAX_WIDTH = 1920;

    private const MIN_ASPECT = 0.01;

    private const MAX_ASPECT = 10;

    private const MAX_MB = 300;

    public const CAPTION_MAX = 2200;

    public const MAX_HASHTAGS = 30;

    // Containers expire after a day
    private const CONTAINER_HOURS = 24;

    public function __construct(private MetaGraph $graph, private PublicMediaUrl $media) {}

    public function name(): string
    {
        return 'Instagram';
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        if ($problem = $this->media->problem()) {
            return "Instagram mengambil video lewat link publik: {$problem}";
        }
        $video = RenderedVideo::of($clip);

        return match (true) {
            $video->seconds < self::MIN_SECONDS => 'Reels Instagram minimal '.self::MIN_SECONDS.' detik, clip ini '.round($video->seconds, 1).' detik.',
            $video->seconds > self::MAX_SECONDS + 0.5 => 'Reels Instagram maksimal 15 menit, clip ini '.round($video->seconds).' detik.',
            $video->hasFrame() && $video->width > self::MAX_WIDTH => 'Lebar video Reels Instagram maksimal '.self::MAX_WIDTH." piksel, clip ini {$video->frame()}.",
            $video->hasFrame() && ($video->aspect() < self::MIN_ASPECT || $video->aspect() > self::MAX_ASPECT) => "Rasio video tidak diterima Instagram, clip ini {$video->frame()}.",
            $video->megabytes() > self::MAX_MB => 'File Reels Instagram maksimal '.self::MAX_MB." MB, clip ini {$video->megabytes()} MB.",
            default => null,
        };
    }

    /** Why Instagram would refuse this caption, or null. */
    public static function captionProblem(string $caption): ?string
    {
        $hashtags = preg_match_all('/(^|\s)#[\p{L}\p{N}_]+/u', $caption);

        return match (true) {
            mb_strlen($caption) > self::CAPTION_MAX => 'Caption Instagram maksimal '.self::CAPTION_MAX.' karakter, caption ini '.mb_strlen($caption).'.',
            $hashtags > self::MAX_HASHTAGS => 'Caption Instagram maksimal '.self::MAX_HASHTAGS." hashtag, caption ini punya {$hashtags}.",
            default => null,
        };
    }

    public function start(MetaTarget $target, GeneratedClip $clip, string $text): array
    {
        if ($problem = self::captionProblem($text)) {
            throw new RuntimeException($problem);
        }
        $this->checkQuota($target);

        $container = $this->graph->post("{$target->external_id}/media", $target->token(), [
            'media_type' => 'REELS',
            'video_url' => $this->media->clip($clip),
            'caption' => $text,
            'share_to_feed' => 'true',
        ], 'buat container Reel', 'Instagram');

        return ['container_id' => (string) ($container['id'] ?? throw new RuntimeException('Instagram tidak mengembalikan id container.'))];
    }

    /** Wait for Meta to finish the download, then publish and fetch the link. */
    public function advance(MetaTarget $target, ClipPublication $publication): array
    {
        $container = $this->graph->get($publication->container_id, $target->token(), ['fields' => 'status_code,status'], 'cek container', 'Instagram');
        $failed = fn (string $reason) => ['status' => 'failed', 'reason' => $reason, 'url' => null];

        return match ($container['status_code'] ?? null) {
            'FINISHED' => $this->publish($target, $publication->container_id),
            'ERROR' => $failed('Instagram gagal memproses video'.(empty($container['status']) ? '.' : ": {$container['status']}.")),
            'EXPIRED' => $failed('Container Instagram kedaluwarsa sebelum dipublikasikan.'),
            'PUBLISHED' => ['status' => 'published', 'reason' => null, 'url' => null],
            default => ['status' => 'processing', 'reason' => null, 'url' => null],
        };
    }

    public function giveUpHours(): int
    {
        return self::CONTAINER_HOURS;
    }

    public function givenUp(): array
    {
        return ['status' => 'failed', 'reason' => 'Instagram tidak selesai memproses video dalam 24 jam, jadi tidak dipublikasikan. Jadwalkan ulang.'];
    }

    /**
     * Instagram caps API posts per account per 24 hours (50 at the time of
     * writing); ask before spending a container or a publish on it.
     */
    public function checkQuota(MetaTarget $target): void
    {
        $limit = $this->graph->get("{$target->external_id}/content_publishing_limit", $target->token(), ['fields' => 'quota_usage,config'], 'cek batas posting', 'Instagram')['data'][0] ?? null;
        $used = (int) ($limit['quota_usage'] ?? 0);
        $total = (int) ($limit['config']['quota_total'] ?? 0);
        if ($total > 0 && $used >= $total) {
            throw new RuntimeException("Batas {$total} posting Instagram lewat API dalam 24 jam sudah tercapai ({$used}/{$total}). Coba lagi besok.");
        }
    }

    private function publish(MetaTarget $target, string $containerId): array
    {
        $this->checkQuota($target);
        $media = $this->graph->post("{$target->external_id}/media_publish", $target->token(), ['creation_id' => $containerId], 'publish Reel', 'Instagram');
        $mediaId = (string) ($media['id'] ?? throw new RuntimeException('Instagram tidak mengembalikan id media.'));

        $url = null;
        try {
            $url = $this->graph->get($mediaId, $target->token(), ['fields' => 'permalink'], 'ambil link', 'Instagram')['permalink'] ?? null;
        } catch (Throwable) {
            // Published either way; the card just has no link
        }

        return ['status' => 'published', 'reason' => null, 'url' => $url, 'external_id' => $mediaId];
    }
}
