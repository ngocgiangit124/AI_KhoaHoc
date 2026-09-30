<?php

namespace App\Models;

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Mã giảm giá quản trị (US-013, data-model §3.5).
 *
 * @property CouponDiscountType $discount_type
 * @property CouponStatus $status
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    /**
     * S17 — mass assignment: `status`, `used_count`, `is_restricted`,
     * `created_by` KHÔNG nằm trong danh sách này, chỉ đổi qua `CouponService`/
     * `CouponUsedCountRecounter`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'discount_type',
        'discount_value',
        'max_uses',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => CouponDiscountType::class,
            'discount_value' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'status' => CouponStatus::class,
            'is_restricted' => 'boolean',
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

    /**
     * Lọc theo trạng thái hiển thị (api-contract §2.5 "filter state", US-013
     * AC6/AC4). Các nhánh loại trừ lẫn nhau theo thứ tự ưu tiên: inactive >
     * expired > exhausted > active — khớp cách đặc tả UX (US-013 §2.1) dự
     * kiến hiển thị mỗi mã đúng 1 trạng thái duy nhất.
     *
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'inactive' => $query->where('status', CouponStatus::Inactive),
            'expired' => $query->where('status', CouponStatus::Active)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', now()),
            'exhausted' => $query->where('status', CouponStatus::Active)
                ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                ->whereNotNull('max_uses')
                ->whereColumn('used_count', '>=', 'max_uses'),
            'active' => $query->where('status', CouponStatus::Active)
                ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses')),
            default => $query,
        };
    }
}
