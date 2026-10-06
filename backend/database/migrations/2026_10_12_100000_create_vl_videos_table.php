<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VideoLab (ADR-002 §3): bảng tiền tố `vl_`, nghiệp vụ không FK vào đây.
     * status: 0 created, 1 uploaded, 2 processing, 3 transcoding, 4 finished, 5 error, 6 upload_failed.
     */
    public function up(): void
    {
        Schema::create('vl_videos', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->string('library_id', 50);
            $table->string('title', 255);
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedInteger('length_seconds')->nullable();
            // Trần kích thước cho phép upload (≤ video.max_upload_mb).
            $table->unsignedBigInteger('max_bytes');
            $table->unsignedBigInteger('upload_length')->nullable();
            $table->unsignedBigInteger('upload_offset')->default(0);
            $table->dateTime('upload_expires_at');
            // Chỉ để hiển thị; KHÔNG bao giờ dùng làm đường dẫn (ADR-002 §3a.5).
            $table->string('original_name', 255)->nullable();
            $table->string('created_by_ref', 64)->nullable();
            $table->string('source_path', 255)->nullable();
            $table->json('renditions')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vl_videos');
    }
};
