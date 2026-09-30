<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dòng giỏ hàng: chỉ có `created_at` (không sửa dòng, chỉ thêm/xoá).
 *
 * @property int $id
 * @property int $cart_id
 * @property int $course_id
 * @property Carbon|null $created_at
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cart_id', 'course_id'];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Gồm cả khóa đã xoá mềm: học sinh vẫn phải thấy (cờ `unavailable`) và xoá
     * được dòng đó khỏi giỏ (US-004 "Trường hợp biên").
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }
}
