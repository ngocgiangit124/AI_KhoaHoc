<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 270)->unique();
            $table->string('short_description', 500)->nullable();
            $table->mediumText('description')->nullable();
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedInteger('price')->default(0);
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->integer('manual_order')->nullable();
            $table->unsignedInteger('enrollments_count')->default(0);
            $table->string('search_text', 1000)->default('');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Danh mục công khai: lọc status + grade_level.
            $table->index(['status', 'grade_level']);
        });

        DB::statement('ALTER TABLE courses ADD CONSTRAINT chk_courses_grade_level CHECK (grade_level BETWEEN 6 AND 12)');

        // Pivot chuyên đề: xoá khoá học thì gỡ liên kết; chuyên đề đang gán thì chặn xoá cứng (US-011 AC3).
        Schema::create('course_subject', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();

            $table->primary(['course_id', 'subject_id']);
            $table->index('subject_id');
        });

        // "user phải là giao_vien" và "tối thiểu 1 giáo viên" do CourseTeacherService (T08) bảo đảm.
        Schema::create('course_teacher', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            // DB tự điền thời điểm gán (relation attach() không ghi timestamp vì pivot chỉ có created_at).
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['course_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_teacher');
        Schema::dropIfExists('course_subject');

        DB::statement('ALTER TABLE courses DROP CHECK chk_courses_grade_level');
        Schema::dropIfExists('courses');
    }
};
