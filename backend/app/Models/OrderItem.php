<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dòng đơn (giá chốt tại thời điểm tạo đơn). Bất biến sau khi tạo.
 *
 * @property int $id
 * @property int $order_id
 * @property int $course_id
 * @property string $course_title
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $final_amount
 */
class OrderItem extends Model
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
        return ['unit_price' => 'integer', 'discount_amount' => 'integer', 'final_amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }
}
