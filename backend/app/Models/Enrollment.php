<?php

namespace App\Models;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ghi danh. `status` chỉ đổi qua EnrollmentService (T12/T14); không có cột nào nhận từ request (S17).
 * `live_flag` là generated column STORED — chỉ đọc.
 *
 * @property EnrollmentStatus $status
 * @property EnrollmentSource $source
 * @property Carbon|null $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_accessed_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'course_id'];

    /**
     * @return array<string, string>
     */
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
}
