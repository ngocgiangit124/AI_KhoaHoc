<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data-model §3.2. `external_video_id` KHÔNG lưu URL người nhập — chỉ ID
     * bắt được bằng regex chặt (S13), việc xử lý ở Form Request thuộc T09.
     */
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            // Denormalize course_id để kiểm quyền/tính tiến độ không phải join chapters.
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->string('title', 255);
            $table->unsignedInteger('position');
            $table->string('video_source', 20)->default('none');
            $table->foreignId('video_asset_id')->nullable()->constrained('video_assets')->nullOnDelete();
            $table->string('external_provider', 10)->nullable();
            $table->string('external_video_id', 32)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('course_id');
            $table->index(['chapter_id', 'position']);
        });

        // Phụ thuộc vòng với video_assets (xem docblock migration video_assets):
        // thêm FK thật ở đây, sau khi cả 2 bảng đã tồn tại.
        Schema::table('video_assets', function (Blueprint $table) {
            $table->foreign('lesson_id')->references('id')->on('lessons')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_assets', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
        });

        Schema::dropIfExists('lessons');
    }
};
