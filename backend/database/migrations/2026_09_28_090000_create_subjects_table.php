<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * data-model §3.2 (US-011) — `name`/`slug` unique nhờ collation mặc định
     * `utf8mb4_0900_ai_ci` (không phân biệt hoa/thường **và dấu**, đã xác nhận
     * hành vi ở T01). Bảng `course_subject` (T07) sẽ khai FK
     * `subject_id` ... `restrict` để chặn xoá cứng chuyên đề đang gán
     * (US-011 AC3) — không khai ở đây để tránh phụ thuộc ngược thứ tự
     * migration giữa 2 task chạy song song.
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->string('status', 10)->default('active');
            $table->timestamps();

            $table->unique('name');
            $table->unique('slug');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
