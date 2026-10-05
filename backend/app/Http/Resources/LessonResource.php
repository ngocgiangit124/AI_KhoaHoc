<?php

namespace App\Http\Resources;

use App\Models\Lesson;
use App\Services\Content\ExternalVideoLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bài học cho trang quản trị (staff/giáo viên được gán, không phải học sinh). `external_embed_url` dựng lại từ ID
 * (S13); không bao giờ trả URL người nhập. `video_status` chỉ có khi đã nạp `videoAsset`.
 *
 * @mixin Lesson
 */
class LessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'chapter_id' => $this->chapter_id,
            'title' => $this->title,
            'position' => $this->position,
            'is_preview' => $this->is_preview,
            'video_source' => $this->video_source->value,
            'duration_seconds' => $this->duration_seconds,
            'external_provider' => $this->external_provider,
            'external_video_id' => $this->external_video_id,
            'external_embed_url' => ExternalVideoLink::embedUrl($this->external_provider, $this->external_video_id),
            'has_video_asset' => $this->video_asset_id !== null,
            'video_status' => $this->video_asset_id !== null && $this->relationLoaded('videoAsset')
                ? $this->videoAsset?->status->value
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
