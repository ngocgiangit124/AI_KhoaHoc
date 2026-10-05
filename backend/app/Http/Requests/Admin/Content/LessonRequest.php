<?php

namespace App\Http\Requests\Admin\Content;

use App\Enums\VideoSource;
use App\Models\Course;
use App\Models\Lesson;
use App\Rules\PlainText;
use App\Services\Content\ExternalVideoLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tạo/sửa bài. KHÔNG nhận `video_asset_id`, `course_id`, `chapter_id`, `position` (S5): asset chỉ gắn bởi luồng
 * upload (T11), chương/thứ tự đổi qua curriculum/order. Tạo: title bắt buộc; sửa: gửi trường nào sửa trường đó.
 * `external_url` chỉ parse ra {provider,id} (S13); link ngoài chỉ cho bài `is_preview`.
 */
class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageContent', $course);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim((string) preg_replace('/\s+/u', ' ', $this->input('title')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('lesson') === null;

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', new PlainText],
            'is_preview' => ['sometimes', 'boolean'],
            'video_source' => ['sometimes', Rule::enum(VideoSource::class)],
            'external_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Lesson|null $lesson */
            $lesson = $this->route('lesson');
            $source = $this->has('video_source') ? VideoSource::from((string) $this->input('video_source')) : ($lesson->video_source ?? VideoSource::None);
            $preview = $this->has('is_preview') ? $this->boolean('is_preview') : (bool) $lesson?->is_preview;

            if ($source === VideoSource::Upload && $lesson?->video_asset_id === null) {
                $validator->errors()->add('video_source', 'Hãy lưu bài học trước rồi tải video lên (bài chưa có video tải lên).');

                return;
            }

            if ($source !== VideoSource::ExternalLink) {
                return;
            }

            if (! $preview) {
                $validator->errors()->add('video_source', 'Link video ngoài chỉ dùng được cho bài học xem thử (is_preview).');

                return;
            }

            $url = $this->input('external_url');

            if ($url === null || $url === '') {
                // Giữ link cũ nếu bài đã là external_link và không gửi URL mới.
                if ($lesson?->video_source !== VideoSource::ExternalLink || $lesson->external_video_id === null || $this->has('external_url')) {
                    $validator->errors()->add('external_url', 'Vui lòng nhập link video YouTube hoặc Vimeo.');
                }

                return;
            }

            if (app(ExternalVideoLink::class)->parse((string) $url) === null) {
                $validator->errors()->add('external_url', 'Link video không hợp lệ. Chỉ hỗ trợ link https của YouTube hoặc Vimeo.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên bài học.',
            'title.max' => 'Tên bài học tối đa 255 ký tự.',
            'video_source.enum' => 'Hình thức video không hợp lệ.',
            'is_preview.boolean' => 'Giá trị xem thử không hợp lệ.',
            'duration_seconds.integer' => 'Thời lượng phải là số giây.',
            'duration_seconds.max' => 'Thời lượng tối đa 86400 giây.',
        ];
    }
}
