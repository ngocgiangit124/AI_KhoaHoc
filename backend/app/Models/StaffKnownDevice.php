<?php

namespace App\Models;

use Database\Factories\StaffKnownDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Thiết bị đã biết của 1 tài khoản giáo viên (T28 — email cảnh báo thiết bị
 * mới). Chỉ ghi/đọc qua `App\Services\Auth\StaffDeviceService`.
 */
class StaffKnownDevice extends Model
{
    /** @use HasFactory<StaffKnownDeviceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'first_seen_at',
        'last_seen_at',
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
