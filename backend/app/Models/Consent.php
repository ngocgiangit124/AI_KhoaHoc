<?php

namespace App\Models;

use App\Enums\ConsentType;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bằng chứng đồng ý xử lý dữ liệu (data-model §3.1, S7). Chỉ tạo/thu hồi qua
 * `App\Services\Privacy\ConsentService` — không có route sửa/xoá.
 *
 * L3 (review docs/security/review-T03-FW1.md) — trước đây không có route
 * sửa/xoá, nhưng ở TẦNG MODEL bản ghi vẫn sửa/xoá được tuỳ ý
 * (`$consent->update(['policy_version' => ...])`, `->delete()`). Chặn ở đây
 * theo đúng "vòng đời" hợp lệ DUY NHẤT: tạo (không đổi), rồi CÓ THỂ đổi ĐÚNG
 * `revoked_at` từ `null` sang 1 giá trị (thu hồi đồng ý) — không đổi gì khác,
 * không xoá. Giống tinh thần bất biến của `AuditLog` (M5, T01/T02), nhưng
 * KHÔNG chặn được thao tác qua Eloquent Builder trực tiếp
 * (`Consent::where(...)->update()`) hay `DB::table()` — cần quyền DB (cùng
 * đợt với `audit_logs`, TODO DBA, xem review-T01-T02.md mục M5).
 *
 * @property ConsentType $type
 */
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $dirty = array_keys($model->getDirty());

            $onlyRevoking = $dirty === ['revoked_at'] && $model->getOriginal('revoked_at') === null;

            if (! $onlyRevoking) {
                throw new LogicException(
                    'Consent là bằng chứng đồng ý — chỉ được đổi revoked_at (từ null), không được sửa trường khác.'
                );
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Consent là bằng chứng đồng ý — không được phép xoá.');
        });
    }

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
