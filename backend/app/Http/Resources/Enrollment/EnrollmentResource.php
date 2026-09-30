<?php

namespace App\Http\Resources\Enrollment;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `POST /courses/{course}/free-enrollments` (US-012 AC1, api-contract §2.4):
 * "201 pending_approval".
 *
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
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
