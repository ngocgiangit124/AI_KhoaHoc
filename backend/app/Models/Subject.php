<?php

namespace App\Models;

use App\Enums\SubjectStatus;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Chuyên đề (US-011). `slug` và `status` chỉ đổi qua SubjectService (S17).
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
    protected $fillable = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => SubjectStatus::class];
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_subject');
    }
}
