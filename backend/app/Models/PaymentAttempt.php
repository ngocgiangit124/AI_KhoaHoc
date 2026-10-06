<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Một lần tạo giao dịch với cổng thanh toán. Không cột nào trong $fillable (S17): chỉ đổi qua CheckoutService
 * (tạo/hoàn tất link) và PaymentWebhookService/đối soát (T19/T20).
 *
 * @property int $id
 * @property int $order_id
 * @property string $gateway
 * @property string $gateway_order_id
 * @property string $request_id
 * @property int $amount
 * @property PaymentAttemptStatus $status
 * @property Carbon|null $next_check_at
 * @property string|null $pay_url
 * @property Carbon|null $expires_at
 * @property string|null $gateway_trans_id
 */
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
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
            'status' => PaymentAttemptStatus::class,
            'amount' => 'integer',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'create_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
