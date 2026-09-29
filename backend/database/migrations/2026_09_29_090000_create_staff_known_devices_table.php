<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // T28 — "Email cảnh báo thiết bị mới cho GV" (tasks.md, api-contract
        // §2.5). Tách bảng riêng (không tái dùng `users.current_device_id` —
        // cột đó CHỈ dành cho học sinh, ADR-003) vì 1 giáo viên hợp lệ có thể
        // có NHIỀU thiết bị đã biết cùng lúc (khác học sinh, chỉ 1 phiên).
        Schema::create('staff_known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // UUID v4 do frontend sinh (ADR-003 §2, X-Device-Id) — không phải PII.
            $table->string('device_id', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_known_devices');
    }
};
