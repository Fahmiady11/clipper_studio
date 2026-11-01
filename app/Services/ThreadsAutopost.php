<?php

namespace App\Services;

use App\Models\GeneratedClip;

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
        return 'Profil Threads untuk autopilot belum dipilih atau sudah diputus. Pilih profil di pengaturan Threads.';
    }

    /** Threads turns only one hashtag into a topic: the caption and the first hashtag, cut to 500. */
    public function options(GeneratedClip $clip, string $caption, array $hashtags): array
    {
        return [
            'text' => ThreadsPosts::fit(trim($caption."\n\n".($hashtags[0] ?? ''))),
            'autopilot' => true,
        ];
    }
}
