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
    private const INVALID_KEY = 'curriculum';

    /** Trần số chương mỗi khóa trong 1 lần sắp xếp. */
    public const MAX_CHAPTERS = 200;

    /** Trần số bài mỗi chương trong 1 lần sắp xếp. */
    public const MAX_LESSONS_PER_CHAPTER = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Body không hợp lệ (đã bị `validationData()` thay bằng marker): chỉ
        // báo 1 lỗi duy nhất, không sinh thêm lỗi `curriculum.chapter_id`...
        if (array_key_exists(self::INVALID_KEY, $this->validationData())) {
            return ['*' => ['array']];
        }

        return [
            '*' => ['array:chapter_id,lesson_ids'],
            // KHÔNG dùng `distinct`: trên wildcard nó so từng cặp (bậc hai theo
            // số ID). Trùng lặp/thừa/thiếu do `CurriculumOrderService::sameSet()`
            // bắt dưới khoá hàng (review-T09 R3).
            '*.chapter_id' => ['required', 'integer', 'min:1'],
            '*.lesson_ids' => ['present', 'array', 'list'],
            '*.lesson_ids.*' => ['integer', 'min:1'],
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
            'curriculum.array' => 'Dữ liệu sắp xếp không hợp lệ hoặc vượt giới hạn ('
                .self::MAX_CHAPTERS.' chương, '.self::MAX_LESSONS_PER_CHAPTER.' bài mỗi chương).',
        ];
    }

    /**
     * Cắt trần kích thước TRƯỚC khi Validator mở rộng wildcard (body có thể
     * tới `client_max_body_size` — hàng trăm nghìn ID sẽ ngốn CPU/RAM của
     * worker FPM). Vượt trần hoặc không phải danh sách → 1 giá trị vô nghĩa
     * để rule `*` (array) báo 422 (key `curriculum`) thay vì lặng lẽ bỏ qua.
     *
     * @return array<int|string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->isJson() ? $this->json()->all() : $this->all();
        $invalid = [self::INVALID_KEY => true];

        if (! array_is_list($data) || count($data) > self::MAX_CHAPTERS) {
            return $invalid;
        }

        foreach ($data as $item) {
            if (is_array($item)
                && is_array($item['lesson_ids'] ?? null)
                && count($item['lesson_ids']) > self::MAX_LESSONS_PER_CHAPTER) {
                return $invalid;
            }
        }

        return $data;
    }
}
