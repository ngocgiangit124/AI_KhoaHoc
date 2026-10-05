<?php

namespace App\Services\Video\Exceptions;

use RuntimeException;

/** Lỗi phía nhà cung cấp video (mạng, 5xx, chưa cấu hình...). Không chứa secret trong message. */
class VideoProviderException extends RuntimeException {}
