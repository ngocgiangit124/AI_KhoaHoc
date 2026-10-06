<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * US-020 / T36 / ADR-005: hồ sơ công khai của giáo viên (1-1 với `users`). Bảng mới nên không ảnh hưởng zero-downtime.
 *
 * Backfill: chép `users.bio`/`users.avatar_path` của giáo viên sang bảng mới (dự kiến 0 dòng vì chưa từng có đường
 * ghi). KHÔNG chép đồng ý: mọi hồ sơ cũ đều chưa đồng ý (BR5). `bio` cũ dài hơn 600 ký tự bị cắt bằng LEFT. Hai cột cũ
 * ở `users` KHÔNG bị xoá ở đây (xoá ở release sau, backlog T36-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('headline', 120)->nullable();
            $table->string('bio', 600)->nullable();
            $table->string('avatar_path')->nullable();
            $table->dateTime('public_consent_at')->nullable();
            $table->string('public_consent_version', 20)->nullable();
            $table->dateTime('public_consent_withdrawn_at')->nullable();
            $table->boolean('show_on_homepage')->default(false);
            $table->unsignedSmallInteger('homepage_order')->nullable();
            $table->unsignedBigInteger('profile_updated_by')->nullable();
            $table->dateTime('profile_updated_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('profile_updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['show_on_homepage', 'homepage_order'], 'ix_teacher_profiles_homepage');
        });

        DB::statement(
            'ALTER TABLE teacher_profiles ADD CONSTRAINT chk_teacher_profiles_homepage_order '
            .'CHECK (homepage_order IS NULL OR homepage_order BETWEEN 1 AND 999)'
        );
        DB::statement(
            'ALTER TABLE teacher_profiles ADD CONSTRAINT chk_teacher_profiles_consent_version '
            .'CHECK (public_consent_at IS NULL OR public_consent_version IS NOT NULL)'
        );

        DB::insert(
            'INSERT INTO teacher_profiles (user_id, bio, avatar_path, created_at, updated_at) '
            .'SELECT id, LEFT(NULLIF(TRIM(bio), \'\'), 600), avatar_path, NOW(), NOW() FROM users '
            .'WHERE role = ? AND (bio IS NOT NULL OR avatar_path IS NOT NULL)',
            [UserRole::Teacher->value],
        );
    }

    public function down(): void
    {
        // Xoá CHECK trước (tên tường minh), rồi xoá bảng. Dữ liệu hồ sơ mất; `users.bio/avatar_path` còn nguyên.
        if (Schema::hasTable('teacher_profiles')) {
            DB::statement('ALTER TABLE teacher_profiles DROP CHECK chk_teacher_profiles_consent_version');
            DB::statement('ALTER TABLE teacher_profiles DROP CHECK chk_teacher_profiles_homepage_order');
        }

        Schema::dropIfExists('teacher_profiles');
    }
};
