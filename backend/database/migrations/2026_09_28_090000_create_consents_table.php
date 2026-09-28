<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bằng chứng đồng ý xử lý dữ liệu (data-model §3.1, S7, US-001/US-017).
 * Checkbox đồng ý ở form đăng ký KHÔNG được tick sẵn; thiếu → 422 (S7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // privacy_policy / terms / parent_consent / marketing (enum ConsentType).
            $table->string('type', 30);
            // Phiên bản văn bản chính sách đã hiển thị (config('privacy.policy_version')).
            $table->string('policy_version', 20);
            // self / parent.
            $table->string('granted_by', 10);
            // web_form / email_otp / email_link.
            $table->string('channel', 20);
            $table->string('destination_masked', 100)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Chỉ ghi thêm — không có updated_at (data-model §3.1).
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'type', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
