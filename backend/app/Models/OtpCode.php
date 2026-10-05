<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Database\Factories\OtpCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mã OTP (data-model §3.1). Cột trạng thái (`attempts`, `consumed_at`,
 * `invalidated_at`) CHỈ đổi qua OtpService bằng UPDATE có điều kiện (S9) nên
 * không nằm trong $fillable.
 *
 * @property OtpPurpose $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 * @property int $attempts
 * @property string $code_hash
 */
class OtpCode extends Model
{
    /** @use HasFactory<OtpCodeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'purpose',
        'channel',
        'destination',
        'code_hash',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'attempts' => 'integer',
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
