<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /admin/courses/{course}/curriculum/order` (US-009 BR7/AC8, S5).
 * Body là mảng `[{chapter_id, lesson_ids[]}]` theo thứ tự mới.
 *
 * Request chỉ kiểm HÌNH DẠNG (số nguyên, không trùng chapter_id). Việc kiểm
 * "tập ID gửi lên == đúng tập hiện có của khóa" (không thêm/thiếu/khóa khác/đã
 * xoá, lesson không trùng giữa các chương) làm trong
 * `CurriculumOrderService` DƯỚI KHOÁ HÀNG, không thể làm ở đây vì sẽ bị race
 * (kiểm rồi mới khoá).
 *
 * Phân quyền chạy TRƯỚC ở middleware `can:manageContent,course` của route.
 */
class CurriculumOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            '*' => ['array:chapter_id,lesson_ids'],
            '*.chapter_id' => ['required', 'integer', 'min:1', 'distinct'],
            '*.lesson_ids' => ['present', 'array', 'list'],
            '*.lesson_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.chapter_id.required' => 'Thiếu mã chương.',
            '*.lesson_ids.present' => 'Thiếu danh sách bài học của chương.',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->isJson() ? $this->json()->all() : $this->all();

        // Không phải danh sách (vd. đối tượng `{"chapters": [...]}`): gán 1
        // giá trị vô nghĩa để rule `*` (array) báo 422 thay vì lặng lẽ bỏ qua.
        return array_is_list($data) ? $data : ['__invalid' => true];
    }
}
