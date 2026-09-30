<?php

namespace App\Services\Counters;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đối soát `coupons.used_count` (denormalize) theo số dòng THẬT trong
 * `coupon_usages` (data-model §3.5, DBA #5 —
 * docs/db/design-review.md mục 2.3/2.9: "cân nhắc thêm cron
 * `coupons:recount-used` đối soát `used_count` giống `courses:recount-enrollments`").
 *
 * TODO(T18): `coupon_usages` chỉ được TẠO Ở T18 (Checkout) vì cần FK tới
 * `orders` (chưa tồn tại ở nhánh này — tasks.md T15 chỉ tạo `coupons` +
 * `coupon_course`/`coupon_subject`, xem data-model §5 mục migration 4 vs 6).
 * `recount()` tự phát hiện việc này qua `Schema::hasTable()` và BỎ QUA AN
 * TOÀN (không throw, không giả định/bịa cấu trúc bảng `coupon_usages` khi nó
 * chưa được T18 chốt) thay vì đoán bừa.
 *
 * **Nối vào `counters:recount` khi gộp T14/T18** (task khác tạo lệnh Artisan
 * này — xem tasks.md T14 "`counters:recount` (gồm `courses.enrollments_count`;
 * phần coupon thêm ở T15)"): trong `handle()` của lệnh, gọi
 * `app(CouponUsedCountRecounter::class)->recount();` cạnh phần đối soát
 * `courses.enrollments_count` đã có. KHÔNG tạo lệnh Artisan mới ở đây để
 * tránh xung đột merge với nhánh T14.
 */
class CouponUsedCountRecounter
{
    public function recount(): void
    {
        if (! Schema::hasTable('coupon_usages')) {
            return;
        }

        /** @var Collection<int, int> $actualCounts coupon_id => số lượt đã dùng thật */
        $actualCounts = DB::table('coupon_usages')
            ->select('coupon_id', DB::raw('COUNT(*) as total'))
            ->groupBy('coupon_id')
            ->pluck('total', 'coupon_id');

        $this->applyCounts($actualCounts);
    }

    /**
     * Tách riêng khỏi `recount()` để test được logic "áp số đếm vào từng mã"
     * mà KHÔNG cần bảng `coupon_usages` thật (bảng đó chưa tồn tại tới khi
     * T18 gộp — xem TODO(T18) ở trên). `counters:recount` (T14) chỉ cần gọi
     * `recount()`; `applyCounts()` public chủ yếu phục vụ unit test.
     *
     * @param  Collection<int, int>  $actualCounts  coupon_id => số lượt đã dùng thật
     */
    public function applyCounts(Collection $actualCounts): void
    {
        Coupon::query()->select(['id', 'used_count'])->chunkById(200, function (EloquentCollection $coupons) use ($actualCounts): void {
            foreach ($coupons as $coupon) {
                $actual = (int) ($actualCounts[$coupon->id] ?? 0);

                if ($coupon->used_count !== $actual) {
                    // S17 — `used_count` không nằm trong $fillable, gán trực
                    // tiếp thuộc tính (KHÔNG mass-assign) rồi lưu, giống
                    // `SubjectService::updateStatus()`.
                    $coupon->used_count = $actual;
                    $coupon->save();
                }
            }
        });
    }
}
