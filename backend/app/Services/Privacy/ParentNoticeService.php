<?php

namespace App\Services\Privacy;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phụ huynh huỷ nhận thông báo (api-contract §2.8.2). Token sai/lỗi thời/đã ẩn danh/đã huỷ: không làm gì, không lỗi.
 */
class ParentNoticeService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ParentNoticeSuppression $suppression,
    ) {}

    public function optOut(string $token): void
    {
        $user = ParentNoticeToken::verify($token);

        if ($user === null) {
            return;
        }

        DB::transaction(function () use ($user, $token): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            // Kiểm lại dưới khoá: email phụ huynh có thể vừa đổi.
            if ($locked === null || ParentNoticeToken::verify($token)?->getKey() !== $locked->getKey()) {
                return;
            }

            // Danh sách chặn theo địa chỉ (idempotent): sống sót khi học sinh xoá/thêm lại email.
            $this->suppression->add((string) $locked->parent_email);

            if ($locked->parent_notice_opt_out_at !== null) {
                return;
            }

            $locked->forceFill(['parent_notice_opt_out_at' => now()])->save();
            $this->audit->logAsSystem('parent_notice.opt_out', $locked);
        });
    }
}
