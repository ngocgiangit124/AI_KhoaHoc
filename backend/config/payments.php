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

    'gateways' => [

        'momo' => [
            'partner_code' => env('MOMO_PARTNER_CODE'),
            'access_key' => env('MOMO_ACCESS_KEY'),
            'secret_key' => env('MOMO_SECRET_KEY'),
            'endpoint' => env('MOMO_ENDPOINT'),
            'ipn_url' => env('MOMO_IPN_URL'),
            'redirect_url' => env('MOMO_REDIRECT_URL'),
        ],

    ],

];
