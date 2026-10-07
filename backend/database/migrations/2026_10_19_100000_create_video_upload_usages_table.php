<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ hạn mức upload video theo người + ngày (S2b, review T37). Pruner xoá cứng `video_assets` mồ côi nên không thể tính
 * hạn mức chỉ bằng SUM(video_assets): upload/đè lặp lại sẽ được "hoàn" hạn mức. Sổ này không bị pruner đụng tới;
 * chỉ phiên bị bỏ dở vì nhà cung cấp lỗi (VideoUploadService::abandon) mới được trừ lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_upload_usages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->date('usage_date');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();

            $table->primary(['user_id', 'usage_date']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_upload_usages');
    }
};
