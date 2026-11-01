<?php

namespace App\Services\Social;

final readonly class PublishStatus
{
    /** Every state a clip can be in on a platform, in the order it normally goes through them. */
    public const STATES = ['queued', 'uploading', 'processing', 'inbox', 'published', 'failed'];

    public function __construct(
        public ?string $status,
        public ?string $error = null,
        public ?string $url = null,
    ) {}

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'uploading', 'processing'], true);
    }

    /** @return array{status: ?string, error: ?string, url: ?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'error' => $this->error, 'url' => $this->url];
    }
}
