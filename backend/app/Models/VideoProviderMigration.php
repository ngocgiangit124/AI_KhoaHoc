<?php

namespace App\Models;

use Database\Factories\VideoProviderMigrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sổ chuyển nhà cung cấp video (T37-1). Mọi cột do server đặt.
 */
class VideoProviderMigration extends Model
{
    /** @use HasFactory<VideoProviderMigrationFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const UPLOADED = 'uploaded';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const ABANDONED = 'abandoned';

    public const ROLLED_BACK = 'rolled_back';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'completed_at' => 'datetime',
            'source_deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VideoAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class, 'video_asset_id');
    }
}
