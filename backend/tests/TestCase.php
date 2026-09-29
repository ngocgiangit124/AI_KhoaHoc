<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * T05 (ADR-003) — `EnforceSingleStudentSession` (alias
     * `student.single_session`, gắn trên MỌI route nhóm `student` —
     * api-contract §1.3) so `users.current_session_id` với session id THẬT
     * của request hiện tại. `actingAs()` gốc của Laravel (`be()`) chỉ gán
     * thẳng user lên guard, KHÔNG đi qua session/cookie thật, nên "phiên"
     * giả lập đó không bao giờ khớp — mọi test có sẵn từ T03/T04/T10 dùng
     * `actingAs()` cho user vai trò `hoc_sinh` gọi vào route nhóm `student`
     * sẽ bị middleware coi là "phiên khác" (401 `SESSION_REPLACED`), dù
     * `StudentSessionService::bind()` chưa từng chạy.
     *
     * Sửa ở ĐÚNG 1 NƠI (thay vì rải rác sửa từng test file đã có): khi
     * `actingAs()` một `User` vai trò `hoc_sinh`, tự sinh 1 session id hợp
     * lệ, ghi thẳng vào `current_session_id` (`forceFill` — giống cách
     * `StudentSessionService` ghi, bỏ qua `$fillable`) rồi gắn cookie phiên
     * tương ứng bằng `withCookie()` (TỰ MÃ HOÁ giống trình duyệt thật, khớp
     * với những gì `App\Http\Middleware\EncryptCookies` sẽ giải mã lại khi
     * request thật sự chạy) — nhờ vậy `$request->session()->getId()` của
     * request kế tiếp TRÙNG giá trị này, qua được middleware như một phiên
     * hợp lệ bình thường.
     *
     * Test nào cố tình kiểm hành vi "1 thiết bị/1 phiên" (ADR-003 — T05) PHẢI
     * tự dựng qua luồng đăng nhập thật (`POST /auth/login` + cookie thật của
     * từng response), KHÔNG dùng `actingAs()` (xem `tests/Feature/T05/`).
     *
     * @param  string|null  $guard
     * @return $this
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        if ($user instanceof User && $user->role === UserRole::Student) {
            $sessionId = Str::random(40);
            $user->forceFill(['current_session_id' => $sessionId])->save();

            // `getJson()`/`postJson()` (dùng ở HẦU HẾT test hiện có) chỉ gửi
            // cookie khi `withCredentials()` đã bật (mặc định `false` —
            // `MakesHttpRequests::prepareCookiesForJsonRequest()`), khác hẳn
            // yêu cầu thật của SPA (`credentials: 'include'` — api-contract
            // §1.2). Bật kèm ở đây để không phải sửa từng test file cũ.
            $this->withCookie((string) config('session.cookie'), $sessionId)
                ->withCredentials();
        }

        return parent::actingAs($user, $guard);
    }
}
