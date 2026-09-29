<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * T28 — `staff.idle`/`staff.mfa_passed` không còn là pass-through (trước
     * T28, mọi test admin dùng `actingAs()` mà không cần biết gì về session).
     * Tự đặt sẵn 1 phiên staff "hợp lệ" (không idle, đã qua MFA) mỗi khi
     * `actingAs()` được gọi, để các test có TỪ TRƯỚC T28 (vd
     * `tests/Feature/T06`, kể cả test cố ý dùng tài khoản học sinh để kiểm
     * `role:...` từ chối) không phải sửa từng dòng — các middleware này
     * không nằm trong nhóm route học sinh (host api) nên vô hại với test
     * không đụng tới host admin-api.
     *
     * Test nào cần mô phỏng trạng thái khác (chưa qua MFA, đã hết hạn, THIẾU
     * hẳn dữ liệu phiên...) tự gọi `flushSession()` rồi `withSession()` NGAY
     * SAU `actingAs()` — chạy sau nên ghi đè đúng trạng thái muốn kiểm (xem
     * `tests/Feature/T28/*`).
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User) {
            $this->withSession([
                'staff_login_at' => now()->timestamp,
                'staff_last_activity' => now()->timestamp,
                'staff_mfa_passed' => true,
            ]);
        }

        return parent::actingAs($user, $guard);
    }
}
