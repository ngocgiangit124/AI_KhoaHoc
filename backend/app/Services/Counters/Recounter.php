<?php

namespace App\Services\Counters;

/**
 * Đối soát 1 bộ đếm denormalize cho `counters:recount` (data-model §7 "Bộ
 * đếm denormalize" — cron hằng ngày 02:00, lịch chạy do T30 wiring vào
 * `routes/console.php`). Thêm bộ đếm mới = tạo 1 class implements interface
 * này rồi đăng ký vào mảng ở `App\Providers\CounterServiceProvider` — KHÔNG
 * sửa `CountersRecountCommand` (T15 sẽ thêm `CouponUsedCountRecounter` cho
 * `coupons.used_count` theo đúng mẫu này).
 */
interface Recounter
{
    /**
     * Tên bộ đếm, dùng khi log/hiển thị ở CLI (vd `courses.enrollments_count`).
     */
    public function label(): string;

    /**
     * Đối soát lại bộ đếm; trả về số bản ghi đã sửa vì lệch trước đó.
     */
    public function recount(): int;
}
