<?php

namespace App\Services\Video\Data;

use Carbon\CarbonImmutable;

final readonly class PlaybackInfo
{
    /** @param  'hls'|'embed'  $kind */
    public function __construct(
        public string $kind,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
