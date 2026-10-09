<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Thời gian trả cho FE: ISO 8601 có offset giờ Việt Nam (`+07:00`) bất kể múi giờ của app (api-contract §2.3.1). */
final class VnTime
{
    public static function iso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone((string) config('privacy.age_timezone', 'Asia/Ho_Chi_Minh'))->toIso8601String();
    }
}
