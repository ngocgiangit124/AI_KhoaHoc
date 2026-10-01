<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.4. `chapter_id`/`lesson_id` dùng RESTRICT (không CASCADE/
     * SET NULL) vì MySQL cấm referential action trên cột nằm trong CHECK
     * (lỗi 3823) mà `chk_quizzes_single_parent` cần cả 2 cột này. Dự án chỉ
     * xoá mềm chương/bài nên không ảnh hưởng — `LessonService`/`ChapterService`
     * xoá mềm kèm quiz gắn vào chúng.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('chapters')->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->restrictOnDelete();
            $table->string('title', 255);
            // null = không giới hạn thời gian (US-007 BR6; chờ PO cách cấu hình mặc định).
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->softDeletes();

            $table->index('course_id');
        });

        DB::statement(
            'ALTER TABLE quizzes ADD CONSTRAINT chk_quizzes_single_parent '
            .'CHECK ((chapter_id IS NOT NULL AND lesson_id IS NULL) OR (chapter_id IS NULL AND lesson_id IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
