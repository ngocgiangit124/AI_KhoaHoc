<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mỗi lần xin học/mua là 1 dòng — giữ lịch sử từ chối/thu hồi (data-model §3.3).
     *
     * `live_flag` là generated column STORED mô phỏng "unique chỉ khi đang hoạt
     * động" (DBA checklist §2.2, mục 1 của T07-checklist.md): NULL khi
     * rejected/revoked (không chặn gửi lại/mua lại), = 1 khi pending_approval
     * HOẶC active (US-012 BR5) — chống IPN/duyệt tạo trùng dòng "đang sống".
     *
     * `order_id` CHƯA có FK tới `orders` (bảng đó thuộc T18, tạo sau T07 theo
     * thứ tự migration data-model §5). T18 phải tự thêm
     * `Schema::table('enrollments', fn ($t) => $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete())`
     * khi tạo bảng `orders` — KHÔNG sửa migration này.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('source', 20);
            // FK thật thêm ở migration T18 (orders) — xem docblock trên.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 50)->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->tinyInteger('live_flag')->nullable()->storedAs(
                "CASE WHEN status IN ('pending_approval','active') THEN 1 END"
            );
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'live_flag'], 'enrollments_user_course_live_unique');
            $table->index(['course_id', 'status', 'requested_at']);
            $table->index(['user_id', 'status', 'last_accessed_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
