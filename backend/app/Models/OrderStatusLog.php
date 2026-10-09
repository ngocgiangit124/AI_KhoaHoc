<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lịch sử trạng thái đơn (chỉ thêm, không sửa). `meta` không chứa PII/secret.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string $actor_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class OrderStatusLog extends Model
{
    public const UPDATED_AT = null;

    /** Chỉ thêm (append-only): chặn sửa/xoá qua Eloquent (bằng chứng ai duyệt/huỷ, khi nào). Xoá cascade do FK ở DB không đi qua đây. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('order_status_logs là append-only: không được sửa.'));
        static::deleting(fn () => throw new \LogicException('order_status_logs là append-only: không được xoá.'));
    }

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
