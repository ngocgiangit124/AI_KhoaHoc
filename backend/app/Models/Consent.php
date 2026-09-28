<?php

namespace App\Models;

use App\Enums\ConsentType;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bằng chứng đồng ý xử lý dữ liệu (data-model §3.1, S7). Chỉ tạo qua
 * `App\Services\Privacy\ConsentService` — không sửa/xoá qua route nào.
 *
 * @property ConsentType $type
 */
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'policy_version',
        'granted_by',
        'channel',
        'destination_masked',
        'granted_at',
        'revoked_at',
        'ip',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
