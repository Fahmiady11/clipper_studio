<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Support\RenderedVideo;
use RuntimeException;
use Throwable;

/**
 * Video posts on Threads: a VIDEO container that Meta fills by downloading
 * our signed video URL, then threads_publish once it is FINISHED (Meta asks
 * for about 30 seconds in between; the poller's minute covers that).
 * developers.facebook.com/docs/threads/posts
 */
class ThreadsPosts implements MetaPublisher
{
    public const MAX_SECONDS = 300;

    private const MAX_MB = 1024;

    // 500 characters, with emoji counted by their UTF-8 bytes: bytes are the safe measure
    public const TEXT_MAX_BYTES = 500;

    private const CONTAINER_HOURS = 24;

    private const ERRORS = [
        'FAILED_DOWNLOADING_VIDEO' => 'Threads tidak bisa mengunduh video dari server ini (cek APP_URL bisa dibuka dari internet)',
        'FAILED_PROCESSING_AUDIO' => 'audio gagal diproses',
        'FAILED_PROCESSING_VIDEO' => 'video gagal diproses',
        'INVALID_ASPEC_RATIO' => 'rasio video tidak didukung',
        'INVALID_BIT_RATE' => 'bitrate video tidak didukung',
        'INVALID_DURATION' => 'durasi video tidak didukung',
        'INVALID_FRAME_RATE' => 'frame rate video tidak didukung',
        'INVALID_AUDIO_CHANNELS' => 'jumlah kanal audio tidak didukung',
        'INVALID_AUDIO_CHANNEL_LAYOUT' => 'susunan kanal audio tidak didukung',
    ];

    public function __construct(private ThreadsGraph $graph, private PublicMediaUrl $media) {}

    public function name(): string
    {
        return 'Threads';
    }

    public function refusal(GeneratedClip $clip): ?string
    {
        if ($problem = $this->media->problem()) {
            return "Threads mengambil video lewat link publik: {$problem}";
        }
        $video = RenderedVideo::of($clip);

        return match (true) {
            $video->seconds > self::MAX_SECONDS + 0.5 => 'Video Threads maksimal 5 menit, clip ini '.round($video->seconds).' detik.',
            $video->megabytes() > self::MAX_MB => "File video Threads maksimal 1 GB, clip ini {$video->megabytes()} MB.",
            default => null,
        };
    }

    public static function textProblem(string $text): ?string
    {
        return strlen($text) > self::TEXT_MAX_BYTES
            ? 'Teks Threads maksimal '.self::TEXT_MAX_BYTES.' karakter (emoji dihitung lebih dari satu), teks ini '.strlen($text).'.'
            : null;
    }

    /** Cut to the limit at a word boundary, never inside a character. */
    public static function fit(string $text): string
    {
        $text = trim($text);
        if (strlen($text) <= self::TEXT_MAX_BYTES) {
            return $text;
        }
        $cut = mb_strcut($text, 0, self::TEXT_MAX_BYTES - 3);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > 0 ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    public function start(MetaTarget $target, GeneratedClip $clip, string $text): array
    {
        if ($problem = self::textProblem($text)) {
            throw new RuntimeException($problem);
        }
        $this->checkQuota($target);

        $container = $this->graph->post("{$target->external_id}/threads", $target->token(), array_filter([
            'media_type' => 'VIDEO',
            'video_url' => $this->media->clip($clip),
            'text' => $text,
        ]), 'buat container video');

        return ['container_id' => (string) ($container['id'] ?? throw new RuntimeException('Threads tidak mengembalikan id container.'))];
    }

    public function advance(MetaTarget $target, ClipPublication $publication): array
    {
        $container = $this->graph->get($publication->container_id, $target->token(), ['fields' => 'status,error_message'], 'cek container');
        $failed = fn (string $reason) => ['status' => 'failed', 'reason' => $reason, 'url' => null];

        return match ($container['status'] ?? null) {
            'FINISHED' => $this->publish($target, $publication->container_id),
            'ERROR' => $failed('Threads gagal memproses video: '.(self::ERRORS[$container['error_message'] ?? ''] ?? 'tanpa alasan').'.'),
            'EXPIRED' => $failed('Container Threads kedaluwarsa sebelum dipublikasikan.'),
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
        return ['status' => 'failed', 'reason' => 'Threads tidak selesai memproses video dalam 24 jam, jadi tidak dipublikasikan. Jadwalkan ulang.'];
    }

    /** Threads caps API posts per profile per 24 hours (250 at the time of writing). */
    public function checkQuota(MetaTarget $target): void
    {
        $limit = $this->graph->get("{$target->external_id}/threads_publishing_limit", $target->token(), ['fields' => 'quota_usage,config'], 'cek batas posting')['data'][0] ?? null;
        $used = (int) ($limit['quota_usage'] ?? 0);
        $total = (int) ($limit['config']['quota_total'] ?? 0);
        if ($total > 0 && $used >= $total) {
            throw new RuntimeException("Batas {$total} posting Threads lewat API dalam 24 jam sudah tercapai ({$used}/{$total}). Coba lagi besok.");
        }
    }

    private function publish(MetaTarget $target, string $containerId): array
    {
        $this->checkQuota($target);
        $post = $this->graph->post("{$target->external_id}/threads_publish", $target->token(), ['creation_id' => $containerId], 'publish');
        $postId = (string) ($post['id'] ?? throw new RuntimeException('Threads tidak mengembalikan id post.'));

        $url = null;
        try {
            $url = $this->graph->get($postId, $target->token(), ['fields' => 'permalink'], 'ambil link')['permalink'] ?? null;
        } catch (Throwable) {
            // Published either way; the card just has no link
        }

        return ['status' => 'published', 'reason' => null, 'url' => $url, 'external_id' => $postId];
    }
}
