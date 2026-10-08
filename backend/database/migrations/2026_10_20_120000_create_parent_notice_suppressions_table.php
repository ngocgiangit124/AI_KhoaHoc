<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-006 (T29, review R2): danh sách chặn thư thông báo phụ huynh theo ĐỊA CHỈ. Phụ huynh đã huỷ nhận thì địa chỉ đó
     * không nhận thư nữa dù học sinh xoá rồi thêm lại hay tạo tài khoản mới. Chỉ lưu HMAC có khoá (không lưu email rõ).
     */
    public function up(): void
    {
        Schema::create('parent_notice_suppressions', function (Blueprint $table) {
            $table->id();
            $table->char('email_hmac', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_notice_suppressions');
    }
};
