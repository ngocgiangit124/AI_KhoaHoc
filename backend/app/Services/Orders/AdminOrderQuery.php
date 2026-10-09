<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Http\Requests\Admin\Order\OrderFilterRequest;
use App\Http\Resources\Admin\AdminOrderDetailResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Auth\PhoneNumber;
use App\Support\Like;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Truy vấn đơn cho quản trị (T24-V1, api-contract §2.5.1; DBA `docs/review/T38-dba.md`, `docs/review/T24-V1.md`).
 *
 *  - Danh sách: LUÔN áp khoảng ngày (`created_at >= ? AND created_at < ?`, không bọc hàm) và trạng thái TRƯỚC, rồi mới tới
 *    phương thức/`q`/cờ. `cursorPaginate` (keyset theo `created_at, id`) + một `COUNT(*)` riêng cho `meta.total`. Không thêm index.
 *  - `items_count` = `withCount`, `first_item_title` = subquery chọn cột (dòng `id` nhỏ nhất) → không N+1.
 *  - Đếm chờ duyệt: MỘT câu `COUNT + SUM` trên `(status, payment_method)` (index `(status, expires_at)`/`(status, created_at)`).
 */
class AdminOrderQuery
{
    public function __construct(private readonly ManualOrderService $manual) {}

    /**
     * @return array{data: list<Order>, per_page: int, next_cursor: string|null, prev_cursor: string|null, total: int}
     */
    public function paginate(OrderFilterRequest $request): array
    {
        $query = $this->filtered($request);
        $total = (clone $query)->count();

        $oldest = $request->sortOrder() === 'oldest';
        $dir = $oldest ? 'asc' : 'desc';

        $firstTitle = OrderItem::query()->select('course_title')->whereColumn('order_items.order_id', 'orders.id')->orderBy('id')->limit(1);

        /** @var CursorPaginator<int, Order> $page */
        $page = $query
            ->select('orders.*')
            ->selectSub($firstTitle, 'first_item_title')
            ->withCount('items')
            ->with(['user:'.implode(',', PiiMasker::USER_COLUMNS), 'confirmedBy:id,name'])
            ->orderBy('created_at', $dir)
            ->orderBy('id', $dir)
            ->cursorPaginate($request->perPage(), ['*'], 'cursor', $request->input('cursor'));

        return [
            'data' => array_values($page->items()),
            'per_page' => $request->perPage(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
            'total' => $total,
        ];
    }

    /**
     * Badge menu (US-022 AC15/Q14): chỉ đơn `manual` `pending`. `expiring_soon` = `expires_at < now + expiring_soon_hours`.
     *
     * @return array{pending_manual: int, expiring_soon: int}
     */
    public function pendingCount(): array
    {
        $soon = now()->addHours((int) config('orders.manual.expiring_soon_hours', 12));

        $row = DB::table('orders')
            ->where('status', OrderStatus::Pending->value)
            ->where('payment_method', PaymentMethods::MANUAL)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(expires_at < ?), 0) AS soon', [$soon])
            ->first();

        return ['pending_manual' => (int) ($row->total ?? 0), 'expiring_soon' => (int) ($row->soon ?? 0)];
    }

    /** Đơn kèm mọi quan hệ chi tiết cần; 404 nếu không có mã. Số truy vấn cố định (không phụ thuộc số khóa/log/ghi chú). */
    public function findDetail(string $code): Order
    {
        return Order::query()
            ->where('code', $code)
            ->with([
                'user:'.implode(',', PiiMasker::USER_COLUMNS),
                'items' => fn ($q) => $q->orderBy('id'),
                'items.course',
                'statusLogs' => fn ($q) => $q->orderBy('id'),
                'notes' => fn ($q) => $q->orderBy('id'),
                'attempts' => fn ($q) => $q->orderBy('id')->limit(20),
            ])
            ->firstOrFail();
    }

    /** Dựng resource chi tiết (một truy vấn tên người thao tác + `approvalState`). Dùng chung với approve/cancel/refund. */
    public function detailResource(Order $order): AdminOrderDetailResource
    {
        $order->loadMissing(['user:'.implode(',', PiiMasker::USER_COLUMNS), 'items.course', 'statusLogs', 'notes', 'attempts']);

        $ids = collect([$order->confirmed_by, $order->refunded_by])
            ->merge($order->notes->pluck('author_id'))
            ->merge($order->statusLogs->whereIn('actor_type', ['user', 'staff'])->pluck('actor_id'))
            ->filter()
            ->unique()
            ->values();

        /** @var array<int, string> $names */
        $names = $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return new AdminOrderDetailResource($order, $this->manual->approvalState($order), $names);
    }

    /** @return Builder<Order> */
    private function filtered(OrderFilterRequest $request): Builder
    {
        $query = Order::query();

        // 1. Ngày + trạng thái luôn trước (index `created_at`, `(status, created_at)`).
        if ($request->filled('from') && $request->filled('to')) {
            $from = Carbon::parse((string) $request->input('from'))->startOfDay();
            $to = Carbon::parse((string) $request->input('to'))->addDay()->startOfDay();
            $query->where('orders.created_at', '>=', $from)->where('orders.created_at', '<', $to);
        }

        if (($statuses = $request->statuses()) !== []) {
            $query->whereIn('orders.status', $statuses);
        }

        // 2. Còn lại.
        if ($request->filled('payment_method')) {
            $query->where('orders.payment_method', (string) $request->input('payment_method'));
        }

        if ($request->filled('needs_review')) {
            $query->where('orders.needs_review', $request->boolean('needs_review'));
        }

        if ($request->filled('pending_older_than_hours')) {
            $query->where('orders.status', OrderStatus::Pending->value)
                ->where('orders.created_at', '<', now()->subHours((int) $request->input('pending_older_than_hours')));
        }

        if ($request->filled('q')) {
            $this->applySearch($query, (string) $request->input('q'));
        }

        return $query;
    }

    /**
     * Dạng của `q`: `code` | `email` | `phone` | `name`. Hàm thuần; `email`/`phone` là tìm theo thông tin liên hệ (giới hạn riêng + audit).
     */
    public static function searchKind(string $q): string
    {
        return match (true) {
            preg_match('/^VV[0-9A-Z]{6,18}$/i', $q) === 1 => 'code',
            str_contains($q, '@') => 'email',
            preg_match('/^\+?[\d\s().\-]+$/', $q) === 1 => 'phone',
            default => 'name',
        };
    }

    /**
     * Giá trị liên hệ đã chuẩn hoá để băm audit (không bao giờ lưu giá trị gốc).
     */
    public static function normalizedContact(string $q, string $kind): string
    {
        return $kind === 'email' ? mb_strtolower($q) : (PhoneNumber::normalize($q) ?? (string) preg_replace('/\D/', '', $q));
    }

    /**
     * `q` có 4 dạng (api-contract §2.5): mã đơn (`VV…`) → `orders.code`; có `@` → email chính xác; toàn số (cho phép +84, khoảng trắng,
     * chấm, gạch) → SĐT chuẩn hoá; còn lại → tên học sinh `LIKE 'từ%'` (escape). Mọi dạng đều dùng binding.
     *
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, string $q): void
    {
        $kind = self::searchKind($q);

        if ($kind === 'code') {
            $query->where('orders.code', strtoupper($q));

            return;
        }

        if ($kind === 'email') {
            $query->whereIn('orders.user_id', User::query()->select('id')->where('email', mb_strtolower($q)));

            return;
        }

        if ($kind === 'phone') {
            $phone = PhoneNumber::normalize($q);
            $phone === null
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('orders.user_id', User::query()->select('id')->where('phone', $phone));

            return;
        }

        $query->whereIn('orders.user_id', User::query()->select('id')->where('name', 'like', Like::startsWith($q)));
    }
}
