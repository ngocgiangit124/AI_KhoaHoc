<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot khóa học <-> giáo viên đồng giảng dạy (data-model §3.2). Ràng buộc
     * "user phải là giao_vien" và "tối thiểu 1 giáo viên" ở `CourseTeacherService`
     * (T08) — không thể biểu diễn bằng CHECK constraint (cần tra bảng users).
     */
    public function up(): void
    {
        Schema::create('course_teacher', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['course_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_teacher');
    }
};
