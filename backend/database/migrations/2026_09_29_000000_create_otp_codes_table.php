<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã OTP xác thực (US-001 AC8/AC9, data-model §3.1, S9). KHÔNG BAO GIỜ lưu mã
 * ở dạng rõ — chỉ `code_hash` (`Hash::make`). `attempts` được tăng NGUYÊN TỬ
 * trước khi so mã bằng UPDATE có điều kiện (xem `App\Services\Auth\OtpService`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // Mặc định restrictOnDelete (data-model §3) — chưa có tính năng xoá
            // cứng tài khoản (US-018 dự kiến ẩn danh hoá, không xoá cứng).
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // verify_account / reset_password (T27) / staff_login_mfa (T28) / parent_consent (T29).
            $table->string('purpose', 30);
            // email / sms — production MVP chỉ 'email' (config('auth.otp.channels')).
            $table->string('channel', 10);
            // Email/SĐT nhận mã tại thời điểm gửi (S9 — mã gắn với đích).
            $table->string('destination', 254);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            // Chỉ set bằng UPDATE ... WHERE id=? AND consumed_at IS NULL (OtpService).
            $table->timestamp('consumed_at')->nullable();
            // Khi phát mã mới cùng (user, purpose, channel), hoặc hết lượt.
            $table->timestamp('invalidated_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
