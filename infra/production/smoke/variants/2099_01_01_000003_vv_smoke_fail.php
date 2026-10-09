<?php

use Illuminate\Database\Migrations\Migration;

// Migration giả của smoke: cố tình lỗi để kiểm log_bin_trust_function_creators về 0 sau khi migrate thất bại.
return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('vv-smoke: migration lỗi có chủ ý');
    }

    public function down(): void {}
};
