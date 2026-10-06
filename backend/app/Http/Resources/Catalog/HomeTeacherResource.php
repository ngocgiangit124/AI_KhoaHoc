<?php

namespace App\Http\Resources\Catalog;

use App\Services\Teachers\Data\HomepageTeacher;
use App\Support\PublicTeacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Giáo viên ở khu vực trang chủ (US-020, api-contract §2.9). Ảnh/bio/headline chỉ đi ra qua `PublicTeacher`
 * (chốt đồng ý); không email/SĐT/trạng thái/cờ nội bộ (BR13).
 *
 * @mixin HomepageTeacher
 */
class HomeTeacherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return PublicTeacher::toArray($this->user, withHeadline: true) + [
            'grade_levels' => $this->gradeLevels,
            'courses_count' => $this->coursesCount,
        ];
    }
}
