<?php

namespace App\Http\Requests\Admin\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Bộ lọc danh sách đơn quản trị (api-contract §2.5.1). Quyền (`OrderPolicy@viewAny`: admin, quản lý trang) kiểm TRƯỚC validate.
 *
 * `from`/`to` bắt buộc và cách nhau ≤ 366 ngày, TRỪ tab "Chờ duyệt" (`status[]` đúng bằng `["pending"]`): đơn chờ ít và tự hết hạn.
 */
class OrderFilterRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Order::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $q = $this->input('q');

        if (is_string($q)) {
            $q = trim($q);
            $this->merge(['q' => $q === '' ? null : $q]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dateRequired = ! $this->isPendingOnly();

        return [
            'from' => [Rule::requiredIf($dateRequired), 'required_with:to', 'nullable', 'date_format:Y-m-d'],
            'to' => [Rule::requiredIf($dateRequired), 'required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', 'array', 'max:5'],
            'status.*' => ['string', Rule::in(array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()))],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'manual', 'momo'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'needs_review' => ['sometimes', 'nullable', 'boolean'],
            'pending_older_than_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest'])],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'per_page' => ['sometimes', 'nullable', 'integer', Rule::in([25, 50])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'Vui lòng chọn khoảng ngày (từ ngày).',
            'to.required' => 'Vui lòng chọn khoảng ngày (đến ngày).',
            'from.required_with' => 'Cần nhập cả từ ngày và đến ngày.',
            'to.required_with' => 'Cần nhập cả từ ngày và đến ngày.',
            'from.date_format' => 'Từ ngày phải có dạng YYYY-MM-DD.',
            'to.date_format' => 'Đến ngày phải có dạng YYYY-MM-DD.',
            'to.after_or_equal' => 'Đến ngày phải sau hoặc bằng từ ngày.',
            'status.array' => 'Trạng thái không hợp lệ.',
            'status.*.in' => 'Trạng thái không hợp lệ.',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ.',
            'q.max' => 'Từ khoá tối đa 100 ký tự.',
            'needs_review.boolean' => 'Giá trị "cần xem lại" không hợp lệ.',
            'pending_older_than_hours.*' => 'Số giờ chờ phải từ 1 đến 720.',
            'sort.in' => 'Cách sắp xếp không hợp lệ.',
            'per_page.in' => 'Số dòng mỗi trang chỉ nhận 25 hoặc 50.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Cursor do client gửi lại: giải mã được và đúng khoá sắp xếp (created_at, id) — cursor bị sửa/hỏng → 422 thay vì 500.
            if ($this->filled('cursor') && ! $v->errors()->has('cursor') && ! $this->cursorIsValid((string) $this->input('cursor'))) {
                $v->errors()->add('cursor', 'Con trỏ phân trang không hợp lệ.');
            }

            if ($v->errors()->hasAny(['from', 'to'])) {
                return;
            }

            if ($this->filled('from') && $this->filled('to')) {
                $from = Carbon::createFromFormat('!Y-m-d', (string) $this->input('from'));
                $to = Carbon::createFromFormat('!Y-m-d', (string) $this->input('to'));

                if ($from !== null && $to !== null && $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                    $v->errors()->add('to', 'Khoảng ngày tối đa '.self::MAX_RANGE_DAYS.' ngày.');
                }
            }
        });
    }

    private function cursorIsValid(string $encoded): bool
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return false;
        }

        $params = $cursor->toArray();
        $createdAt = $params['created_at'] ?? null;

        return count(array_diff(array_keys($params), ['created_at', 'id', '_pointsToNextItems'])) === 0
            && is_string($createdAt) && strtotime($createdAt) !== false
            && isset($params['id']) && (is_int($params['id']) || (is_string($params['id']) && ctype_digit($params['id'])))
            && is_bool($params['_pointsToNextItems'] ?? null);
    }

    /** Tab "Chờ duyệt": `status[]` đúng bằng `["pending"]`. */
    private function isPendingOnly(): bool
    {
        $status = $this->input('status');

        return is_array($status) && array_values($status) === [OrderStatus::Pending->value];
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_values(array_unique(array_map('strval', (array) $this->input('status', []))));
    }

    public function sortOrder(): string
    {
        return $this->input('sort') === 'oldest' ? 'oldest' : 'newest';
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?: 25);
    }
}
