<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L2 (review bảo mật cụm 1): `audit_logs` bất biến cả ở tầng DB, không chỉ ở tầng ứng dụng.
 *
 * - `BEFORE UPDATE`: luôn lỗi (SQLSTATE 45000).
 * - `BEFORE DELETE`: chỉ cho xoá dòng quá thời hạn lưu, để `audit:purge` (T30) vẫn chạy.
 *
 * Trigger không đọc được `config()`, nên thời hạn 24 THÁNG ghi cứng ở đây và PHẢI đồng bộ với
 * `config('ops.audit_retention_months')` (`AuditPurgeCommand::MIN_RETENTION_MONTHS`). Đổi thời hạn lưu = migration mới.
 * Cộng 1 ngày đệm để lệch đồng hồ/múi giờ giữa PHP và MySQL không làm `audit:purge` vấp trigger; ngưỡng thực tế là
 * "cũ hơn 24 tháng trừ 1 ngày".
 *
 * Quyền cần có khi chạy: `TRIGGER` trên schema cho user migrate. Nếu bật binary log (mặc định của MySQL 8.4) mà user
 * không có SUPER/SET_USER_ID thì cần `log_bin_trust_function_creators=1` (xem docs/ops/production-checklist.md).
 */
return new class extends Migration
{
    private const MESSAGE = 'audit_logs la bat bien: khong duoc sua/xoa.';

    public function up(): void
    {
        $this->dropTriggers();

        DB::unprepared(
            'CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'"
        );

        DB::unprepared(
            'CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW '
            .'BEGIN '
            // 24 = ops.audit_retention_months (ghi cứng, xem chú thích đầu file).
            .'IF OLD.created_at >= (NOW() - INTERVAL 24 MONTH + INTERVAL 1 DAY) THEN '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'; "
            .'END IF; '
            .'END'
        );
    }

    public function down(): void
    {
        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_block_delete');
    }
};
