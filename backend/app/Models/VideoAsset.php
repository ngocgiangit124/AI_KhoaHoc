<?php

namespace App\Models;

use App\Enums\VideoAssetStatus;
use Database\Factories\VideoAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Video tầng nghiệp vụ, độc lập nhà cung cấp (ADR-002). Mọi cột do server đặt (không có $fillable từ request):
 * tạo bằng service upload (T11) qua forceFill/factory.
 *
 * @property VideoAssetStatus $status
 */
class VideoAsset extends Model
{
    /** @use HasFactory<VideoAssetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoAssetStatus::class,
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'declared_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
