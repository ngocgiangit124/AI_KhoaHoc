<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot mã giảm giá <-> chuyên đề (US-013 BR4, data-model §3.5). Phạm vi
     * áp dụng của 1 mã = khóa trong `coupon_course` HOẶC thuộc ≥1 chuyên đề
     * trong bảng này (hợp — data-model §3.5). `subject_id` restrict, giống
     * `course_subject.subject_id` (T07).
     */
    public function up(): void
    {
        Schema::create('coupon_subject', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();

            $table->primary(['coupon_id', 'subject_id']);
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_subject');
    }
};
