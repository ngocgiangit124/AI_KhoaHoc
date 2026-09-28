<?php

namespace App\Http\Resources\Catalog;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Outline công khai (US-003 BR1, AC1) — CHỈ tên bài, thời lượng, `is_preview`.
 * TUYỆT ĐỐI KHÔNG chứa `video_asset_id`/`external_video_id`/`video_source`
 * hay bất kỳ trường nào dẫn tới URL/ID video (S13) — video phát qua
 * `/learn/lessons/{lesson}/playback` (T13) hoặc `/preview/lessons/{lesson}/playback`.
 *
 * @property-read Lesson $resource
 */
class LessonOutlineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'position' => $this->position,
            'duration_seconds' => $this->duration_seconds,
            'is_preview' => $this->is_preview,
        ];
    }
}
