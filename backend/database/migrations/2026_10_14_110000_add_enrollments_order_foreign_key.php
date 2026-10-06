<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T07 chỉ tạo cột + index (chưa có `orders`). Từ T18: không được xoá đơn đang là nguồn của quyền học.
        // Lưu ý: nếu môi trường đã có enrollments.order_id trỏ vào đơn không tồn tại, migration sẽ lỗi
        // (không tự sửa dữ liệu) — dọn dữ liệu thủ công trước khi chạy.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreign('order_id', 'enrollments_order_id_foreign')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign('enrollments_order_id_foreign');
        });
    }
};
