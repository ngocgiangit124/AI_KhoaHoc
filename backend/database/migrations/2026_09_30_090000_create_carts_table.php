<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Giỏ hàng của học sinh (US-004 BR5, data-model §3.5): mỗi học sinh tối đa
     * 1 giỏ (UNIQUE `user_id`), tạo lazy khi thêm khóa đầu tiên. Dòng này là
     * KHOÁ TUẦN TỰ HOÁ mọi thao tác giỏ/checkout của học sinh (`lockForUpdate`
     * theo PK — data-model §6, thứ tự khoá `carts → orders → coupons`).
     *
     * `coupon_id` nullOnDelete: xoá mã (chỉ khi chưa dùng — T15) tự gỡ khỏi
     * giỏ. FK tạo sẵn index cho `coupon_id` (truy vấn ngược: mã đang nằm trong
     * bao nhiêu giỏ).
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
