# SECURITY: T03 [SEC] (Đăng ký/đăng nhập học sinh, backend) + FW1 phần 1 (apps/web: Đăng ký/Đăng nhập/Đăng xuất) | 2026-09-28

**Phạm vi:** `git diff 7f03516 HEAD -- backend frontend` trên nhánh `claude/zen-dirac-fmucf7` (commit `f2781ec`, `623504a`, `10c1b82`, `56f72ba` (R7), `de2fa75` (mock test FE)). Backend: `LoginService`, `RegistrationService`, `PhoneNumber`, `RegisterRequest`/`LoginRequest`, `LoginController`/`RegisterController`/`MeController`, `EnsureGuest`, `UserResource`/`MeResource`, `Consent` + migration + `ConsentService`, `CaptchaVerifier`/`FakeCaptchaVerifier`/`TurnstileVerifier`, `AppServiceProvider`, `ApiExceptionRenderer`, `routes/api.php`, test T03 và test kiến trúc T02. Frontend: `app/dang-ky/*`, `app/dang-nhap/*`, `lib/auth/*`, `components/SiteHeader.tsx`, `proxy.ts` (CSP), `packages/api-client/src/authFetch.ts`, `packages/ui/src/TurnstileWidget.tsx`.
**Chuẩn đối chiếu:** api-contract §1.2, §1.3, §1.6, §1.7, §2.2; tasks.md T03; ADR-003, ADR-004; `docs/security/audit-2026-09-25.md` (S7, S10, S17, S19, S20, S21, S23); `docs/security/review-T01-T02.md` (M4, N1, mục "Việc cần làm trước T03"); `docs/qa/review-T03-FW1.md` (R1–R7).

**Kết luận:** **PASS có điều kiện**. Không có Critical/High. Có **4 phát hiện Medium** (M1–M4), mỗi cái sửa trong vài dòng. Ba trong số đó (M1, M2, M4) làm mất tác dụng đúng những biện pháp chống dò tài khoản / chống brute-force mà T03 được giao (S10, S20). Điều kiện:
1. Sửa M1, M2, M3 kèm test **trước khi đánh dấu T03 xong** trên board. Muộn nhất là trước khi bắt đầu T04/T27, vì 2 task này dùng lại cùng mẫu throttle theo định danh và captcha.
2. Sửa M4, hoặc PO chấp nhận rủi ro bằng văn bản (ghi vào `docs/board.md`), trước staging.
3. Các mục Low đưa vào backlog có chủ và hạn (T05/T28/T31 như ghi ở từng mục).

Nền tảng làm tốt (đã kiểm bằng test/request thật, không chỉ đọc code):
- **S17:** chỉ dùng `validated()`, `$fillable` chặt, `forceCreate` với danh sách thuộc tính tường minh. `role=admin` trong body bị bỏ qua (có test).
- **S20 và thứ tự trả lỗi đăng nhập đúng:** throttle, rồi sai thông tin (422, thông điệp chung), rồi `ACCOUNT_LOCKED` (chỉ khi mật khẩu đúng), rồi `WRONG_PORTAL`. Tài khoản bị khoá mà nhập sai mật khẩu vẫn trả 422 chung.
- **CSRF:** register, login và logout đều bị chặn 419 khi thiếu `X-CSRF-TOKEN`. Đã xác minh bằng test tạm có bật lại `ValidateCsrfToken`, vì Laravel bỏ qua CSRF khi chạy unit test.
- **Phiên:**
  - Login/register gọi `regenerate()`: session id đổi, CSRF token đổi.
  - Logout gọi `logout()`, `invalidate()` và `regenerateToken()`: token đổi (đã xác minh).
- **Captcha:**
  - `TurnstileVerifier` gửi secret, `remoteip` (IP thật qua TrustProxies) và `timeout(5)`.
  - Fail-closed khi secret rỗng, HTTP lỗi hoặc `success != true`.
  - Lỗi mạng ném exception trước transaction nên không tạo được tài khoản (500, vẫn là fail-closed).
- **Unique race:** bắt `23000` và trả 422 đúng field. Không lộ 500.
- **`/auth/me`:**
  - Có đủ nhóm `student` + `role:hoc_sinh` + `no_store`. Test kiến trúc S19 cho host api đã bổ sung, có test âm.
  - `parent_*` chỉ trả bản đã che; trong DB là ciphertext (`encrypted`).
  - `UserResource` không có `parent_*`.
- **Consents:** ghi IP (qua TrustProxies), UA (cắt 255), `policy_version` từ config, `granted_by`, `channel`. Không có route sửa/xoá.
- **Log:** `dontFlash` có `password`, `password_confirmation`, `captcha_token`, `parent_*`. Code mới không ghi log tự do. N1 (AuditLogger) đã sửa: lọc chuỗi con `password`/`secret`/`otp`/`token`, lọc hậu tố `_code` có allowlist.
- **Frontend:**
  - CSP chỉ thêm `https://challenges.cloudflare.com` vào `script-src`, `connect-src` và `frame-src`. **Không** thêm `unsafe-inline`/`unsafe-eval` cho script (`unsafe-eval` chỉ có ở dev, như trước). Script Turnstile được gắn nonce.
  - `?next=` đi qua `safeRedirect` (chặn `//`, `/\`, ký tự điều khiển, URL tuyệt đối).
  - Không lưu token/PII vào `localStorage` (chỉ có `device_id` UUID, đúng ADR-003).
  - Thông điệp lỗi của server được render dạng text của React, không dùng `dangerouslySetInnerHTML` nên không có XSS.
  - `suppressAuthEvents` chỉ tắt sự kiện UI toàn cục, không bỏ qua kiểm tra nào ở server.

## Môi trường & công cụ

| Mục | Kết quả |
|---|---|
| Môi trường | PHP 8.4 CLI (cloud), MySQL 8.0.46 (cloud, không phải 8.4). Collation `utf8mb4_0900_ai_ci` giống cấu hình dự án |
| `vendor/bin/pest` | **164 passed (518 assertions)** |
| `pnpm -r run test` | api-client 35, ui 24, admin 5, web 38: tất cả pass |
| `composer audit` | No security vulnerability advisories found |
| `pnpm audit --prod` | No known vulnerabilities found |
| PHPStan/Larastan | Không chạy được (không có trong `vendor`, mạng chặn cài). Không lách |
| Git | Không có `.env` nào được track ngoài `*.env.example` |

**Test tạm để xác minh** (`backend/tests/Feature/TmpSec/`, **đã xoá**, working tree sạch):

| Kiểm tra | Kết quả |
|---|---|
| Thời gian `POST /auth/login`: tài khoản tồn tại (hash cost 12) + sai mật khẩu, so với tài khoản không tồn tại (6 lần mỗi loại) | **209–247 ms** so với **3–4 ms**. Lộ tài khoản qua thời gian phản hồi (M1) |
| 10 lần sai với `victim@example.com`, lần 11 | 429 (đúng) |
| Ngay sau đó dùng biến thể có dấu `victím@…` | 429 (khoá throttle của Laravel tự bỏ dấu qua `htmlentities`) |
| Ngay sau đó dùng biến thể full-width `ｖictim@…` | **422** (không bị throttle). 15 biến thể full-width liên tiếp đều 422 (M2) |
| Đăng nhập **đúng mật khẩu** bằng `ｖｉｃｔｉｍ@example.com` / `０９１２３４５６７９` | **200**: MySQL `_ai_ci` coi là cùng tài khoản (M2) |
| `POST /auth/register` chỉ gồm `{email, phone}` đã tồn tại, `captcha_token` = token sai | **422** kèm `errors.email = "Email đã được sử dụng."`, `errors.phone = "Số điện thoại đã được sử dụng."`. Captcha không được kiểm (M4) |
| `captcha.driver` = `Turnstile` / `TURNSTILE` / `turnstile ` / `''` / `none` | Container bind **`FakeCaptchaVerifier`**, `ProductionConfigGuard::guardCaptcha()` **không ném** (M3) |
| CSRF (bật lại `ValidateCsrfToken`): login/register/logout không token | 419 / 419 / 419 |
| Login có token | 200, session id đổi, CSRF token đổi |
| Logout có token | 204, CSRF token đổi |
| `POST /auth/login` **không có Origin** (hoặc Origin lạ), đúng mật khẩu | **500** `Session store not set on request` (L1) |
| `POST /auth/login` không có Origin, sai mật khẩu | 422 |
| `POST /auth/register` không Origin, hợp lệ | **500**, nhưng tài khoản + consents **đã được tạo** (L1) |
| Đăng ký với mật khẩu 100 ký tự | 201. Bcrypt chỉ dùng 72 byte đầu (Info) |

---

## Phát hiện

### M1 [Medium] Dummy hash không bao giờ được dùng: lộ tài khoản qua thời gian phản hồi đăng nhập (~220 ms so với ~4 ms) — OWASP A07 (S20, BR5)
- **Vị trí:** `backend/app/Services/Auth/LoginService.php:72-74`
  ```php
  $hashToCheck = $user !== null ? $user->password : self::DUMMY_HASH;
  if ($user === null || ! Hash::check($password, $hashToCheck)) {
  ```
- **Mô tả & tác động:**
  - `$user === null` đúng thì `||` bị ngắt mạch, nên `Hash::check()` **không chạy** khi tài khoản không tồn tại. `DUMMY_HASH` (hợp lệ, cost 12, `password_verify` mất ~200 ms) chỉ được gán rồi bỏ đi. Comment ở dòng 36-41 mô tả một biện pháp không có hiệu lực.
  - Đo thật: tài khoản tồn tại mất 209–247 ms, không tồn tại mất 3–4 ms. Chênh ~50 lần, phân biệt được chỉ bằng 1 request, kể cả qua Internet.
  - Kẻ tấn công dò được email/SĐT của học sinh (trẻ vị thành niên) có đăng ký hay không. Mỗi định danh chỉ cần 1 request nên throttle theo tài khoản không giúp được gì. Chỉ còn lớp IP 50/giờ.
  - Thông điệp và status đã chung (đạt BR5), nhưng thời gian thì không.
- **Cách sửa:**
  ```php
  $passwordOk = Hash::check($password, $user?->password ?? self::dummyHash());

  if ($user === null || ! $passwordOk) {
      RateLimiter::hit($throttleKey, self::ACCOUNT_DECAY_SECONDS);
      throw $this->genericFailure();
  }

  // Dummy hash cùng cost với cấu hình thật (BCRYPT_ROUNDS có thể khác 12 ở production).
  private static function dummyHash(): string
  {
      static $hash;
      return $hash ??= Hash::make(Str::random(32));
  }
  ```
  Hoặc giữ hằng số, nhưng thêm test khẳng định cost của hằng số bằng `config('hashing.bcrypt.rounds')` khi chạy production. Lưu ý: T28 (đăng nhập staff) và T27 (quên mật khẩu) phải dùng cùng cách.
- **Kiểm chứng:** test Pest `Hash::spy()` (hoặc `Hash::partialMock()`), gọi login với định danh không tồn tại, rồi `Hash::shouldHaveReceived('check')->once()`. Không nên test bằng đo thời gian vì dễ chập chờn.

### M2 [Medium] Throttle "10 lần sai/giờ/tài khoản" vẫn lách được, không giới hạn số lần, bằng ký tự Unicode tương đương theo collation MySQL — OWASP A07 (S10, hồi quy phần còn lại của R7)
- **Vị trí:**
  - `backend/app/Services/Auth/LoginService.php:60` (khoá throttle), `:117-127` (`findByLogin`), `:139-152` (`normalizeIdentity`).
  - `backend/config/database.php` collation `utf8mb4_0900_ai_ci`.
  - `backend/app/Services/Auth/PhoneNumber.php:42`: `\d` không có cờ `/u`, nên chữ số full-width không được chuẩn hoá.
- **Mô tả & tác động:**
  - R7 đã chuẩn hoá `0…/84…/+84…` và hoa/thường. Nhưng tra DB dùng collation *accent- và case-insensitive*, coi `ｖ` (U+FF56) = `v`, `０` = `0`…, trong khi khoá throttle dùng chuỗi đã `mb_strtolower`.
  - Mỗi ký tự của định danh có thể viết ở dạng ASCII hoặc full-width, nên có tới 2^n khoá throttle khác nhau cho **cùng một** tài khoản. Đã xác minh: 15 biến thể liên tiếp đều 422, không có 429. Đăng nhập đúng bằng biến thể full-width trả 200.
  - Hệ quả: lớp theo tài khoản (mục tiêu chính của S10: chặn credential stuffing phân tán nhiều IP) mất tác dụng. Chỉ còn 50/giờ/IP, và với botnet thì không có trần nào cho mỗi tài khoản.
  - Biến thể có dấu (`é`) tình cờ bị gộp nhờ `RateLimiter::cleanRateLimiterKey()`. Các tương đương khác của collation (full-width, `ß`/`ss`, ligature…) thì không.
- **Cách sửa (chọn 1, khuyến nghị cách a):**
  - (a) Chỉ chấp nhận ASCII in được cho `login`, sau khi chuẩn hoá NFKC:
    ```php
    // LoginService::normalizeIdentity()
    $s = \Normalizer::normalize(trim($login), \Normalizer::FORM_KC) ?: trim($login);
    $s = mb_strtolower($s);
    // findByLogin(): nếu preg_match('/[^\x21-\x7E]/', $s) → return null (không tra DB)
    ```
    NFKC biến `ｖ` thành `v` và `０` thành `0`, nên khoá throttle trùng với bản ASCII. Chuỗi còn ký tự ngoài ASCII thì không tra DB (email/SĐT hợp lệ của dự án đều là ASCII). Ở `RegisterRequest`, thêm rule chỉ nhận email ASCII để hai phía nhất quán.
  - (b) So sánh nhị phân khi tra: `where('email', $normalized)` trên cột có collation `utf8mb4_bin`/`_as_cs` (migration đổi collation cột, DBA duyệt). Không dùng `whereRaw` nối chuỗi.
  - **Không** khoá throttle theo `user_id` khi tìm thấy user. Làm vậy tạo ra oracle dò tài khoản qua hành vi 429.
- **Kiểm chứng:** mở rộng `ThrottleTest.php`: 10 lần sai với `victim@example.com`, rồi lần 11 với `ｖictim@example.com` / `ＶＩＣＴＩＭ@example.com` phải trả 429. Đăng nhập bằng `０９１２…` phải cùng khoá với `0912…` (hoặc bị coi là không tồn tại, tuỳ phương án).

### M3 [Medium] Captcha "fail-open" ở production khi `CAPTCHA_DRIVER` sai chính tả hoặc viết hoa: guard kiểu blocklist, binding mặc định là Fake — OWASP A05/A04 (M4 của review T01/T02 chưa đóng hẳn)
- **Vị trí:** `backend/app/Providers/AppServiceProvider.php:29-34`
  ```php
  return match (config('captcha.driver')) {
      'turnstile' => new TurnstileVerifier(...),
      default => new FakeCaptchaVerifier,   // ← mọi giá trị khác 'turnstile'
  };
  ```
  và `backend/app/Support/ProductionConfigGuard.php:44-53` (chỉ chặn đúng chuỗi `fake`).
- **Mô tả & tác động:**
  - Đã xác minh: `CAPTCHA_DRIVER=Turnstile`, `TURNSTILE`, `turnstile ` (có khoảng trắng), chuỗi rỗng hoặc `none` đều qua được guard (guard lowercase rồi so với `fake`). Nhưng `match` phân biệt hoa/thường, nên container bind `FakeCaptchaVerifier`, **chấp nhận mọi token không rỗng**.
  - Frontend cũng tự gửi `LOCAL_FAKE_CAPTCHA_TOKEN` khi `captcha_site_key = null` (`RegisterForm.tsx`). Kết quả: quên đặt `TURNSTILE_SITE_KEY` + gõ sai `CAPTCHA_DRIVER` thì production chạy **không có captcha** mà không có cảnh báo nào.
  - Captcha là biện pháp chính của đăng ký (api-contract §1.6, S10, S20). Mất nó thì bot tạo tài khoản hàng loạt và dò email/SĐT, chỉ còn bị giới hạn 30/giờ/IP.
  - Guard được ghi là "allowlist" nhưng phần captcha thực chất là blocklist.
- **Cách sửa:**
  ```php
  // AppServiceProvider::register()
  return match (config('captcha.driver')) {
      'turnstile' => new TurnstileVerifier((string) config('services.turnstile.secret')),
      'fake' => new FakeCaptchaVerifier,
      default => throw new \RuntimeException('CAPTCHA_DRIVER không hợp lệ.'),
  };

  // ProductionConfigGuard::guardCaptcha() — allowlist thật
  throw_unless(config('captcha.driver') === 'turnstile', RuntimeException::class, 'Production bắt buộc CAPTCHA_DRIVER=turnstile.');
  throw_if(blank(config('services.turnstile.secret')) || blank(config('services.turnstile.site_key')), RuntimeException::class, 'Thiếu TURNSTILE_SECRET/TURNSTILE_SITE_KEY.');
  ```
  Tuỳ chọn: `FakeCaptchaVerifier::verify()` tự trả `false` khi `app()->isProduction()` (phòng thủ nhiều lớp).
- **Kiểm chứng:** mở rộng test guard với các giá trị `Turnstile`, `''`, `none` (production) phải ném exception. Test `turnstile` mà secret hoặc site key rỗng cũng phải ném. Test binding với driver lạ phải ném exception.

### M4 [Medium] Dò email/SĐT qua `/auth/register` **không cần captcha**: FormRequest kiểm `unique` trước khi Service kiểm captcha — OWASP A07/A04 (S20)
- **Vị trí:**
  - `backend/app/Http/Requests/Auth/RegisterRequest.php:36` (`Rule::unique('users','email')`), `:37-49` (kiểm SĐT đã tồn tại).
  - `backend/app/Services/Auth/RegistrationService.php:155` (captcha chỉ được kiểm sau khi validation đã qua).
- **Mô tả & tác động:**
  - S20 chấp nhận AC2 ("Email/SĐT đã được sử dụng") **với điều kiện có throttle + captcha**.
  - Nhưng validation chạy trước captcha. Request `{"email":"x@y","phone":"09…","captcha_token":"rác"}` nhận ngay 422 với `errors.email`/`errors.phone` cho biết tài khoản tồn tại (đã xác minh), không cần giải captcha, không cần các field khác.
  - Mỗi request kiểm được **cả email lẫn SĐT**. Chỉ bị giới hạn 30/giờ/IP.
  - Đây là oracle thứ hai, độc lập với M1.
- **Cách sửa:**
  - Kiểm captcha **trước** các rule truy vấn DB. Ví dụ bỏ `Rule::unique`/`exists` khỏi `RegisterRequest`: FormRequest chỉ kiểm định dạng, rồi `RegistrationService` làm theo thứ tự: captcha, kiểm trùng (trả 422 `email`/`phone` như cũ), insert (vẫn giữ `translateUniqueViolation`).
  - Hoặc dùng `withValidator()->after()` nhưng chỉ kiểm unique khi `captcha_token` hợp lệ. Như vậy captcha bị tiêu thụ trong FormRequest nên cần lưu kết quả vào request để Service không verify lần 2 (token Turnstile chỉ dùng được 1 lần).
  - **Tác động frontend:** vì token bị tiêu thụ ở mọi lần submit tới được bước kiểm trùng, `RegisterForm.tsx` phải reset widget (`setTurnstileResetKey`) sau **mọi** 422, không chỉ sau `CAPTCHA_FAILED`.
  - Nếu PO muốn giữ nguyên (chấp nhận rủi ro dò tài khoản ở đăng ký) thì phải ghi rõ vào board. Khi đó vẫn nên giảm ngưỡng dò (limiter riêng cho các request 422 do trùng).
- **Kiểm chứng:** test `POST /auth/register` với email đã tồn tại + `captcha_token = FakeCaptchaVerifier::INVALID_TOKEN` phải trả 422 `CAPTCHA_FAILED` và **không** có `errors.email`/`errors.phone`.

### L1 [Low] `/auth/login` và `/auth/register` trả 500 khi request không "stateful" (không Origin/Referer hợp lệ); register đã ghi DB trước khi lỗi — OWASP A04/A05
- **Vị trí:** `LoginController.php:181`, `RegisterController.php:232` (`$request->session()->regenerate()`), `routes/api.php:220-226`.
- **Mô tả:**
  - Client ngoài trình duyệt (hoặc Origin lạ) không đi qua `EnsureFrontendRequestsAreStateful`, nên không có session.
  - Service vẫn chạy hết, sau đó `session()` ném `RuntimeException`, trả 500 `INTERNAL_ERROR` và ghi log error cho mỗi request.
  - Login: đúng mật khẩu trả 500, sai trả 422. Oracle này vẫn tương đương 200/422 nên không phải lỗ hổng mới. Nhưng `RateLimiter::clear()` đã chạy, và log bị nhiễu (có thể spam log).
  - Register: user + 2 bản ghi consents **đã commit** rồi mới 500.
  - Không phải login-CSRF: không có cookie phiên nào được phát ra.
- **Cách sửa:** middleware nhỏ (ví dụ `require.stateful`) đặt **trước** `guest`/`throttle` trên 3 route auth: `if (! $request->hasSession()) throw new DomainException('ORIGIN_NOT_ALLOWED', …, 400/403)`. Cách này nhất quán với `CsrfController` (R3 của T01).
- **Kiểm chứng:** test login/register không Origin phải trả 4xx `ORIGIN_NOT_ALLOWED` và `User::count()` không đổi.

### L2 [Low] `WRONG_PORTAL` biến host học sinh thành nơi thử mật khẩu staff/admin, không qua các lớp bảo vệ của admin-api — OWASP A07
- **Vị trí:** `LoginService.php:92-98`; api-contract §2.2.
- **Mô tả:**
  - Theo hợp đồng, mật khẩu đúng + vai trò khác `hoc_sinh` thì trả 403 `WRONG_PORTAL`, nghĩa là **xác nhận mật khẩu admin đúng** trên host api. Host này không có `admin.origin`, không có MFA, và (sau này) không có các cảnh báo/khoá dành riêng cho staff ở T28.
  - Cộng với M2, số lần thử cho mỗi tài khoản admin không có trần.
  - Hiện chưa có audit log cho sự kiện này.
- **Đề xuất (PO/Architect quyết):**
  - Với vai trò khác `hoc_sinh` trên host api, trả 422 chung (không xác nhận mật khẩu), hoặc vẫn trả `WRONG_PORTAL` nhưng: (1) đếm lần sai của **tài khoản staff** vào cùng limiter với admin-api (T28), (2) ghi `audit_logs` `staff.login_wrong_portal`.
  - Ghi yêu cầu này vào T28.

### L3 [Low] Bằng chứng đồng ý (consents) chưa bất biến và bị xoá dây chuyền theo user — OWASP A09 / Dữ liệu cá nhân (S7)
- **Vị trí:**
  - `backend/database/migrations/2026_09_28_090000_create_consents_table.php:124` (`cascadeOnDelete()`).
  - `backend/app/Models/Consent.php` (không chặn `updating`/`deleting`; `$fillable` gồm cả `revoked_at`, `policy_version`, `ip`).
  - `ConsentService.php:103-106`.
- **Mô tả:**
  - Không có route sửa/xoá (đạt). Nhưng ở tầng model, bản ghi vẫn sửa được (`$c->update(['policy_version'=>…])`) và xoá được.
  - Xoá user thì bằng chứng đồng ý mất theo. data-model §6 ghi "Giữ suốt vòng đời tài khoản + thời hạn pháp chế chốt", và US-018 dự kiến ẩn danh hoá thay vì xoá cứng.
  - Chuẩn hiện tại thấp hơn `AuditLog` (M5 của T01/T02).
- **Đề xuất:**
  - `restrictOnDelete()` (khớp mặc định trong data-model §3 "FK mặc định `restrictOnDelete`").
  - Model chặn `updating` trừ khi chỉ đổi `revoked_at` từ `null`, và chặn `deleting`.
  - Quyền DB (cùng đợt với M5 của `audit_logs`).
- Thời hạn lưu IP/UA trong consents và việc giữ bằng chứng sau khi xoá tài khoản: **cần bộ phận pháp chế xác nhận**.

### L4 [Low] Che dữ liệu phụ huynh ở `/auth/me` còn lộ nhiều với chuỗi ngắn; `parent_phone` không được chuẩn hoá — Dữ liệu cá nhân
- **Vị trí:** `backend/app/Http/Resources/Auth/MeResource.php:184-205`; `RegisterRequest.php:54`.
- **Mô tả:**
  - Email có phần local 1–2 ký tự bị lộ toàn bộ: `ab@x.com` thành `ab*@x.com`, `a@x.com` thành `a*@x.com`.
  - SĐT 8 ký tự lộ 5/8 chữ số.
  - `parent_phone` chấp nhận `[0-9+\-\s()]{8,20}`, ví dụ `((((((((` là hợp lệ. Giá trị không đi qua `PhoneNumber` nên dữ liệu bẩn, và bản đã che giữ nguyên ký tự định dạng.
  - Rủi ro thấp vì chỉ chính học sinh xem.
- **Đề xuất:**
  - Chuẩn hoá `parent_phone` qua `PhoneNumber` (hoặc một biến thể chấp nhận cả số cố định nếu PO muốn).
  - Che email theo quy tắc "tối đa 1 ký tự đầu + `***`", không lộ độ dài; che SĐT "2 đầu + `*****` + 3 cuối" cho số đã chuẩn hoá 10 số.
  - Chốt tên field `parent_*_masked` vào api-contract (R5 của QA).

### L5 [Low] Chi tiết `TurnstileVerifier`
- **Vị trí:** `backend/app/Services/Auth/Captcha/TurnstileVerifier.php:55-65`; `RegisterRequest.php:59`.
- **Mô tả và đề xuất:**
  - (a) `ConnectionException`/timeout không được bắt, nên người dùng nhận 500 `INTERNAL_ERROR` thay vì `CAPTCHA_FAILED`/503. Vẫn là fail-closed, chấp nhận về bảo mật. Nên `try/catch (ConnectionException) { report(); return false; }` để trả thông điệp đúng và không ghi stack trace mỗi lần Cloudflare chậm.
  - (b) Không kiểm `hostname` và `action` trong response. Nên so `action === 'register'` (frontend đã truyền `action`) và `hostname` thuộc `FRONTEND_URL`, để token lấy từ widget ở trang khác cùng site key không dùng lại được.
  - (c) `captcha_token` không có `max`. Nên đặt `max:2048` theo giới hạn của Cloudflare.

### L6 [Low] Frontend: đăng xuất lỗi thì im lặng
- **Vị trí:** `frontend/apps/web/components/SiteHeader.tsx:20-31`.
- **Mô tả:** `handleLogout` chỉ có `try/finally`. Mạng lỗi hoặc 5xx gây unhandled rejection: không có toast, header vẫn "Xin chào…". Trên máy dùng chung (phòng máy trường, quán net) người dùng có thể bỏ đi mà tưởng đã đăng xuất.
- **Đề xuất:** `catch` rồi hiện toast lỗi "Đăng xuất chưa thành công, vui lòng thử lại". Có thể xoá luôn trạng thái UI phía client (`notifyAuthChanged`), nhưng phải báo rõ phiên ở server có thể còn.

### Info
- **Bcrypt 72 byte:** mật khẩu 100 ký tự được chấp nhận (201), bcrypt chỉ dùng 72 byte đầu (Laravel không bật `hashing.bcrypt.limit`). Đề xuất `max:72` byte (hoặc thông báo cho người dùng), hoặc chuyển sang argon2id khi có kế hoạch rehash. `Password::defaults()` mới chỉ có `min(8)`. `uncompromised()` cần gọi API ngoài: PO/Architect quyết.
- **Ngưỡng theo IP ở trường học (N4 cũ):** `throttle:login` đếm **mọi** request 50/giờ/IP; `register` 30/giờ/IP. Một lớp 40 học sinh sau NAT đăng ký/đăng nhập cùng tiết có thể chạm ngưỡng. Nên theo dõi sau khi go-live; không đổi trước khi có số liệu.
- **Không có audit log cho sự kiện xác thực** (đăng nhập thất bại hàng loạt, `ACCOUNT_LOCKED`, `WRONG_PORTAL`). Sẽ cần ở T05/T28 để giám sát credential stuffing.
- **`policy_version`** ghi vào consents lấy theo config **tại thời điểm submit**, không phải phiên bản đã hiển thị cho người dùng. Frontend nên gửi `policy_version` đã hiển thị; backend từ chối (422) nếu khác config hiện hành. Cách này giúp bằng chứng đồng ý khớp văn bản người dùng thật sự đã đọc.
- **`EnsureGuest` trả `FORBIDDEN`** cho người đã đăng nhập (R4 của QA): không có vấn đề bảo mật.
- **Nonce CSP** được truyền từ Server Component xuống client qua prop (nằm trong RSC payload của chính response đó). Đây là cách chấp nhận được, nonce đổi theo mỗi request. `'strict-dynamic'` làm allowlist host bị bỏ qua ở trình duyệt mới. Đúng như comment trong `proxy.ts`.
- **`suppressAuthEvents`** trên `/auth/me` của header làm lỗi `SESSION_REPLACED` (T05) ở header hiện im lặng thành "chưa đăng nhập" thay vì overlay. Chấp nhận được về bảo mật, vì server vẫn chặn. FW1 phần sau (overlay phiên) cần kiểm lại UX này.

---

## Đối chiếu yêu cầu trọng tâm

| Mục | Kết quả | Ghi chú |
|---|---|---|
| Enumeration: message/status khi login sai | Đạt | Cùng 422 + cùng message (sau R1). Khoá chỉ báo khi mật khẩu đúng |
| Enumeration: thời gian login, dummy hash | **Chưa đạt (M1)** | Dummy hash không bao giờ chạy |
| Enumeration qua đăng ký | **Chưa đạt (M4)** | Lộ trước captcha |
| S20 thứ tự khoá / sai portal | Đạt | Test đủ 3 nhánh. Lưu ý L2 cho staff |
| Throttle 2 lớp | Đạt về cấu trúc, **lách được (M2)** | IP 50/giờ; tài khoản 10 lần sai/giờ, chỉ đếm lần sai, clear khi đúng |
| XFF giả | Đạt | Có test, `TrustProxies` chỉ tin IP cụ thể (H1 cũ đã đóng) |
| Biến thể định danh (`+84`/`84`/`0`, hoa/thường, có dấu) | Đạt | R7 đã sửa |
| Biến thể full-width / tương đương collation | **Chưa đạt (M2)** | |
| S17 mass assignment / `validated()` | Đạt | Test `role=admin` bị bỏ qua |
| Unique race | Đạt | `23000` trả 422 đúng field |
| Captcha fake bị cấm ở production | **Một phần (M3)** | Chỉ cấm đúng chuỗi `fake` |
| Turnstile: secret + remoteip + timeout, fail-closed | Đạt | L5 để cải thiện UX/kiểm `action` |
| Session fixation | Đạt | `regenerate()` ở login/register; `invalidate()` + `regenerateToken()` ở logout |
| CSRF register/login/logout | Đạt | 419 khi thiếu token (đã xác minh). Không Origin trả 500 (L1) |
| `guest` middleware | Đạt | 403 envelope chuẩn |
| `/auth/me` không lộ PII phụ huynh | Đạt | Chỉ trả bản đã che, che chưa tốt với chuỗi ngắn (L4) |
| Consents: IP/UA/policy_version | Đạt | |
| Consents: không sửa/xoá được | **Một phần (L3)** | Không có route, nhưng model/FK cho phép |
| Log không chứa mật khẩu/token | Đạt | `dontFlash` đủ; N1 đã sửa |
| FE CSP Turnstile, nonce | Đạt | Không nới `unsafe-inline`/`unsafe-eval` cho script |
| FE `safeRedirect` cho `?next=` | Đạt | |
| FE không lưu token/PII vào localStorage | Đạt | Chỉ `device_id` |
| FE `suppressAuthEvents` | Đạt | Chỉ ảnh hưởng UI |
| FE hiển thị lỗi server không XSS | Đạt | Text React |

## Trạng thái S* liên quan

| # | Trạng thái | Ghi chú |
|---|---|---|
| S7 | Đạt (kỹ thuật) / Một phần | Checkbox tách riêng, không tick sẵn, 422 khi thiếu; consents ghi đủ. L3 (bất biến, cascade), nội dung pháp lý chờ pháp chế |
| S10 | **Một phần** | XFF đạt. Lớp tài khoản lách được (M2) |
| S17 | Đạt | |
| S19 | Đạt | Test kiến trúc host api bắt buộc `role:hoc_sinh`, có test âm |
| S20 | **Một phần** | Thứ tự đạt. M1 (timing) và M4 (đăng ký trước captcha) chưa đạt |
| S21 | Đạt | |
| S23 | Đạt | `safeRedirect` |
| M4 (T01/T02) | **Mở lại một phần (M3)** | Nhánh captcha của guard vẫn là blocklist |
| N1 (T01/T02) | Đóng | |

## Việc chuyển `laravel-dev`
1. **M1:** gọi `Hash::check()` vô điều kiện với dummy hash cùng cost; thêm test `Hash::shouldHaveReceived('check')->once()`.
2. **M2:** chuẩn hoá NFKC + chỉ nhận ASCII cho `login` (hoặc cột collation nhị phân, cần DBA duyệt); test full-width vẫn bị 429.
3. **M3:** binding captcha ném exception khi driver lạ; guard production kiểu allowlist `=== 'turnstile'` + bắt buộc có secret/site key; test các biến thể.
4. **M4:** kiểm captcha trước các rule truy vấn DB ở đăng ký (hoặc PO chấp nhận rủi ro bằng văn bản).
5. **L1:** middleware bắt buộc stateful trên 3 route auth, đặt trước `guest`/`throttle`.
6. **L3:** `restrictOnDelete` + chặn sửa/xoá ở model `Consent`.
7. **L4, L5:** chuẩn hoá `parent_phone`, che lại; `TurnstileVerifier` bắt `ConnectionException`, kiểm `action`/`hostname`, `max:2048`.
8. **L2:** ghi vào T28 (limiter chung với staff, audit `WRONG_PORTAL`) sau khi PO/Architect quyết.

## Việc chuyển `nextjs-dev`
- **M4 (sau khi backend đổi):** reset Turnstile widget sau mọi 422 của đăng ký, không chỉ `CAPTCHA_FAILED`.
- **L6:** bắt lỗi đăng xuất, hiện toast.
- **M3:** không gửi `LOCAL_FAKE_CAPTCHA_TOKEN` khi `NODE_ENV === 'production'`. Nếu `captcha_site_key` null ở production thì hiện lỗi cấu hình thay vì cho submit.
- **Info:** gửi `policy_version` đã hiển thị khi đăng ký (sau khi Architect cập nhật api-contract).

## Test `laravel-qa` nên thêm
- Login với định danh không tồn tại vẫn gọi `Hash::check` đúng 1 lần (M1).
- 10 lần sai với `victim@…`, rồi `ｖictim@…` / `ＶＩＣＴＩＭ@…` / `０９１２…` phải trả 429 (M2).
- `ProductionConfigGuard` ném exception với `CAPTCHA_DRIVER` = `Turnstile` / `''` / `none`, và với `turnstile` thiếu secret/site key; binding với driver lạ phải ném exception (M3).
- Register với email/SĐT đã tồn tại + captcha sai trả `CAPTCHA_FAILED`, không có `errors.email`/`errors.phone` (M4).
- Login/register không Origin trả 4xx, không tạo user (L1).
- CSRF thật: một test bật lại `ValidateCsrfToken` (override `runningUnitTests()`) để khoá hành vi 419 cho register/login/logout, vì Laravel bỏ qua CSRF khi chạy unit test nên các test hiện có không bao giờ kiểm được điều này.
- `Consent` `update()`/`delete()` phải ném exception; xoá user có consents bị chặn (L3).

## Điểm cần pháp chế / PO quyết
- **M4:** có chấp nhận việc form đăng ký cho biết email/SĐT đã tồn tại (AC2) khi đã có captcha đúng thứ tự không? Hiện S20 chấp nhận có điều kiện. PO xác nhận lại.
- **L2:** có trả `WRONG_PORTAL` cho staff trên host học sinh không (xác nhận mật khẩu admin ngoài admin-api)? PO/Architect quyết.
- **L3:** thời hạn giữ bằng chứng đồng ý (kèm IP, UA) sau khi tài khoản bị xoá/ẩn danh hoá: **cần bộ phận pháp chế xác nhận**.
- Thu thập IP/UA trong consents là dữ liệu cá nhân của trẻ em: mục đích (bằng chứng đồng ý) và thời hạn lưu **cần bộ phận pháp chế xác nhận** theo Luật Bảo vệ dữ liệu cá nhân 2025 và Nghị định 356/2025/NĐ-CP.
