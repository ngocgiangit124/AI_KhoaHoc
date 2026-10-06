<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lượt dùng mã (ghi khi đơn paid). Không cột nào trong $fillable: chỉ OrderFulfillmentService ghi.
 *
 * @property int $coupon_id
 * @property int $user_id
 * @property int $order_id
 * @property Carbon $used_at
 */
class CouponUsage extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
