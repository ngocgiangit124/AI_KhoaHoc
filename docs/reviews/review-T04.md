# REVIEW: T04 — OTP (xác thực tài khoản, đổi liên hệ)
**Kết luận:** REQUEST CHANGES
**Phạm vi:** `git diff claude/zen-dirac-fmucf7...claude/zen-dirac-fmucf7-t04` (commit `5ca6356`, worktree `.claude/worktrees/t04`) · 29 file (1587 dòng thêm)

Đối chiếu: `docs/architecture/tasks.md` mục T04, `docs/architecture/api-contract.md` §1.3/§1.6/§1.7/§2.2/§3, `docs/architecture/data-model.md` §3.1 `otp_codes`, US-001 (AC8/AC9), `docs/security/audit-2026-09-25.md` S9/S11/S21.

Đã tự chạy lại trong Docker (không chỉ tin báo cáo bàn giao): `vendor/bin/pint --test` sạch (143 file), `vendor/bin/phpstan analyse` **0 lỗi**, `vendor/bin/pest -c phpunit.t04.xml` **236 passed (694 assertions)** — khớp số Dev báo.

## Tổng quan
Phần lõi (`OtpService::verify()` tăng `attempts` nguyên tử trước khi so, consume bằng UPDATE có điều kiện, `LogSmsOtpSender` chỉ bind local/testing và không ghi mã, `OtpMail` `ShouldBeEncrypted`, `ProductionConfigGuard::guardOtpChannels()`) được làm đúng và có test tốt — đọc code thấy rõ tinh thần S9 (không tin đọc-rồi-so, luôn dùng UPDATE có điều kiện làm nguồn sự thật). Comment giải thích lý do (không chỉ chép lại code) đúng phong cách dự án. Tuy nhiên có 1 lỗ hổng thật: `PUT /auth/contact` không có `throttle` nào và gọi thẳng `OtpService::send()` — hoàn toàn nằm ngoài các giới hạn 60s/5 giờ/10 ngày mà `/auth/otp/send` đang có, tức là bỏ ngỏ đúng đường mà S9 yêu cầu chặn. Ngoài ra một số quyết định Dev tự khai (audit `otp.daily_limit`, FK `cascade` vs `restrictOnDelete`, test 20-verify tuần tự) là đánh đổi hợp lý nhưng cần ghi lại rõ ràng hơn (TODO/tài liệu) thay vì im lặng.

## Phát hiện

### R1 [BLOCKER] `PUT /auth/contact` không có rate limit, bỏ qua hoàn toàn giới hạn gửi OTP (S9)
- Vị trí: `backend/routes/api.php:82-84` (route `api.auth.contact.update`, không có `->middleware('throttle:...')` nào ngoài nhóm `student` chuẩn); `backend/app/Services/Auth/ContactService.php:53-61` (`sendIfChannelEnabled`) gọi thẳng `OtpService::send()` (`backend/app/Services/Auth/OtpService.php:36`).
- Vấn đề: `RateLimiter::for('otp-send')` (cooldown 60s, ≤5/giờ, ≤10/ngày/user — api-contract §1.6, data-model §3.1) chỉ được gắn ở tầng HTTP middleware của route `POST /auth/otp/send`. `OtpService::send()` tự nó **không có bất kỳ giới hạn nội tại nào** (không đếm số lần gửi trong ngày/giờ) — nó tin tưởng hoàn toàn vào middleware `throttle:otp-send` của route gọi nó. `ContactController::update` → `ContactService::update()` → `OtpService::sendIfChannelEnabled()` đi thẳng vào `send()` mà **không qua route nào có throttle**. Vì `PUT /auth/contact` cũng không tự có `throttle:` riêng, một học sinh đã đăng nhập có thể gọi endpoint này liên tục (không giới hạn tốc độ) với email/SĐT khác nhau mỗi lần → mỗi lần đều tạo `otp_codes` mới + `Mail::to($destination)->queue(new OtpMail($code))` thật, bất kể đã gửi bao nhiêu lần trong ngày. Vì unique constraint chỉ cấm 2 *user khác nhau* trùng email, một user có thể đổi qua đổi lại giữa email của chính mình và một email nạn nhân bất kỳ (đổi sang X → nhận OTP tại X → đổi khỏi X → đổi lại sang X → nhận OTP nữa...) để gửi OTP liên tục đến hộp thư người khác (email bombing), hoàn toàn không bị chặn — đúng chính lỗ hổng "thiếu trần theo ngày" mà S9 yêu cầu vá, chỉ là đi qua một cửa khác so với `/auth/otp/send`.
- Đề xuất (chọn 1 hoặc cả 2, ưu tiên cả 2 — phòng thủ 2 lớp):
  1. Gắn `throttle:otp-send` (hoặc limiter tương đương) lên route `PUT /auth/contact`:
     ```php
     Route::put('/auth/contact', [ContactController::class, 'update'])
         ->middleware('throttle:otp-send')
         ->name('api.auth.contact.update');
     ```
  2. Đưa giới hạn giờ/ngày vào **bên trong** `OtpService::send()` (không chỉ ở middleware route) để bất kỳ caller nào sau này (T27, T29...) cũng không thể vô tình bỏ qua trần — ví dụ đếm số `otp_codes` đã tạo cho `(user_id, purpose)` trong 1 giờ/1 ngày gần nhất trước khi tạo mã mới, ném `DomainException('TOO_MANY_ATTEMPTS', ..., 429)` nếu vượt.
- Cần test: gọi `PUT /auth/contact` liên tục > 10 lần/ngày với email khác nhau mỗi lần → từ lần thứ 11 phải bị chặn (429 hoặc tương đương), không được tạo thêm `otp_codes`/queue thêm mail.

### R2 [SHOULD] Audit `otp.daily_limit` (data-model §3.1) chưa được ghi, và chưa có TODO đánh dấu trong code
- Vị trí: `backend/app/Providers/AppServiceProvider.php:126-144` (`RateLimiter::for('otp-send'|'otp-verify')`, không có `Limit::response()`); không có lệnh gọi `AuditLogger` nào trong `OtpService`/`OtpController`/`AppServiceProvider` liên quan tới việc vượt trần ngày.
- Vấn đề: data-model §3.1 ghi rõ "vượt trần ngày → khoá xác thực 24h **+ ghi `audit_logs`**", và `otp.daily_limit` có mặt trong danh sách `action` chuẩn của `audit_logs`. Việc khoá 24h tự động có (nhờ cơ chế cửa sổ trượt `Limit::perDay()` của Laravel), nhưng phần ghi audit thì hoàn toàn vắng mặt — không có dòng log/TODO nào đánh dấu việc này còn thiếu. Đúng như Dev tự nhận: cách "hiển nhiên" (`Limit::response()`) sẽ làm middleware bọc response tuỳ biến vào `Illuminate\Http\Exceptions\HttpResponseException`, exception này **không** implement `HttpExceptionInterface` nên rơi vào nhánh mặc định `[500, 'INTERNAL_ERROR', ...]` của `ApiExceptionRenderer::resolve()` (`backend/app/Support/ApiExceptionRenderer.php:73`) — xác nhận đúng, đây là xung đột kiến trúc thật, không phải cái cớ.
- Đề xuất cách an toàn (không đổi response 429 hiện có): tách việc "đếm/kiểm trần ngày" ra khỏi `RateLimiter::for()` closure build-limit, và thay vào đó thêm 1 bước kiểm tra + ghi audit **trước** khi build danh sách `Limit`, ví dụ trong chính closure `otp-verify`/`otp-send`:
  ```php
  RateLimiter::for('otp-verify', function (Request $request) {
      $identity = $this->identity($request);
      $dayKey = 'otp-verify-day:'.$identity;
      $max = (int) config('auth.otp.max_verify_per_day');

      // Ghi audit đúng 1 lần khi CHẠM trần (không ghi lại ở các lần bị chặn tiếp theo
      // trong cùng ngày) — Cache::add trả false nếu key đã tồn tại.
      if (app(\Illuminate\Cache\RateLimiter::class)->tooManyAttempts($dayKey, $max)
          && Cache::add('otp-verify-day-audit:'.$identity, true, now()->addDay())) {
          app(AuditLogger::class)->log('otp.daily_limit', $request->user());
      }

      return [
          Limit::perMinute(...)->by('otp-verify:'.$identity),
          Limit::perDay($max)->by($dayKey),   // response mặc định — KHÔNG dùng ->response()
          Limit::perHour(60)->by('otp-verify-ip:'.$request->ip()),
      ];
  });
  ```
  Cách này không đổi hình dạng response 429 (không có `HttpResponseException`), chỉ thêm side-effect ghi audit khi phát hiện đã chạm trần. Nếu thấy cách này không đủ sạch, tối thiểu nên để lại `// TODO(T04 hoặc task sau): ghi audit otp.daily_limit — Limit::response() vỡ ApiExceptionRenderer (xem review-T04.md R2)` ngay tại `AppServiceProvider` để không bị quên.

### R3 [SHOULD] `RegistrationService::register()` gọi `OtpService::send()` sau khi đã commit transaction, không bắt lỗi
- Vị trí: `backend/app/Services/Auth/RegistrationService.php:106-109`.
- Vấn đề: `$this->otpService->send(...)` chạy **sau** `DB::transaction()` đã commit user, không có `try/catch`. Nếu bước này ném lỗi (ví dụ push job vào queue thất bại vì Redis/DB tạm gián đoạn), `register()` ném exception, `RegisterController` không kịp chạy `Auth::login()`/trả `201` → client nhận `500`, nhưng **user đã được tạo thật trong DB** (active, có mật khẩu). Học sinh không được tự động đăng nhập, không nhận được OTP, và không thể đăng ký lại (email/SĐT đã bị coi là trùng). Có đường tự phục hồi (đăng nhập lại bằng mật khẩu đã đặt, sau đó tự bấm "Gửi lại OTP") nên không phải mất dữ liệu, nhưng trải nghiệm là "đăng ký lỗi 500" trong khi tài khoản đã tồn tại — dễ gây báo cáo lỗi sai và học sinh không biết phải đăng nhập lại.
- Đề xuất: bọc gọi `send()` trong `try/catch`, log cảnh báo (`Log::warning`, không log mã/PII) và **không** để lỗi này chặn phản hồi 201 — tài khoản đã tạo hợp lệ, học sinh vẫn cần được đăng nhập + trả kết quả đăng ký thành công, chỉ là chưa có OTP (FE vẫn hiển thị lời nhắc xác thực + nút gửi lại theo AC9):
  ```php
  try {
      $this->otpService->send($user, OtpPurpose::VerifyAccount, 'email');
  } catch (\Throwable $e) {
      report($e); // hoặc Log::warning('otp.send_failed_after_register', ['user_id' => $user->id])
  }
  ```

### R4 [SHOULD] `otp_codes.user_id` dùng `restrictOnDelete`, lệch với `data-model.md` §3.1 ghi "FK users cascade"
- Vị trí: `backend/database/migrations/2026_09_29_000000_create_otp_codes_table.php:18-19`; đối chiếu `docs/architecture/data-model.md` dòng "otp_codes... user_id ... FK users cascade".
- Vấn đề: Tài liệu data-model ghi rõ ràng `cascade`, code hiện tại dùng `restrictOnDelete`. Lý do Dev nêu (chưa có xoá cứng tài khoản, US-018 dự kiến ẩn danh hoá chứ không xoá) hợp lý và có thể còn AN TOÀN HƠN cascade (tránh mất dấu vết nếu lỡ có thao tác xoá cứng ngoài ý muốn), nhưng đây là một quyết định lệch tài liệu kiến trúc đã chốt — không nên tự quyết một chiều dù hướng đi đúng.
- Đề xuất: cập nhật `data-model.md` §3.1 thành `restrictOnDelete` (khớp code, kèm 1 dòng lý do) trong cùng PR, hoặc xin xác nhận Architect nếu muốn giữ đúng "cascade" như tài liệu gốc. Không chặn merge nếu tài liệu được đồng bộ.

### R5 [SHOULD] Thiếu test cho trần theo giờ/ngày (5/giờ, 10/ngày gửi; 20/ngày verify) — chỉ có test cooldown 60s và 5 lần/phút
- Vị trí: `backend/tests/Feature/T04/OtpSendTest.php`, `backend/tests/Feature/T04/OtpVerifyTest.php`.
- Vấn đề: tasks.md T04 ghi rõ "Trần 5/phút, 20/ngày verify; ≤ 10 mã/ngày" là 1 hạng mục DoD. Test hiện có chỉ phủ: cooldown 60s (`OtpSendTest`) và 5 lần/phút verify (`OtpVerifyTest`). Không có test nào chứng minh `max_per_hour=5`, `max_per_day=10` (gửi) hay `max_verify_per_day=20` thực sự có hiệu lực qua HTTP. Vì `Limit::perDay()`/`perHour()` của Laravel chỉ đếm số lần gọi trong cửa sổ (không cần đợi thời gian thật trôi qua), việc test này khả thi bằng vòng lặp gọi liên tiếp (kèm `Carbon::setTestNow()` nhích qua 60s mỗi lần để né cooldown, vẫn trong cùng ngày).
- Đề xuất: thêm ít nhất 1 test cho mỗi trần còn thiếu (giờ/ngày gửi, ngày verify), khẳng định lần vượt trần trả `429 TOO_MANY_ATTEMPTS`.

### R6 [SHOULD] `OtpService::verify()` bỏ qua `channel` khi chọn mã "đang hiệu lực" — có thể chọn nhầm khi 2 kênh cùng có mã đang chờ
- Vị trí: `backend/app/Services/Auth/OtpService.php:78-84` (`verify()` chỉ `where('user_id')->where('purpose')`, không lọc `channel`, rồi `latest('id')->first()`).
- Vấn đề: `send()`/`sendIfChannelEnabled()` chỉ huỷ (`invalidated_at`) các mã **cùng channel** (`ContactService::update()` có thể gọi `sendIfChannelEnabled` cho cả `email` lẫn `sms` trong cùng 1 request khi đổi cả email lẫn SĐT). Nếu tại một thời điểm có 2 mã đang hiệu lực cho cùng `(user, purpose)` nhưng khác `channel` (ví dụ: mã email lúc đăng ký chưa xác thực + mã SMS mới sinh do vừa đổi SĐT), `verify()` chỉ xét mã **mới nhất theo `id`** — mã còn lại (dù vẫn hợp lệ) sẽ luôn báo "mã không đúng" cho tới khi người dùng chủ động gọi lại `/auth/otp/send` cho đúng kênh đó. Không phải lỗ hổng bảo mật (không làm lộ gì, chỉ là UX khó hiểu: "tôi nhập đúng mã trong email nhưng vẫn báo sai"), nhưng đáng lưu ý vì `VerifyOtpRequest` không có tham số `channel` để người dùng tự chọn.
- Đề xuất: hoặc (a) tài liệu hoá rõ ràng đây là giới hạn thiết kế đã biết (chỉ 1 luồng xác thực "đang hoạt động" tại 1 thời điểm cho mỗi purpose, người dùng phải bấm "gửi lại mã" đúng kênh cần xác thực) và báo cho FW1/QA biết để UX xử lý đúng, hoặc (b) đổi `send()` để khi phát mã mới, huỷ **mọi** mã đang hiệu lực của `(user, purpose)` bất kể channel (đơn giản hoá, tránh nhầm lẫn — nhưng cần xác nhận không phá luồng đăng ký-rồi-đổi-liên-hệ nào khác).

### Ghi nhận, không chặn merge
- Test "20 verify song song" (`OtpServiceTest.php` — vòng lặp 20 lần gọi `OtpService::verify()` tuần tự trong 1 process, không `Http::pool`/nhiều process) **đủ chứng minh tính đúng đắn của câu UPDATE có điều kiện** (`attempts < 5`, khoá hàng InnoDB tự nhiên tuần tự hoá mọi UPDATE cùng 1 dòng, kể cả khi tới từ nhiều connection thật) — tôi đồng ý với lý do Dev nêu, đây không phải rủi ro thật. Tuy vậy DoD (tasks.md) ghi cụ thể "`Http::pool` vào server test hoặc nhiều process" — nên bổ sung 1 test HTTP thật qua `Http::pool`/tiến trình riêng để khớp chữ nghĩa DoD, dù không bắt buộc để APPROVE (SHOULD nhẹ, gộp cùng R5 nếu muốn làm 1 lần).
- `random_int(100000, 999999)` (không phải `random_int(0, 999999)` + pad theo gợi ý trong S9) → luôn đủ 6 chữ số, không cần `str_pad`, nhưng vùng giá trị hẹp hơn ~10% (900.000 thay vì 1.000.000 khả năng). Không đáng kể về bảo mật (giới hạn 5 lần/mã mới là lớp chặn chính), không cần sửa.
- `Route::put('/auth/contact')` không yêu cầu captcha — đúng theo api-contract (route đã yêu cầu `auth:sanctum`, không có `captcha_token` trong bảng request của §2.2) — không phải lỗi, nhưng càng lý do để phải có throttle (R1) vì không còn lớp chặn nào khác ngoài đăng nhập.
- `ContactService` không có trong danh sách `Services/Auth/` ở api-contract §3 — hợp lý về mặt tách logic (controller mỏng, OTP thuần tuý ở `OtpService`), đề nghị bổ sung tên Service này vào api-contract §3 trong lần cập nhật tài liệu tới cho khớp thực tế (không chặn merge).
- Đã xác nhận qua `OtpVerifyTest` rằng phản hồi 429 của `throttle:otp-verify` giữ được header `Retry-After` (`ApiExceptionRenderer` giữ header của mọi `HttpExceptionInterface`) — đúng như Dev khai ở mục #9, ngược với giả định #4 của FW1 ("429 khoá 24h không có thời điểm mở khoá"); nên báo lại cho `nextjs-dev`/FW1-OTP để họ dùng `Retry-After` thay vì giả định không có.
- Không tìm thấy chỗ nào log/response/exception lộ mã OTP dạng rõ (đã grep các Service/Controller/Test liên quan; `LogSmsOtpSender` có test riêng khẳng định chỉ ghi `***`).

## Đối chiếu acceptance criteria (US-001 AC8/AC9) & DoD T04

| Hạng mục | Code đáp ứng | Ghi chú |
|---|---|---|
| AC8: nhập đúng OTP trong hạn → `*_verified_at` được ghi, có thể mua khoá học | Có | `OtpController::verify` + `OtpService::verify` + test `OtpVerifyTest` |
| AC9: chưa xác thực chặn checkout, có nút gửi lại OTP | Một phần | Middleware `account.verified` đã có nhưng **chưa gắn route nào** (đúng phạm vi T04 — checkout thuộc T18); `/auth/otp/send` dùng để "gửi lại" hoạt động, có `resend_available_at` |
| `otp_codes`, `OtpService` (random_int, tăng attempts nguyên tử, consume UPDATE có điều kiện) | Có | Đúng mẫu S9; xem R6 về việc `verify()` không lọc `channel` |
| Trần 5/phút, 20/ngày verify; ≤ 10 mã/ngày | Một phần | Limiter khai đúng số ở `AppServiceProvider` nhưng: (1) không test giờ/ngày (R5); (2) `PUT /auth/contact` không đi qua giới hạn này (R1 — BLOCKER) |
| Kênh theo `auth.otp.channels` (production chỉ email, `sms` → 422) | Có | `SendOtpRequest` + `ProductionConfigGuard::guardOtpChannels()` (2 lớp), có test |
| `LogSmsOtpSender` chỉ bind local/testing, ghi `***` | Có | `OtpSenderManager::smsSender()` + test `OtpSenderManagerTest` |
| `OtpMail` `ShouldBeEncrypted` (S21) | Có | `app/Mail/OtpMail.php` |
| `PUT /auth/contact` huỷ mã cũ + reset verified (S9) | Có (nhưng xem R1, R6) | Test `ContactUpdateTest` phủ khá đủ hành vi reset/huỷ, nhưng thiếu throttle |
| Middleware `account.verified` | Có | Chưa gắn route (đúng phạm vi T04) |
| Test 20 verify song song, grep log không có mã 6 số | Một phần | Có test atomic (tuần tự, xem ghi nhận) + test log `***`; thiếu bản `Http::pool`/nhiều-process đúng chữ DoD (không chặn merge) |
| Ghi `audit_logs` `otp.daily_limit` khi vượt trần ngày (data-model §3.1) | Không | R2 — SHOULD, có hướng sửa an toàn cụ thể |

## Gợi ý cho QA
- Ưu tiên kiểm chứng R1 trước khi test các phần khác: gọi `PUT /auth/contact` liên tục nhiều lần trong 1 phút (đổi email lần lượt sang các địa chỉ khác nhau, kể cả toggling qua lại 2 địa chỉ) — hiện tại **không** bị chặn tốc độ, mỗi lần đều gửi mail thật (Mailpit ở local sẽ thấy N email trong vài giây).
- Test kịch bản R6: đăng ký (có mã email đang chờ xác thực) → gọi `PUT /auth/contact` đổi SĐT ngay khi kênh `sms` đang bật (vd local) → thử xác thực bằng mã email cũ → xác nhận có báo lỗi (không phải lộ dữ liệu, chỉ là UX) trước khi coi đây là bug đã biết.
- Test race thật giữa 2 tiến trình gọi `POST /auth/otp/verify` song song (không chỉ tuần tự trong process) để có thêm bằng chứng độc lập ngoài `OtpServiceTest`, đúng tinh thần DoD.
- Sau khi R1 được vá, chạy lại toàn bộ test throttle (`ThrottleTest` T03 + test mới cho `otp-send`/`otp-verify`/`contact`) để chắc chắn không phá vỡ các giới hạn khác.
