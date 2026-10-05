<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mã giảm giá (T15, US-013, data-model §3.5). `code` lưu UPPERCASE, unique theo collation `_ci`
        // (BR1: "TOAN2026" = "toan2026"). `max_uses_per_user` không có cột: cố định 1, ép bằng unique
        // (coupon_id, user_id) ở `coupon_usages` (T18). `coupon_usages` và FK orders.coupon_id do T18 tạo.
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->collation('utf8mb4_0900_ai_ci')->unique();
            $table->string('name', 255)->nullable();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'valid_until']);
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT chk_coupons_percent_range CHECK (discount_type <> 'percent' OR discount_value BETWEEN 1 AND 100)");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT chk_coupons_dates CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT chk_coupons_type CHECK (discount_type IN ('percent', 'fixed_amount'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT chk_coupons_full_discount_limited CHECK (NOT (discount_type = 'percent' AND discount_value = 100) OR (max_uses IS NOT NULL AND valid_until IS NOT NULL))");

        // Phạm vi = khóa trong coupon_course HOẶC thuộc ≥ 1 chuyên đề trong coupon_subject (khi is_restricted).
        Schema::create('coupon_course', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();

            $table->primary(['coupon_id', 'course_id']);
            $table->index('course_id');
        });

        // Xoá cứng chuyên đề (chỉ khi chưa gán khóa) thì gỡ khỏi phạm vi mã: phạm vi chỉ hẹp lại, không rộng ra.
        Schema::create('coupon_subject', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->primary(['coupon_id', 'subject_id']);
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_subject');
        Schema::dropIfExists('coupon_course');

        DB::statement('ALTER TABLE coupons DROP CHECK chk_coupons_type');
        DB::statement('ALTER TABLE coupons DROP CHECK chk_coupons_dates');
        DB::statement('ALTER TABLE coupons DROP CHECK chk_coupons_full_discount_limited');
        DB::statement('ALTER TABLE coupons DROP CHECK chk_coupons_percent_range');
        Schema::dropIfExists('coupons');
    }
};
