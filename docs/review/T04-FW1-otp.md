# REVIEW: T04 (OTP backend + GET /auth/me) và FW1 phần OTP (frontend)
**Kết luận:** APPROVE (0 BLOCKER, 6 SHOULD, 5 NIT)
**Phạm vi:** backend: working tree chưa commit (13 file sửa, ~20 file mới gồm `Services/Auth/Otp/*`, `ContactService`, 3 controller, 3 FormRequest, middleware, mail, `tests/Feature/T04/*`). Frontend: phần OTP đã nằm trong commit `fa936c7` (`OtpInput`, `OtpVerifyForm`, `ChangeContactForm`, `AccountBanner`, `AuthProvider`, `lib/auth/otp.ts|api.ts|flash.ts`), không phải working tree. Đã chạy `docker compose exec -T php composer ci`: pint, phpstan, Pest đều xanh, 300 test pass. Frontend review bằng đọc code, không chạy lại lint/test/build. Chưa có e2e backend + frontend.

## Tổng quan
Phần lõi OTP làm đúng và chặt. Tăng `attempts` bằng UPDATE có điều kiện trước khi so mã, consume bằng UPDATE có điều kiện, `lockForUpdate` hàng `users` để tuần tự hoá việc phát mã, trần đếm từ bảng `otp_codes`, mã chỉ có giá trị với đúng đích đã gửi. Test song song bằng nhiều process thật. Shape backend khớp `lib/auth/api.ts`. Các điểm cần sửa nằm ở luồng biên (đổi SĐT trên production, lỗi gửi mail, `/auth/me` lỗi tạm thời) và UX của trang OTP.

## Phát hiện

### R1 [SHOULD] Đổi chỉ SĐT trên production huỷ mã email đang chờ, không gửi mã mới, nhưng UI báo "đã gửi mã mới"
- Vị trí: `backend/app/Services/Auth/ContactService.php:50-63` (`invalidateAll`) và `:82-90`; `frontend/apps/web/components/auth/OtpVerifyForm.tsx` (`onDone`, notice "…và gửi mã xác thực mới")
- Vấn đề: production chỉ có kênh email. Học sinh vừa đăng ký, mở form "Đổi email/SĐT" và chỉ sửa SĐT. Backend reset `phone_verified_at` và `invalidateAll()` huỷ cả mã email còn hiệu lực. Backend trả `resend_available_at: null` mà không phát mã nào. Frontend vẫn hiện "Đã cập nhật … và gửi mã xác thực mới." và chạy cooldown mặc định. Học sinh nhập mã trong email cũ thì nhận "hết hạn". Bị kẹt cho đến khi bấm gửi lại, mà có thể còn dính cooldown 60s.
- Đề xuất: chỉ huỷ mã của kênh có đích bị đổi (`where channel = 'email'` khi đổi email, `'sms'` khi đổi SĐT). Đổi SĐT riêng lẻ không đụng mã email. Frontend: khi `resendAvailableAt === null` hiện thông điệp "Đã cập nhật thông tin liên hệ" (không nói đã gửi mã) và giữ mã email hiện có.
  ~~~php
  public function invalidateChannel(User $user, string $channel): void
  {
      OtpCode::query()->where('user_id', $user->getKey())->where('channel', $channel)
          ->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
  }
  ~~~
  Cập nhật mô tả contract (dòng PUT /auth/contact) cho khớp.

### R2 [SHOULD] Gửi mã thất bại: 500 sau khi đã commit mã và trừ trần/cooldown, và log mất nguyên nhân thật
- Vị trí: `backend/app/Services/Auth/Otp/OtpService.php:106-112` (`issue`) và `:289-298` (`deliver`)
- Đối chiếu điểm 2: việc nuốt exception ở `deliver()` rồi ném `RuntimeException` mới ở frame không có đối số `$code` là đúng với mục tiêu S9/S21. `$code` chỉ là biến cục bộ của `issue()` và không có trong args của frame nào đang bắt. `issue()` vẫn có `$destination` trong args (email, PII nhẹ) nếu `zend.exception_ignore_args=Off`, nên cân nhắc. Với `OtpMail` là `ShouldQueue` thì lỗi SMTP xảy ra ở worker, và payload mã hoá bởi `ShouldBeEncrypted`. Nhánh `deliver` fail thực tế chỉ xảy ra khi queue/Redis sập. Tuy vậy:
  1. Mã mới đã được ghi và mã cũ đã bị huỷ, trần giờ/ngày và cooldown đã bị trừ. Người dùng nhận 500 chung chung, phải chờ 60s, và mất 1 trong 5 lượt/giờ cho một mã chưa bao giờ được gửi.
  2. Log chỉ có tên class (`Throwable::class`), không có message như "Connection refused", nên Ops không biết nguyên nhân.
- Đề xuất: khi `deliver()` lỗi, đánh dấu mã vừa tạo `invalidated_at` và xoá khỏi phép đếm (hoặc `delete()` hàng đó trong cùng khoá) rồi ném `DomainException('OTP_DELIVERY_FAILED', 'Không gửi được mã, vui lòng thử lại sau.', 503)`. Log kèm `$e->getMessage()` đã `str_replace($code, '******', ...)`. Test khẳng định log không chứa mã vẫn giữ nguyên (đã có).

### R3 [SHOULD] `verifyAccount`: consume mã và ghi `*_verified_at` không cùng một giao dịch
- Vị trí: `OtpService.php:140-152` và `:158-217`
- Vấn đề: sau khi `consume()` thành công (mã đã bị đốt), `forceFill(...)->save()` chạy riêng. Nếu lỗi DB/timeout nằm giữa hai bước, mã bị tiêu mà tài khoản vẫn chưa xác thực (học sinh phải xin mã mới, tốn trần). Ngoài ra kiểm `destinationStillValid` đọc `$user` đã nạp từ đầu request, không dưới khoá; request đổi liên hệ chạy song song có thể xen vào giữa kiểm tra và consume, nên mã gửi tới email cũ vẫn xác thực được email mới (`email_verified_at` ghi theo `$otp->channel` mà không kiểm lại).
- Đề xuất: không bọc cả `consume()` trong transaction (sẽ rollback `attempts` khi mã sai, mất bảo vệ brute-force). Chỉ gói phần "so đúng → consume → ghi verified" trong `DB::transaction`, trong đó `lockForUpdate` user, kiểm lại đích, `UPDATE consumed_at`, rồi cập nhật cột verified. `attempts` tăng ở câu UPDATE ngoài transaction trước đó.

### R4 [SHOULD] FE: `fetchCurrentUser` coi lỗi mạng/5xx/429 là "khách", trang OTP đá người đã đăng nhập sang `/dang-nhap`
- Vị trí: `frontend/apps/web/lib/auth/api.ts` (`fetchCurrentUser`: `if (!res.ok) return null; … catch { return null }`), `AuthProvider.tsx`, `OtpVerifyForm.tsx` (effect redirect khi `guest`)
- Vấn đề: chỉ 401 mới là khách. Một lần `/auth/me` trả 500/502/429 hoặc rớt mạng thì `AuthProvider` đặt `guest`. `OtpVerifyForm` `router.replace('/dang-nhap?next=/xac-thuc-otp')`, người dùng đang ở giữa việc xác thực bị đẩy ra ngoài. `AuthNav` cũng nhấp nháy sang "Đăng nhập/Đăng ký". R3 của review FW1-part1 (`SESSION_REPLACED` bị nuốt) vẫn còn.
- Đề xuất: trả kiểu phân biệt `{kind:'user'|'guest'|'error'}`. Chỉ 401 là `guest`. Với `error`, `AuthProvider` giữ state `error`, trang OTP hiện "Không tải được thông tin tài khoản" kèm nút "Thử lại" thay vì chuyển hướng. 401 có `code === 'SESSION_REPLACED'` thì phát sự kiện của api-client như đã gợi ý ở FW1-part1.

### R5 [SHOULD] FE: vào `/xac-thuc-otp` không có đếm ngược, và 429 không dùng `Retry-After`
- Vị trí: `OtpVerifyForm.tsx` (`resendEndAt` khởi tạo `null`; `onResend` catch dùng `otpErrorMessage`)
- Vấn đề: đăng ký đã phát mã (cooldown 60s bắt đầu). Trang OTP mở ra ngay, nút "Gửi lại mã" bật ngay. Bấm thì nhận 429 (cooldown service và throttle `otp-send` 1/phút), message chung của renderer, không có đếm ngược. 429 `TOO_MANY_ATTEMPTS` khi nhập sai 5 lần cũng không dẫn người dùng tới "Gửi lại mã". Backend đã gửi `Retry-After` nhưng FE không đọc.
- Đề xuất: (a) chọn một trong hai: `GET /auth/me` thêm `otp_resend_available_at` (backend tính từ `max(created_at)+cooldown`), hoặc FE khởi tạo cooldown từ `resendCooldownSeconds` khi vào từ luồng đăng ký (flash). (b) ở `onResend` khi `ApiError.status === 429`, đọc `Retry-After` (nếu `ApiError` chưa lộ header thì thêm vào api-client) và gọi `startCooldown`. Khi `TOO_MANY_ATTEMPTS` của verify, gợi ý và làm nổi bật nút "Gửi lại mã".

### R6 [SHOULD] FE a11y: `aria-describedby` trỏ vào id không tồn tại; mất focus sau lỗi
- Vị trí: `OtpVerifyForm.tsx` (`describedBy={error ? errorId : undefined}`, `<Alert>{error}</Alert>` không nhận `id`); `packages/ui/src/Alert.tsx` không có prop `id`
- Vấn đề: (1) `errorId` không gắn vào phần tử nào, `aria-describedby` của 6 ô là dangling. Alert có `role="alert"` nên vẫn đọc khi hiện, nhưng liên kết sai. (2) Khi `pending` các ô `disabled` nên focus rời đi; sau lỗi `setPending(false)` và `setCode("")` nhưng không trả focus, người dùng bàn phím/screen reader phải tab lại từ đầu. (3) `notice` (success) cũng là `role="alert"` rộng; chấp nhận được.
- Đề xuất: thêm `id` vào `Alert` (spread `...rest` hoặc prop `id`) và truyền `errorId`; sau lỗi gọi focus vào ô đầu (qua `ref` được `forwardRef`/prop `focusKey` của `OtpInput`, hoặc `autoFocus` theo `key` đổi khi reset). Không dùng `disabled` trong lúc gửi mà dùng `readOnly`/`aria-busy` để giữ focus.

### R7 [NIT] `OtpInput`: bấm vào ô phía sau chỗ đã nhập, mã bị ghi sai vị trí
- Vị trí: `packages/ui/src/OtpInput.tsx` `onInput`
- Khi `value.length < i` (người dùng bấm thẳng ô 5 lúc đang trống) thì `value.slice(0,i)+entered` dồn chữ số vào ô 1 trong khi focus nhảy tới ô 6. Dùng `const at = Math.min(i, value.length)` cho cả đoạn ghép và `focusAt(at + entered.length)`. Backspace, ArrowLeft/Right, paste cả mã và autofill một lần vào ô đầu (`maxLength={length}` + `one-time-code`) đều xử lý đúng. Ô tối thiểu h-12 w-11 (44px), 6 ô + gap vừa 375px (6×44+5×8 = 304px).

### R8 [NIT] `ChangeContactForm`
- Gõ số khác dạng nhưng cùng giá trị sau chuẩn hoá (vd `+84912345678` thay `0912345678`) thì server coi là không đổi, trả `null`, UI vẫn báo "đã gửi mã mới" (cùng gốc R1: nên đọc `resend_available_at === null`). Cả hai field gắn `required` dù chỉ cần một. Phần chú thích đầu `otp.ts`/`api.ts` ("contract chưa liệt kê", "định dạng chưa ghi") đã lỗi thời sau khi contract được cập nhật, nên sửa.

### R9 [NIT] Test song song: ghi dữ liệu thật ngoài transaction
- Vị trí: `tests/Feature/T04/OtpConcurrencyTest.php` (`afterEach` xoá toàn bộ `users`, `otp_codes`, `audit_logs`, `consents`; `vvCommittedOtpUser` commit; `worker-verify.php`)
- Đối chiếu điểm 6: dùng DB `vitaminvui_testing` (ép bởi `phpunit.xml`), nên không đụng DB dev; test chạy tuần tự (không paratest) nên không rò dữ liệu sang test khác. Rủi ro còn lại: (a) `delete()` toàn bảng là nguy hiểm nếu sau này bật paratest hoặc ai đó đổi `DB_DATABASE`; (b) nếu process test chết giữa chừng thì dữ liệu còn lại làm test sau vướng unique; (c) mỗi test chờ mốc 3s (3 test ≈ 9s thêm vào CI, chấp nhận được, tổng 36s). Đề xuất: thêm guard `expect(DB::getDatabaseName())->toEndWith('_testing')` trước khi xoá, dọn thêm cả ở `beforeEach`, và giảm mốc xuống 1,5s nếu máy CI đủ nhanh. Worker nhận mật khẩu DB qua env của process con, không in ra, chấp nhận được.

### R10 [NIT] Middleware còn pass-through và route nhóm student
- `account.verified` đã đăng ký alias và test, nhưng chưa gắn vào route nào (đúng, checkout T18/T14 mới dùng, AC9 thuộc T18). `student.single_session` còn pass-through (T05). Ghi rõ vào `docs/board.md` rằng AC9 và AC single-session chưa được thực thi cho tới T14/T18 và T05, để QA không đánh dấu "pass".
- `PUT /auth/contact` cho tài khoản đã xác thực đổi email mà không hỏi lại mật khẩu: đã ghi ở `docs/security/backlog-v2.md` T04-1, không tính là lỗi mới.

### R11 [NIT] `flash.ts` / `AuthProvider`
- `flash.ts` viết lại gọn, đọc rồi xoá ngay (Strict Mode hai lần chạy effect: lần 2 đọc null và không ghi đè state, đã chú thích đúng), try/catch quanh `sessionStorage`. OK.
- `AuthProvider` gọi `/auth/me` một lần cho mỗi trang (trang chủ và trang OTP mỗi nơi một provider, nên `verifyOtp` xong `router.replace('/')` làm provider mới gọi lại, không bị state cũ). Có abort khi unmount. `refresh()` không có AbortController nhưng chỉ gọi sau thao tác người dùng. OK.

## Đối chiếu các điểm được yêu cầu soi
| # | Điểm | Kết quả |
|---|---|---|
| 1 | Nguyên tử `consume`/`attempts`, `lockForUpdate` | Đúng thiết kế S9. Tăng `attempts` bằng UPDATE điều kiện (còn hạn, chưa consume/invalidate, `attempts < max`), 0 dòng thì không so. Consume bằng UPDATE điều kiện, đúng 1 request thắng. `issue()` khoá hàng user nên trần giờ/ngày không bị vượt khi song song. Test 20 process pass. Còn R3 (không cùng giao dịch với ghi verified) |
| 2 | Nuốt exception ở `deliver()` | Đúng mục đích chống rò mã qua trace; nhưng che mất nguyên nhân và để lại mã đã commit (R2) |
| 3 | `PUT /auth/contact` | Huỷ mã cũ, reset verified, không áp cooldown 60s, `assertCanSend` kiểm trần trước khi đổi: đúng contract. Lỗi: R1 (SĐT-only), có khe race nhỏ giữa `assertCanSend` và `issue()` (đã ghi chú trong code, chấp nhận) |
| 4 | Backend ↔ FE | `/auth/otp/send` 202 `{resend_available_at}` (ISO 8601 có offset, `Date.parse` đọc được), `/otp/verify` 200 user phẳng (`UserResource`, ép 200), `PUT /auth/contact` 200 `{resend_available_at|null}`, 422 `errors.code` với verify, 429 `TOO_MANY_ATTEMPTS`. Khớp. Điểm lệch: R4, R5 |
| 5 | Middleware pass-through | R10 |
| 6 | Test song song | R9 |
| 7 | Frontend OtpInput, auto-submit, đếm ngược, redirect, a11y | OtpInput tốt (R7). Đếm ngược theo mốc tuyệt đối đúng. Redirect nằm trong `useEffect`, đúng. Tự submit: `pending` guard + `disabled` chống gửi đôi. Lỗi a11y R6, `AuthProvider` R4 |
| 8 | Quy ước | Validation trong FormRequest, controller mỏng, logic trong Service, không `request->all()`, không `env()` ngoài config, `down()` có trong migration, index `(user_id, purpose, created_at)`. `composer ci` xanh |

## Đối chiếu acceptance criteria (US-001)
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 (gửi OTP sau đăng ký, về trang chủ kèm lời nhắc) | `RegistrationService` gọi `sendVerification` (lỗi gửi không làm hỏng đăng ký), `AccountBanner` nhắc xác thực | Cooldown ban đầu chưa hiện ở FE (R5) |
| AC8 (nhập đúng OTP → ghi verified) | `OtpService::verifyAccount`, `UserResource.is_verified`, `OtpVerifyForm` | Cần R3 để chắc chắn tính nguyên tử |
| AC9 (chưa xác thực bị chặn checkout, có nút gửi lại OTP) | Middleware `account.verified` (403 `ACCOUNT_NOT_VERIFIED`) | Chưa gắn route (T14/T18). Nút gửi lại ở trang OTP có |
| Gửi lại có giới hạn, hết hạn/sai nhiều lần | Cooldown 60s, 5/giờ, 10/ngày, 5 lần/mã, verify 5/phút và 20/ngày | Khớp contract |
| Đổi email/SĐT khi chưa xác thực | `PUT /auth/contact` + `ChangeContactForm` | R1, R8 |

## Gợi ý cho QA
- Đổi chỉ SĐT trên production (kênh email only) khi đang có mã email chờ: mã cũ còn dùng được không, UI nói gì (R1).
- Giả lập `/auth/me` 500 hoặc ngắt mạng đúng lúc đang ở `/xac-thuc-otp` (R4).
- Mở `/xac-thuc-otp` ngay sau đăng ký, bấm "Gửi lại mã" liền (R5). Nhập sai 5 lần rồi nhập đúng (429 `TOO_MANY_ATTEMPTS`).
- Verify song song cùng lúc với `PUT /auth/contact` đổi email (R3).
- OtpInput trên iOS Safari/Android Chrome: autofill từ email/SMS, dán 6 số có khoảng trắng, Backspace giữa dãy, bấm ô cuối khi đang trống; màn 375px; screen reader đọc lỗi và nhãn từng ô.
- Mail queue sập (Redis down) lúc gửi OTP: kỳ vọng thông điệp rõ và không bị trừ trần (R2).
- Chạy e2e thật web ↔ api cho luồng đăng ký → OTP → trang chủ có banner "Xác thực thành công"; cả hai phía chưa từng chạy với nhau.
