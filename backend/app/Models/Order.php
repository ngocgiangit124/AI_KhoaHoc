<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Đơn hàng. KHÔNG cột nào nằm trong $fillable (S17): mọi thay đổi (đặc biệt `status`, số tiền) chỉ qua
 * CheckoutService / OrderFulfillmentService. `pending_flag` là generated column — chỉ đọc.
 *
 * @property int $id
 * @property string $code
 * @property int $user_id
 * @property OrderStatus $status
 * @property string|null $status_reason
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $total_amount
 * @property int|null $coupon_id
 * @property string|null $coupon_code
 * @property Carbon|null $coupon_hold_until
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property bool $needs_review
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'needs_review' => 'boolean',
            'coupon_hold_until' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
