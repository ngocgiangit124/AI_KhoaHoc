<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.2. CHECK đặt tên tường minh `chk_courses_grade_level`
     * (data-model §5) để `down()` chạy được `DROP CHECK`.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 270)->unique();
            $table->string('short_description', 500)->nullable();
            // HTML đã sanitize (Purifier §4, S8) — sanitize khi ghi VÀ khi đọc (T08/T10).
            $table->mediumText('description')->nullable();
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedInteger('price')->default(0);
            // Tên file ngẫu nhiên ({uuid}.webp), phục vụ từ STATIC_URL (S2) — T08.
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('manual_order')->nullable();
            // Denormalize (S17 — không fillable, chỉ đổi qua Service chuyên trách).
            $table->unsignedInteger('enrollments_count')->default(0);
            // Str::ascii(title + short_description) đã bỏ dấu, viết thường (US-002 AC5).
            $table->string('search_text', 1000)->default('');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'grade_level']);
        });

        DB::statement(
            'ALTER TABLE courses ADD CONSTRAINT chk_courses_grade_level '
            .'CHECK (grade_level BETWEEN 6 AND 12)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
