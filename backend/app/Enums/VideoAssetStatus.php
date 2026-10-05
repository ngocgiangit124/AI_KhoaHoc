<?php

namespace App\Enums;

enum VideoAssetStatus: string
{
    case Created = 'created';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
