<?php

namespace App\Services\Privacy;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\CurrentPasswordGuard;
use Illuminate\Support\Facades\DB;

/**
 * Sửa liên hệ phụ huynh của chính học sinh (api-contract §2.8.2, ADR-006).
 */
class ParentContactService
{
    public function __construct(
        private readonly CurrentPasswordGuard $currentPassword,
        private readonly AuditLogger $audit,
        private readonly ParentNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  `current_password` + (`parent_email` và/hoặc `parent_phone`; thiếu key = giữ,
     *                                           null = xoá). CHỈ `validated()` (đã chuẩn hoá).
     * @return User bản ghi sau cập nhật
     */
    public function update(User $user, array $validated): User
    {
        $this->currentPassword->assert($user, (string) $validated['current_password'], 'parent_contact.update_failed');

        return DB::transaction(function () use ($user, $validated): User {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $email = $this->diff($locked->parent_email, $validated, 'parent_email', true);
            $phone = $this->diff($locked->parent_phone, $validated, 'parent_phone', false);

            if ($email['status'] === 'unchanged' && $phone['status'] === 'unchanged') {
                return $locked;
            }

            if ($email['status'] !== 'unchanged') {
                $locked->parent_email = $email['value'];
            }

            if ($phone['status'] !== 'unchanged') {
                $locked->parent_phone = $phone['value'];
            }

            // Opt-out gắn với địa chỉ: sang địa chỉ khác (kể cả thêm lại sau khi xoá) thì hết hiệu lực.
            // Xoá email thì giữ nguyên dấu opt-out (không có địa chỉ nào để nhận thư).
            $emailReplaced = in_array($email['status'], ['added', 'changed'], true);

            if ($emailReplaced) {
                $locked->forceFill(['parent_notice_opt_out_at' => null]);
            }

            $locked->save();

            // Chỉ trạng thái, không giá trị (PII). Khoá nằm trong allowlist của AuditLogger.
            $this->audit->log('parent_contact.update', $locked, [
                'parent_email_change' => $email['status'],
                'parent_phone_change' => $phone['status'],
            ]);

            if ($emailReplaced) {
                DB::afterCommit(fn () => $this->notifier->contactAdded($locked));
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: 'added'|'changed'|'removed'|'unchanged', value: string|null}
     */
    private function diff(?string $current, array $validated, string $key, bool $caseInsensitive): array
    {
        if (! array_key_exists($key, $validated)) {
            return ['status' => 'unchanged', 'value' => $current];
        }

        $new = $validated[$key];
        $new = is_string($new) && $new !== '' ? $new : null;

        $normalize = static fn (?string $v): ?string => $v !== null && $caseInsensitive ? mb_strtolower($v) : $v;

        $old = $normalize($current !== '' ? $current : null);
        $new = $normalize($new);

        return match (true) {
            $old === $new => ['status' => 'unchanged', 'value' => $current],
            $new === null => ['status' => 'removed', 'value' => null],
            $old === null => ['status' => 'added', 'value' => $new],
            default => ['status' => 'changed', 'value' => $new],
        };
    }
}
