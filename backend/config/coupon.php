<?php

/*
|--------------------------------------------------------------------------
| Mã giảm giá phía học sinh (US-004, S18)
|--------------------------------------------------------------------------
|
| `max_failed_per_day*`: trần số lần áp mã THẤT BẠI trong 24 giờ (chống dò
| mã, S18). Chỉ đếm lần sai; lần áp mã thành công được hoàn lại.
| Theo IP đặt cao hơn theo tài khoản vì lớp học/trường dùng chung NAT.
*/

return [
    'max_failed_per_day' => (int) env('COUPON_MAX_FAILED_PER_DAY', 30),
    'max_failed_per_day_per_ip' => (int) env('COUPON_MAX_FAILED_PER_DAY_PER_IP', 100),
];
