<?php

namespace App\Services;

use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Support\CaptionCredit;

/** Autopilot to Threads video posts. */
class ThreadsAutopost extends MetaAutopost
{
    public function __construct(MetaPublishing $publishing, private ThreadsGraph $graph)
    {
        parent::__construct($publishing);
    }

    protected function platform(): string
    {
        return 'threads';
    }

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function missingAccount(): string
    {
        return 'Belum ada profil Threads yang terhubung. Hubungkan di Akun terhubung.';
    }

    /** Threads turns only one hashtag into a topic: the caption and the first hashtag, cut to 500. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags, ?string $credit = null, ?PlatformSetting $settings = null): array
    {
        return [
            'text' => CaptionCredit::compose($caption, $credit, $hashtags[0] ?? '', ThreadsPosts::TEXT_MAX_BYTES, bytes: true),
            'autopilot' => true,
        ];
    }
}
