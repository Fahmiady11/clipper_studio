<?php

namespace App\Services;

use App\Models\GeneratedClip;

/** Autopilot to Facebook Page Reels. */
class FacebookAutopost extends MetaAutopost
{
    public function __construct(MetaPublishing $publishing, private MetaGraph $graph)
    {
        parent::__construct($publishing);
    }

    protected function platform(): string
    {
        return 'facebook';
    }

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function missingAccount(): string
    {
        return 'Page Facebook untuk autopilot belum dipilih atau sudah diputus. Pilih Page di pengaturan Facebook.';
    }

    /** The Reel description: AI caption with music credit, then the hashtags. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array
    {
        return [
            'description' => mb_substr(trim($caption."\n\n".implode(' ', $hashtags)), 0, 5000),
            'autopilot' => true,
        ];
    }
}
