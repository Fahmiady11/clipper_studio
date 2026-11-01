<?php

namespace App\Services;

use App\Models\ClipPublication;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;

/**
 * One Meta platform's publishing API, kept in its own service so an API
 * change stays in one file. PublishToMetaJob calls start(); MetaStatusPoller
 * calls advance() until the post is published or failed.
 */
interface MetaPublisher
{
    /** "Facebook", "Instagram", "Threads" */
    public function name(): string;

    /** Why this clip can't go to the platform, in Indonesian, or null. */
    public function refusal(GeneratedClip $clip): ?string;

    /**
     * Send the clip. Returns the ClipPublication attributes to store:
     * external_id (a posted video) or container_id (still to be published).
     */
    public function start(MetaTarget $target, GeneratedClip $clip, string $text): array;

    /**
     * One status step for a publication in "processing".
     *
     * @return array{status: string, reason: ?string, url: ?string, external_id?: string}
     */
    public function advance(MetaTarget $target, ClipPublication $publication): array;

    /** After this many hours in processing the poller stops asking. */
    public function giveUpHours(): int;

    /** @return array{status: string, reason: string} what a publication becomes when the poller gives up */
    public function givenUp(): array;
}
