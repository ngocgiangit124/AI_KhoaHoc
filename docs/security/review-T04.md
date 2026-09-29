# SECURITY: T04 [SEC] (OTP xác thực tài khoản, đổi liên hệ) | 2026-09-28

**Phạm vi:** `git diff claude/zen-dirac-fmucf7...claude/zen-dirac-fmucf7-t04` (commit `5ca6356`, `047bff2`; worktree `.claude/worktrees/t04`). Backend: `OtpService`, `ContactService`, `RegistrationService` (phần gửi OTP), `Otp/*Sender*`, `OtpMail` + view, `OtpCode` + migration + factory, `OtpController`, `ContactController`, `SendOtpRequest`/`VerifyOtpRequest`/`UpdateContactRequest`, `EnsureAccountVerified`, `AppServiceProvider` (limiter `otp-send`/`otp-verify`, audit `otp.daily_limit`), `ProductionConfigGuard::guardOtpChannels()`, `routes/api.php`, `bootstrap/app.php`, test `tests/Feature/T04/*`.
**Chuẩn đối chiếu:** tasks.md T04; api-contract §1.3, §1.6, §1.7, §2.2; data-model §3.1 `otp_codes`; ADR-004; `docs/security/audit-2026-09-25.md` (S9, S19, S20, S21, S22); `docs/security/review-T03-FW1.md`; `docs/reviews/review-T04.md` (2 vòng, APPROVE).

**Kết luận:** **PASS có điều kiện**. Không có Critical/High. Có **3 phát hiện Medium**:
- **M1:** mã OTP không được ràng buộc với địa chỉ nhận (`destination`) lúc xác thực.
- **M2:** trần gửi vượt được bằng request song song. Nhận định "race ở lớp 2 không chạm tới được" trong review code R1 **không đúng**, vì `ThrottleRequests` kiểm hết các limit rồi mới `hit()`, hai bước này không nguyên tử.
- **M3:** đổi email/SĐT không cần xác thực lại, không báo về địa chỉ cũ, không ghi audit.

Điều kiện:
1. Sửa M1 và M2 kèm test **trước khi đánh dấu T04 xong** trên board. Mỗi mục sửa vài dòng trong `OtpService`/`ContactService`.
2. M3 cần PO và Architect chốt (đổi hợp đồng `PUT /auth/contact`). Phải xong **trước khi bắt đầu T27** (quên mật khẩu), vì từ lúc có T27 thì M3 trở thành đường chiếm tài khoản vĩnh viễn.
3. Các mục Low/Info đưa vào backlog có người phụ trách (T05, T14/T18, T30, FW1 như ghi ở từng mục).

Những phần làm tốt (đã kiểm bằng test thật, không chỉ đọc code):
- **Brute force (S9):**
  - Mã sinh bằng `random_int(100000, 999999)` (CSPRNG).
  - `attempts` tăng bằng `UPDATE ... WHERE attempts < max AND expires_at > now() AND consumed_at IS NULL` **trước** `Hash::check`. 0 dòng ảnh hưởng thì coi là sai.
  - Tiêu thụ mã bằng UPDATE có điều kiện `consumed_at IS NULL`, nên mã không dùng lại được.
  - Tổng cộng: 5 lần/mã, 5 lần verify/phút, 20 lần/ngày (Redis), ≤10 mã/ngày (DB). Tối đa khoảng 20–50 lần đoán/ngày/tài khoản trên 900.000 giá trị.
- **Lộ mã (S21):**
  - Mã rõ chỉ nằm trong biến cục bộ và trong nội dung mail.
  - `OtpMail` có `ShouldBeEncrypted`. Đã xác minh payload queue là ciphertext: không chứa mã, không chứa email người nhận.
  - `LogSmsOtpSender` ghi `***` và chỉ resolve ở local/testing.
  - `dontFlash` có `code`. `report()` ở R3 chỉ nhận exception (message không chứa mã hay PII) + `user_id`.
  - Response `otp/send` chỉ trả `resend_available_at`.
  - `AuditLogger` lọc chuỗi con `otp` (N1).
- **Middleware (S19):** 3 route mới đều thuộc nhóm `auth:sanctum, account.active, student.single_session, no_store, role:hoc_sinh`. Tài khoản bị khoá nhận 403. CSRF chặn 419 cả 3 route (đã bật lại `ValidateCsrfToken` trong test tạm).
- **Chống email bombing qua `PUT /auth/contact` (R1) đã đóng với request tuần tự:**
  - Route có `throttle:otp-send`, dùng chung khoá theo `user_id` với `/auth/otp/send`.
  - `OtpService::send()` tự kiểm trần bằng số dòng thật trong DB, nên mọi caller đều bị chặn, kể cả register.
  - `ContactService` kiểm trần **trước** khi đổi liên hệ (fail-closed).
  - Không còn đường nào gọi `send()` mà không đi qua lớp DB.
- **Mass assignment (S17):** chỉ dùng `validated()`. `*_verified_at` chỉ được ghi bằng `forceFill` trong Service.
- **Audit `otp.daily_limit` (R2):**
  - Khoá cache tái tạo `md5($limiterName.$limit->key)` khớp đúng `ThrottleRequests::handleRequestUsingNamedLimiter()` của Laravel v13.33.0 (dòng 134, `$shouldHashKeys = true`).
  - Có test HTTP thật nên sẽ báo đỏ nếu framework đổi cách tạo khoá.
  - `Cache::add` bảo đảm chỉ ghi audit 1 lần cho mỗi lần chạm trần.
- **ProductionConfigGuard:** production bắt buộc có `email` và cấm `sms`. `OtpSenderManager` chặn thêm lần nữa lúc chạy (hai lớp).

## Môi trường & công cụ

| Mục | Kết quả |
|---|---|
| Môi trường | Docker local (`infra`, service `php`), worktree mount `/var/www/wt`, DB `vitaminvui_testing_t04` (không đụng `vitaminvui`, `_testing`, `_t06`, `_t10`) |
| Pint (`--test`) | Sạch (145 file) |
| Larastan | 0 lỗi |
| Pest `phpunit.t04.xml` | **247 passed (774 assertions)** |
| `composer audit` | No security vulnerability advisories found |
| Laravel | v13.33.0 |

**Test tạm để xác minh** (`backend/tests/Feature/TmpSecT04/`, **đã xoá**; `git status` chỉ còn các thay đổi có sẵn từ trước: `docs/reviews/review-T04.md`, `backend/phpunit.t04.xml`):

| Kiểm tra | Kết quả |
|---|---|
| Có mã active với `destination = attacker@…`, email user đổi thành `victim@…` (mô phỏng trạng thái sau race ở M1), gọi `verify()` bằng mã đúng | **`email_verified_at` được ghi cho `victim@…`** (M1) |
| Payload queue của `SendQueuedMailable(OtpMail)` tạo bằng `DatabaseQueue::createPayload` | Không chứa mã, không chứa email người nhận (ciphertext). Đạt |
| Tài khoản `locked` gọi contact, verify, send | 403 / 403 / **429**: throttle chạy trước `account.active`, nên request 403 trước đó vẫn tiêu bộ đếm. Vẫn bị chặn, không phải lỗ hổng (Info) |
| Bật lại CSRF: 3 route không có token | 419 / 419 / 419. Đạt |
| 429 từ **lớp Service** (cooldown DB, ví dụ ngay sau đăng ký) | **Không có `Retry-After`**. Body `TOO_MANY_ATTEMPTS` (L3) |
| 429 từ **throttle route** | `Retry-After: 60`, `X-RateLimit-Reset`, `X-RateLimit-Limit: 1`, `X-RateLimit-Remaining: 0` |
| `PUT /auth/contact` với email của tài khoản khác (khác hoa/thường) | 422 `email: "Email đã được sử dụng."` (Info, cùng rủi ro đã chấp nhận ở S20) |
| `PUT /auth/contact` đổi email đã xác thực, không gửi mật khẩu | 200. OTP chỉ gửi tới email **mới**, không có thông báo nào tới email cũ, `audit_logs` = 0 dòng (M3) |

## Phát hiện

### M1 [Medium] `verify()` không ràng buộc mã với địa chỉ nhận hiện tại, nên có thể xác thực email/SĐT không thuộc về mình — OWASP A07/A04 (S9: "mã gắn với đích")
- **Vị trí:**
  - `backend/app/Services/Auth/OtpService.php:115-158`: `verify()` chọn mã theo `(user, purpose)`, rồi `markVerified($user, $otp->channel)`. Không so `$otp->destination` với `$user->email`/`$user->phone` hiện tại.
  - `OtpService.php:131-136` và `146-149`: UPDATE `attempts`/`consumed_at` không có `whereNull('invalidated_at')`.
  - `backend/app/Services/Auth/ContactService.php:54-82`: transaction đổi email **không** huỷ mã cũ. Mã cũ chỉ bị huỷ bên trong `send()` ở bước sau, và `send()` có thể ném 429 trước khi huỷ.
- **Mô tả & tác động:** data-model §3.1 ghi "mã gắn với đích". Code lưu `destination` nhưng không bao giờ dùng lúc verify. Tính đúng đắn hiện chỉ dựa vào giả định "đổi liên hệ luôn huỷ mã cũ", và giả định này vỡ được bằng race (xem M2):
  1. Tài khoản của kẻ tấn công có email A (hộp thư của hắn). Hắn gửi song song `PUT /auth/contact {email: B}` và `POST /auth/otp/send`. Hai request cùng lọt `throttle:otp-send` vì lỗi check-then-hit ở M2.
  2. `ContactService` chạy `assertCanSend()` và qua.
  3. `otp/send` tạo mã có `destination = A`. Đối tượng `$user` của request này được nạp từ trước, nên email vẫn là A. Mã gửi tới hộp thư A.
  4. `ContactService` commit `email = B`, `email_verified_at = null`. Sau đó gọi `send()`, bị cooldown DB chặn nên ném **429** trước khi huỷ mã cũ. Email đã đổi dù response là 429, tức là cũng phá luôn cam kết fail-closed của R1.
  5. Kẻ tấn công nhập mã nhận ở A. `markVerified('email')` ghi `email_verified_at` cho **B**.

  Kết quả: tài khoản mang email B (của nạn nhân, chưa đăng ký) ở trạng thái **đã xác thực**. Việc giữ chỗ email (S20) trở nên "hợp lệ". Nếu sau này có cơ chế "giành lại email bằng OTP" hoặc dọn tài khoản chưa xác thực (T30), tài khoản này sẽ được coi là chính chủ. Khi bật kênh `sms` hoặc có `parent_consent` (T29) thì cùng lỗi đó xác thực được SĐT không phải của mình. Đã tái hiện trạng thái ở bước 5 bằng test tạm. Race ở bước 1–4 chỉ phân tích từ code, chưa chạy song song thật.
- **Cách sửa** (3 dòng phòng thủ, độc lập với M2):
  ```php
  // OtpService::verify() — trước markVerified(), và thêm whereNull('invalidated_at') vào 2 UPDATE
  $current = $otp->channel === 'email' ? $user->email : $user->phone;
  if ($current === null || ! hash_equals(mb_strtolower($otp->destination), mb_strtolower($current))) {
      throw self::invalidCodeException();
  }
  ```
  ```php
  // ContactService::update() — trong CÙNG DB::transaction đổi email/SĐT
  OtpCode::query()->where('user_id', $user->getKey())
      ->whereNull('consumed_at')->whereNull('invalidated_at')
      ->update(['invalidated_at' => now()]);
  ```
- **Kiểm chứng:**
  - Test Service: tạo mã `destination = A`, đổi email sang B bằng `forceFill`, verify đúng mã thì nhận 422 và `email_verified_at` vẫn null.
  - Test: `PUT /auth/contact` mà `send()` ném 429 (giả lập bằng cách tạo `otp_codes` mới giữa `assertCanSend` và `send`, hoặc mock) thì mã cũ phải đã bị huỷ.
  - Test: mã có `invalidated_at` thì không tăng `attempts`, không consume được.

### M2 [Medium] Trần gửi OTP (cooldown 60s, 5/giờ, 10/ngày) vượt được bằng request song song: cả lớp route và lớp Service đều là check-then-act — OWASP A04 (S9)
- **Vị trí:**
  - `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:154-166` (`handleRequest`): vòng 1 gọi `tooManyAttempts()` cho **mọi** limit, vòng 2 mới `hit()`.
  - `backend/app/Services/Auth/OtpService.php:189-223` (`assertUnderSendLimits`): `count()` rồi `INSERT` ở transaction khác, không có khoá.
  - `ContactService.php:46-52` → `77-82`.
- **Mô tả & tác động:**
  - Review code R1 kết luận lớp 1 "đếm nguyên tử (`Cache::increment`)" nên race ở lớp 2 "không thể chạm tới". Đúng là `hit()` nguyên tử, nhưng **quyết định chặn hay không nằm ở `tooManyAttempts()` chạy trước đó**. N request đến cùng lúc khi bộ đếm = 0 đều đọc 0, đều qua, rồi mới cùng `hit()`. Limit theo IP (`otp-send-ip` 30/giờ) cũng lọt theo cùng cách.
  - Ở lớp DB, cả N request cùng đọc `count() < max` rồi cùng `INSERT`.
  - Hệ quả: một tài khoản bắn một loạt N request song song (N bị giới hạn bởi số worker php-fpm và độ trễ mạng, thực tế vài chục) thì gửi được khoảng N mail trong một lần. Sau đó bộ đếm ngày đã vượt nên bị khoá 24 giờ. Mỗi tài khoản vượt trần khoảng N/10 lần mỗi ngày.
  - Qua `PUT /auth/contact` với N email khác nhau, N nạn nhân mỗi người nhận 1 mail.
  - Qua `otp/send`, khi tài khoản đang giữ email nạn nhân (email chưa đăng ký), N mail dồn vào **cùng một** hộp thư.
  - Nhân lên theo số tài khoản (mỗi tài khoản cần qua captcha lúc đăng ký). Ảnh hưởng uy tín domain gửi mail và chi phí nhà cung cấp email. Đây cũng là tiền đề của M1.
  - Test hiện có đều gửi request tuần tự nên không phát hiện được.
- **Cách sửa:** tuần tự hoá theo user ở lớp Service. Đây là lớp mọi caller đều đi qua, không phụ thuộc framework:
  ```php
  // OtpService::send() — gộp kiểm trần + huỷ + tạo mã vào 1 transaction có khoá hàng user
  DB::transaction(function () use (...) {
      User::query()->whereKey($user->getKey())->lockForUpdate()->first(); // serialize per-user
      $this->assertUnderSendLimits($user);   // đếm SAU khi giữ khoá
      // invalidate + create như hiện tại
  });
  $this->senders->forChannel($channel)->send($destination, $code); // sau commit
  ```
  - `ContactService` nên giữ cùng khoá hàng user khi đổi liên hệ và gửi mã (hoặc gọi `send()` bên trong cùng transaction có khoá, gửi mail sau commit), để không còn cửa sổ giữa `assertCanSend()` và `send()`.
  - Cách thay thế: `Cache::lock("otp-send:{$userId}", 10)->block(3, ...)` (Redis).
  - Lớp route không cần sửa: khi lớp Service đã nguyên tử thì lớp route chỉ còn vai trò chặn sớm.
- **Kiểm chứng:**
  - Test Service đa tiến trình, theo đúng DoD "song song" của tasks.md: `pcntl_fork` hoặc N `Process` gọi `artisan tinker`/command tạm lên DB test. Hai lời gọi `send()` cho cùng user đồng thời thì chỉ 1 thành công, lời gọi còn lại nhận `TOO_MANY_ATTEMPTS`.
  - Tối thiểu: test đơn tiến trình khẳng định `send()` gọi `lockForUpdate` (query log có `for update`).

### M3 [Medium] Đổi email/SĐT (kênh khôi phục tài khoản) không cần xác thực lại, không báo về địa chỉ cũ, không ghi audit. Khi có T27, phiên bị chiếm sẽ thành tài khoản bị chiếm vĩnh viễn — OWASP A07/A09
- **Vị trí:** `backend/app/Http/Requests/Auth/UpdateContactRequest.php:25-44` (chỉ có `email`, `phone`); `backend/app/Services/Auth/ContactService.php:25-85`; api-contract §2.2 `PUT /auth/contact` ("email/phone mới"); T27 `POST /auth/password/forgot` + `reset` (OTP tới email/SĐT của tài khoản).
- **Mô tả & tác động:**
  - `PUT /auth/password` bắt buộc `current_password`, nhưng `PUT /auth/contact`, thao tác quyết định **ai nhận mã đặt lại mật khẩu**, thì không bắt buộc gì.
  - Người dùng là học sinh 11–18 tuổi, hay dùng máy chung ở trường hoặc quán net. Người ngồi sau (hoặc XSS, cookie bị lộ) chỉ cần đổi email sang địa chỉ của mình, xác thực OTP, rồi (sau T27) "quên mật khẩu". Mã đặt lại gửi về kẻ tấn công, chủ thật mất tài khoản và mọi khoá học đã mua.
  - Email cũ không nhận được thông báo nào (đã xác minh: chỉ `OtpMail` tới email mới). Không có bản ghi `audit_logs` (0 dòng), nên hỗ trợ khách hàng không có dữ liệu để khôi phục hay điều tra.
  - Hiện tại (chưa có T27) tác động dừng ở mức phá: đổi email làm tài khoản mất trạng thái đã xác thực.
- **Cách sửa (cần PO + Architect chốt vì đổi hợp đồng):**
  - Khi đổi một kênh **đã xác thực**, bắt buộc `current_password` (dùng `Hash::check`, đếm vào limiter sai mật khẩu giống login), hoặc OTP gửi tới kênh cũ.
  - Gửi mail thông báo "email/SĐT của bạn vừa được đổi thành `ng***@…`" tới địa chỉ **cũ** (không chứa link hay mã).
  - `AuditLogger::log('user.contact_changed', $user, ['fields' => ['email']])`: chỉ ghi tên trường, không ghi giá trị cũ/mới rõ, hoặc ghi dạng đã che.
  - Cân nhắc: T27 không gửi mã đặt lại tới kênh mới đổi trong vòng 24–72 giờ, hoặc gửi song song tới cả kênh cũ.
- **Kiểm chứng:**
  - Đổi email khi thiếu hoặc sai `current_password` thì nhận 422, email không đổi, không có OTP.
  - Đổi đúng thì có mail thông báo tới địa chỉ cũ và 1 dòng `audit_logs` `user.contact_changed` không chứa email rõ.

### L1 [Low] Không có trần theo địa chỉ nhận. Nhiều tài khoản lần lượt "mượn" cùng một email chưa đăng ký để gửi mail — OWASP A04 (S9, S20)
- **Vị trí:** `AppServiceProvider.php:128-141` (khoá chỉ theo `user_id` hoặc IP); `OtpService.php:189-223` (đếm theo `user_id`).
- **Mô tả & tác động:**
  - Unique email bảo đảm tại một thời điểm chỉ một tài khoản giữ email X. Nhưng tài khoản 1 có thể đổi X sang email khác, rồi tài khoản 2 nhận X. Mỗi tài khoản gửi được tối đa 10 mail/ngày tới X.
  - Với K tài khoản (mỗi cái tốn một lần captcha lúc đăng ký), X nhận khoảng 10K mail/ngày, trong khi IP chỉ bị giới hạn 30/giờ (xoay IP được).
  - Chỉ áp dụng cho email **chưa** đăng ký.
  - Mức Low vì chi phí captcha + tài khoản, nhưng nên có lớp theo đích.
- **Cách sửa:** thêm limit theo `sha256(mb_strtolower(destination))`, ví dụ 10/ngày, 3/giờ. Đặt trong `assertUnderSendLimits()`, đếm `otp_codes.destination` (cần index `(destination, created_at)`) hoặc dùng `RateLimiter::attempt('otp-dest:'.hash(...))` kèm khoá (M2). Kết hợp S20: dọn tài khoản chưa xác thực quá N ngày (T30 `users:purge-unverified`).
- **Kiểm chứng:** 3 tài khoản lần lượt đổi email sang cùng X, mỗi tài khoản gửi tới trần. Tổng mail tới X không vượt trần theo đích.

### L2 [Low] `guardOtpChannels()` là blocklist; production chưa chặn `MAIL_MAILER=log/array` (mã OTP rơi vào log) và ngưỡng OTP bất thường — OWASP A05 (S21, S22)
- **Vị trí:** `backend/app/Support/ProductionConfigGuard.php:143-158`; `config/auth.php:128-137`.
- **Mô tả & tác động:**
  - Chỉ cấm đúng chuỗi `sms`. `AUTH_OTP_CHANNELS=email,SMS` hoặc `email,push` vẫn boot được. `SendOtpRequest` chấp nhận `SMS`, `OtpService` tạo dòng `otp_codes` (`destinationFor` rơi vào nhánh phone), rồi `OtpSenderManager` ném lỗi 500. Không lộ mã, nhưng thành đường tạo lỗi 500 và dòng rác.
  - Guard không kiểm `MAIL_MAILER`. Nếu production để `log` (cấu hình rất hay bị copy từ local), **mọi mã OTP được ghi rõ vào `storage/logs`**, đi ngược S21.
  - `AUTH_OTP_*` có thể đặt `cooldown=0`, `max_attempts_per_code=1000` (cột `attempts` là `tinyint unsigned`, quá 255 sẽ gây lỗi SQL) mà không có cảnh báo.
- **Cách sửa:** đổi sang allowlist giống `guardCaptcha()`:
  - `array_values($channels) === ['email']`.
  - `config('mail.default')` ∉ {`log`, `array`}.
  - Ràng buộc `1 ≤ max_attempts_per_code ≤ 10`, `cooldown_seconds ≥ 30`, `max_per_day ≤ 20`, `max_verify_per_day ≤ 50`.
- **Kiểm chứng:** mở rộng `ProductionConfigGuardOtpTest`: `email,SMS`, `email,push`, `MAIL_MAILER=log` và `max_attempts_per_code=0` đều phải ném lỗi ở production.

### L3 [Low] Hành vi 429 không đồng nhất; frontend không đọc được `Retry-After` qua CORS — OWASP A04 (liên quan FW1 phần 2)
- **Vị trí:** `ApiExceptionRenderer` (chỉ giữ header của `HttpException`, L6); `OtpService::tooManySendException()`; `AppServiceProvider.php:136` (`Limit::perMinute(1)` cố định, không theo `auth.otp.cooldown_seconds`); `config/cors.php:34` (`exposed_headers => ['X-Request-Id']`).
- **Mô tả & tác động:**
  - 429 từ throttle route có `Retry-After` và `X-RateLimit-Reset`. 429 từ lớp Service (cooldown DB, ví dụ bấm "Gửi lại" trong 60 giây đầu sau khi đăng ký, vì register không đi qua `throttle:otp-send`) thì **không có**. Cùng mã lỗi `TOO_MANY_ATTEMPTS` nhưng hai dạng khác nhau.
  - Frontend `apps/web` gọi thẳng `api.` từ trình duyệt (Sanctum SPA, khác origin), nên JS **không đọc được `Retry-After`** vì header không nằm trong `exposed_headers`.
  - Khi nhiều limit cùng vượt, `Retry-After` lấy theo limit **đầu tiên** trong danh sách (cooldown 60 giây) chứ không phải lúc thực sự mở khoá (hết trần ngày).
  - Về câu hỏi "lộ `Retry-After` có rủi ro không": **không đáng kể.** Mọi khoá đều theo `user_id` của chính người gọi (hoặc IP của chính họ). Header chỉ cho biết bộ đếm của chính họ, không lộ tồn tại hay trạng thái tài khoản khác, và không nới trần. Kẻ tấn công canh được đúng thời điểm, nhưng trần là tuyệt đối (20 lần verify/ngày, 5 lần/mã). Không cần giấu. Rủi ro thật nằm ở phía UX: FW1 giả định "429 không có thời điểm mở khoá" là sai một nửa, vì có lúc có, có lúc không, và trình duyệt không đọc được.
- **Cách sửa:**
  - Thêm `Retry-After` vào `cors.exposed_headers` (an toàn).
  - `tooManySendException()` truyền `retry_after` (giây tới khi `created_at` mới nhất + cooldown, hoặc tới khi mở trần giờ/ngày) vào `context`, và renderer đặt header `Retry-After`.
  - `Limit::perMinute(1)` đổi thành `Limit::perSecond(1, cooldown_seconds)`, hoặc `perMinutes(ceil(cooldown/60), 1)` để khớp config.
  - FW1: ưu tiên `resend_available_at` của 202. Khi gặp 429 thì dùng `Retry-After` nếu có, không có thì dùng `config/public.otp.resend_cooldown_seconds`. Không hiển thị đồng hồ đếm ngược dựa trên giả định trần ngày.
- **Kiểm chứng:** test 429 lớp Service có `Retry-After`. Test CORS preflight/response có `Access-Control-Expose-Headers` chứa `Retry-After`.

### Info
- **I1 — S19 chưa đóng hẳn:** `student.single_session` vẫn là middleware pass-through (T05), nên phiên đã bị thay thế vẫn gọi được OTP/contact tới khi T05 xong. Đúng lộ trình. T05 cần test "phiên bị thay thế gọi `/auth/otp/send` → 401 `SESSION_REPLACED`".
- **I2 — `account.verified`** đã đăng ký alias nhưng chưa gắn route nào (đúng phạm vi). T14/T18 phải gắn. Đề nghị thêm test kiến trúc: mọi route `checkout`/`enroll` có `account.verified`. Logic "đã xác thực = ≥1 kênh đã xác thực" chấp nhận được. Sau khi sửa M1 thì không còn đường xác thực kênh không thuộc về mình.
- **I3 — Thứ tự middleware:** `ThrottleRequests` được Laravel xếp ngay sau `Authenticate`, **trước** `account.active`. Tài khoản bị khoá vẫn tiêu bộ đếm và có thể nhận 429 thay vì 403. Vẫn bị chặn, không phải lỗ hổng. Chỉ ảnh hưởng thông điệp.
- **I4 — Dò email/SĐT qua `PUT /auth/contact`:** trả 422 "Email/Số điện thoại đã được sử dụng.", cùng loại rủi ro S20 đã chấp nhận ở register. Ở đây cần đăng nhập, bị giới hạn 1 lần/phút, 10 lần/ngày/tài khoản, 30 lần/giờ/IP (bộ đếm tăng cả khi validation fail), nên kém hiệu quả hơn register. Chấp nhận, PO đã biết từ S20.
- **I5 — Thông điệp verify:** hết lượt (5 lần) thì trả "Mã OTP không đúng" kể cả khi mã đúng. Không lộ gì, nhưng UX nên báo "Mã đã hết lượt nhập, bấm Gửi lại mã". Thời gian phản hồi (bcrypt chỉ chạy khi còn lượt) không lộ gì hơn thông điệp.
- **I6 — Trần verify/ngày chỉ nằm ở Redis.** Nếu Redis bị flush thì bộ đếm reset, nhưng lớp DB (5 lần/mã × 10 mã/ngày) vẫn giới hạn ≤50 lần đoán/ngày. Audit `otp.daily_limit` chỉ ghi khi lớp Redis chặn, lớp DB chặn thì không ghi. Chấp nhận. Tách Redis DB/prefix cho limiter theo S22.
- **I7 — Dữ liệu cá nhân:** `otp_codes.destination` lưu email/SĐT rõ (cần cho M1 và L1). Cần thời hạn lưu và dọn (T30 `otp:prune`, đề xuất xoá mã quá 30 ngày). `LogSmsOtpSender` ghi SĐT vào log local (chỉ local/testing). `failed_jobs` đã mã hoá nhưng chưa có lịch `queue:prune-failed` (S21, T30).
- **I8 — R4 (review code):** FK `otp_codes.user_id` dùng `restrictOnDelete`, lệch data-model ("cascade"). Về bảo mật thì `restrict` tốt hơn (không mất dấu vết khi xoá cứng). Chỉ cần đồng bộ tài liệu.
- **I9 — api-contract §2.2** chưa ghi `throttle:otp-send` cho `PUT /auth/contact`. Cần cập nhật tài liệu.

## Đối chiếu yêu cầu trọng tâm

| # | Câu hỏi | Kết luận |
|---|---|---|
| 1 | Email bombing qua contact/otp-send/register đã đóng hoàn toàn chưa? | **Với request tuần tự thì đã đóng.** Mọi đường gọi `send()` (otp/send, contact, register) đều qua lớp DB. Register không qua throttle route nhưng user mới chỉ gửi được 1 mã. **Chưa đóng với request song song (M2).** Khoá throttle theo **user** (có user) hoặc IP. **Không có trần theo địa chỉ nhận (L1)**: nhiều tài khoản lần lượt nhận cùng email chưa đăng ký thì nhân được số mail. Email đã đăng ký thì không bị nhắm được, nhờ unique |
| 2 | Brute force OTP | Đạt. Tăng attempts nguyên tử trước `Hash::check`, 5 lần/mã, 5/phút + 20/ngày, `random_int`, hết hạn 10 phút, consume có điều kiện. Thiếu `whereNull('invalidated_at')` ở 2 UPDATE, đã gộp vào M1 |
| 3 | Lộ mã | Đạt. Không có trong log, response, audit hay exception. Queue đã mã hoá (đã xác minh). Rủi ro còn lại là cấu hình `MAIL_MAILER=log` ở production (L2) |
| 4 | Enumeration/timing | otp/send và verify chỉ tác động lên tài khoản của chính người gọi, không dò được gì. Contact trả "đã được sử dụng", cùng rủi ro S20 đã chấp nhận (I4) |
| 5 | Audit `otp.daily_limit`; guard sms | Khoá cache tái tạo đúng (v13.33.0), có test HTTP làm hàng rào khi nâng framework. Guard cấm `sms` đúng, nhưng là blocklist (L2) |
| 6 | Middleware | Đủ nhóm `student` + `role:hoc_sinh` + `no_store`. CSRF 419. Locked → 403. `single_session` còn pass-through tới T05 (I1). `account.verified` chưa gắn route, đúng phạm vi (I2) |
| 7 | Giả định FW1 về 429 | Throttle route **có** `Retry-After`, nhưng lớp Service **không có**, và trình duyệt **không đọc được** vì CORS chưa expose. Lộ `Retry-After` không gây rủi ro bảo mật (L3) |

## Trạng thái S* liên quan

| Mục | Trạng thái sau T04 |
|---|---|
| S9 (OTP race, trần ngày, SMS log) | Phần lớn đã đóng. Còn M1 (gắn đích), M2 (race trần gửi) |
| S19 (middleware nhất quán) | Đúng trên route mới. Chờ T05 hiện thực `single_session` |
| S20 (dò/giữ chỗ email) | Không đổi. M1 làm việc giữ chỗ nặng hơn (xác thực được). L1 và T30 |
| S21 (PII/OTP trong queue/log) | `OtpMail` mã hoá, `dontFlash` có `code`. Còn `queue:prune-failed` (T30) và guard `MAIL_MAILER` (L2) |
| S22 (cấu hình production) | Bổ sung guard OTP. Nên chuyển sang allowlist (L2) |

## Việc chuyển `laravel-dev`
1. **M1:** `OtpService::verify()` so `destination` với liên hệ hiện tại. Thêm `whereNull('invalidated_at')` vào 2 UPDATE. `ContactService` huỷ mã active trong cùng transaction đổi liên hệ.
2. **M2:** `OtpService::send()` gói `lockForUpdate` hàng user + `assertUnderSendLimits()` + huỷ/tạo mã trong 1 transaction, gửi mail sau commit. `ContactService` không để cửa sổ giữa `assertCanSend()` và `send()`.
3. **L2:** `guardOtpChannels()` dùng allowlist `['email']`. Chặn `mail.default ∈ {log, array}` ở production. Kiểm biên các ngưỡng `auth.otp.*`.
4. **L3:** `Retry-After` vào `cors.exposed_headers`. 429 lớp Service có `Retry-After`. Limit cooldown theo `cooldown_seconds`.
5. **L1** (có thể làm cùng M2): limit theo địa chỉ nhận (hash).
6. **M3:** làm sau khi PO/Architect chốt hợp đồng, bắt buộc trước T27.

## Việc chuyển `nextjs-dev` (FW1 phần 2)
- 429 có **hai dạng**. Không dựa vào `Retry-After` (hiện JS không đọc được qua CORS). Dùng `resend_available_at` (202) và `otp.resend_cooldown_seconds` (`/config/public`). Khi backend expose `Retry-After` thì ưu tiên header này.
- Thông điệp hết lượt hoặc 429 nên hướng người dùng bấm "Gửi lại mã" hoặc thử lại sau, không hiển thị đồng hồ theo trần ngày.

## Test `laravel-qa` nên thêm
- M1: mã `destination` ≠ liên hệ hiện tại → 422, không xác thực. Mã `invalidated` → không tăng `attempts`.
- M2: `send()` song song đa tiến trình cho cùng user → chỉ 1 mã được tạo trong cửa sổ cooldown. Đây cũng là DoD "20 verify song song" của tasks.md, hiện vẫn là test tuần tự.
- S21: payload queue `OtpMail` là ciphertext (không chứa mã, không chứa email). Sau bộ test, `grep -E '\b[0-9]{6}\b' storage/logs/*.log` không ra mã.
- S19: tài khoản bị khoá gọi 3 route → 403 (lần đầu). Sau T05: phiên bị thay thế → 401 `SESSION_REPLACED`.
- CSRF: 3 route mới không token → 419 (bật lại `ValidateCsrfToken` như test tạm).
- L2/L3: như mục kiểm chứng ở trên.

## Điểm cần pháp chế / PO quyết
1. **M3:** có bắt buộc mật khẩu hiện tại (hoặc OTP tới kênh cũ) khi đổi email/SĐT đã xác thực không? Có gửi thông báo tới địa chỉ cũ không? T27 có tạm không gửi mã đặt lại tới kênh vừa đổi trong X giờ không? (PO + Architect)
2. **L1/S20:** thời hạn giữ tài khoản chưa xác thực trước khi dọn (T30) và cơ chế "giành lại" email. (PO)
3. **I7:** thời hạn lưu `otp_codes` (có email/SĐT rõ) và `failed_jobs`. Cần bộ phận pháp chế xác nhận thời hạn lưu tối thiểu hoặc tối đa theo Luật Bảo vệ dữ liệu cá nhân 2025 / Nghị định 356/2025/NĐ-CP.

## Xác nhận lại sau khi sửa (vòng 2) — 2026-09-29

**Phạm vi:** `git diff 047bff2..c4a60b5` (`OtpService`, `ContactService`, `DomainException`, `ApiExceptionRenderer`, `ProductionConfigGuard`, `AppServiceProvider`, `config/auth.php`, `config/cors.php`, `OtpCodeFactory`, test T01/T04).
**Công cụ (tự chạy lại trong Docker):** Pint sạch, Larastan 0 lỗi, Pest `phpunit.t04.xml` **264 passed (801 assertions)**. Test tạm `tests/Feature/TmpSecT04b/` (2 test) đã **xoá** sau khi chạy.

### Kết luận mới: **PASS có điều kiện**
M1, M2, L2 và L3 đã đóng. L1 mới đóng một phần và sinh ra một lỗi logic nhỏ (N1, Low). M3 vẫn mở, đúng như dự kiến vì cần PO chốt. Không có Critical/High/Medium mới. Điều kiện:
1. **M3** do PO/Architect chốt và dev sửa **trước khi bắt đầu T27** (không đổi so với vòng 1).
2. **N1** sửa trước khi `laravel-qa` chạy giai đoạn 1 (vài dòng). Không chặn việc đánh dấu T04 xong.

### Trạng thái từng phát hiện

| Mục | Trạng thái | Ghi chú xác minh |
|---|---|---|
| M1 | **Đã đóng** | Xem mục "Kiểm M1" |
| M2 | **Đã đóng** | Xem mục "Kiểm M2" |
| M3 | **Mở**, chờ PO | Không đổi. Chặn trước T27 |
| L1 | **Đóng một phần**, xem N1 | Ngưỡng 10/giờ, 20/ngày theo đích là hợp lý |
| L2 | **Đã đóng** | Allowlist đúng `['email']`. Chặn `mail.default` là `log`/`array`. Có kiểm biên cooldown ≥30s, attempts 1–10, trần ngày 1–20, verify/ngày 1–50, TTL ≥1 phút. Có test |
| L3 | **Đã đóng** | 429 lớp Service có `Retry-After`. CORS expose `Retry-After`. Cooldown route dùng `perSecond(1, cooldown_seconds)` |
| I1–I9 | Không đổi | Thêm I10–I12 bên dưới |

### Kiểm M1: mã gắn với đích
- `verify()` so `destination` với `email`/`phone` **hiện tại** (không phân biệt hoa/thường) **sau** khi đã consume mã. Hai lệnh UPDATE `attempts`/`consumed_at` đã có `whereNull('invalidated_at')`. `ContactService` gộp việc đổi liên hệ, huỷ **mọi** mã active và tạo mã mới vào một transaction có khoá.
- **Consume mã khi lệch đích có mở DoS cho người khác không? Không.** Mã luôn thuộc `user_id` của người đang đăng nhập, `verify()` chỉ xét mã của chính user đó. Không ai khác làm mã của một user bị "tiêu" được. Consume trước khi so đích là lựa chọn đúng: mã lệch đích không dùng lại được, không thành oracle để thử nhiều lần. Trường hợp lệch đích chỉ xảy ra khi chính user tự race với mình, và người dùng chỉ cần bấm "Gửi lại mã".
- Test tạm xác nhận thêm: `send()` với đối tượng `$user` **cũ** (email trong DB đã đổi A→B bởi request khác) tạo mã `destination = A` và gửi mail tới A. Mã này **không** xác thực được B (M1 chặn). Trong thực tế đường này cũng bị cooldown chặn: request đổi liên hệ vừa tạo mã, cooldown ≥30 giây bắt buộc ở production. Chỉ còn là I10.

### Kiểm M2: khoá hàng users
- `createCodeAtomically()` thực hiện `SELECT … FOR UPDATE` trên hàng `users`, **sau đó** mới kiểm trần user, kiểm trần đích, đổi liên hệ, huỷ và tạo mã, tất cả trong một transaction. Mail gửi sau commit. Vượt trần thì closure đổi liên hệ không chạy. `send()`, `sendAfterContactChange()` và đăng ký (qua `send()`) đều đi qua lõi này. Không còn caller nào dùng `assertCanSend()` (xem I11).
- **Deadlock với đăng ký/đăng nhập: không thấy nguy cơ.**
  - Kết nối dùng `READ COMMITTED` (`config/database.php:68`), nên UPDATE/INSERT theo index không sinh gap lock, tức không có mẫu deadlock "gap lock + insert intention" giữa các user khác nhau.
  - Thứ tự khoá chỉ có một chiều: hàng `users`, rồi `otp_codes`. INSERT `otp_codes` lấy khoá S trên hàng cha `users` mà transaction đang giữ X.
  - Hiện không có transaction nào khác khoá hàng `users` đã tồn tại. `RegistrationService` chỉ INSERT user trong transaction riêng, commit rồi mới gọi `send()`, không lồng nhau. `LoginService` không ghi vào `users`. `verify()` gồm các câu autocommit riêng lẻ.
  - **Lưu ý cho T05/T27/T28:** transaction nào ghi `users` kèm bảng khác phải khoá `users` **trước** (cùng thứ tự). Không được gọi `send()`/`sendAfterContactChange()` bên trong một transaction ngoài: khi đó "gửi sau commit" không còn đúng, và khoá bị giữ lâu hơn.
- Thời gian giữ khoá gồm `Hash::make` (bcrypt, khoảng 50–250 ms) và 2–4 lệnh gọi Redis. Chấp nhận được, vì chỉ chặn request của **cùng** user. Có thể tính hash trước khi mở transaction (I12).
- **Gửi mail sau commit mà lỗi** (Redis/queue sập):
  - Mã đã nằm trong DB và đã tính vào trần, nhưng không tới người dùng.
  - `otp/send` và `PUT /auth/contact` trả 500. Với contact, liên hệ **đã** đổi và có mã active đúng đích mới, nên trạng thái vẫn nhất quán và an toàn: không có mã sai đích, không bỏ qua được trần.
  - Người dùng bấm "Gửi lại" sau cooldown. Đăng ký đã bắt lỗi (R3).
  - Không phải lỗ hổng. Đề nghị `ContactService` bắt lỗi gửi giống `RegistrationService`: `report()` rồi vẫn trả 200, vì liên hệ đã đổi thành công (I12).
- Không có test đa tiến trình thật. Tôi chấp nhận cặp test hiện có làm bằng chứng: query log cho thấy `FOR UPDATE` chạy trước `count`, và 2 connection thật cho thấy connection thứ hai nhận `Lock wait timeout`. Hai test này chứng minh khoá có mặt đúng chỗ và InnoDB thực thi khoá, đủ cho cơ chế tuần tự hoá này. Test 2 connection tự dọn dữ liệu (rollback + delete).

### Kiểm `ApiExceptionRenderer`: có mở đường cho header tuỳ ý không? **Không.**
Header chỉ được copy từ `DomainException::headers()`, tức mảng do code truyền vào constructor. Hiện chỉ `OtpService::tooManySendException()` truyền `Retry-After`, với giá trị là số nguyên (tính từ `created_at` trong DB hoặc `RateLimiter::availableIn`). Không có dữ liệu request nào chảy vào tên hay giá trị header. Symfony `HeaderBag` cũng không cho CRLF. Đề nghị phòng xa (Info): renderer chỉ copy header thuộc allowlist (`Retry-After`), để sau này không ai vô tình đưa dữ liệu người dùng vào.

### N1 [Low] Trần theo đích (L1) trong luồng đổi liên hệ kiểm **địa chỉ cũ** nhưng lại đếm cho **địa chỉ mới** — OWASP A04
- **Vị trí:** `backend/app/Services/Auth/OtpService.php:261-271`. `assertUnderDestinationLimit(self::destinationFor($user, $channel))` chạy **trước** `$beforeCreate()` (closure gán email mới), còn `hitDestinationLimit()` ở dòng 302 chạy **sau**, trên địa chỉ mới.
- **Test tạm** (trần đích = 2, trần user nới rộng):

  | Bước | Kết quả |
  |---|---|
  | A đổi sang `x@` | OK |
  | A đổi sang `a2@` | OK (bước này kiểm `x@`) |
  | B đổi sang `x@` | OK. **Không kiểm `x@`**, `x@` đã nhận 2 mail |
  | B đổi `x@` sang `b2@` | **429**: B bị kẹt ở `x@`, không đổi email đi được |

- **Tác động:** trần theo đích vẫn giữ được, nhưng là tình cờ: tài khoản đang giữ `x@` bị kẹt nên `x@` không nhận quá ngưỡng mỗi cửa sổ. Tuy vậy logic sai địa chỉ gây hai hệ quả:
  1. Người dùng hợp lệ có địa chỉ **hiện tại** đã chạm ngưỡng (do tài khoản khác từng nhắm vào) bị 429 khi muốn **đổi sang** địa chỉ khác, tối đa 24 giờ.
  2. Địa chỉ **mới** không được kiểm trước khi gửi, nên nếu sau này có đường nào "nhả" địa chỉ (T34 xoá/ẩn danh tài khoản, admin sửa email) thì trần theo đích sẽ bị vượt thật.
- **Cách sửa:** kiểm trần đích trên **địa chỉ sẽ gửi**, tức sau khi áp thay đổi (hoặc truyền `$newEmail`/`$newPhone` vào thay vì closure):
  ```php
  if ($beforeCreate !== null) { $beforeCreate(); }            // gán giá trị mới (chưa save)
  foreach ($channelList as $ch) { self::assertUnderDestinationLimit(self::destinationFor($user, $ch)); }
  if ($beforeCreate !== null) { $user->save(); }
  ```
  Vượt trần thì exception làm transaction rollback. `ContactService` ném tiếp 429, còn model `$user` đã bị gán giá trị trong bộ nhớ nhưng không được trả ra response. Có thể thêm `$user->refresh()` trong nhánh catch cho chắc.
- **Kiểm chứng:** test "địa chỉ mới đã chạm trần đích → 429, email không đổi, không có mail". Test "địa chỉ cũ đã chạm trần đích → vẫn đổi sang địa chỉ khác được (200)".

### Ngưỡng L1: 10/giờ, 20/ngày theo đích — **hợp lý, chấp nhận**
Lý do dev đưa ra là đúng: ngưỡng theo đích thấp hơn ngưỡng theo user (5/giờ, 10/ngày) sẽ chặn cả người dùng hợp lệ tự gửi lại cho mình. Gấp đôi nghĩa là lớp này chỉ có tác dụng khi từ 2 tài khoản trở lên cùng nhắm vào một địa chỉ. Kết hợp unique email và việc tài khoản giữ địa chỉ bị kẹt, một hộp thư chưa đăng ký nhận tối đa khoảng 20 mail/ngày. Mặt trái vốn có của mọi trần theo đích: kẻ tấn công có thể **tiêu hết** ngưỡng của một email chưa đăng ký, khiến chủ thật đăng ký trong 24 giờ đó không nhận được OTP ngay (đăng ký vẫn thành công nhờ R3, sau đó gửi lại bị 429 cho tới khi hết cửa sổ). Chấp nhận ở MVP, PO nên biết (xem thêm S20/T30).

### Info mới
- **I10:** `createCodeAtomically()` khoá hàng `users` nhưng bỏ kết quả, vẫn dùng `$user` đã nạp từ trước khi khoá. Đề nghị `$user->refresh()` ngay sau khi khoá, hoặc gán thuộc tính từ hàng đã khoá, để `destinationFor()` luôn dùng giá trị mới nhất. Hiện không khai thác được (cooldown + M1), nhưng sẽ tránh gửi mail tới địa chỉ cũ.
- **I11:** `assertCanSend()` và `sendIfChannelEnabled()` không còn caller. `assertCanSend()` kiểm trần **không khoá** và **không kiểm đích**, là cái bẫy nếu T27/T29 dùng lại. Nên xoá, hoặc đánh dấu `@internal` kèm cảnh báo.
- **I12:** tính `Hash::make($code)` trước khi mở transaction để giảm thời gian giữ khoá. `ContactService` nên bắt lỗi gửi mail sau commit như `RegistrationService` (`report()` + trả 200).
- `Retry-After` của trần theo đích cho biết khi nào ngưỡng của địa chỉ **hiện tại** của chính người gọi được mở lại. Nó có thể gián tiếp cho biết địa chỉ này vừa bị tài khoản khác gửi tới, nhưng chỉ với địa chỉ người gọi đang giữ. Không đáng kể.

### Trạng thái S* sau vòng 2

| Mục | Trạng thái |
|---|---|
| S9 | **Đóng** cho T04: gắn đích, tuần tự hoá trần, trần ngày, trần theo đích (còn N1 là lỗi logic nhỏ) |
| S19 | Không đổi, chờ T05 (`single_session`) |
| S20 | Không đổi. Mặt trái trần theo đích đã ghi ở trên |
| S21 | Guard `MAIL_MAILER` đã có. Còn `queue:prune-failed` (T30) |
| S22 | Guard OTP đã chuyển sang allowlist và có kiểm biên |

### Việc chuyển `laravel-dev` (vòng 3, nhỏ)
1. **N1:** kiểm trần đích trên địa chỉ mới, kèm 2 test ở trên.
2. **I10/I11/I12** (không bắt buộc): refresh `$user` sau khi khoá, xoá `assertCanSend()`, hash trước transaction, bắt lỗi gửi mail trong `ContactService`, allowlist header trong renderer.
3. **M3:** sau khi PO chốt, trước T27.

### Test `laravel-qa` nên giữ/bổ sung
- Giữ: test M1 (lệch đích, không phân biệt hoa/thường), test `FOR UPDATE` trước `count`, test 2 connection `Lock wait timeout`, test guard L2, test `Retry-After` và CORS expose.
- Thêm: 2 test N1. Tay-test trên Docker có Redis thật: bắn song song khoảng 20 `POST /auth/otp/send` cùng user (ví dụ `xargs -P 20 curl` vào môi trường local), kết quả phải là đúng 1 mã được tạo và 1 mail trong Mailpit.
