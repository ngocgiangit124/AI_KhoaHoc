<?php

namespace App\Models;

use App\Enums\ConsentType;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bằng chứng đồng ý xử lý dữ liệu (S7, data-model §3.1). Chỉ có `created_at`.
 *
 * @property ConsentType $type
 * @property string $policy_version
 * @property string $granted_by
 * @property string $channel
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const GRANTED_BY_SELF = 'self';

    public const GRANTED_BY_PARENT = 'parent';

    public const CHANNEL_WEB_FORM = 'web_form';

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
