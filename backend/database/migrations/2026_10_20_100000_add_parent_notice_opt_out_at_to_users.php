<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-006 (T29): thời điểm phụ huynh bấm "ngừng nhận thông báo". Gắn với `parent_email` hiện tại; đổi email phụ huynh
     * thì Service đặt lại NULL. Cột nullable thêm cuối bảng nên MySQL 8.4 làm bằng ALGORITHM=INSTANT (không khoá, không chép bảng).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('parent_notice_opt_out_at')->nullable()->after('parent_consent_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('parent_notice_opt_out_at');
        });
    }
};
