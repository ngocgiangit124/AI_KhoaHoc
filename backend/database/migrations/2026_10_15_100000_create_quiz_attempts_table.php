<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lượt làm quiz (data-model §3.4). `question_ids` là mảng SỐ NGUYÊN (JSON_CONTAINS của copy-on-write dựa vào đó);
 * `answers` autosave bằng JSON_SET; `in_progress_flag` + unique → mỗi học sinh chỉ có 1 lượt đang làm/quiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->restrictOnDelete();
            // Denormalize cho tiến độ khóa học (US-008 AC5); suy ra từ quiz, không từ request.
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->json('question_ids');
            $table->json('answers');
            $table->json('result')->nullable();
            $table->dateTime('started_at');
            // null = không giới hạn thời gian.
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->unsignedSmallInteger('total_questions');
            $table->unsignedSmallInteger('correct_count')->nullable();
            $table->decimal('score', 4, 2)->nullable();
            // 1 khi đang làm, NULL khi đã nộp → unique chỉ chặn lượt "đang làm" trùng.
            $table->tinyInteger('in_progress_flag')->nullable()
                ->storedAs('CASE WHEN submitted_at IS NULL THEN 1 END');
            $table->timestamps();

            $table->unique(['user_id', 'quiz_id', 'in_progress_flag'], 'quiz_attempts_user_quiz_in_progress_unique');
            // quiz_id đứng riêng cho hasAttempts() của copy-on-write (lọc theo quiz rồi JSON_CONTAINS).
            $table->index('quiz_id', 'quiz_attempts_quiz_id_index');
            $table->index(['submitted_at', 'expires_at'], 'quiz_attempts_submitted_expires_index');
            $table->index(['user_id', 'quiz_id', 'score'], 'quiz_attempts_user_quiz_score_index');
            $table->index(['user_id', 'course_id'], 'quiz_attempts_user_course_index');
        });

        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT chk_quiz_attempts_question_count CHECK (JSON_LENGTH(question_ids) BETWEEN 1 AND 200)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
