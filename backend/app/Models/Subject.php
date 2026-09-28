<?php

namespace App\Models;

use App\Enums\SubjectStatus;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Chuyên đề khóa học (US-011). `name`/`slug` là văn bản thuần, không HTML.
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
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubjectStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SubjectStatus::Active;
    }
}
