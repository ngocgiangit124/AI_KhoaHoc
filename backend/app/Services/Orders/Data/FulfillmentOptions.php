<?php

namespace App\Services\Orders\Data;

use App\Models\Order;
use Closure;

/**
 * Tuỳ chọn của `OrderFulfillmentService::markPaid` (US-022, docs/tech/US-022.md).
 *
 * - `guard(Order $locked)`: chạy ngay sau khi khoá `orders`, TRƯỚC nhánh "đã paid thì trả về"; ném `DomainException` để từ chối.
 * - `after(Order $locked, list<string> $reviewReasons)`: chạy sau khi đơn đã chuyển `paid`, cùng transaction.
 * - Cả hai móc chỉ được đọc/ghi `orders`, `order_notes`, `audit_logs`; không gọi mạng; không khoá bảng khác. Có thể bị gọi lại khi
 *   transaction retry (deadlock) nên phải chạy lại an toàn.
 * - `attributes`: cột `orders` ghi cùng lần `save()` của chuyển `paid` (vd `confirmed_by`), không tốn thêm UPDATE.
 * - `strictCourses`: khóa đã xoá → gom mọi khóa rồi ném 409 `COURSE_UNAVAILABLE` (rollback), thay vì đánh `needs_review`.
 *
 * @phpstan-type Guard Closure(Order): void
 * @phpstan-type After Closure(Order, list<string>): void
 */
final readonly class FulfillmentOptions
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(Order): void)|null  $guard
     * @param  (Closure(Order, list<string>): void)|null  $after
     */
    public function __construct(
        public string $actorType,
        public ?int $actorId = null,
        public ?string $statusReason = null,
        public bool $strictCourses = false,
        public array $attributes = [],
        public ?Closure $guard = null,
        public ?Closure $after = null,
    ) {}
}
