<?php

namespace App\Services\Video\Data;

use App\Enums\VideoAssetStatus;

final readonly class ProviderVideo
{
    public function __construct(
        public string $guid,
        public VideoAssetStatus $status,
        public ?int $durationSeconds = null,
        public ?string $errorMessage = null,
    ) {}
}
