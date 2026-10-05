<?php

namespace App\Http\Requests\Admin\Course;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Tạo khóa học (US-009 AC1, AC9). Staff gửi `teacher_ids` (bắt buộc); giáo viên gửi thì bị bỏ qua và tự được thêm
 * làm giáo viên phụ trách (BR8). `status`, `slug`, `created_by`, `manual_order` không bao giờ nhận từ request (S17).
 * Ảnh bìa bắt buộc khi tạo (AC1). Multipart/form-data khi có ảnh.
 */
class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Course::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'title' => CourseRules::title('required'),
            'grade_level' => CourseRules::gradeLevel('required'),
            'short_description' => CourseRules::shortDescription(),
            'description' => CourseRules::description('required'),
            'price' => CourseRules::price('required'),
            'thumbnail' => CourseRules::thumbnail('required'),
        ] + CourseRules::subjects('required');

        /** @var User $user */
        $user = $this->user();

        return $user->isStaff() ? $rules + CourseRules::teachers('required') : $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CourseRules::messages();
    }

    /**
     * Giáo viên phụ trách sẽ gán: staff theo `teacher_ids`; giáo viên tự tạo thì chỉ chính họ.
     *
     * @return list<int>
     */
    public function teacherIds(): array
    {
        /** @var User $user */
        $user = $this->user();

        return $user->isStaff() ? array_map('intval', $this->validated('teacher_ids')) : [(int) $user->getKey()];
    }
}
