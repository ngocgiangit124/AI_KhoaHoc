<?php

namespace App\Http\Resources\Admin;

use App\Models\Enrollment;
use App\Support\PiiMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /admin/enrollment-requests`, `POST .../approve|reject` (US-012
 * AC7, api-contract §2.5): "Tên HS hiển thị, email/SĐT che". Controller PHẢI
 * eager-load `user`/`course` trước khi tạo Resource này
 * (`Model::preventLazyLoading()` bật ở local/testing).
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
            'rejection_reason' => $this->rejection_reason,
            'student' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email_masked' => PiiMask::email($this->user->email),
                'phone_masked' => PiiMask::phone($this->user->phone),
            ],
            'course' => [
                'id' => $this->course->id,
                'title' => $this->course->title,
            ],
        ];
    }
}
