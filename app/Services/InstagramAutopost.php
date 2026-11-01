<?php

namespace App\Services;

use App\Models\GeneratedClip;

/** Autopilot to Instagram Reels on an account linked to a connected Page. */
class InstagramAutopost extends MetaAutopost
{
    public function __construct(MetaPublishing $publishing, private MetaGraph $graph)
    {
        parent::__construct($publishing);
    }

    protected function platform(): string
    {
        return 'instagram';
    }

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function missingAccount(): string
    {
        return 'Belum ada akun Instagram yang terhubung. Hubungkan di Akun terhubung.';
    }

    /** Caption with music credit, then at most 30 hashtags, within 2200 characters. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array
    {
        $tags = implode(' ', array_slice($hashtags, 0, InstagramReels::MAX_HASHTAGS));

        return [
            'caption' => mb_substr(trim($caption."\n\n".$tags), 0, InstagramReels::CAPTION_MAX),
            'autopilot' => true,
        ];
    }
}
