<?php

namespace App\Models;

use App\Models\Builders\ImmutableAuditLogBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Nhật ký thao tác nhạy cảm (data-model §3.1, S15) — CHỈ ghi thêm qua
 * `App\Services\Audit\AuditLogger`. Không có route/API sửa hoặc xoá.
 *
 * M5 (review bảo mật T01/T02) — bất biến được chặn ở NHIỀU lớp trong app:
 * 1. Override `update()`/`delete()` instance — chặn gọi trực tiếp 2 method này.
 * 2. Sự kiện `saving` (khi `$model->exists`)/`deleting`/`updating` — chặn
 *    `$log->action = 'x'; $log->save();` (bypass (1) vì `save()` trên bản ghi
 *    đã tồn tại gọi thẳng `performUpdate()`, không qua method `update()`), và
 *    chặn `$log->increment('id')`/`decrement()` (chỉ bắn sự kiện `updating`,
 *    KHÔNG bắn `saving` — bypass (1) lẫn phần `saving` của (2) nếu chỉ có 1
 *    listener).
 * 3. `saveQuietly()` override riêng — `Model::saveQuietly()` gọi
 *    `static::withoutEvents(fn () => $this->save())`, tắt TOÀN BỘ sự kiện kể
 *    cả listener ở (2).
 * 4. `ImmutableAuditLogBuilder` — chặn mọi method sửa/xoá đi qua Eloquent
 *    Builder trực tiếp (`update`, `delete`, `increment`, `decrement`,
 *    `incrementEach`, `decrementEach`, `touch`, `upsert`, `forceDelete`,
 *    `truncate` — bypass cả (1), (2), (3)).
 *
 * KHÔNG chặn được `DB::table('audit_logs')->update()/delete()` (bỏ qua
 * Eloquent hoàn toàn) hay thao tác trực tiếp trên DB.
 * TODO(DBA, task sau — xem docs/security/review-T01-T02.md mục M5): giới hạn
 * quyền MySQL của user ứng dụng trên bảng `audit_logs` chỉ còn INSERT/SELECT
 * (chạy migration bằng user khác ở production), hoặc trigger
 * `BEFORE UPDATE/DELETE ... SIGNAL SQLSTATE '45000'`.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'ip',
        'user_agent',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException('AuditLog là bất biến — không được phép sửa (save() trên bản ghi đã tồn tại).');
            }
        });

        // M5 — increment()/decrement() trên instance (Model::incrementOrDecrement())
        // chỉ bắn 'updating', KHÔNG bắn 'saving' — cần listener riêng.
        static::updating(function (): void {
            throw new LogicException('AuditLog là bất biến — không được phép sửa (increment()/decrement()/update()).');
        });

        static::deleting(function (): void {
            throw new LogicException('AuditLog là bất biến — không được phép xoá (delete()).');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder  $query
     * @return ImmutableAuditLogBuilder<AuditLog>
     */
    public function newEloquentBuilder($query): ImmutableAuditLogBuilder
    {
        /** @var ImmutableAuditLogBuilder<AuditLog> $builder */
        $builder = new ImmutableAuditLogBuilder($query);

        return $builder;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('AuditLog là bất biến — không được phép update().');
    }

    public function delete(): ?bool
    {
        throw new LogicException('AuditLog là bất biến — không được phép delete().');
    }

    /**
     * M5 — `Model::saveQuietly()` chạy trong `static::withoutEvents(...)`, tắt
     * cả listener `saving`/`updating` ở `booted()` phía trên.
     *
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        throw new LogicException('AuditLog là bất biến — không được phép saveQuietly().');
    }
}
