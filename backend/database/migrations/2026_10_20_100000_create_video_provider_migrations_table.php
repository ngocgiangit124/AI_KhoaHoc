<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T37-1: sổ chuyển nhà cung cấp video. Giữ video nguồn (provider cũ) để dọn sau, và là điểm tiếp tục khi lệnh bị
 * ngắt (video đích đã tạo/đã tải lên thì lần sau không tạo trùng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_provider_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_asset_id')->constrained('video_assets')->cascadeOnDelete();
            $table->string('from_provider', 20);
            $table->string('from_library_id', 50);
            $table->string('from_video_id', 64);
            $table->string('to_provider', 20);
            $table->string('to_library_id', 50);
            $table->string('to_video_id', 64);
            // pending: đã tạo video đích, chưa tải xong · uploaded: đã tải, chờ mã hoá · completed · failed · abandoned
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('source_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['to_provider', 'to_video_id']);
            $table->index(['video_asset_id', 'status']);
            $table->index(['status', 'source_deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_provider_migrations');
    }
};
