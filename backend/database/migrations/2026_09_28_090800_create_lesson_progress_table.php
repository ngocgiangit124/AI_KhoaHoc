<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng lớn nhất + ghi nhiều nhất (data-model §2, §3.3) — DBA review §2.5:
     * không cần partition/bảng tổng hợp ở MVP (~50 UPDATE/giây là tải nhẹ).
     * `course_id` denormalize, CỐ Ý KHÔNG có FK (giảm chi phí ghi).
     */
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id'], 'lesson_progress_user_lesson_unique');
            $table->index(['user_id', 'course_id', 'status']);
            $table->index(['user_id', 'course_id', 'last_accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
