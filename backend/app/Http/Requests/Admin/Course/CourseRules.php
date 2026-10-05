<?php

namespace App\Http\Requests\Admin\Course;

use App\Enums\SubjectStatus;
use App\Enums\UserRole;
use App\Rules\PlainText;
use App\Services\Content\HtmlSanitizer;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Rule dùng chung của Store/UpdateCourseRequest (api-contract §4: ảnh, mô tả HTML).
 */
final class CourseRules
{
    public const MAX_PRICE = 50_000_000;

    /** @return list<mixed> */
    public static function title(string $presence): array
    {
        return [$presence, 'string', 'min:1', 'max:255', new PlainText];
    }

    /** @return list<mixed> */
    public static function gradeLevel(string $presence): array
    {
        return [$presence, 'integer', 'between:6,12'];
    }

    /** @return list<mixed> */
    public static function shortDescription(): array
    {
        return ['nullable', 'string', 'max:500', new PlainText];
    }

    /** @return list<mixed> */
    public static function description(string $presence): array
    {
        return [
            $presence,
            'string',
            'max:100000',
            function (string $attribute, mixed $value, Closure $fail): void {
                // Sau khi lọc HTML phải còn nội dung chữ (không chỉ thẻ bị loại).
                if (is_string($value) && app(HtmlSanitizer::class)->plainText($value) === '') {
                    $fail('Vui lòng nhập mô tả khóa học.');
                }
            },
        ];
    }

    /** @return list<mixed> */
    public static function price(string $presence): array
    {
        return [$presence, 'integer', 'min:0', 'max:'.self::MAX_PRICE];
    }

    /** @return list<mixed> */
    public static function thumbnail(string $presence): array
    {
        return [$presence, 'file', 'max:2048',
            'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
            'dimensions:max_width=4000,max_height=4000'];
    }

    /** @return array<string, list<mixed>> */
    public static function subjects(string $presence): array
    {
        return [
            'subject_ids' => [$presence, 'array', 'min:1', 'max:20'],
            'subject_ids.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')->where('status', SubjectStatus::Active->value)],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function teachers(string $presence): array
    {
        return [
            'teacher_ids' => [$presence, 'array', 'min:1', 'max:50'],
            // Chỉ kiểm role ở đây; "đang hoạt động" kiểm ở CourseTeacherService cho giáo viên MỚI thêm
            // (giáo viên đã gán sẵn mà bị khoá vẫn được giữ — R5 review T08).
            'teacher_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', UserRole::Teacher->value)],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên khóa học.',
            'title.max' => 'Tên khóa học tối đa 255 ký tự.',
            'grade_level.required' => 'Vui lòng chọn lớp (6–12).',
            'grade_level.between' => 'Lớp phải từ 6 đến 12.',
            'description.required' => 'Vui lòng nhập mô tả khóa học.',
            'price.required' => 'Vui lòng nhập học phí.',
            'price.integer' => 'Học phí phải là số nguyên (VNĐ).',
            'price.min' => 'Học phí không được âm.',
            'price.max' => 'Học phí tối đa 50.000.000đ.',
            'thumbnail.required' => 'Vui lòng chọn ảnh đại diện.',
            'thumbnail.mimes' => 'Ảnh đại diện chỉ nhận JPG, PNG hoặc WebP.',
            'thumbnail.mimetypes' => 'Ảnh đại diện chỉ nhận JPG, PNG hoặc WebP.',
            'thumbnail.max' => 'Ảnh đại diện tối đa 2 MB.',
            'thumbnail.dimensions' => 'Ảnh đại diện tối đa 4000x4000 px.',
            'subject_ids.required' => 'Vui lòng chọn ít nhất 1 chuyên đề.',
            'subject_ids.min' => 'Vui lòng chọn ít nhất 1 chuyên đề.',
            'subject_ids.*.exists' => 'Chuyên đề không tồn tại hoặc đang ẩn.',
            'teacher_ids.required' => 'Khóa học cần tối thiểu 1 giáo viên phụ trách.',
            'teacher_ids.min' => 'Khóa học cần tối thiểu 1 giáo viên phụ trách.',
            'teacher_ids.*.exists' => 'Chỉ được gán tài khoản giáo viên đang hoạt động.',
        ];
    }
}
