<?php

namespace App\Models;

use App\Enums\CouponDiscountType;
use App\Enums\CouponState;
use App\Enums\CouponStatus;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Mã giảm giá (US-013). `code`, `status`, `is_restricted`, `used_count`, `created_by` KHÔNG nằm trong
 * $fillable (S17): chỉ đổi qua CouponService (used_count: T18/T19 và counters:recount).
 *
 * @property CouponDiscountType $discount_type
 * @property CouponStatus $status
 * @property int $discount_value
 * @property int|null $max_uses
 * @property int $used_count
 * @property bool $is_restricted
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    /** Mỗi học sinh dùng mỗi mã đúng 1 lần (US-013 BR3) — không cấu hình được ở MVP. */
    public const MAX_USES_PER_USER = 1;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'discount_type', 'discount_value', 'max_uses', 'valid_from', 'valid_until'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => CouponDiscountType::class,
            'status' => CouponStatus::class,
            'discount_value' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'is_restricted' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'coupon_course');
    }

    /**
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'coupon_subject');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Chuẩn hoá mã nhập vào: cắt khoảng trắng, chữ hoa (BR1). Dùng chung cho admin và T16. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** Trạng thái hiệu lực tại thời điểm `$now` (thứ tự ưu tiên xem CouponState). */
    public function state(?Carbon $now = null): CouponState
    {
        $now ??= now();

        return match (true) {
            $this->status === CouponStatus::Inactive => CouponState::Inactive,
            $this->valid_until !== null && $this->valid_until->lt($now) => CouponState::Expired,
            $this->max_uses !== null && $this->used_count >= $this->max_uses => CouponState::Exhausted,
            $this->valid_from->gt($now) => CouponState::Upcoming,
            default => CouponState::Active,
        };
    }

    /**
     * Lọc theo `state()` (cùng thứ tự ưu tiên với method `state`).
     *
     * @param  Builder<Coupon>  $query
     */
    public function scopeInState(Builder $query, CouponState $state, ?Carbon $now = null): void
    {
        $now ??= now();

        if ($state === CouponState::Inactive) {
            $query->where('status', CouponStatus::Inactive->value);

            return;
        }

        $query->where('status', CouponStatus::Active->value);

        if ($state === CouponState::Expired) {
            $query->where('valid_until', '<', $now);

            return;
        }

        $query->where(fn (Builder $w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $now));

        if ($state === CouponState::Exhausted) {
            $query->whereNotNull('max_uses')->whereColumn('used_count', '>=', 'max_uses');

            return;
        }

        $query->where(fn (Builder $w) => $w->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'));

        $state === CouponState::Upcoming
            ? $query->where('valid_from', '>', $now)
            : $query->where('valid_from', '<=', $now);
    }
}
