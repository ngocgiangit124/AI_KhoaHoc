<?php

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Enrollment của chính học sinh (không lộ người duyệt/PII).
 *
 * @mixin Enrollment
 */
class MyEnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'status' => $this->status->value,
            'requested_at' => $this->requested_at?->toIso8601String(),
        ];
    }
}
