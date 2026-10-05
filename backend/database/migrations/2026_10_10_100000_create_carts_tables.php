<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Giỏ hàng (T16, US-004, data-model §3.5). Mỗi học sinh tối đa 1 giỏ (U user_id); dòng `carts` là khoá
        // tuần tự hoá mọi thao tác giỏ/checkout. `coupon_id` null on delete: xoá mã (chỉ khi chưa dùng) thì giỏ tự gỡ mã.
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamps();
        });

        // U (cart_id, course_id) chặn trùng ở tầng DB (race 2 tab). IX course_id phục vụ FK + tra theo khóa.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cart_id', 'course_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
