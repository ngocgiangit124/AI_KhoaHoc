<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('channel', 10);
            // Email/SĐT nhận mã: mã gắn với đích, đổi liên hệ thì mã cũ bị huỷ (S9).
            $table->string('destination', 254);
            // Hash::make của mã 6 số — không bao giờ lưu/ghi log mã rõ.
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'created_at']);
            // Job `otp:prune` (T30) xoá theo created_at.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
