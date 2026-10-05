<?php

namespace App\Enums;

enum VideoSource: string
{
    case None = 'none';
    case Upload = 'upload';
    case ExternalLink = 'external_link';
}
