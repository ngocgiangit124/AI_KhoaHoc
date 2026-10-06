# SECURITY: Cụm 1 "Xác thực, phiên, tài khoản" (T04, T05, T27, T28, T33, Sửa lỗi nhỏ 2) | 2026-10-06
**Kết luận:** FAIL (1 High chưa sửa: H1). Phần còn lại đạt, hoặc chỉ có Medium/Low.

H1 không phải lỗi mới. Đây là mục **T04-1 trong backlog-v2, được nâng mức**. Backlog ghi "Hoãn v2 (trước T27)". Nay T27 đã xong mà T04-1 chưa sửa, nên từ một phiên bị chiếm đã đi được trọn chuỗi chiếm tài khoản vĩnh viễn (đã thử thật trên stack local). Theo quy tắc trên board, lỗi High phải báo PO ngay.

**Phạm vi:** code trên `main` (commit 2cf9de0) cộng các thay đổi chưa commit của dev đang sửa Low song song (`OtpService`, `PasswordService`, `StaffAccountService`). Đã đọc:
- route `routes/api.php`, `routes/admin.php`; `bootstrap/app.php`;
- middleware `ConfigureHostContext`, `EncryptCookies`, `EnsureAdminOrigin`, `EnsureRole`, `EnsureAccountActive`, `EnsureStaffMfaPassed`, `EnsurePasswordFresh`, `StaffIdleTimeout`, `EnforceSingleStudentSession`, `EnsureGuestStudent`, `AuthenticateSessionExceptEntryRoutes`;
- service `LoginService`, `AtomicCounter`, `StaffAuthService`, `StaffSession`, `StaffSessionRevoker`, `OtpService`, `OtpDispatcher`, `OtpMail`, `PasswordService`, `ContactService`, `StudentSessionService`, `StaffAccountService`, `AuditLogger`;
- model `AuditLog`; các FormRequest/Resource liên quan;
- config `session`, `sanctum`, `cors`, `auth`, `features`; `infra/production/.env.production.example`, `infra/production/mysql/grants.sql`.

Đối chiếu với: `review-T01-T02.md`, `review-T03.md`, `audit-2026-09-25.md`, `backlog-v2.md`, ADR-003, ADR-004.

**Cách kiểm:** phân tích tĩnh, kết hợp thử thật bằng curl vào `api.localhost:8000` và `admin-api.localhost:8000` (DB dev, Mailpit). Đã tạo các tài khoản test `sec-test-admin@`, `sec-test-quan_ly_trang@`, `sec-test-giao_vien@`, `sec-test-hs1@example.test` (id 752–754, 761) và **đã xoá sau khi xong**, kèm bộ đếm limiter của các tài khoản này. Bản ghi `audit_logs` của chúng vẫn còn vì bảng này bất biến.

## Các điểm đạt (đã kiểm, phần lớn bằng request thật)

**V2 Xác thực / chống dò, vượt giới hạn**
- Login học sinh và staff: 10 lượt sai trên một tài khoản (không tồn tại) thì lượt 11 nhận 429 sau khoảng 0,03 s, không so mật khẩu. Biến thể `séc-…` và `SEC-…` dùng chung bộ đếm (429). Đã đọc trực tiếp bằng tinker: `AtomicCounter::attempts` = 10 đúng bằng giá trị thô trong Redis, nghĩa là khoá của script Lua và khoá của `RateLimiter` là một (M2/M3 của T03 đã đóng).
- Timing: sai mật khẩu với tài khoản có thật và với tài khoản không tồn tại đều khoảng 0,31–0,36 s (6 mẫu xen kẽ). Hai nhánh cùng thông điệp và cùng mã 422.
- `reset` mật khẩu: mã sai cho tài khoản có thật và tài khoản không tồn tại đều trả `OTP_EXPIRED` cùng một thông điệp (T27-5 đã đóng). `forgot` luôn trả 202 với nội dung cố định.
- OTP: sinh bằng `random_int` (6 số), TTL 10 phút, 5 lượt mỗi mã. Đã thử: 5 lần sai → `TOO_MANY_ATTEMPTS`, sau đó mã đúng cũng bị từ chối. Mã đã dùng thì không dùng lại được (verify → "đã xác thực"; reset → `OTP_EXPIRED`). Mã của purpose này không dùng được cho purpose khác (lọc theo `purpose`). MFA kiểm thêm đích là email hiện tại.
- Brute force MFA: tối đa 5 lần cho mỗi mã, 10 mã/ngày, và phải đăng nhập lại bằng mật khẩu mới có mã mới. Như vậy tối đa khoảng 50 lần đoán/ngày trên không gian 10^6.

**V3 Phiên**
- Session fixation: cookie lấy trước khi đăng nhập không còn đăng nhập được sau khi login (401). Login học sinh có đổi CSRF token. MFA verify có regenerate.
- Tách phiên 2 host:
  - Cookie học sinh gửi tới admin-api, dù dưới tên `vv_admin_session`, đều nhận 401. Cookie admin gửi tới api, dù dưới tên `vv_session` hay `vv_admin_session`, cũng nhận 401: tiền tố tên cookie nằm trong giá trị đã mã hoá.
  - Route học sinh trên host admin và route admin trên host api đều 404. `X-Forwarded-Host` giả không đổi được host. `/sanctum/csrf-cookie` trả 404.
  - Đăng nhập chéo cổng trả `WRONG_PORTAL`, và chỉ trả khi mật khẩu đúng.
- Một phiên học sinh: phiên cũ nhận `SESSION_REPLACED`. Sau `reset` mật khẩu, phiên cũ nhận `SESSION_REVOKED`.
- Cookie admin: `HttpOnly; SameSite=Strict`, hết khi đóng trình duyệt. Host-only (`SESSION_DOMAIN=null`).

**CSRF / CORS**
- Thiếu `X-CSRF-TOKEN` → `CSRF_TOKEN_MISMATCH`. Origin lạ hoặc không có Origin thì api không mở phiên (401). admin-api bắt buộc Origin/Referer khớp `ADMIN_URL`.
- Preflight từ origin lạ chỉ nhận lại `Access-Control-Allow-Origin` cố định là FE, nên trình duyệt chặn.

**V4 Phân quyền**
- Phiên đang chờ MFA gọi `/admin/auth/me`, `/admin/staff`, `/admin/audit-logs`, `PUT /admin/auth/password` đều nhận 403 `MFA_REQUIRED`.
- Phiên đã qua MFA nhưng `must_change_password` thì mọi route nhóm staff trả `PASSWORD_CHANGE_REQUIRED`.
- Giáo viên gọi `/admin/staff`, `/admin/staff/{id}`, `/admin/audit-logs`, `/admin/coupons`, `PATCH /admin/staff/{self}/role`, `POST /admin/staff` đều nhận 403. Gate `manage-system` = chỉ admin, được kiểm trước khi tra id, nên không dò được id.
- IDOR `/admin/staff/{id}`: id của học sinh trả 404 với cả `show` lẫn `reset-password`. id không phải số trả 404.
- Khoá giáo viên: phiên đang mở nhận `ACCOUNT_LOCKED`. Sau khi mở khoá, phiên cũ vẫn chết (401) nhờ `StaffSessionRevoker`.
- Mass assignment: không có `$request->all()` hay `$guarded = []` trong phạm vi. `role/status/must_change_password` chỉ được ghi qua `forceFill` trong service.

**V7 Log / audit**
- Đã có audit cho: `staff.create`, `staff.login`, `staff.login_failed`, `staff.mfa_failed`, `staff.password_changed`, `staff.password_change_failed`, `user.lock/unlock`, `staff.role_change`, `staff.password_reset`, `account.password_reset/changed`, `account.contact_changed`, `account.verified`, `otp.send_limit_reached`. Đã kiểm thực tế chuỗi lock/unlock: có `actor_id`, `actor_role`, giá trị from/to.
- `AuditLogger` lọc các khoá chứa `password/otp/token/email/phone/code`.
- API audit chỉ có GET: `PUT` và `DELETE /admin/audit-logs` đều trả 405. Model chặn update/delete ở 4 lớp.
- Log: `laravel.log` không chứa email test, mã OTP hay mật khẩu. Lỗi gửi OTP đã che 6 chữ số và email (dev vừa sửa song song). `OtpMail` dùng `ShouldBeEncrypted`. 5xx không lộ trace khi `APP_DEBUG=false`.

## Phát hiện

### H1 [High] Từ một phiên bị chiếm, đổi được email mà không xác thực lại, rồi đặt lại mật khẩu qua email mới: chiếm tài khoản vĩnh viễn — OWASP A07 / ASVS V3.7.1, V2.5.x (nâng mức từ T04-1)
- Vị trí:
  - `backend/routes/api.php:133` (`PUT /auth/contact`, chỉ có `throttle:contact`);
  - `backend/app/Services/Auth/ContactService.php:29` (`update()` không yêu cầu `current_password`, không báo cho email cũ);
  - `backend/app/Services/Auth/PasswordService.php:46-53` và `:96-105`: `isEligible()` và `sendResetCode()` gửi mã reset tới email hiện tại **kể cả khi email đó chưa xác thực**.
- Mô tả & tác động: người có phiên học sinh trong tay (máy phòng tin học dùng chung, phiên trượt 7 ngày, hoặc XSS về sau) làm như sau:
  1. đổi email sang hộp thư của mình (200, không hỏi mật khẩu);
  2. gọi `forgot`, mã reset tới hộp thư mới;
  3. `reset` mật khẩu. `revoke()` đá luôn phiên của chủ tài khoản.

  Chủ tài khoản không nhận được thông báo nào và không còn cách tự khôi phục, vì email khôi phục đã bị đổi. Kẻ chiếm có toàn quyền với dữ liệu cá nhân của trẻ (SĐT, lớp, tiến độ) và khóa đã mua. Khi T04-1 được hoãn, chuỗi này chưa đi trọn vì T27 chưa có. Nay T27 đã chạy nên mức thật là High.
- Tái hiện (đã chạy thật với `sec-test-hs1`, bước 1 trả 200):
  ```bash
  # đã đăng nhập học sinh (cookie jar $J), lấy token: GET /api/v1/csrf-token với Origin http://api.localhost:3000
  curl -b $J -c $J -X PUT http://api.localhost:8000/api/v1/auth/contact \
    -H 'Origin: http://api.localhost:3000' -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "X-CSRF-TOKEN: $T" -d '{"email":"attacker@example.test"}'          # → 200 {"resend_available_at":...}
  # sau đó ở trạng thái khách: POST /auth/password/forgot {"login":"attacker@example.test","captcha_token":"…"} → mã tới hộp thư kẻ chiếm
  # POST /auth/password/reset {"login":"attacker@example.test","code":"<mã>","password":"…"} → 200, phiên chủ cũ → SESSION_REVOKED
  ```
- Cách sửa (làm cả 1 và 2; 3 nên làm):
  1. `UpdateContactRequest`: thêm `'current_password' => ['required', 'string', 'max:128']`. `ContactService::update` kiểm `Hash::check` trước mọi thay đổi, sai thì 422 `current_password` + audit `account.contact_change_failed`. Dùng chung limiter `password-change` (5/phút, 20/giờ) để không dò được mật khẩu bằng route này:
     ```php
     if (! Hash::check($data['current_password'], $user->password)) {
         $this->audit->log('account.contact_change_failed', $user, ['reason' => 'wrong_current_password']);
         throw ValidationException::withMessages(['current_password' => PasswordService::MESSAGE_WRONG_CURRENT]);
     }
     ```
     Luồng "sửa nhầm email ngay sau đăng ký" vẫn chạy vì người dùng vừa gõ mật khẩu. Nếu PO muốn bỏ bước này cho tài khoản **chưa xác thực**, chỉ được miễn khi `! $user->isVerified()`.
  2. Khi đổi email của tài khoản đã có email xác thực: gửi email thông báo tới **địa chỉ cũ** (đổi lúc nào, IP nào, kèm hướng dẫn liên hệ hỗ trợ). Huỷ các phiên khác (`StudentSessionService::revoke` + bind lại phiên hiện tại, như `change()`).
  3. Nên làm thêm: `sendResetCode` chỉ gửi tới email đã xác thực, khi tài khoản đã có ít nhất một kênh xác thực. Tài khoản chưa từng xác thực thì giữ hành vi hiện tại (đúng AC của US-015). Cập nhật api-contract §2.2 (`PUT /auth/contact` có `current_password`) và FE màn đổi liên hệ.
- Cách kiểm chứng (test `laravel-qa` nên thêm, `tests/Feature/T04/ContactReauthTest.php`):
  - `PUT /auth/contact` thiếu `current_password` → 422. Sai → 422, có audit, email không đổi. Đúng → 200.
  - 6 lần sai trong 1 phút → 429.
  - Đổi email của tài khoản đã xác thực → `Mail::assertQueued(ContactChangedMail)` tới địa chỉ cũ, và phiên thứ hai bị `SESSION_REVOKED`.
  - Nếu làm cách 3: tài khoản đã xác thực email A, đổi sang B (chưa xác thực), rồi `forgot` → không có mail tới B.

### M1 [Medium] Mật khẩu tài khoản staff chỉ yêu cầu 8 ký tự, không chặn mật khẩu phổ biến; giáo viên không có MFA — OWASP A07 / ASVS V2.1.1, V2.1.7
- Vị trí: `backend/app/Providers/AppServiceProvider.php:111` (`Password::min(8)` dùng cho mọi vai trò), `backend/app/Http/Requests/Admin/Auth/ChangeStaffPasswordRequest.php:22`.
- Mô tả & tác động: đã thử thật. Admin (sau MFA, ở bước buộc đổi mật khẩu) đặt được mật khẩu `12345678` và nhận 200. Mật khẩu ngẫu nhiên 20 ký tự do hệ thống cấp vì vậy chỉ có tác dụng tới lần đổi đầu tiên.
  - Admin và quản lý trang còn có MFA che chắn.
  - **Giáo viên không có MFA** (đúng US-016) nhưng sửa được nội dung khóa đang bán và thấy danh sách học sinh xin học. Với 10 lượt sai/giờ/tài khoản, kẻ thử chậm và phân tán bằng danh sách mật khẩu phổ biến (credential stuffing hoặc password spraying trên nhiều giáo viên) vẫn có xác suất trúng đáng kể.
  - Mục I3 của T03 chỉ nói về học sinh. Chưa có mục nào trong backlog nói về staff.
- Cách sửa: tách quy tắc cho staff (`StaffPassword::rules()`): `Password::min(12)->letters()->numbers()`, cộng với chặn danh sách mật khẩu phổ biến **cục bộ** (file khoảng 10k dòng trong `resources/`, không gọi HIBP ra ngoài; `uncompromised()` gọi ra ngoài thì cần PO duyệt). Thêm rule chặn mật khẩu chứa phần trước `@` của email. Áp cho `PUT /admin/auth/password`.
- Kiểm chứng: test đổi mật khẩu staff với `12345678`, `password123`, `Abcdefgh1` (dưới 12 ký tự) → 422. Mật khẩu 14 ký tự ngẫu nhiên → 200.

### L1 [Low] `FEATURE_STAFF_MFA` không được `ProductionConfigGuard` ép bật — ASVS V2.8 / A05
- Vị trí: `backend/config/features.php:25`, `backend/app/Support/ProductionConfigGuard.php:22` (không có `guardStaffMfa`).
- Mô tả: đặt nhầm `FEATURE_STAFF_MFA=false` (khi debug SMTP, hoặc copy từ `.env` local) là tắt MFA của toàn bộ admin và quản lý trang, mà không có cảnh báo nào. `EnsureStaffMfaPassed` cho qua.
- Cách sửa: guard ném lỗi khi `app()->isProduction() && ! config('features.staff_mfa')`. Staging cũng nên ép như vậy (cùng ý với L4 của T03).
- Kiểm chứng: test guard với `features.staff_mfa=false` và env production → ném `RuntimeException`.

### L2 [Low] `audit_logs` vẫn sửa/xoá được ở tầng DB: mẫu `grants.sql` cấp `UPDATE, DELETE` toàn schema cho `vv_app`, không có trigger — ASVS V7.3.3
- Vị trí: `infra/production/mysql/grants.sql:5-8`. Model `AuditLog` (TODO M5).
- Mô tả: ở tầng ứng dụng thì audit log bất biến (đã kiểm). Nhưng bất kỳ ai có SQL bằng user `vv_app` (lộ credential, SQLi sau này, RCE) đều sửa hoặc xoá được dấu vết. Board ghi "Quyền MySQL/trigger chặn sửa `audit_logs` (DBA, trước staging)", còn mẫu T31 lại cấp `UPDATE`, trong khi `audit:purge` chỉ cần `DELETE`. Hai chỗ này mâu thuẫn nhau.
- Cách sửa (DBA): thêm migration trigger `BEFORE UPDATE ON audit_logs … SIGNAL SQLSTATE '45000'`. Có hai cách cho `DELETE`:
  - (a) trigger `BEFORE DELETE` chỉ cho xoá dòng có `created_at < NOW() - INTERVAL 24 MONTH`;
  - (b) chạy `audit:purge` bằng user riêng `vv_ops` (chỉ có DELETE trên `audit_logs`) và thu hồi DELETE của `vv_app` trên bảng này.
- Kiểm chứng: test (MySQL thật) `DB::table('audit_logs')->update([...])` → `QueryException`. Xoá dòng mới tạo → lỗi. `audit:purge` vẫn xoá được dòng quá hạn.

### L3 [Low] Cookie phiên chưa dùng tiền tố `__Host-` — ASVS V3.4.4
- Vị trí: `infra/production/.env.production.example:39-40` (`vv_session`, `vv_admin_session`), `backend/config/session.php`.
- Mô tả: production đặt FE/API trên cùng site `vitaminvui.vn`. Nếu một subdomain cùng site bị chiếm (subdomain takeover, dịch vụ bên thứ ba gắn CNAME), kẻ đó có thể đặt cookie `Domain=.vitaminvui.vn` mang **phiên hợp lệ của chính hắn** vào trình duyệt nạn nhân. Đây là kiểu "login CSRF" bằng cookie tossing: học sinh làm bài hoặc nhập SĐT vào tài khoản của kẻ khác. Kẻ đó không giả mạo được cookie của người khác nhờ mã hoá và tiền tố.
- Cách sửa: production/staging đặt `SESSION_COOKIE=__Host-vv_session`, `SESSION_ADMIN_COOKIE=__Host-vv_admin_session`. Điều kiện đã có sẵn: Secure, `Path=/`, không có Domain. Local giữ tên cũ vì chạy http. Kiểm `ConfigureHostContext` không đặt `domain`.
- Kiểm chứng: `curl -I https://api…/api/v1/csrf-token` trên staging → `Set-Cookie: __Host-vv_session=…; secure; path=/`, không có `domain=`.

### Info
- **I1:** đăng nhập staff không đổi CSRF token. `StaffAuthService.php:102` chỉ gọi `Auth::login()`, mà hàm này `migrate` id nhưng giữ `_token`. Học sinh thì có `regenerate()` nên đổi token. Rủi ro thấp (cookie host-only, SameSite=Strict). Nên gọi `$request->session()->regenerateToken()` sau login staff cho đồng bộ. FE vốn luôn lấy lại token qua `/csrf-token`.
- **I2:** `staff.login_failed` với tài khoản không tồn tại có `subject` null và không lưu định danh đã thử, nên khó điều tra một đợt credential stuffing. Có thể lưu `login_hmac = hash_hmac('sha256', accountKey, APP_KEY)` (không phải PII rõ). Chưa audit `staff.mfa_resend`.
- **I3:** đã thấy thực tế mục T04-4 (`otp-send` đếm cả request 429) và T27-7 (sau khi gửi OTP xác thực, `forgot` trong 60 s bị nuốt im lặng dù trả 202). Mức giữ nguyên như backlog.
- **I4 (ngoài phạm vi, chuyển ops):** `laravel.log` ghi lỗi mỗi 5 phút `ops:health --log='1'`, kèm `The "--log" option does not accept a value`. Lệnh giám sát định kỳ đang không chạy (A09).
- **I5:** SANCTUM stateful gồm cả origin admin FE. Request credentialed từ `admin-api.localhost:3001` (production: `admin.vitaminvui.vn`) tới host api vẫn được mở phiên học sinh, nhưng CORS chỉ cho FE học sinh đọc response và vẫn cần CSRF token. Chấp nhận được. Có thể tách `SANCTUM_STATEFUL_DOMAINS` theo host trong `ConfigureHostContext` nếu muốn chặt hơn.

## Kết quả công cụ
- `composer audit`: không có advisory.
- Thử thật (curl + Mailpit + tinker chỉ đọc khoá limiter), tóm tắt ở mục "Các điểm đạt". Không chạy bộ test Pest (máy yếu; không có thay đổi code để kiểm hồi quy).
- Dọn dẹp: đã xoá 4 user test (`consents` của học sinh test xoá theo; `otp_codes`, `staff_devices` xoá theo cascade). Đã xoá bộ đếm `login-fail:u:*`, `staff-login-fail:u:*`, `staff-session-version:*` của các user này và `login-fail:a:sec-test-nobody@example.test`. Bộ đếm IP chung `login-fail-ip:*` có thêm khoảng 15 lượt sai từ IP Docker gateway, tự hết sau 1 giờ.

## Đánh giá lại theo yêu cầu
| Hạng mục | Trạng thái | Ghi chú |
|---|---|---|
| Brute force / vượt throttle | ✅ | Nguyên tử, khoá theo user id, ASCII-fold. M1 (khoá oan), L1 IPv6 vẫn ở backlog |
| Dò tài khoản (message/status/timing) | ✅ | Login, forgot, reset đồng nhất. MF2-R4 vẫn ở backlog |
| Session fixation | ✅ | Có regenerate. I1 (CSRF token staff) |
| Tách phiên api ↔ admin-api | ✅ | Đã thử đổi chéo tên và giá trị cookie |
| CSRF | ✅ | Có token, Origin, SameSite. L3 `__Host-` |
| Vượt MFA / bỏ bước buộc đổi mật khẩu | ✅ | L1 (cờ MFA không được guard ép) |
| Leo quyền giữa vai trò | ✅ | |
| IDOR `/admin/staff/{id}` | ✅ | |
| Audit log đủ / không sửa được | ⚠️ | Tầng app đạt. L2 (DB), I2 |
| OTP entropy / hạn / lượt / replay | ✅ | T04-2 (bcrypt) vẫn ở backlog |
| Token reset dùng lại | ✅ | |
| Mass assignment | ✅ | |
| Lộ thông tin trong lỗi/log | ✅ | |
| Thay đổi thông tin nhạy cảm cần xác thực lại | ❌ | **H1** |
| Chính sách mật khẩu | ⚠️ | M1 (staff) |

## Việc chuyển `laravel-dev`
1. **H1 (chặn):** `current_password` cho `PUT /auth/contact`, thông báo tới email cũ, huỷ phiên khác. Nên làm thêm cách 3 (reset chỉ gửi tới email đã xác thực). Cập nhật api-contract §2.2 và báo `nextjs-dev` sửa form đổi liên hệ.
2. **M1:** quy tắc mật khẩu staff (12 ký tự, chặn danh sách mật khẩu phổ biến cục bộ).
3. **L1:** thêm `guardStaffMfa()` vào `ProductionConfigGuard`.
4. **L2 (DBA):** trigger chặn UPDATE/DELETE `audit_logs` và tách quyền purge. Sửa `grants.sql`.
5. **L3 (T31):** đổi tên cookie sang `__Host-` trong mẫu env production/staging.
6. I1, I2 khi tiện tay.

## Test `laravel-qa` nên thêm
- H1: các case ở mục "Cách kiểm chứng" của H1. Thêm e2e: phiên học sinh trên máy A đổi email không có mật khẩu → 422.
- M1: mật khẩu staff yếu → 422.
- L1: guard production với `staff_mfa=false` → exception.
- L2: test DB thật, `UPDATE audit_logs` → lỗi.
- Hồi quy cho các điểm đạt, nếu chưa có: cookie `vv_session` gửi tới admin-api dưới tên `vv_admin_session` → 401; phiên chờ MFA gọi `/admin/staff` → 403 `MFA_REQUIRED`.

## Điểm cần pháp chế / PO quyết
- **H1:** có miễn `current_password` cho tài khoản chưa xác thực (sửa nhầm email ngay sau đăng ký) không. Có chặn gửi mã reset tới email chưa xác thực khi tài khoản đã có kênh xác thực không. Về pháp lý, tài khoản trẻ em bị chiếm (lộ SĐT, thông tin học tập) có thể thuộc diện "vi phạm dữ liệu cá nhân" phải xử lý và thông báo theo Luật BVDLCN 2025 / Nghị định 356/2025: **cần bộ phận pháp chế xác nhận**.
- **M1:** có dùng `uncompromised()` (gọi HIBP ra nước ngoài, chỉ gửi 5 ký tự đầu của SHA-1) hay chỉ dùng danh sách cục bộ. PO đã nói "chuyển dữ liệu ra nước ngoài thì không được", nên khuyến nghị dùng danh sách cục bộ.
