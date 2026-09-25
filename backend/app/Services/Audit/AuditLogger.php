<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Ghi nhật ký thao tác nhạy cảm (data-model §3.1, S15). Gọi từ Service, KHÔNG
 * dùng Observer chung (dễ sót — ADR-004 §4).
 */
class AuditLogger
{
    /**
     * Danh sách khoá cấm — bị lọc khỏi `changes` dù nằm ở bất kỳ độ sâu nào
     * (không phân biệt hoa/thường, khớp theo tiền tố cho `parent_*`).
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'password',
        'code',
        'token',
        'email',
        'phone',
        'signature',
    ];

    /**
     * @param  array<string, mixed>  $changes  Trước/sau — đã loại PII/secret trước khi lưu.
     */
    public function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
    {
        $actor = Auth::user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_role' => $actor?->role?->value,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $this->sanitize($changes),
            'ip' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 255, ''),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $result;
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        if (str_starts_with($normalized, 'parent_')) {
            return true;
        }

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
