<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thiết bị đã từng đăng nhập quản trị của giáo viên (T28, US-016 AC10): thiết bị lạ → email cảnh báo.
        Schema::create('staff_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // sha256 của X-Device-Id (UUID) hoặc, khi thiếu, của User-Agent — không lưu UUID gốc.
            $table->char('device_hash', 64);
            $table->string('last_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_devices');
    }
};
