<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * US-022 (T38.1): CHECK `chk_orders_payment_method`. Thêm cổng thanh toán mới sau này PHẢI kèm migration sửa CHECK này.
 *
 * Dữ liệu lạ được kiểm TRƯỚC bằng so sánh nhị phân: CHECK so theo collation cột (`_ci`) nên `'MANUAL'` lọt CHECK nhưng
 * `=== 'manual'` ở PHP thì sai. Có dòng lạ → dừng, KHÔNG tự sửa dữ liệu. `ADD CONSTRAINT CHECK` chỉ hỗ trợ COPY (chặn ghi
 * trong lúc chạy; bảng production nhỏ), `DROP CHECK` là INPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::select(
            "SELECT payment_method, COUNT(*) AS total FROM orders WHERE payment_method IS NOT NULL AND BINARY payment_method NOT IN ('none', 'manual', 'momo', 'fake') GROUP BY payment_method"
        );

        if ($rows !== []) {
            $list = implode(', ', array_map(fn ($r) => "'{$r->payment_method}' ({$r->total} dòng)", $rows));

            throw new RuntimeException("orders.payment_method có giá trị ngoài danh sách cho phép: {$list}. Hãy sửa dữ liệu thủ công rồi chạy lại migration.");
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_payment_method CHECK (payment_method IS NULL OR payment_method IN ('none', 'manual', 'momo', 'fake'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_payment_method');
    }
};
