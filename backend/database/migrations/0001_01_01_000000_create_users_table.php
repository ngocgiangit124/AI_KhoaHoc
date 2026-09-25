<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 254)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password');
            $table->string('role', 20)->default('hoc_sinh');
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('grade_level')->nullable();
            $table->date('date_of_birth')->nullable();
            // encrypted (cast Model) — ciphertext, kiểu text vì dài hơn varchar sau khi mã hoá (S7).
            $table->text('parent_phone')->nullable();
            $table->text('parent_email')->nullable();
            $table->string('parent_consent_status', 20)->default('not_required');
            $table->string('referral_code_used', 50)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            // ADR-003 — chỉ dùng cho học sinh. 'logged_out' sau khi đăng xuất (không NULL — S11).
            $table->string('current_session_id')->nullable();
            $table->string('current_device_id', 64)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
            $table->index(['email_verified_at', 'phone_verified_at', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT chk_users_grade_level '
            .'CHECK (grade_level IS NULL OR grade_level BETWEEN 6 AND 12)'
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
