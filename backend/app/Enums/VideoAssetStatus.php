<?php

namespace App\Enums;

/**
 * `video_assets.status` (ADR-002, data-model §3.2).
 */
enum VideoAssetStatus: string
{
    case Created = 'created';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
