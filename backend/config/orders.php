<?php

/*
|--------------------------------------------------------------------------
| Đơn hàng (US-005, ADR-001 §7)
|--------------------------------------------------------------------------
*/

return [

    // Đơn pending quá thời hạn này tự huỷ (US-005 BR7) — `orders.expires_at = created_at + giá trị này`.
    'pending_ttl_hours' => (int) env('ORDERS_PENDING_TTL_HOURS', 12),

];
