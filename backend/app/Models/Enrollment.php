<?php

namespace App\Models;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mỗi lần xin học/mua là 1 dòng — giữ lịch sử từ chối/thu hồi (data-model §3.3,
 * US-012). `order_id` CHƯA có FK tới `orders` (bảng đó thuộc T18) — xem
 * docblock migration `..._create_enrollments_table.php`.
 *
 * Larastan không tự suy ra kiểu cast từ `casts()` (chỉ đọc `protected $casts`
 * khai báo tĩnh — như đã ghi ở `App\Models\User`) — khai tường minh các cột
 * ngày giờ được `EnrollmentResource`/`EnrollmentRequestResource` (T14) gọi
 * `->toIso8601String()`.
 *
 * @property EnrollmentStatus $status
 * @property EnrollmentSource $source
 * @property int|null $live_flag
 * @property Carbon|null $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $revoked_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * S17 — `status`, `approved_by`, `approved_at`, `activated_at`,
     * `revoked_at`, `revoked_reason`, `live_flag` chỉ đổi qua
     * `EnrollmentService` (T14) — KHÔNG BAO GIỜ fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'source',
        'order_id',
        'requested_at',
        'rejection_reason',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'source' => EnrollmentSource::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'live_flag' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Đang sở hữu quyền học (US-003 BR3) — chỉ `active`.
     *
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active);
    }
}
