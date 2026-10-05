<?php

namespace App\Models;

use App\Enums\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Khóa học (data-model §3.2). `status`, `published_at`, `manual_order`, `enrollments_count`, `created_by`,
 * `slug` KHÔNG nằm trong $fillable (S17): chỉ đổi qua CourseService/EnrollmentService (T08+).
 *
 * @property CourseStatus $status
 * @property Carbon|null $published_at
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'short_description',
        'description',
        'grade_level',
        'price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'grade_level' => 'integer',
            'price' => 'integer',
            'manual_order' => 'integer',
            'enrollments_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Tìm kiếm không dấu (US-002 AC5): tiêu đề + mô tả ngắn, bỏ dấu (đ→d), chữ thường.
        static::saving(function (Course $course): void {
            $course->search_text = self::buildSearchText($course->title, $course->short_description);
        });
    }

    public static function buildSearchText(?string $title, ?string $shortDescription): string
    {
        $text = mb_strtolower(Str::ascii(trim((string) $title.' '.(string) $shortDescription)));

        return mb_substr($text, 0, 1000);
    }

    /**
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'course_subject');
    }

    /**
     * @return BelongsToMany<User, $this, CourseTeacher, 'pivot'>
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teacher')->using(CourseTeacher::class)->withPivot('added_by');
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
     * Khóa học user được thấy ở trang quản trị: staff thấy tất cả, giáo viên chỉ khóa mình có tên trong
     * `course_teacher` (US-009 AC6); vai trò khác không thấy khóa nào.
     *
     * @param  Builder<Course>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isStaff()) {
            return;
        }

        if ($user->isTeacher()) {
            $query->whereIn('courses.id', DB::table('course_teacher')->select('course_id')->where('user_id', $user->getKey()));

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
