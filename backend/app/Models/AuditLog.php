<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Nhật ký thao tác nhạy cảm (data-model §3.1, S15) — CHỈ ghi thêm qua
 * `App\Services\Audit\AuditLogger`. Không có route/API sửa hoặc xoá.
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

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('AuditLog là bất biến — không được phép update().');
    }

    public function delete(): ?bool
    {
        throw new LogicException('AuditLog là bất biến — không được phép delete().');
    }
}
