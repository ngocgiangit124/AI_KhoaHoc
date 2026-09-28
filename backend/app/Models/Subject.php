<?php

namespace App\Models;

use App\Enums\SubjectStatus;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Chuyên đề (US-011, data-model §3.2). T07 chỉ tạo schema + model tối thiểu vì
 * `courses`/`course_subject` (T07) cần FK/quan hệ tới bảng này; CRUD/policy/ẩn-hiện
 * đầy đủ thuộc T06 — nếu cần thêm field/hành vi, mở rộng trong T06, không nhân bản.
 *
 * @property SubjectStatus $status
 */
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubjectStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_subject');
    }

    /**
     * @param  Builder<Subject>  $query
     * @return Builder<Subject>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubjectStatus::Active);
    }
}
