<?php

namespace App\Services\Social;

use Illuminate\Support\Collection;

/** Every platform clips can be published to, keyed by SocialPublisher::platform(). */
class SocialPublishers
{
    /** @var array<string, SocialPublisher> */
    private array $byPlatform = [];

    public function __construct(TikTokPublisher $tiktok, InstagramPublisher $instagram, YouTubePublisher $youtube)
    {
        foreach ([$tiktok, $instagram, $youtube] as $publisher) {
            $this->byPlatform[$publisher->platform()] = $publisher;
        }
    }

    /** @return Collection<string, SocialPublisher> */
    public function all(): Collection
    {
        return collect($this->byPlatform);
    }

    public function get(string $platform): SocialPublisher
    {
        return $this->byPlatform[$platform] ?? abort(404, "Platform tidak dikenal: {$platform}");
    }
}
