<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.4. Tạo sớm ở T21 (thay vì T22) vì copy-on-write của câu
     * hỏi cần tra "câu này đã nằm trong lượt làm nào chưa" (`question_ids`).
     * Logic làm bài (bắt đầu/autosave/nộp) và review [DBA] vẫn thuộc T22.
     *
     * `in_progress_flag` (generated STORED) + unique
     * `(user_id, quiz_id, in_progress_flag)` = mỗi HS chỉ 1 lượt đang làm/quiz
     * (giống `enrollments.live_flag`). CHECK đặt tên tường minh để `down()`
     * hoặc `dropIfExists` đều sạch.
     */
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->restrictOnDelete();
            // Denormalize cho US-008 AC5.
            $table->unsignedBigInteger('course_id');
            $table->json('question_ids');
            $table->json('answers');
            $table->json('result')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->unsignedSmallInteger('total_questions');
            $table->unsignedSmallInteger('correct_count')->nullable();
            $table->decimal('score', 4, 2)->nullable();
            $table->tinyInteger('in_progress_flag')->nullable()->storedAs(
                'CASE WHEN submitted_at IS NULL THEN 1 END'
            );
            $table->timestamps();

            $table->unique(['user_id', 'quiz_id', 'in_progress_flag'], 'quiz_attempts_user_quiz_in_progress_unique');
            $table->index(['submitted_at', 'expires_at']);
            $table->index(['user_id', 'quiz_id', 'score']);
            $table->index('quiz_id');
            $table->index('course_id');
        });

        DB::statement(
            'ALTER TABLE quiz_attempts ADD CONSTRAINT chk_quiz_attempts_question_count '
            .'CHECK (JSON_LENGTH(question_ids) BETWEEN 1 AND 200)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
