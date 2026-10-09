<?php

// VV-IRREVERSIBLE: backfill không hoàn tác (T29)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BATCH = 1000;

    /**
     * ADR-006 (T29): không còn phụ huynh đồng ý, mọi dòng `users.parent_consent_status` về `not_required`.
     * Chạy theo lô 1.000 id (khoá hàng ngắn, không giữ một transaction dài trên bảng lớn). Idempotent: chạy lại không lỗi
     * và không đụng dòng đã `not_required`.
     */
    public function up(): void
    {
        $maxId = (int) DB::table('users')->max('id');

        for ($from = 1; $from <= $maxId; $from += self::BATCH) {
            DB::update(
                "UPDATE users SET parent_consent_status = 'not_required' WHERE parent_consent_status <> 'not_required' AND id BETWEEN ? AND ?",
                [$from, $from + self::BATCH - 1],
            );
        }
    }

    /**
     * Không khôi phục được giá trị cũ: dữ liệu `pending/granted/revoked` chưa từng được dùng để chặn ai
     * (cờ `parent_consent_enforced` luôn tắt) và ADR-006 bỏ hẳn khái niệm này.
     */
    public function down(): void
    {
        //
    }
};
