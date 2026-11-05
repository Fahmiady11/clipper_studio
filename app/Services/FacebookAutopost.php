<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Support\CaptionCredit;

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
        return 'Belum ada Page Facebook yang terhubung. Hubungkan di Akun terhubung.';
    }

    /** The Reel description: AI caption with music credit, then the hashtags. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags, ?string $credit = null): array
    {
        return [
            'description' => CaptionCredit::compose($caption, $credit, implode(' ', $hashtags), 5000),
            'autopilot' => true,
        ];
    }
}
