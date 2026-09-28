<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DBA review T07/T10 (`docs/db/T07-review.md` §2.1) — MEDIUM: `GET
     * /courses` không tham số (sort mặc định "mới nhất", lưu lượng cao nhất)
     * quét toàn bảng (`type=ALL`) rồi `filesort` theo `published_at`, dù
     * `docs/stories/US-002-...md` mục "Ảnh hưởng dữ liệu" yêu cầu index trên
     * `courses.published_at`. Migration T07 (`2026_09_28_090100`) chỉ có
     * `(status, grade_level)` — thêm migration MỚI thay vì sửa migration đã
     * chạy (quy ước dự án), index ghép `(status, published_at)` giúp
     * `WHERE status='published' ORDER BY published_at DESC` không còn
     * `Using filesort` (MySQL 8 quét lùi index khi thiếu index DESC tường
     * minh).
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->index(['status', 'published_at'], 'courses_status_published_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex('courses_status_published_at_index');
        });
    }
};
