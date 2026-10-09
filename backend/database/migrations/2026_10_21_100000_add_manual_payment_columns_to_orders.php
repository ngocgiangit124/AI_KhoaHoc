<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-022 (T38.1, DBA 2026-10-08, docs/review/T38-dba.md): thêm 3 cột nullable vào `orders` cho thanh toán thủ công.
 *
 *  - Thêm cột: 2 câu ALTER riêng, KHÔNG dùng `->after()` → MySQL 8.4 chọn ALGORITHM=INSTANT (đã đo trên bảng 200k dòng có cột
 *    generated STORED `pending_flag` + unique `(user_id, pending_flag)`), không khoá, không rebuild.
 *  - FK `confirmed_by` → users: giữ FK nhưng thêm dưới `foreign_key_checks=0` để MySQL dùng INPLACE, LOCK=NONE (mặc định là COPY,
 *    chặn ghi cả bảng). An toàn vì cột mới toàn NULL nên không dòng nào cần kiểm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_note', 500)->nullable();
            $table->string('cancel_reason_public', 500)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_by')->nullable();
        });

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('confirmed_by')->references('id')->on('users')->restrictOnDelete();
            });
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_note', 'cancel_reason_public', 'confirmed_by']);
        });
    }
};
