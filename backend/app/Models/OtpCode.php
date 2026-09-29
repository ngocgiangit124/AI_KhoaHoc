<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Database\Factories\OtpCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mã OTP (US-001 AC8/AC9, data-model §3.1, S9). Chỉ tạo/tiêu thụ qua
 * `App\Services\Auth\OtpService` — KHÔNG BAO GIỜ lưu mã ở dạng rõ, chỉ
 * `code_hash` (`Hash::make`), và không bao giờ ghi mã ra log (S21).
 *
 * @property OtpPurpose $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 */
class OtpCode extends Model
{
    /** @use HasFactory<OtpCodeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'purpose',
        'channel',
        'destination',
        'code_hash',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'attempts',
    ];

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
