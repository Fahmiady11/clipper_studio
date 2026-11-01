<?php

namespace App\Services\Social;

final readonly class ConnectedAccount
{
    public function __construct(
        public int $id,
        public string $platform,
        public string $name,
        public ?string $avatarUrl = null,
    ) {}

    /** @return array{id: int, name: string, avatar_url: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'avatar_url' => $this->avatarUrl];
    }
}
