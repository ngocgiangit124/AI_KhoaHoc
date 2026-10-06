<?php

namespace App\VideoLab\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * File TUS đã nhận đủ nhưng không phải video hợp lệ → 422 `VIDEO_INVALID`. Tự dựng response JSON (cùng dạng envelope
 * `{message, code}` của api-contract §1.7) để VideoLab không phụ thuộc `App\Exceptions`/`App\Support` (ADR-002 §1).
 */
class VideoInvalidUploadException extends RuntimeException
{
    public const CODE = 'VIDEO_INVALID';

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 422);
    }
}
