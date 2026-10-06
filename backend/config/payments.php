<?php

/*
|--------------------------------------------------------------------------
| Thanh toán (ADR-001, S4)
|--------------------------------------------------------------------------
|
| `enabled_gateways` là allowlist tường minh dùng ở route webhook
| (`->whereIn('gateway', config('payments.enabled_gateways'))`) và ở
| `PaymentGatewayManager` (T17). Boot guard production (AppServiceProvider)
| cấm `fake` lọt lên production, cấm endpoint MoMo sandbox ở production.
|
*/

return [

    'enabled_gateways' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENT_GATEWAYS', 'fake'))
    ))),

    // Cụm 3 L1: T19 (IPN) + T20 (đối soát) đã có thì đổi thành true; ProductionConfigGuard chặn FEATURE_PAID_CHECKOUT=true khi còn false.
    'ipn_ready' => false,

    // Đơn pending chỉ "giữ chỗ" lượt mã giảm giá tới `coupon_hold_until` = min(hạn link thanh toán, giá trị này)
    // (ADR-001 §6, S18) — chặn việc tạo hàng loạt đơn pending để chiếm hết lượt của mã.
    'coupon_hold_minutes' => (int) env('PAYMENTS_COUPON_HOLD_MINUTES', 30),

    'gateways' => [

        'momo' => [
            'partner_code' => env('MOMO_PARTNER_CODE'),
            'access_key' => env('MOMO_ACCESS_KEY'),
            'secret_key' => env('MOMO_SECRET_KEY'),
            // Gốc (scheme + host) hoặc URL đầy đủ của API MoMo; adapter chỉ lấy scheme+host rồi
            // nối `paths.*`. Bắt buộc https. Production: boot guard ép đúng payment.momo.vn.
            'endpoint' => env('MOMO_ENDPOINT'),
            'paths' => [
                'create' => '/v2/gateway/api/create',
                'query' => '/v2/gateway/api/query',
            ],
            'request_type' => env('MOMO_REQUEST_TYPE', 'captureWallet'),
            'lang' => 'vi',
            'link_ttl_minutes' => (int) env('MOMO_LINK_TTL_MINUTES', 30),
            'ipn_url' => env('MOMO_IPN_URL'),
            'redirect_url' => env('MOMO_REDIRECT_URL'),
            // Chỉ cho phép pay_url trỏ tới các host này (S23). Production chỉ nên là payment.momo.vn.
            'pay_url_hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MOMO_PAY_URL_HOSTS', 'payment.momo.vn'))
            ))),
            // Hạn mức số tiền một giao dịch (VNĐ) theo tài liệu MoMo; cần xác nhận lại với sandbox.
            'min_amount' => 1000,
            'max_amount' => 50000000,
            'timeout' => 10,
            'connect_timeout' => 5,
        ],

        // Chỉ được đăng ký ở local/testing (PaymentGatewayManager) và bị boot guard cấm ở production.
        'fake' => [
            'secret' => env('FAKE_GATEWAY_SECRET', 'fake-gateway-local-only'),
            'pay_url_base' => env('FAKE_GATEWAY_PAY_URL', 'https://fake-pay.vitaminvui.test/pay'),
        ],

    ],

];
