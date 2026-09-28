<?php

namespace App\Models;

use App\Enums\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * data-model §3.2 (US-002, US-003, US-009, US-011).
 *
 * @property CourseStatus $status
 * @property Carbon|null $published_at
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, SoftDeletes;

    /**
     * S17 — mass assignment: `status`, `published_at`, `manual_order`,
     * `enrollments_count`, `created_by`, `search_text` KHÔNG được liệt kê ở
     * đây; chỉ đổi qua Service chuyên trách (CourseService — T08).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'description',
        'grade_level',
        'price',
        'thumbnail_path',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'published_at' => 'datetime',
            'grade_level' => 'integer',
            'price' => 'integer',
            'manual_order' => 'integer',
            'enrollments_count' => 'integer',
        ];
    }

    /**
     * `search_text` = Str::ascii(title + short_description), bỏ dấu, viết
     * thường (US-002 AC5) — tính lại mỗi lần lưu, không phải trong `casts()`
     * vì đây là cột suy ra từ 2 cột khác, không phải kiểu dữ liệu.
     */
    protected static function booted(): void
    {
        static::saving(function (Course $course): void {
            $course->setAttribute('search_text', Str::lower(Str::ascii(
                $course->title.' '.(string) $course->short_description
            )));
        });
    }

    /**
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'course_subject');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teacher')
            ->withPivot(['added_by', 'created_at']);
    }

    /**
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Danh mục công khai chỉ hiển thị khóa học `published` (US-002 BR2).
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published);
    }
}
