<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chuyên đề (US-011, data-model §3.2). T07 chỉ tạo schema tối thiểu vì
     * `course_subject` (T07) cần FK tới bảng này; CRUD/policy/quản lý ẩn-hiện
     * đầy đủ thuộc T06 — nếu T06 cần đổi cấu trúc, tạo migration MỚI, không
     * sửa migration này (đã có thể chạy trên môi trường chung).
     *
     * Unique trên `name` dựa vào collation utf8mb4_0900_ai_ci (không phân biệt
     * hoa/thường và dấu — US-011 BR1, xác nhận ở DatabaseConfigTest T01).
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->string('status', 10)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
