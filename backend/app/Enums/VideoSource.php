<?php

namespace App\Enums;

/**
 * `lessons.video_source` (US-009 BR10, data-model §3.2).
 */
enum VideoSource: string
{
    case None = 'none';
    case Upload = 'upload';
    case ExternalLink = 'external_link';
}
