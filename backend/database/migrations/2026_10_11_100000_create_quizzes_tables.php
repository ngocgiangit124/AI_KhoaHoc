<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('chapters')->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->restrictOnDelete();
            $table->string('title', 255);
            // null = không giới hạn thời gian.
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'position']);
        });

        DB::statement('ALTER TABLE quizzes ADD CONSTRAINT chk_quizzes_single_parent CHECK ((chapter_id IS NULL) <> (lesson_id IS NULL))');

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->restrictOnDelete();
            $table->text('content');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('position');
            // Copy-on-write: câu cũ (đã soft delete) trỏ tới câu thay thế.
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['quiz_id', 'position']);
        });

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('quiz_questions')->restrictOnDelete();
            $table->text('content');
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
