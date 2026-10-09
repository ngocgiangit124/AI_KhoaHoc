<?php

namespace App\Models;

use Database\Factories\OrderNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ghi chú nội bộ của Quản trị viên trên đơn (US-022 BR18). Chỉ thêm: không có route sửa/xoá. KHÔNG bao giờ hiển thị cho
 * học sinh và không đưa vào `order_status_logs.meta`/`audit_logs`.
 *
 * @property int $id
 * @property int $order_id
 * @property int $author_id
 * @property string $body
 * @property Carbon $created_at
 */
class OrderNote extends Model
{
    /** @use HasFactory<OrderNoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
