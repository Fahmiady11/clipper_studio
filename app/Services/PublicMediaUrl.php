<?php

namespace App\Services;

use App\Models\GeneratedClip;
use Illuminate\Support\Facades\URL;

/**
 * Expiring links to a rendered clip for platforms that download the video
 * themselves (Instagram Reels, Threads). The link needs no login; the clip's
 * UUID and the signature make it unguessable, the expiry keeps it short-lived.
 * APP_URL has to be reachable from the internet for Meta to fetch it.
 */
class PublicMediaUrl
{
    public function clip(GeneratedClip $clip, ?int $minutes = null): string
    {
        $minutes ??= (int) config('services.clipper.public_media_minutes', 120);
        $path = URL::temporarySignedRoute('media.clip', now()->addMinutes($minutes), ['clipId' => $clip->id], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }
}
