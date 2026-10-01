<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.4. Quy tắc "đúng 4 lựa chọn, đúng 1 đáp án đúng" kiểm ở
     * `QuizQuestionService` (không ép ở DB). `position` 1–4 = A–D.
     */
    public function up(): void
    {
        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('quiz_questions')->cascadeOnDelete();
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
    }
};
