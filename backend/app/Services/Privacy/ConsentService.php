<?php

namespace App\Services\Privacy;

use App\Enums\ConsentType;
use App\Exceptions\DomainException;
use App\Models\Consent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\LockedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ghi bằng chứng đồng ý của CHÍNH học sinh (S7). ADR-006: không có đồng ý của phụ huynh.
 */
class ConsentService
{
    /** Số dòng tối đa trả về ở `GET /me/consents` (không phân trang). */
    public const LIST_LIMIT = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Mọi dòng đồng ý của học sinh, mới nhất trước (api-contract §2.8.3). Chỉ dòng của chính `$user`.
     *
     * @return Collection<int, Consent>
     */
    public function listFor(User $user): Collection
    {
        return Consent::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /** Cùng nghĩa với `needsPolicyAcceptance` (tên theo tasks.md T34.1). */
    public function needsAcceptance(User $user): bool
    {
        return $this->needsPolicyAcceptance($user);
    }

    /**
     * Học sinh chấp nhận lại điều khoản + chính sách ở phiên bản hiện hành (api-contract §2.8.3).
     * Khoá dòng `users` (X) rồi chỉ chèn loại CHƯA có đồng ý còn hiệu lực ở phiên bản hiện hành, nên double submit /
     * 2 request song song không tạo dòng trùng. Đã đủ thì không ghi gì (không audit).
     *
     * @throws DomainException CONSENT_VERSION_CHANGED 409 khi `$version` khác phiên bản hiện hành
     */
    public function acceptCurrent(User $user, string $version, Request $request): void
    {
        $current = (string) config('privacy.policy_version');

        if ($version !== $current) {
            throw new DomainException(
                'CONSENT_VERSION_CHANGED',
                'Điều khoản đã được cập nhật. Vui lòng tải lại để xem phiên bản mới nhất.',
                409,
                ['current_version' => $current],
            );
        }

        DB::transaction(function () use ($user, $current, $request): void {
            LockedUser::lockActive($user->getKey());

            $ip = $request->ip();
            $userAgent = mb_substr((string) $request->userAgent(), 0, 255);
            $inserted = 0;

            foreach ([ConsentType::Terms, ConsentType::PrivacyPolicy] as $type) {
                $exists = Consent::query()
                    ->where('user_id', $user->getKey())
                    ->where('type', $type->value)
                    ->where('policy_version', $current)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($exists) {
                    continue;
                }

                Consent::create([
                    'user_id' => $user->getKey(),
                    'type' => $type,
                    'policy_version' => $current,
                    'granted_by' => Consent::GRANTED_BY_SELF,
                    'channel' => Consent::CHANNEL_WEB_FORM,
                    'granted_at' => now(),
                    'ip' => $ip,
                    'user_agent' => $userAgent !== '' ? $userAgent : null,
                ]);
                $inserted++;
            }

            if ($inserted > 0) {
                $this->audit->log('privacy.policy_accepted', $user, ['policy_version' => $current]);
            }
        });
    }

    /**
     * `true` khi đồng ý `terms` HOẶC `privacy_policy` mới nhất (chưa thu hồi) của học sinh có `policy_version` khác phiên bản
     * hiện hành (api-contract §2.8.1). FE chỉ hiện banner, không chặn học hay mua. Loại chưa có đồng ý nào thì không tính.
     */
    public function needsPolicyAcceptance(User $user): bool
    {
        $current = (string) config('privacy.policy_version');

        $rows = Consent::query()
            ->where('user_id', $user->getKey())
            ->whereIn('type', [ConsentType::Terms->value, ConsentType::PrivacyPolicy->value])
            ->whereNull('revoked_at')
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get(['id', 'type', 'policy_version']);

        foreach ($rows->groupBy(fn (Consent $c) => $c->type->value) as $perType) {
            if ((string) $perType->first()->policy_version !== $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ghi đồng ý điều khoản + chính sách bảo mật tại thời điểm đăng ký.
     * Gọi trong cùng transaction tạo user.
     */
    public function recordSelfConsentAtRegistration(User $user, Request $request): void
    {
        $now = now();
        $ip = $request->ip();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);

        foreach ([ConsentType::Terms, ConsentType::PrivacyPolicy] as $type) {
            Consent::create([
                'user_id' => $user->id,
                'type' => $type,
                'policy_version' => (string) config('privacy.policy_version'),
                'granted_by' => Consent::GRANTED_BY_SELF,
                'channel' => Consent::CHANNEL_WEB_FORM,
                'granted_at' => $now,
                'ip' => $ip,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ]);
        }
    }
}
