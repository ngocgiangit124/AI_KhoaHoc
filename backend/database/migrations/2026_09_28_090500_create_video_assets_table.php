<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tầng nghiệp vụ video, độc lập nhà cung cấp (ADR-002, data-model §3.2).
     *
     * `lesson_id` là cột thường (KHÔNG có FK) ở migration này vì thứ tự migration
     * theo data-model §5 đặt `video_assets` TRƯỚC `lessons` (phụ thuộc vòng:
     * video_assets.lesson_id -> lessons, lessons.video_asset_id -> video_assets).
     * FK thật của `lesson_id` được thêm bằng `Schema::table()` ở cuối migration
     * `..._create_lessons_table.php` (sau khi bảng `lessons` đã tồn tại).
     */
    public function up(): void
    {
        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_library_id', 50);
            $table->string('provider_video_id', 64);
            $table->string('status', 20)->default('created');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error_message', 500)->nullable();
            // FK thật thêm ở migration lessons (xem docblock trên).
            $table->unsignedBigInteger('lesson_id');
            // Kích thước khai báo lúc tạo phiên upload; TUS không nhận Upload-Length lớn hơn (S3).
            $table->unsignedBigInteger('declared_size_bytes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_video_id']);
            $table->index(['status', 'updated_at']);
            $table->index('lesson_id');
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_assets');
    }
};
