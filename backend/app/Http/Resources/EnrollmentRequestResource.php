<?php

namespace App\Http\Resources;

use App\Models\Enrollment;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Yêu cầu đăng ký dưới góc nhìn người duyệt: email/SĐT học sinh đã che.
 *
 * @mixin Enrollment
 */
class EnrollmentRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ]),
            'student' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'grade_level' => $this->user->grade_level,
                'email_masked' => Mask::email($this->user->email),
                'phone_masked' => Mask::phone($this->user->phone),
            ]),
        ];
    }
}
