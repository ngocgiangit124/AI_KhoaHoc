<?php

namespace App\Services\Orders;

use App\Models\Order;

/** Mã đơn hiển thị cho HS/admin: `VV` + yymmdd + 6 ký tự Crockford base32 ngẫu nhiên (data-model orders.code). */
class OrderCodeGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        // 32^6 ≈ 1 tỷ tổ hợp/ngày: va chạm gần như không xảy ra; unique index `orders.code` là chốt chặn cuối.
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::ALPHABET[random_int(0, 31)];
            }
            $code = 'VV'.now()->format('ymd').$suffix;
        } while (Order::query()->where('code', $code)->exists());

        return $code;
    }
}
