<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mã giảm giá quản trị (US-013, data-model §3.5). `code` lưu UPPERCASE
     * (chuẩn hoá ở `CouponRequest`); unique dựa vào collation
     * utf8mb4_0900_ai_ci của connection (không phân biệt hoa/thường — BR1,
     * giống `subjects.name` ở T06/T07).
     *
     * CHECK đặt tên tường minh (data-model §5) để `down()`/rollback chạy được
     * `DROP CHECK`:
     * - `chk_coupons_percent_range` (DBA #5, docs/db/design-review.md §2.9):
     *   `discount_type = 'percent'` chỉ nhận `discount_value` 1–100.
     * - `chk_coupons_full_discount_limited` (DBA #5, S18): mã giảm 100% bắt
     *   buộc có `max_uses` + `valid_until` (chống lộ mã bị khai thác hàng
     *   loạt / giữ chỗ ảo vô thời hạn). Trường hợp mã `fixed_amount` ≥ giá
     *   khóa rẻ nhất đang bán KHÔNG thể biểu diễn bằng CHECK tĩnh (phụ thuộc
     *   dữ liệu `courses.price` thay đổi theo thời gian) — kiểm ở tầng App
     *   (`CouponRequest`) + ghi `audit_logs` (data-model §3.5).
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255)->nullable();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('max_uses')->nullable();
            // Denormalize (S17 — không fillable, chỉ đổi qua CouponService/
            // CouponUsedCountRecounter). Nguồn sự thật thật sự là số dòng
            // `coupon_usages` (bảng tạo ở T18, cần FK `orders`).
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->string('status', 20)->default('active');
            // false = toàn bộ khóa học; true = theo coupon_course ∪ coupon_subject.
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'valid_until']);
        });

        DB::statement(
            'ALTER TABLE coupons ADD CONSTRAINT chk_coupons_percent_range '
            ."CHECK (discount_type <> 'percent' OR discount_value BETWEEN 1 AND 100)"
        );

        DB::statement(
            'ALTER TABLE coupons ADD CONSTRAINT chk_coupons_full_discount_limited '
            ."CHECK (NOT (discount_type = 'percent' AND discount_value = 100) "
            .'OR (max_uses IS NOT NULL AND valid_until IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
