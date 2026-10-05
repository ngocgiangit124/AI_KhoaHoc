<?php

namespace App\Http\Requests\Admin\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** PUT /admin/courses/{course}/teachers — chỉ staff (quyền kiểm trước validate). */
class CourseTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageTeachers', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CourseRules::teachers('required');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CourseRules::messages();
    }
}
