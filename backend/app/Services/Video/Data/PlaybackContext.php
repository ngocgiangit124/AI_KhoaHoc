<?php

namespace App\Services\Video\Data;

final readonly class PlaybackContext
{
    public function __construct(
        public int $userId,
        public ?string $ip,
        public int $ttlSeconds,
    ) {}
}
