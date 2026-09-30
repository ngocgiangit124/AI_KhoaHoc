<?php

namespace App\Http\Requests\Admin;

use App\Enums\VideoSource;
use App\Rules\ExternalVideoUrl;
use App\Rules\PlainText;
use App\Support\ExternalVideoLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST/PUT /admin/courses/{course}/chapters/{chapter}/lessons[/{lesson}]`
 * (US-009 BR10, api-contract §2.5, S5/S13). PUT là thay thế đầy đủ (mọi trường
 * bắt buộc) để không bao giờ tồn tại trạng thái nửa vời (vd. đổi `is_preview`
 * thành false mà vẫn giữ link ngoài).
 *
 * KHÔNG nhận `video_asset_id`, `course_id`, `chapter_id`, `position`,
 * `external_provider`, `external_video_id` — kể cả có gửi lên cũng không nằm
 * trong `validated()` nên không bao giờ được áp dụng: course/chapter lấy từ
 * route, asset chỉ gán bởi luồng upload của CHÍNH bài đó (T11), provider/ID
 * chỉ suy ra từ `external_url` bằng regex chặt ({@see ExternalVideoLink}).
 *
 * `external_url`: chỉ khi `video_source = external_link` VÀ `is_preview = true`
 * (S13 — nội dung trả phí không thể bảo vệ khi nhúng link ngoài).
 * `duration_seconds`: video `upload` do webhook điền (không nhận từ request);
 * `none`/`external_link` nhập tay.
 *
 * Phân quyền chạy TRƯỚC ở middleware `can:manageContent,course` của route.
 */
class LessonRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255', new PlainText],
            'is_preview' => ['required', 'boolean'],
            'video_source' => ['required', Rule::enum(VideoSource::class)],
            'external_url' => [
                'required_if:video_source,'.VideoSource::ExternalLink->value,
                'prohibited_unless:video_source,'.VideoSource::ExternalLink->value,
                'nullable',
                'string',
                'max:2048',
                new ExternalVideoUrl,
            ],
            'duration_seconds' => [
                'exclude_if:video_source,'.VideoSource::Upload->value,
                'nullable',
                'integer',
                'min:1',
                'max:86400',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('title'))) {
            $merge['title'] = trim($this->input('title'));
        }

        if (is_string($this->input('external_url'))) {
            $merge['external_url'] = trim($this->input('external_url'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('video_source') === VideoSource::ExternalLink->value
                    && ! $this->boolean('is_preview')) {
                    $validator->errors()->add(
                        'external_url',
                        'Link video ngoài chỉ dùng được cho bài học xem thử (preview).'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên bài học.',
            'external_url.required_if' => 'Vui lòng nhập link video.',
            'external_url.prohibited_unless' => 'Chỉ nhập link video khi chọn hình thức "Dán link video ngoài".',
        ];
    }
}
