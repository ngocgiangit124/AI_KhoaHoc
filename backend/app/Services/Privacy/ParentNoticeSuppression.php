<?php

namespace App\Services\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Danh sách địa chỉ phụ huynh đã huỷ nhận thông báo (theo HMAC có khoá). Chỉ thêm, đổi/xoá email trong tài khoản
 * học sinh KHÔNG gỡ khỏi danh sách.
 */
class ParentNoticeSuppression
{
    public function since(string $email): ?Carbon
    {
        $at = DB::table('parent_notice_suppressions')->where('email_hmac', ParentNoticeToken::addressHash($email))->value('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    public function isSuppressed(string $email): bool
    {
        return $this->since($email) !== null;
    }

    public function add(string $email): void
    {
        DB::table('parent_notice_suppressions')->insertOrIgnore([
            'email_hmac' => ParentNoticeToken::addressHash($email),
            'created_at' => now(),
        ]);
    }
}
