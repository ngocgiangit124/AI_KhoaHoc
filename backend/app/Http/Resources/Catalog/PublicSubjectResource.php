<?php

namespace App\Http\Resources\Catalog;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chuyên đề công khai: chỉ id/name/slug (không status, không đếm).
 *
 * @mixin Subject
 */
class PublicSubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug];
    }
}
