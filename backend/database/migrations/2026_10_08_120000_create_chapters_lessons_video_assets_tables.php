<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('title', 255);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'position']);
        });

        // `lessons.video_asset_id` ↔ `video_assets.lesson_id` tham chiếu vòng nhau: tạo lessons trước (chưa FK
        // video_asset_id), rồi video_assets (FK lessons), cuối cùng thêm FK video_asset_id (xem dưới).
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            // Denormalize để kiểm quyền/tính tiến độ không phải join chapters.
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->restrictOnDelete();
            $table->string('title', 255);
            $table->unsignedInteger('position');
            $table->string('video_source', 20)->default('none');
            $table->unsignedBigInteger('video_asset_id')->nullable();
            $table->string('external_provider', 10)->nullable();
            $table->string('external_video_id', 32)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['chapter_id', 'position']);
            $table->index('video_asset_id');
        });

        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_library_id', 50);
            $table->string('provider_video_id', 64);
            $table->string('status', 20);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error_message', 500)->nullable();
            // Asset chỉ thuộc đúng 1 bài (S5).
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            // Kích thước khai báo lúc tạo phiên upload; TUS không nhận Upload-Length lớn hơn (S3).
            $table->unsignedBigInteger('declared_size_bytes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_video_id']);
            $table->index(['status', 'updated_at']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('video_asset_id')->references('id')->on('video_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['video_asset_id']);
        });
        Schema::dropIfExists('video_assets');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('chapters');
    }
};
