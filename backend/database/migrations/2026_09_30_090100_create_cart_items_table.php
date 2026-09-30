<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dòng giỏ hàng (US-004 BR2, data-model §3.5). UNIQUE (cart_id, course_id)
     * ở tầng DB chống 2 tab thêm trùng (race); index `cart_id` đã được unique
     * này phủ (tiền tố), `course_id` có index do FK. Không có `updated_at`
     * (dòng chỉ thêm/xoá, không sửa).
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['cart_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
