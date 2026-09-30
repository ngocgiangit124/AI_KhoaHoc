<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot mã giảm giá <-> khóa học cụ thể (US-013 BR4, data-model §3.5).
     * Chỉ có dữ liệu khi `coupons.is_restricted = true`. `course_id` restrict
     * (giống `course_subject.subject_id` ở T07) — chặn xoá cứng khóa học
     * đang nằm trong phạm vi 1 mã giảm giá.
     */
    public function up(): void
    {
        Schema::create('coupon_course', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();

            $table->primary(['coupon_id', 'course_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_course');
    }
};
