<?php

namespace App\Services\Social;

use App\Models\GeneratedClip;
use Illuminate\Support\Collection;

/**
 * What the app needs from a platform that finished clips can be sent to:
 * connect an account through OAuth, list and drop accounts, publish a clip
 * and report how that went.
 */
interface SocialPublisher
{
    /** Stable key used in routes, config and the clip status columns: tiktok, youtube, instagram. */
    public function platform(): string;

    public function label(): string;

    /** False until the OAuth client credentials are in .env. */
    public function isConfigured(): bool;

    /** Where the "connect" button sends the user: the route that starts the OAuth flow. */
    public function connectUrl(): string;

    /** A page that manages this platform's accounts itself; null when the connections page does it. */
    public function manageUrl(): ?string;

    /** Provider consent screen, carrying the CSRF state. */
    public function authorizeUrl(string $state): string;

    /** Exchange the OAuth code and store or refresh the connected account. */
    public function callback(int $userId, string $code): ConnectedAccount;

    /** @return Collection<int, ConnectedAccount> */
    public function accounts(int $userId): Collection;

    public function disconnect(int $userId, int $accountId): void;

    /**
     * Queue the clip for upload and mark it "queued". Options are platform
     * specific (caption, privacy, ...). Throws PublishRefused when the clip or
     * the account cannot be used.
     *
     * @param  array<string, mixed>  $options
     */
    public function publish(GeneratedClip $clip, int $accountId, string $mode = 'default', array $options = []): void;

    /** Where this clip stands on this platform: null status until it was ever sent. */
    public function status(GeneratedClip $clip): PublishStatus;
}
