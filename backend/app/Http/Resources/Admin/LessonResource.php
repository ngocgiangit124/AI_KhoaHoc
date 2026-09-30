<?php

namespace App\Http\Resources\Admin;

use App\Models\Lesson;
use App\Support\ExternalVideoLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bài học ở API quản trị (US-009). CHỈ dùng cho staff/GV phụ trách của khóa
 * (route `can:manageContent`); Resource công khai/học sinh
 * (`Catalog\LessonOutlineResource`) là lớp KHÁC và không có URL/ID video
 * (S13). Link ngoài trả dạng URL embed dựng lại từ ID (không phải URL người
 * nhập).
 *
 * @property-read Lesson $resource
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
            'video_asset_id' => $this->video_asset_id,
            'external_provider' => $this->external_provider,
            'external_embed_url' => ExternalVideoLink::embedUrl($this->external_provider, $this->external_video_id),
            'duration_seconds' => $this->duration_seconds,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
