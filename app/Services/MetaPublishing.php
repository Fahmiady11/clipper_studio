<?php

namespace App\Services;

use RuntimeException;

/** The Meta platforms clips are published to, each with its own MetaPublisher. */
class MetaPublishing
{
    public const PLATFORMS = ['facebook', 'instagram', 'threads'];

    public function __construct(
        private FacebookReels $facebook,
        private InstagramReels $instagram,
        private ThreadsPosts $threads,
    ) {}

    public function publisher(string $platform): MetaPublisher
    {
        return match ($platform) {
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'threads' => $this->threads,
            default => throw new RuntimeException("Platform Meta tidak dikenal: {$platform}."),
        };
    }

    /** What the user calls the account in messages: "Page Facebook sudah diputus." */
    public static function targetNoun(string $platform): string
    {
        return ['facebook' => 'Page Facebook', 'instagram' => 'Akun Instagram', 'threads' => 'Profil Threads'][$platform];
    }

    /** Options key the calendar stores the post text under. */
    public static function textOption(string $platform): string
    {
        return ['facebook' => 'description', 'instagram' => 'caption', 'threads' => 'text'][$platform];
    }
}
