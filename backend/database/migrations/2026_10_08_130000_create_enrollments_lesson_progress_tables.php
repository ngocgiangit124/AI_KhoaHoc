<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mỗi lần xin học/mua là 1 dòng (giữ lịch sử từ chối/thu hồi).
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('source', 20);
            // Chưa có bảng `orders` ở T07: FK tới orders thêm ở migration của bảng orders (task thanh toán).
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->dateTime('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 50)->nullable();
            $table->dateTime('last_accessed_at')->nullable();
            // 1 khi đang chờ duyệt hoặc đang học, NULL khi lịch sử → unique chỉ chặn bản ghi "sống" trùng.
            $table->unsignedTinyInteger('live_flag')->nullable()
                ->storedAs("CASE WHEN status IN ('pending_approval','active') THEN 1 END");
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'live_flag'], 'enrollments_user_course_live_unique');
            $table->index(['course_id', 'status', 'requested_at']);
            $table->index(['user_id', 'status', 'last_accessed_at']);
        });

        // Bảng ghi nhiều nhất (heartbeat): course_id denormalize, KHÔNG FK để giảm chi phí ghi.
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('last_accessed_at');
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'course_id', 'status']);
            $table->index(['user_id', 'course_id', 'last_accessed_at']);
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('enrollments');
    }
};
