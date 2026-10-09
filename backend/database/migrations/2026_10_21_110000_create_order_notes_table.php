<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-022 (T38.1, BR18): ghi chú nội bộ của Quản trị viên trên đơn. Chỉ thêm, không sửa/xoá. Đơn không bao giờ bị xoá nên
 * cascade chỉ để dọn dữ liệu test (cùng `order_status_logs`). Index `(order_id, id)` đồng thời là chỉ mục FK của `order_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('body', 1000);
            $table->dateTime('created_at');

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
    }
};
