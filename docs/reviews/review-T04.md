# REVIEW: T04 — OTP (xác thực tài khoản, đổi liên hệ)
**Kết luận:** APPROVE (sau vòng 2)
**Phạm vi vòng 1:** `git diff claude/zen-dirac-fmucf7...claude/zen-dirac-fmucf7-t04` (commit `5ca6356`, worktree `.claude/worktrees/t04`) · 29 file (1587 dòng thêm)
**Phạm vi vòng 2:** `git diff 5ca6356..047bff2` (commit `047bff2`) · 11 file (618 dòng thêm/sửa) — chỉ soi phần sửa theo R1–R6 + code mới đổi, không đọc lại toàn bộ.

Đối chiếu: `docs/architecture/tasks.md` mục T04, `docs/architecture/api-contract.md` §1.3/§1.6/§1.7/§2.2/§3, `docs/architecture/data-model.md` §3.1 `otp_codes`, US-001 (AC8/AC9), `docs/security/audit-2026-09-25.md` S9/S11/S21.

Đã tự chạy lại trong Docker cả 2 vòng (không chỉ tin báo cáo bàn giao):
- Vòng 1 (`5ca6356`): Pint sạch (143 file), Larastan **0 lỗi**, Pest `phpunit.t04.xml` **236 passed (694 assertions)**.
- Vòng 2 (`047bff2`): Pint sạch (145 file), Larastan **0 lỗi**, Pest `phpunit.t04.xml` **247 passed (774 assertions)** — khớp số Dev/coordinator báo.

## Tổng quan (vòng 1)
Phần lõi (`OtpService::verify()` tăng `attempts` nguyên tử trước khi so, consume bằng UPDATE có điều kiện, `LogSmsOtpSender` chỉ bind local/testing và không ghi mã, `OtpMail` `ShouldBeEncrypted`, `ProductionConfigGuard::guardOtpChannels()`) được làm đúng và có test tốt — đọc code thấy rõ tinh thần S9 (không tin đọc-rồi-so, luôn dùng UPDATE có điều kiện làm nguồn sự thật). Comment giải thích lý do (không chỉ chép lại code) đúng phong cách dự án. Tuy nhiên có 1 lỗ hổng thật: `PUT /auth/contact` không có `throttle` nào và gọi thẳng `OtpService::send()` — hoàn toàn nằm ngoài các giới hạn 60s/5 giờ/10 ngày mà `/auth/otp/send` đang có, tức là bỏ ngỏ đúng đường mà S9 yêu cầu chặn. Ngoài ra một số quyết định Dev tự khai (audit `otp.daily_limit`, FK `cascade` vs `restrictOnDelete`, test 20-verify tuần tự) là đánh đổi hợp lý nhưng cần ghi lại rõ ràng hơn (TODO/tài liệu) thay vì im lặng.

## Phát hiện (vòng 1) — trạng thái sau vòng 2

### R1 [BLOCKER] `PUT /auth/contact` không có rate limit, bỏ qua hoàn toàn giới hạn gửi OTP (S9) — **ĐÃ SỬA, xác nhận vòng 2**
- Vị trí gốc: `backend/routes/api.php:82-84`; `backend/app/Services/Auth/ContactService.php:53-61`; `backend/app/Services/Auth/OtpService.php:36`.
- Xác nhận sửa (`git diff 5ca6356..047bff2`): vá đúng cả 2 hướng đã đề xuất, không chỉ 1:
  1. **Lớp 1 (route):** `PUT /auth/contact` giờ có `->middleware('throttle:otp-send')` (`routes/api.php`), dùng chung định danh user với `/auth/otp/send` — bị khoá bởi cùng bộ đếm cooldown/giờ/ngày.
  2. **Lớp 2 (Service, độc lập với middleware nào gọi tới):** `OtpService::send()` có thêm `assertUnderSendLimits()` — đếm SỐ THẬT `otp_codes` đã tạo cho user trong DB (cooldown 60s, `max_per_hour`, `max_per_day`) và ném `DomainException('TOO_MANY_ATTEMPTS', 429)` nếu vượt; thêm `assertCanSend()` (bỏ qua khi kênh chưa bật, khớp `sendIfChannelEnabled()`) để `ContactService::update()` gọi **TRƯỚC** `DB::transaction()` đổi email/SĐT — **fail-closed**: vượt trần thì không đổi liên hệ, không tạo trạng thái nửa vời.
- Test xác nhận (chạy thật, không chỉ đọc code): `ContactUpdateThrottleTest.php` (mới) — gọi `PUT /auth/contact` đúng `max_per_day` lần (10) với email khác nhau mỗi lần → lần thứ 11 nhận `429`, **email không đổi** (`expect($user->fresh()->email)->toBe($emailBeforeBlocked)`), không tạo thêm `otp_codes`/mail; test riêng cho cooldown 60s giữa 2 lần gọi liên tiếp. Cộng thêm `OtpServiceTest.php` test trực tiếp `assertUnderSendLimits`/`assertCanSend` ở tầng Service (cooldown, giờ, ngày). Đã tự chạy lại toàn bộ — xanh.
- Đánh giá thêm (câu hỏi race của coordinator): `assertUnderSendLimits()` (đếm rồi `INSERT` trong `DB::transaction` riêng, không có khoá) có khoảng hở TOCTOU về lý thuyết — 2 request đồng thời có thể cùng đọc `count() < max` trước khi cả 2 đều insert, vượt trần 1-2 đơn vị. Tuy nhiên **lớp 1 (route `throttle:otp-send`) đã chặn trước** bằng cơ chế đếm nguyên tử của Laravel (`Cache::increment()` — atomic trên Redis, driver mặc định của dự án theo ADR-004) cho MỌI request đi qua 2 route hiện có (`/auth/otp/send`, `PUT /auth/contact`), nên race ở lớp 2 hiện **không thể chạm tới** qua bất kỳ đường nào đang tồn tại — lớp 2 chỉ là lưới an toàn cho *caller tương lai* (T27, T29) lỡ quên gắn `throttle:otp-send`. Vì vậy đây là **NIT**, không cần sửa ngay: nếu muốn triệt để, có thể bọc `assertUnderSendLimits()` + phần tạo mã trong CÙNG 1 `DB::transaction` với `lockForUpdate()` trên dòng mới nhất của user, nhưng không bắt buộc cho T04.

### R2 [SHOULD] Audit `otp.daily_limit` chưa được ghi — **ĐÃ SỬA, xác nhận vòng 2**
- Xác nhận sửa: `AppServiceProvider::auditOnceIfDailyLimitReached()` (mới) — trong chính closure `RateLimiter::for('otp-send'|'otp-verify')`, TRƯỚC khi trả về mảng `Limit[]`, gọi `RateLimiter::tooManyAttempts()` với khoá cache tái tạo đúng công thức nội bộ của `Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter()` (`md5($limiterName.$rawKey)`, đúng vì `ThrottleRequests::$shouldHashKeys` mặc định `true` và dự án không override) — nếu đã chạm trần, `Cache::add(...)` (dedup, TTL 24h) rồi ghi `AuditLogger::log('otp.daily_limit', $user)` đúng 1 lần. **Không** dùng `Limit::response()` — giữ nguyên response 429 mặc định, không phá `ApiExceptionRenderer` như đã phân tích ở vòng 1.
- Test xác nhận: `OtpDailyLimitAuditTest.php` (mới) — test **qua HTTP thật** (không mock, không gọi thẳng `AppServiceProvider`): gửi đủ `max_per_day` lần OTP (send/verify riêng), request thứ N+1 nhận 429 VÀ có đúng 1 bản ghi `audit_logs` action `otp.daily_limit` với `actor_id` đúng user; 2 lần bị chặn tiếp theo trong ngày KHÔNG ghi thêm (dedup). Vì test chạy qua route thật với `ThrottleRequests` thật của Laravel (không mock), đây đồng thời là bài test hồi quy cho chính việc tái tạo khoá cache — nếu 1 bản nâng cấp Laravel sau này đổi cách hash khoá, test này sẽ đỏ ngay, giảm đáng kể rủi ro "coupling ngầm với nội bộ framework" mà cách làm này chấp nhận đánh đổi.

### R3 [SHOULD] `RegistrationService::register()` không bắt lỗi khi gửi OTP sau commit — **ĐÃ SỬA, xác nhận vòng 2**
- Xác nhận sửa: bọc `$this->otpService->send(...)` trong `try/catch (Throwable $e)`, gọi `report($e)` + `Log::warning('otp.send_failed_after_register', ['user_id' => $user->getKey()])` (chỉ log `user_id`, không log mã/PII), không để lỗi này chặn `201`/`Auth::login()`. Đúng hướng đã đề xuất, comment giải thích rõ lý do.

### R4 [SHOULD] `otp_codes.user_id` dùng `restrictOnDelete`, lệch `data-model.md` ("FK users cascade") — **CHƯA SỬA, còn mở**
- Vòng 2 không đụng tới migration hay `docs/architecture/data-model.md`. Không phải lỗi mới, không chặn merge (đã đánh giá ở vòng 1: hướng `restrictOnDelete` hợp lý, chỉ là lệch tài liệu), nhưng cần theo dõi để không bị quên — đề nghị Dev/PO xử lý ở 1 commit tài liệu riêng (đồng bộ `data-model.md` thành `restrictOnDelete` kèm 1 dòng lý do, hoặc đổi migration nếu Architect muốn giữ `cascade`).

### R5 [SHOULD] Thiếu test cho trần giờ/ngày (5/giờ, 10/ngày gửi; 20/ngày verify) — **ĐÃ SỬA, xác nhận vòng 2**
- Xác nhận sửa: `OtpSendTest.php` thêm test HTTP thật cho `max_per_hour`; `OtpServiceTest.php` thêm test tầng Service cho cooldown/`max_per_hour`/`max_per_day` (kèm test "vượt ngày dù chưa chạm giờ" tách bạch 2 trần); `OtpDailyLimitAuditTest.php` thêm test HTTP cho trần ngày của cả `otp-send` và `otp-verify`. Còn thiếu (không bắt buộc, xem "Ghi nhận"): bản test dùng đúng `Http::pool`/nhiều tiến trình cho "20 verify song song" theo đúng chữ DoD — hiện vẫn là test tuần tự (chấp nhận được, xem lại đánh giá vòng 1).

### R6 [SHOULD] `verify()` bỏ qua `channel` khi chọn mã đang hiệu lực — **ĐÃ SỬA theo hướng (b), xác nhận vòng 2**
- Xác nhận sửa: `OtpService::send()` giờ huỷ **MỌI** mã đang hiệu lực của `(user, purpose)` khi phát mã mới, **bất kể `channel`** (bỏ điều kiện `->where('channel', $channel)` khỏi câu UPDATE invalidate) — đảm bảo tại mọi thời điểm chỉ có tối đa 1 mã active cho mỗi `(user, purpose)`, khớp đúng việc `VerifyOtpRequest` không có tham số `channel` để chọn. Đánh đổi được ghi rõ trong docblock: nếu 1 request đổi CẢ email lẫn SĐT (hiếm, chỉ khả thi khi kênh `sms` bật ở local/testing), mã kênh gửi trước bị mã kênh gửi sau huỷ ngay — chấp nhận được vì production MVP chỉ có kênh `email` (không có tình huống 2-kênh-cùng-lúc trong thực tế). Test `send() kenh moi huy ca ma cua kenh khac cung purpose (R6)` xác nhận hành vi.

## Ghi nhận, không chặn merge (giữ nguyên từ vòng 1, vẫn đúng)
- Test "20 verify song song" tuần tự (không `Http::pool`) vẫn đủ chứng minh tính đúng của UPDATE có điều kiện nhờ khoá hàng InnoDB — không bắt buộc sửa.
- `random_int(100000, 999999)` không cần pad, vùng giá trị hẹp hơn ~10% — không đáng kể.
- `PUT /auth/contact` không cần captcha (đúng api-contract) — nay đã có `throttle:otp-send` (R1) nên không còn là điểm hở.
- `ContactService` ngoài danh sách §3 api-contract — đề nghị bổ sung tài liệu (không chặn merge).
- 429 của `throttle:otp-verify`/`otp-send` giữ `Retry-After` — đã xác nhận qua test, cần báo `nextjs-dev`/FW1-OTP vì giả định #4 của FW1 sai (tưởng không có thời điểm mở khoá).
- Không tìm thấy chỗ nào log/response/exception lộ mã OTP dạng rõ.

## Đối chiếu acceptance criteria (US-001 AC8/AC9) & DoD T04 — cập nhật sau vòng 2

| Hạng mục | Code đáp ứng | Ghi chú |
|---|---|---|
| AC8: nhập đúng OTP trong hạn → `*_verified_at` được ghi | Có | Không đổi so với vòng 1 |
| AC9: chưa xác thực chặn checkout, có nút gửi lại OTP | Một phần | Middleware `account.verified` chưa gắn route (đúng phạm vi T04, thuộc T18) |
| `otp_codes`, `OtpService` (random_int, attempts nguyên tử, consume UPDATE có điều kiện) | Có | Không đổi |
| Trần 5/phút, 20/ngày verify; ≤ 10 mã/ngày | **Có** | R1 + R5 đã sửa: `PUT /auth/contact` nay bị chặn đúng trần (2 lớp), có test HTTP cho giờ/ngày |
| Kênh theo `auth.otp.channels` (production chỉ email) | Có | Không đổi |
| `LogSmsOtpSender` chỉ bind local/testing, ghi `***` | Có | Không đổi |
| `OtpMail` `ShouldBeEncrypted` (S21) | Có | Không đổi |
| `PUT /auth/contact` huỷ mã cũ + reset verified, có trần gửi (S9) | **Có** | R1, R6 đã sửa |
| Middleware `account.verified` | Có | Chưa gắn route (đúng phạm vi T04) |
| Test 20 verify song song, grep log không có mã 6 số | Một phần | Như vòng 1 (không chặn merge) |
| Ghi `audit_logs` `otp.daily_limit` khi vượt trần ngày | **Có** | R2 đã sửa, có test HTTP thật xác nhận |

**Còn mở, không chặn merge:** R4 (đồng bộ tài liệu FK `otp_codes.user_id`).

## Gợi ý cho QA
- R1 đã vá và có test tự động (`ContactUpdateThrottleTest`), nhưng nên tay-test lại 1 lần trên môi trường gần giống thật (Redis thật, không `RateLimiter::for` bị fake) để chắc chắn 2 lớp throttle (route + Service) không xung đột nhau khi vượt trần ở các mốc lệch nhau (vd lớp Service chặn trước lớp route do đếm theo cách khác — cả 2 đều phải trả 429 nhất quán).
- Test lại toàn bộ luồng đăng ký → đổi liên hệ → xác thực (end-to-end) 1 lần bằng tay qua Mailpit, xác nhận số lượng email nhận được khớp đúng số lần bấm gửi, không có email "rơi rớt" do R6 (huỷ mã cả 2 kênh khi đổi cả email+SĐT).
- Theo dõi R4 (FK cascade/restrictOnDelete) được xử lý ở 1 commit tài liệu riêng trước khi T34 (xoá tài khoản) bắt đầu — không cần chặn QA giai đoạn 1.
- Không cần lo lại các mục đã PASS ở vòng 1 (verify/attempts/log OTP) — không có gì đổi ở vòng 2 ngoài phần throttle/audit/register/verify-channel.
