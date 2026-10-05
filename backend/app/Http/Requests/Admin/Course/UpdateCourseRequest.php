<?php

namespace App\Http\Requests\Admin\Course;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Sửa khóa học — 2 bộ rule (S5, S17). Chỉ các trường có trong rule mới vào `validated()`, các trường khác (kể cả
 * `status`, `manual_order`, `slug`, `created_by`) bị bỏ qua im lặng.
 *  - Staff: title, grade_level, short_description, description, price, thumbnail, subject_ids, teacher_ids.
 *  - Giáo viên: title, short_description, description, thumbnail, subject_ids; `grade_level` chỉ khi khóa chưa từng
 *    xuất bản (`published_at` null); KHÔNG có `price`, `teacher_ids`.
 * Tất cả `sometimes` (gửi trường nào sửa trường đó). Upload ảnh: POST multipart kèm `_method=PUT`.
 */
class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kiểm quyền TRƯỚC validate: giáo viên không được gán không dò được gì qua lỗi 422.
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('update', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        /** @var Course $course */
        $course = $this->route('course');

        $rules = [
            'title' => CourseRules::title('sometimes'),
            'short_description' => CourseRules::shortDescription(),
            'description' => CourseRules::description('sometimes'),
            'thumbnail' => CourseRules::thumbnail('sometimes'),
        ] + CourseRules::subjects('sometimes');

        if ($user->isStaff()) {
            return $rules + [
                'grade_level' => CourseRules::gradeLevel('sometimes'),
                'price' => CourseRules::price('sometimes'),
            ] + CourseRules::teachers('sometimes');
        }

        if ($course->published_at === null) {
            $rules['grade_level'] = CourseRules::gradeLevel('sometimes');
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CourseRules::messages();
    }
}
