<?php

namespace App\Models;

use Database\Factories\TeacherProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hồ sơ công khai của giáo viên, 1-1 với `users` (data-model §3.1, ADR-005). Chỉ `TeacherProfileService` được ghi.
 *
 * S17: `$fillable` chỉ gồm `headline`, `bio`. Ảnh, đồng ý, cờ trang chủ, người sửa gần nhất chỉ đổi bằng `forceFill`
 * trong service. Ảnh/bio chỉ được xuất ra API công khai qua `App\Support\PublicTeacher`.
 *
 * @property int $user_id
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property Carbon|null $public_consent_at
 * @property string|null $public_consent_version
 * @property Carbon|null $public_consent_withdrawn_at
 * @property bool $show_on_homepage
 * @property int|null $homepage_order
 * @property int|null $profile_updated_by
 * @property Carbon|null $profile_updated_at
 */
class TeacherProfile extends Model
{
    /** @use HasFactory<TeacherProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'headline',
        'bio',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_consent_at' => 'datetime',
            'public_consent_withdrawn_at' => 'datetime',
            'show_on_homepage' => 'boolean',
            'homepage_order' => 'integer',
            'profile_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_updated_by');
    }
}
