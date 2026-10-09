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

    /** Nội dung thay cho `body` khi xoá theo chính sách lưu trữ (T38-2) hoặc khi xoá tài khoản; cột NOT NULL. */
    public const PURGED_BODY = '[Đã xoá theo chính sách lưu trữ]';

    /** Chỉ thêm (append-only): chặn sửa/xoá qua Eloquent (bằng chứng ai duyệt/huỷ, khi nào). Xoá cascade do FK ở DB không đi qua đây. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('order_notes là append-only: không được sửa.'));
        static::deleting(fn () => throw new \LogicException('order_notes là append-only: không được xoá.'));
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
