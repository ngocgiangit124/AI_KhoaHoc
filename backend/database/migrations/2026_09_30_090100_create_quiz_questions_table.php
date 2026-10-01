<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.4. `content`/`explanation` là văn bản thuần (có đoạn LaTeX
     * `$...$`), ≤ 5.000 ký tự — ép ở Form Request, không ở DB. `replaced_by_id`
     * trỏ tới câu thay thế khi copy-on-write (không FK: câu cũ/mới cùng sống
     * bên nhau, chỉ dùng để truy vết).
     */
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->text('content');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
