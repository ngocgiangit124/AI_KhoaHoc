# REVIEW: T28 — Đăng nhập quản trị [SEC]
**Kết luận:** REQUEST CHANGES
**Phạm vi:** `git -C <worktree> diff 48bb4ba...claude/zen-dirac-fmucf7-t28` (commit `8bdd8b0`, nhánh `claude/zen-dirac-fmucf7-t28`, worktree `.claude/worktrees/t28`) · 29 file thay đổi (+1463/−22)

Đây là cổng review cuối trước khi gộp (PO đã tạm hoãn `laravel-security` theo task — xem `docs/board.md` 2026-09-29), nên đã soi kỹ phần bảo mật đăng nhập staff như một audit độc lập, không chỉ đối chiếu DoD.

## Tổng quan
Chất lượng code tổng thể tốt: `StaffAuthService` bám sát đúng pattern đã qua security của `LoginService` (T03) — cùng chống time-based account enumeration (dummy hash), cùng chuẩn hoá NFKC/PhoneNumber, cùng thứ tự kiểm tra (throttle → mật khẩu → khoá → vai trò) — và tách file riêng có lý do rõ ràng (tránh đụng worktree T05 đang chạy song song). MFA tái dùng đúng `OtpService` đã được hardening ở T04 (giới hạn gửi theo DB, không mở lại lỗ hổng throttle-bypass đã ghi trong agent memory dự án). Audit `staff.login*`/`staff.mfa_*`/`staff.password_changed` đầy đủ. Migration `staff_known_devices` có FK, unique index, `down()` sạch, dùng `lockForUpdate()` đúng chỗ cho race điều kiện ghi đồng thời. Tự chạy lại độc lập: Pint sạch, Larastan 0 lỗi, Pest 366 passed (khớp báo cáo bàn giao).

Tuy nhiên có **một lỗ hổng bảo mật thật** trong luồng MFA/đổi mật khẩu (R1) cần sửa trước khi gộp, và hai khoảng trống về test/kiến trúc-test (R2, R3) nên đóng trước khi coi T28 là xong, đúng như 2 điểm được yêu cầu soi kỹ trong đề bài.

## Phát hiện

### R1 [BLOCKER] `PUT /admin/auth/password` không đòi `staff.mfa_passed` → kẻ chỉ có đúng mật khẩu (chưa qua MFA) có thể đổi mật khẩu, vô hiệu hoá hoàn toàn tác dụng của MFA
- Vị trí: `backend/routes/admin.php` (route `admin.auth.password.update`, dòng khai báo middleware `['auth:sanctum', 'staff.session', 'account.active', 'staff.idle', 'no_store', 'role:admin,quan_ly_trang,giao_vien']`); `backend/app/Http/Controllers/Api/V1/Admin/Auth/PasswordController.php@update`.
- Vấn đề: Theo thiết kế (`api-contract` §2.5, ADR-004 §3 "Admin và Quản lý trang phải nhập OTP mỗi lần đăng nhập"), MFA là lớp bảo vệ thứ 2 **bắt buộc** cho Admin/QLT trước khi làm bất kỳ hành động nào trên host admin-api. Route đăng nhập (`LoginController::store`) đặt `staff_mfa_passed=false` trong session ngay sau khi mật khẩu đúng, và mọi route "nhóm staff" khác đều chặn bằng `staff.mfa_passed` (403 `MFA_REQUIRED`) cho đến khi qua `POST /admin/auth/mfa/verify`. Nhưng route `PUT /admin/auth/password` **cố tình không có** `staff.mfa_passed` trong middleware stack (comment giải thích: "Service tự kiểm `current_password` nên không mở thêm lỗ hổng nào khi cho phép gọi sớm"). Lập luận này chỉ đúng cho Giáo Viên (không có MFA) — với Admin/QLT thì sai: `PasswordController::update()` chỉ kiểm `current_password` đúng/sai, không kiểm `staff_mfa_passed`, nên bất kỳ ai **chỉ cần biết đúng mật khẩu hiện tại** của 1 tài khoản admin/QLT (ví dụ đánh cắp qua phishing, dò được, hoặc lộ ở nơi khác) có thể: đăng nhập → nhận `mfa_required: true` (chưa có/không cần mã OTP) → gọi thẳng `PUT /admin/auth/password` với `current_password` đúng → đổi mật khẩu tài khoản đó thành công **mà không cần vượt qua MFA**. `admin.origin` không chặn được kịch bản này vì đó chỉ là kiểm tra header `Origin`/`Referer` (`EnsureAdminOrigin::resolveOrigin()`), hoàn toàn giả mạo được bởi client không phải trình duyệt (curl/Postman/script) — nó chặn CSRF từ trình duyệt khác, không chặn kẻ tấn công có script và mật khẩu hợp lệ.
  - Hệ quả tối thiểu: DoS có chủ đích lên tài khoản admin thật (đổi mật khẩu mà chủ tài khoản không hay biết, phải tự khôi phục qua quên mật khẩu).
  - Hệ quả xấu hơn: nếu kẻ tấn công sau đó (qua kỹ nghệ xã hội khác, hoặc do người dùng dùng lại mật khẩu này ở nơi đã bị lộ email) có được luôn email/hộp thư của nạn nhân (nơi nhận mã MFA), họ hoàn toàn không cần biết mật khẩu CŨ nữa — chỉ cần mật khẩu MỚI do chính họ đặt — làm giảm hẳn giá trị của việc bắt buộc xác thực 2 yếu tố trước khi cho phép hành động nhạy cảm.
  - Lưu ý: route này **không sai** khi bỏ qua `staff.password_fresh` (đó đúng là lối thoát duy nhất khỏi `must_change_password` — hợp lý). Chỗ sai là bỏ luôn cả `staff.mfa_passed`, trong khi 2 middleware độc lập nhau và luồng hợp lệ (GV không MFA, hoặc Admin/QLT đã đăng nhập vào chờ đổi mật khẩu) **không cần** phụ thuộc vào việc bỏ qua middleware này: mọi route "staff" khác (kể cả các route mà người dùng cần gọi sau khi đổi mật khẩu) vẫn đòi MFA trước — nghĩa là yêu cầu MFA trước khi đổi mật khẩu không hề chặn đường đổi mật khẩu hợp lệ nào của Admin/QLT, nó chỉ đóng đúng lỗ hổng này.
- Đề xuất: thêm `staff.mfa_passed` vào middleware của `PUT /admin/auth/password`, giữ nguyên việc bỏ `staff.password_fresh`:
  ~~~php
  Route::put('/admin/auth/password', [PasswordController::class, 'update'])
      ->middleware([
          'auth:sanctum',
          'staff.session',
          'account.active',
          'staff.idle',
          'staff.mfa_passed', // thêm — đóng lỗ hổng: đổi mật khẩu KHÔNG được phép bỏ qua MFA
          'no_store',
          'role:admin,quan_ly_trang,giao_vien',
      ])
      ->name('admin.auth.password.update');
  ~~~
  Cập nhật đồng bộ:
  - `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php`: bỏ `admin.auth.password.update` khỏi `VV_ADMIN_MFA_OR_PASSWORD_ROUTE_NAMES` dùng chung cho cả 2 middleware, hoặc tách 2 allowlist riêng (1 cho exemption `staff.mfa_passed`, 1 cho exemption `staff.password_fresh`) — hiện constant này gộp chung nên route `mfa.verify` (đúng, cần miễn cả 2) và `password.update` (chỉ nên miễn `staff.password_fresh`) đang bị xử lý giống nhau.
  - `backend/tests/Feature/T28/StaffPasswordTest.php`: test `'goi duoc PUT /admin/auth/password ke ca khi dang bi must_change_password chan'` hiện dùng `actingAs()` (TestCase override tự set `staff_mfa_passed: true`) nên không thực sự phơi bày lỗ hổng này — sau khi sửa, thêm 1 test mới: Admin với `staff_mfa_passed: false` gọi `PUT /admin/auth/password` với `current_password` đúng → phải nhận 403 `MFA_REQUIRED`, KHÔNG được đổi mật khẩu.

### R2 [SHOULD] Chưa có test HTTP thật (2 phiên/2 cookie) chứng minh "đổi mật khẩu đăng xuất phiên khác" — đúng bullet test bắt buộc của T28 trong `tasks.md`
- Vị trí: `backend/tests/Feature/T28/StaffPasswordTest.php`; cơ chế cần kiểm: `Laravel\Sanctum\Http\Middleware\AuthenticateSession` (alias `staff.session`, `backend/bootstrap/app.php`).
- Vấn đề: `tasks.md` T28 liệt kê rõ trong mục Test: "đổi mật khẩu đăng xuất phiên khác" — đây chính là mục đích của việc thêm `staff.session`. Nhưng mọi test T28 dùng `actingAs()` (được `tests/TestCase.php` override để tự bơm sẵn 1 phiên staff hợp lệ) hoặc `withSession()` gán trực tiếp vào session store của tiến trình test — không có test nào dựng 2 **request HTTP thật** (2 cookie jar khác nhau, mô phỏng 2 thiết bị) để xác nhận: thiết bị B (đã đăng nhập trước) bị `AuthenticateSession` phát hiện hash mật khẩu cũ và tự đăng xuất (401) ở request tiếp theo sau khi thiết bị A đổi mật khẩu thành công. Tôi đã đọc `vendor/laravel/sanctum/src/Http/Middleware/AuthenticateSession.php` và xác nhận cơ chế (`password_hash_{guard}` lưu trong session, so bằng `hash_equals` ở mỗi request) khớp với thiết kế Dev mô tả trong docblock `PasswordController` — về mặt đọc code tôi tin cơ chế đúng — nhưng đây là kiểm soát bảo mật cốt lõi và là bullet test tường minh của DoD, nên cần 1 test HTTP end-to-end thật (không mock qua `actingAs`) để làm lưới chống hồi quy (đề phòng nâng cấp Sanctum/Laravel đổi hành vi nội bộ này, giống bài học đã ghi trong agent memory dự án ở T04 về audit-daily-limit).
- Đề xuất: thêm 1 test kiểu:
  ~~~php
  test('doi mat khau huy phien khac (2 thiet bi, HTTP that)', function () {
      $admin = User::factory()->admin()->create(['password' => Hash::make('matkhaucu123')]);

      // Thiet bi A: dang nhap that qua HTTP, giu cookie rieng.
      $deviceA = $this->postJson(vvAdminUrl('/admin/auth/login'), [...])->assertOk();
      // Thiet bi B: dang nhap that qua HTTP, giu cookie khac.
      $deviceB = ...; // tuong tu, cookie jar khac

      // (bo qua MFA neu can qua config(['features.staff_mfa' => false]) de don gian hoa test nay)

      // Thiet bi A doi mat khau.
      $this->withCookies($deviceA->headers->getCookies())->putJson(...)->assertOk();

      // Thiet bi B goi tiep 1 route staff bat ky -> phai bi 401 (session cu, AuthenticateSession phat hien hash cu).
      $this->withCookies($deviceB->headers->getCookies())->getJson(vvAdminUrl('/admin/auth/me'))->assertStatus(401);
  });
  ~~~
  (Chi tiết cú pháp lấy/gắn cookie giữa 2 `TestResponse` tuỳ API test client hiện có trong dự án — xem cách T03 xử lý case tương tự nếu đã có, nếu chưa thì đây sẽ là tiền lệ đầu tiên.)

### R3 [SHOULD] `staff.session` không nằm trong danh sách middleware bắt buộc của `RouteMiddlewareGroupsTest` → route admin tương lai (T08+) có thể thiếu nó mà không bị bắt
- Vị trí: `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php`, hàm test `'moi route auth:sanctum co du middleware chuan theo host'`, biến `$required` cho nhánh `admin_api_host` (dòng ~139): `['admin.origin', 'account.active', 'staff.idle', 'no_store']` (+ `staff.mfa_passed`/`staff.password_fresh` có điều kiện) — **thiếu `staff.session`**.
- Vấn đề: `staff.session` (Sanctum `AuthenticateSession`) là cơ chế duy nhất thực thi "đổi mật khẩu huỷ phiên khác" (DoD T28) và hiện được gắn thủ công trên từng route/group trong `routes/admin.php`. Vì test kiến trúc S19 không kiểm alias này, một route admin thêm ở T08+ (khoá học/chương/bài...) mà lỡ quên `staff.session` sẽ không bị lưới an toàn nào bắt — khác với các middleware còn lại của nhóm `staff`, vốn được `api-contract` §1.3 liệt kê đầy đủ và được test này bảo vệ.
- Đề xuất: thêm `'staff.session'` vào mảng `$required` cho nhánh admin-api trong test trên (cùng chỗ với `account.active`, `staff.idle`, `no_store`), và cân nhắc thêm luôn vào bảng middleware nhóm `staff` ở `api-contract.md` §1.3 (hiện bảng liệt kê `+ Sanctum AuthenticateSession` ở cuối dòng nhưng không phải dạng alias tường minh như các middleware khác — dễ bị bỏ sót khi task sau copy middleware stack).

## Đối chiếu acceptance criteria / DoD (tasks.md T28)

| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| Host admin-api: login chỉ staff/GV, `EnsureAdminOrigin` | Có | `StaffAuthService`, `EnsureAdminOrigin` (đã tồn tại từ T01/T02) |
| MFA email cho admin/QLT, `staff.mfa_passed`, flag `FEATURE_STAFF_MFA` | Có, nhưng có lỗ hổng bỏ qua MFA ở 1 route | Xem R1 |
| Email cảnh báo thiết bị mới cho GV | Có | `StaffDeviceService`, test đủ 3 kịch bản (lần đầu/thiết bị mới/thiết bị cũ) |
| `staff.idle` (120 phút / 12 giờ) | Có, test đủ 2 ngưỡng + case thiếu dữ liệu phiên | — |
| `staff.password_fresh` (`must_change_password`), `PUT /admin/auth/password` | Có | Test đủ, nhưng thiếu case MFA (R1) |
| Sanctum `AuthenticateSession` cho host admin-api | Có (`staff.session`) | Thiếu test HTTP 2 phiên thật (R2), thiếu lưới kiến trúc test (R3) |
| Audit `staff.login*` | Có | `staff.login`, `staff.login_failed`, `staff.mfa_failed`, `staff.mfa_verified`, `staff.password_changed`, `staff.logout` — đều có test kiểm tồn tại bản ghi |
| Test: admin-api Origin sai → 403 | Có | `'dang nhap admin thieu Origin dung tra 403 ORIGIN_NOT_ALLOWED'` |
| Test: HS đăng nhập admin → `WRONG_PORTAL` | Có | `'hoc sinh dang nhap dung mat khau o host admin tra 403 WRONG_PORTAL'` |
| Test: idle quá hạn → 401 `STAFF_IDLE_TIMEOUT` | Có | `StaffIdleTimeoutTest` |
| Test: đổi mật khẩu đăng xuất phiên khác | Thiếu (chỉ đọc code, không có test) | R2 |

## Đối chiếu giả định Dev tự khai
1. Thêm `account.active`/`staff.idle`/`no_store`/`role` cho mfa/verify và PUT password dù contract liệt kê ngắn — **hợp lý**, khớp đúng test kiến trúc T02 (S19), không phải bịa thêm.
2. Hình dạng response `mfa/verify` (UserResource, không field lạ), `password` (UserResource), `/admin/auth/me` (`StaffMeResource` với `permissions.manage_system` dựa trên Gate thật `manage-system` đã có từ T02) — **không bịa field**: đúng quy ước dự án (chỉ thêm field khi có cơ chế thật đứng sau, ghi rõ lý do trong docblock).
3. `current_password`/`password`/`password_confirmation` — khớp đúng mẫu `PUT /auth/password` học sinh trong api-contract §2.2, hợp lý.
4. Staff login bằng SĐT — hợp lý, cùng cơ chế `login` (email/SĐT) như học sinh, contract không cấm.
5. `PUT /admin/auth/password` yêu cầu `staff.mfa_passed` — **KHÔNG đúng, đây chính là R1**: code hiện KHÔNG yêu cầu, và đó là lỗ hổng.
6. `staff_login_at` tính từ khi mật khẩu đúng (trước MFA) — hợp lý và đúng ý đồ (không cho trì hoãn MFA để kéo dài hạn phiên 12h).
7. Không đụng L2 (URL trang quản trị đăng nhập nhầm cổng) — xác nhận đúng, diff không đụng tới `LoginController`/`ContactService` phía học sinh cho phần này (L2 vẫn là việc mở ở T28 nói chung theo board.md, nhưng không thấy code nào trong diff này cố xử lý nó — cần xác nhận với Architect/FA1 xem có bị bỏ sót phạm vi hay chủ đích để dành cho phần sau, không chặn merge phần đã làm).

## Gợi ý cho QA
- Ưu tiên kiểm chứng R1 bằng tay: đăng nhập admin thật (không tắt MFA), KHÔNG nhập mã OTP, gọi thẳng `PUT /admin/auth/password` với `current_password` đúng — hiện tại (trước khi sửa) sẽ đổi được mật khẩu; sau khi Dev sửa phải nhận 403 `MFA_REQUIRED`.
- Kiểm bằng 2 trình duyệt/2 profile thật (không chỉ Postman) case "đổi mật khẩu ở thiết bị A, thiết bị B bị đăng xuất ở request tiếp theo" — đúng cơ chế R2 mô tả, để chắc chắn hành vi qua cookie thật (không chỉ qua test giả lập session).
- Kiểm `staff.idle` với đồng hồ thật (không chỉ giả lập timestamp trong session) ít nhất 1 lần thủ công, vì middleware đọc `staff_login_at`/`staff_last_activity` — đảm bảo `ConfigureHostContext`/cấu hình cookie session thật không có lệch múi giờ.
- Kiểm luồng GV (không MFA) đăng nhập từ thiết bị mới thật (không giả lập `X-Device-Id`) để xác nhận email cảnh báo tới đúng hộp thư GV, không lộ trong log (S21 — kiểm log không chứa mã OTP/mật khẩu, đã có `dontFlash` từ T01 nhưng đáng xác nhận lại với luồng mới).
