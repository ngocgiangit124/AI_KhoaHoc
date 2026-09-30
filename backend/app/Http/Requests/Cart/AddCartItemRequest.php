<?php

namespace App\Http\Requests\Cart;

use App\Enums\CourseStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /cart/items (US-004 BR1, BR6, api-contract §2.3): `course_id` tồn tại,
 * chưa xoá, `published`, `price > 0`. Mọi trường hợp không hợp lệ ra CÙNG 1
 * lỗi 422 (không lộ khóa nháp/đã gỡ có tồn tại hay không — giống 404 ở
 * `GET /courses/{slug}`).
 */
class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'course_id' => [
                'required',
                'integer',
                Rule::exists('courses', 'id')->where(
                    fn (Builder $query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', CourseStatus::Published->value)
                        ->where('price', '>', 0)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.exists' => 'Khóa học không tồn tại hoặc không thể thêm vào giỏ hàng.',
        ];
    }
}
