<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot chuyên đề <-> khóa học (data-model §3.2). `subject_id` FK restrict
     * — chặn xoá cứng chuyên đề đang được gán (US-011 AC3).
     */
    public function up(): void
    {
        Schema::create('course_subject', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();

            $table->primary(['course_id', 'subject_id']);
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_subject');
    }
};
