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
     * Khoá được PHÉP đi qua dù khớp quy tắc lọc bên dưới (kiểm TRƯỚC mọi quy
     * tắc khác) — N1 (review bảo mật T01/T02, hồi quy từ L4): `coupon_code`/
     * `referral_code_used` là dữ liệu nghiệp vụ hợp lệ cần audit, không phải
     * secret.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'coupon_code',
        'referral_code_used',
    ];

    /**
     * Khoá bị lọc khi khớp CHÍNH XÁC (không phân biệt hoa/thường) — dùng cho
     * các trường không mong đợi biến thể ghép tên khác (`address`,
     * `date_of_birth`, `cccd`, `signature`, `code` — mã OTP người dùng nhập).
     *
     * @var list<string>
     */
    private const FORBIDDEN_EXACT_KEYS = [
        'signature',
        'address',
        'date_of_birth',
        'cccd',
        'code',
    ];

    /**
     * Hậu tố bị lọc — N1: thêm `_code` (vd. `verification_code`, `reset_code`)
     * nhưng KHÔNG áp cho khoá nằm trong `ALLOWED_KEYS` (kiểm trước).
     *
     * @var list<string>
     */
    private const FORBIDDEN_SUFFIXES = ['_key', '_code'];

    /**
     * Khoá CHỨA các chuỗi này bị lọc (không phân biệt hoa/thường) — N1 (hồi
     * quy từ L4): `password`/`secret`/`otp`/`token` được ĐƯA VỀ so khớp
     * "chứa chuỗi" (không còn khớp chính xác) vì tên trường ghép rất phổ biến
     * (`new_password`, `current_password`, `password_confirmation`,
     * `otp_code`, `client_secret_value`, `access_token`...) — khớp chính xác
     * làm lọt các biến thể này. Thà lọc nhầm còn hơn lộ PII/secret; nhóm
     * `email`/`phone` giữ nguyên từ trước.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SUBSTRINGS = ['email', 'phone', 'password', 'secret', 'otp', 'token'];

    /**
     * @param  array<string, mixed>  $changes  Trước/sau — đã loại PII/secret trước khi lưu.
     */
    public function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
    {
        $actor = Auth::user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            // L4 — phân biệt thao tác chạy từ CLI (staff:create/lock/unlock...)
            // với thao tác qua HTTP khi không có actor đăng nhập (audit_logs
            // chưa có cột actor_type riêng — ghi tạm vào actor_role để không
            // cần thêm migration ở review này).
            'actor_role' => $actor?->role->value ?? (app()->runningInConsole() ? 'cli' : null),
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

        if (in_array($normalized, self::ALLOWED_KEYS, true)) {
            return false;
        }

        if (str_starts_with($normalized, 'parent_')) {
            return true;
        }

        if (in_array($normalized, self::FORBIDDEN_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
