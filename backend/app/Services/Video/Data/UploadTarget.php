<?php

namespace App\Services\Video\Data;

use Carbon\CarbonImmutable;

final readonly class UploadTarget
{
    /**
     * @param  array<string, string>  $headers  AuthorizationSignature, AuthorizationExpire, VideoId, LibraryId
     */
    public function __construct(
        public string $protocol,
        public string $endpoint,
        public array $headers,
        public CarbonImmutable $expiresAt,
    ) {}
}
