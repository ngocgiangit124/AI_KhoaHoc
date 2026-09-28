<?php

namespace App\Http\Resources\Catalog;

use App\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Chapter $resource
 */
class ChapterOutlineResource extends JsonResource
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
            'lessons' => LessonOutlineResource::collection(
                $this->lessons->sortBy('position')->values()
            ),
        ];
    }
}
