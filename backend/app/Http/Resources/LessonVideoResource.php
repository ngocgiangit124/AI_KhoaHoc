<?php

namespace App\Http\Resources;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Trạng thái video của bài (cho trang quản trị poll). Không trả provider id/library/headers upload.
 *
 * @mixin Lesson
 */
class LessonVideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $asset = $this->videoAsset;

        return [
            'lesson_id' => $this->id,
            'video_source' => $this->video_source->value,
            'has_video_asset' => $asset !== null,
            'video_asset_id' => $asset?->id,
            'status' => $asset?->status->value,
            'duration_seconds' => $asset?->duration_seconds,
            'original_filename' => $asset?->original_filename,
            'error_message' => $asset?->error_message,
            'updated_at' => $asset?->updated_at?->toIso8601String(),
        ];
    }
}
