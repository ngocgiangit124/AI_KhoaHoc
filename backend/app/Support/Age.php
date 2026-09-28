<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Tính tuổi tại thời điểm hiện tại theo giờ server (US-001 — "học sinh sinh
 * nhật đúng ngày đăng ký chuyển từ dưới 18 sang đủ 18 tuổi → tính theo ngày
 * hiện tại của server"). Dùng chung giữa `RegisterRequest` (validation) và
 * `RegistrationService` (nghiệp vụ) để không lệch quy tắc.
 */
final class Age
{
    public static function isMinor(Carbon $dateOfBirth, int $thresholdYears): bool
    {
        return $dateOfBirth->age < $thresholdYears;
    }
}
