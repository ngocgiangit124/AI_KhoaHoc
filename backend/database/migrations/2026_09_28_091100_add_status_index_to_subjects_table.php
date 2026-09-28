<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DBA review T07/T10 (`docs/db/T07-review.md` mục 4, LOW) — migration T07
     * (`2026_09_28_090000`, bản giữ lại sau khi gộp T06+T07) không có
     * `$table->index('status')` như bản T06 tự viết trước đó. `GET
     * /admin/subjects` (`Admin\SubjectController@index`, US-011) lọc
     * `where('status', 'active')` cho Giáo Viên — thêm migration MỚI thay vì
     * sửa migration đã chạy (quy ước dự án). DBA ghi nhận bảng `subjects` chỉ
     * vài chục dòng nên không ảnh hưởng hiệu năng thực tế; thêm cho nhất
     * quán với các bảng lọc theo `status` khác.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->index('status', 'subjects_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropIndex('subjects_status_index');
        });
    }
};
