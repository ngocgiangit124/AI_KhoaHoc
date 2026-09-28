# REVIEW: T03 (Đăng ký/đăng nhập học sinh — backend) + FW1 phần 1 (Đăng ký/Đăng nhập/Đăng xuất — apps/web)

**Kết luận (review lần 1):** REQUEST CHANGES
**Kết luận (review lần 2, sau commit `10c1b82`):** PASS CÓ ĐIỀU KIỆN — xem mục "Review lần 2" ở cuối file. R1 đã sửa đúng và xác minh lại bằng request thật; R2/R3 đã sửa đúng hướng nhưng lộ thêm 1 gap normalization (R7, mới) cần vá trước khi có thêm 2 luồng dùng lại cùng cơ chế (T04 OTP, T27 quên mật khẩu); R6 đã sửa đúng.
**Phạm vi:**
- Backend: thay đổi chưa commit trong `backend/` (working tree so với HEAD `58fa34d`) — `RegisterController`, `LoginController`, `MeController`, `EnsureGuest`, `RegisterRequest`, `LoginRequest`, `UserResource`/`MeResource`, `LoginService`, `RegistrationService`, `PhoneNumber`, `Age`, `ConsentService`, `Consent` (model + migration + factory), `CaptchaVerifier`/`FakeCaptchaVerifier`/`TurnstileVerifier`, `routes/api.php`, `AppServiceProvider`, `bootstrap/app.php`, `UserFactory`, `tests/Feature/T03/*` (8 file test), test kiến trúc T02 đã cập nhật. ~22 file.
- Frontend: commit `f2781ec` so với `7f03516` — `app/dang-ky/*`, `app/dang-nhap/*`, `lib/auth/*`, `lib/validation/*`, `lib/types/auth.ts`, `components/SiteHeader.tsx`, `proxy.ts` (CSP), `packages/api-client/src/authFetch.ts`, `packages/ui` (`PasswordInput`, `Select`, `TextInput`, `TurnstileWidget`). 34 file (gồm test).

**Đã chạy:** `backend && vendor/bin/pint --test` (pass) · `vendor/bin/pest --filter=T03` (42/42 pass) · `frontend && pnpm -r run typecheck` (pass) · `pnpm -r run lint` (pass, 1 warning không liên quan tới đúng/sai — React Compiler bỏ qua memo hoá `watch()` của react-hook-form) · `pnpm -r run test` (tất cả pass, 4 workspace).

## Tổng quan
Code sạch, đúng quy ước dự án: controller mỏng, logic ở Service, `validated()`-only (S17 có test kiến trúc + test "role=admin trong body bị bỏ qua"), thứ tự kiểm đăng nhập đúng S20 (đã verify bằng test + đọc code), throttle 2 lớp đúng S10/S18, captcha fake bị chặn ở production qua `ProductionConfigGuard` (allowlist, không phải blocklist). Migration `consents` khớp đúng `data-model.md` §3.1. Frontend đúng S7 (checkbox không tick sẵn, có test), CSP mở đúng 3 directive cho Turnstile, không secret lộ ra client, `safeRedirect` chặn open-redirect (đã có từ trước, dùng lại đúng chỗ).

Tuy nhiên có **1 lỗi thực sự nghiêm trọng** xuyên suốt backend + frontend: thông điệp lỗi 422 khi sai email/mật khẩu **không phải** là thông điệp cụ thể "Thông tin đăng nhập hoặc mật khẩu không đúng" mà luôn là thông điệp validate chung "Dữ liệu gửi lên không hợp lệ." — vì `ApiExceptionRenderer::resolve()` (đã có từ T01/T02, không sửa ở lần này) hard-code message cho MỌI `ValidationException`, bỏ qua message thật gán bằng `withMessages()`. Đây là lần đầu tiên pattern này được dùng cho một message có ý nghĩa nghiệp vụ (khác các lỗi field trước đó chỉ hiển thị dưới field), và không ai trong T03 lẫn FW1 phát hiện — kể cả test FE còn "giả định" backend trả đúng message, che khuất bug này. Xem R1.

## Phát hiện

### R1 [BLOCKER] Banner đăng nhập sai luôn hiện thông điệp chung "Dữ liệu gửi lên không hợp lệ." thay vì thông điệp cụ thể
- Vị trí: `backend/app/Support/ApiExceptionRenderer.php:65-69` (hàm `resolve()`, nhánh `ValidationException`) + `backend/app/Services/Auth/LoginService.php:63-68` (`genericFailure()`) + `frontend/apps/web/app/dang-nhap/LoginForm.tsx:55-58` (banner dùng `err.message`).
- Vấn đề: `LoginService::genericFailure()` ném `ValidationException::withMessages(['login' => ['Thông tin đăng nhập hoặc mật khẩu không đúng.']])` đúng theo BR5/S20 (thông điệp chung, không tiết lộ tài khoản tồn tại hay không). Nhưng `ApiExceptionRenderer::resolve()` không đọc `$e->getMessage()`/`$e->errors()` để suy ra message hiển thị — nó **hard-code** `'Dữ liệu gửi lên không hợp lệ.'` cho mọi `ValidationException`, bất kể nội dung field errors là gì. Tôi đã xác minh trực tiếp bằng request thật (test tạm, đã xoá): body trả về là:
  ```json
  {
    "message": "Dữ liệu gửi lên không hợp lệ.",
    "code": "VALIDATION_ERROR",
    "errors": { "login": ["Thông tin đăng nhập hoặc mật khẩu không đúng."] }
  }
  ```
  `LoginForm.tsx` hiển thị banner bằng `err.message` (top-level) — người dùng thấy "Dữ liệu gửi lên không hợp lệ." thay vì thông điệp có ý nghĩa nghiệp vụ, gây hiểu lầm nghiêm trọng (tưởng lỗi định dạng form, không phải sai thông tin đăng nhập). Điều này vi phạm chính AC4/US-001 (thông điệp phải rõ ràng, dù "chung" theo nghĩa không phân biệt tài khoản tồn tại hay không — không phải "chung chung vô nghĩa").
  Bằng chứng frontend cũng "giả định sai" hành vi backend: `apps/web/app/dang-nhap/LoginForm.test.tsx:61-75` mock `new ApiError(422, { message: "Thông tin đăng nhập hoặc mật khẩu không đúng" })` (không có `errors`) — khác hẳn response backend thật ở trên (có `errors.login`, message top-level khác) — nên test xanh nhưng không phản ánh tích hợp thật.
  Không phải lỗi mới do diff T03/FW1 gây ra (file `ApiExceptionRenderer.php` không nằm trong diff, đã có từ T01/T02, đã PASS review lúc đó) — nhưng T03 là nơi ĐẦU TIÊN dựa vào message cụ thể của `ValidationException` để truyền ý nghĩa nghiệp vụ ra ngoài, và không ai bắt được sự lệch pha này trước khi merge 2 phía.
- Đề xuất: Sửa `ApiExceptionRenderer::resolve()` để dùng message thật của exception khi có (ví dụ lấy message đầu tiên của lỗi đầu tiên, hoặc gọi `$e->getMessage()` nếu khác message mặc định của Laravel `"The given data was invalid."`):
  ```php
  if ($e instanceof ValidationException) {
      $message = $e->getMessage();
      $isDefault = $message === 'The given data was invalid.' || $message === '';
      return [422, 'VALIDATION_ERROR', $isDefault ? 'Dữ liệu gửi lên không hợp lệ.' : $message, $e->errors()];
  }
  ```
  Sau đó thêm lại assertion `assertJson(['message' => 'Thông tin đăng nhập hoặc mật khẩu không đúng.'])` vào `LoginTest.php` (AC4) để khoá hành vi này, và sửa mock trong `LoginForm.test.tsx` để phản ánh đúng cả `errors.login` như response thật (test hiện tại không phát hiện được vì mock quá đơn giản).
  Việc sửa `ApiExceptionRenderer` ảnh hưởng MỌI response 422 khác (đăng ký, checkout...) — cần rà lại các chỗ khác có đang dựa vào message chung "Dữ liệu gửi lên không hợp lệ." hay không trước khi đổi (có thể T03 dev không sở hữu file này — nên phối hợp/basso T01-T02 hoặc xin duyệt sửa chung).

### R2 [SHOULD] `throttle:login` theo tài khoản đếm cả lần đăng nhập ĐÚNG, không chỉ lần sai — sai với văn bản api-contract §1.6
- Vị trí: `backend/app/Providers/AppServiceProvider.php:91-96` (`RateLimiter::for('login', ...)`), không đổi trong diff T03 nhưng lần đầu được exercise thật qua `routes/api.php` (mới thêm `throttle:login` cho `/auth/login`).
- Vấn đề: api-contract §1.6 ghi `login (cả 2 host) | 10 lần sai/giờ/login`. Middleware `ThrottleRequests` mặc định của Laravel tăng bộ đếm ở MỌI request đi qua (`increment()` chạy trước khi vào controller), không phân biệt đăng nhập thành công hay thất bại. Một học sinh đăng nhập đúng > 10 lần/giờ (ví dụ mở nhiều tab/thiết bị hợp lệ trước khi T05 áp "1 thiết bị/1 phiên") sẽ bị khoá `TOO_MANY_ATTEMPTS` dù không có hành vi dò mật khẩu nào. `ThrottleTest.php` chỉ test kịch bản toàn sai mật khẩu, không có test cho đăng nhập đúng lặp lại → gap không bị phát hiện.
- Đề xuất: Chỉ tăng bộ đếm khi xác thực thất bại — ví dụ không dùng `throttle:login` middleware cho phần "theo tài khoản" mà tự gọi `RateLimiter::hit()` trong `LoginService::genericFailure()`/khi bắt `ACCOUNT_LOCKED` bằng key `login:<email>`, và chỉ giữ `throttle:login` middleware cho lớp IP (không phụ thuộc input). Hoặc tách 2 limiter riêng: 1 cho IP qua middleware (luôn đếm), 1 cho account chỉ đếm khi login thất bại (gọi thủ công trong Service, kiểm `RateLimiter::tooManyAttempts()` trước, `hit()` sau khi fail, `clear()` khi thành công). Cần thống nhất với Architect/Security vì đổi cách áp throttle (S10).

### R3 [SHOULD] Thiếu test khẳng định `role`/`status`/đăng nhập đúng KHÔNG bị khoá lẫn nhau khi throttle theo tài khoản dùng chung key với đăng ký sai
- Vị trí: `backend/tests/Feature/T03/ThrottleTest.php`.
- Vấn đề: liên quan trực tiếp R2 — chưa có test "đăng nhập đúng nhiều lần liên tiếp không bị 429" để khẳng định ranh giới hành vi mong muốn trước khi sửa R2. Việc thiếu test này là lý do R2 lọt qua.
- Đề xuất: thêm test kiểu `test('dang nhap dung nhieu lan lien tiep KHONG bi throttle (chi dem lan sai)', ...)` — hiện sẽ FAIL, chứng minh rõ R2, và sẽ PASS sau khi Dev sửa theo R2.

### R4 [NIT] `EnsureGuest` dùng mã lỗi `FORBIDDEN` cho "đã đăng nhập mà gọi lại /auth/login" — chấp nhận được nhưng nên xác nhận với Architect
- Vị trí: `backend/app/Http/Middleware/EnsureGuest.php:17-30`.
- Vấn đề: Dev tự nhận trong comment đây là lựa chọn khi hợp đồng chưa định nghĩa mã riêng. `FORBIDDEN` (403, "Policy từ chối" theo bảng api-contract §1.7) hơi lệch ngữ nghĩa (đây không phải do Policy từ chối quyền, mà do trạng thái guest-only), nhưng dùng lại mã có sẵn thay vì bịa mã mới là đúng tinh thần "không tự bịa field ngoài api-contract" trong `docs/board.md`. Không chặn merge — chỉ cần Dev xác nhận với Architect (đã tự đánh dấu trong code, chỉ cần đảm bảo có ai theo dõi việc này, không rơi vào quên).
- Đề xuất: Không bắt buộc sửa. Nếu Architect muốn mã riêng (`GUEST_ONLY`?) thì cập nhật `api-contract.md` §1.7 trước khi đổi code.

### R5 [NIT] `parent_phone_masked`/`parent_email_masked` là tên field Dev tự đặt, chưa vào api-contract
- Vị trí: `backend/app/Http/Resources/Auth/MeResource.php:30-31`; đối chiếu `docs/architecture/api-contract.md` §2.2 (`GET /auth/me` → "user + is_verified + parent_consent_status + cart_count + thông tin phụ huynh đã che", không liệt kê tên field cụ thể).
- Vấn đề: Hợp đồng không sai (Dev không bịa NGOÀI những gì được yêu cầu — "thông tin phụ huynh đã che" đúng là cái được yêu cầu), nhưng đặt tên field là quyết định 1 phía, rủi ro nếu FW1 (frontend) tự đoán tên khác. Đã kiểm: `frontend/apps/web/lib/types/auth.ts` **hiện chưa khai báo** `parent_phone_masked`/`parent_email_masked` trong `authUserSchema` (chỉ có `.passthrough()` nên không vỡ, nhưng phần UI hiển thị "phụ huynh đã xác nhận/đang chờ" ở FW1 phần 1 vẫn chưa dùng field này — có thể vì ngoài phạm vi FW1 phần 1, chỉ cần `parent_consent_status`). Không phải BLOCKER vì FW1 phần 1 (chỉ đăng ký/đăng nhập/đăng xuất) không cần hiển thị field này.
- Đề xuất: Khi có story dùng tới (ví dụ màn "tài khoản của tôi" hiển thị liên hệ phụ huynh), chốt tên field này vào `api-contract.md` §2.2 trước, tránh 2 team đoán khác nhau lần nữa.

### R6 [NIT] `PhoneNumber` chỉ chấp nhận đầu số di động (03/05/07/08/09) — khớp story nhưng chưa test đầu số cố định bị từ chối rõ ràng
- Vị trí: `backend/app/Services/Auth/PhoneNumber.php:55-58`; `backend/tests/Feature/T03/PhoneNumberTest.php`.
- Đã đọc `PhoneNumberTest.php` — có test biên dạng hợp lệ (`+84`, `84`, `0xxxxxxxxx`) nhưng nên thêm 1 test số cố định (đầu 02x) bị từ chối để khoá đúng phạm vi US-001 (chỉ SĐT di động), phòng khi ai đó nới lỏng pattern sau này mà không để ý phá quy tắc.
- Đề xuất: thêm `test('so co dinh (dau 02x) bi tu choi', ...)`.

## Đối chiếu acceptance criteria (US-001, api-contract §2.2)

| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1: Đăng ký tạo tài khoản `hoc_sinh`, tự đăng nhập | ✅ | `RegisterTest.php` AC1 pass, kiểm cả 2 bản ghi `consents` |
| AC2: Email/SĐT trùng → 422 đúng field, kể cả race condition | ✅ | `RegistrationServiceTest`/`RegisterTest` + `translateUniqueViolation()` bắt `QueryException 23000` |
| AC3: Đăng nhập bằng email hoặc SĐT (kể cả định dạng `+84`) | ✅ | `LoginTest.php` |
| AC4: Sai thông tin → 422 thông điệp chung, không phân biệt tài khoản tồn tại | ⚠️ Có lỗi hiển thị | Logic backend đúng (cùng field, cùng status), nhưng **message top-level sai** — xem R1 |
| AC5: Mật khẩu xác nhận không khớp → 422 field `password` | ✅ | |
| AC7: Mã giới thiệu lưu kèm tài khoản khi bật flag | ✅ | |
| AC10: Dưới ngưỡng tuổi thiếu liên hệ phụ huynh → 422; đủ 1 trong 2 thì `pending` | ✅ | Test biên 17 năm 364 ngày / đúng 18 tuổi |
| S17: `role/status/*_verified_at` không mass-assign được | ✅ | Test riêng "role=admin trong body bị bỏ qua" + `$fillable` chặt + `forceCreate` tường minh |
| S20: thứ tự sai mật khẩu → khoá → vai trò sai | ✅ | 3 test riêng đúng thứ tự, không lộ `ACCOUNT_LOCKED` khi sai mật khẩu |
| S10/S18: throttle 2 lớp (tài khoản + IP) | ⚠️ | Đúng cấu trúc 2 lớp, nhưng lớp tài khoản đếm cả lần đúng — xem R2 |
| Captcha fake cấm ở production | ✅ | `ProductionConfigGuard::guardCaptcha()` allowlist |
| S7: checkbox không tick sẵn | ✅ (backend + frontend) | Test cả 2 phía |
| FW1: redirect `next` an toàn | ✅ | dùng lại `safeRedirect` đã có test open-redirect |
| FW1: lỗi 422 map đúng field | ✅ cho field-level, ❌ cho banner chung khi sai đăng nhập | Xem R1 |
| FW1: CSP cho Turnstile | ✅ | 3 directive đúng khuyến nghị Cloudflare |
| FW1: token không lộ ra client / không dùng localStorage cho session | ✅ | Session vẫn là cookie `vv_session` HttpOnly (Sanctum SPA), FW1 không tự thêm token nào |

## Gợi ý cho QA
- Ưu tiên test thủ công/kịch bản E2E: đăng nhập sai mật khẩu → chụp lại đúng NỘI DUNG banner hiển thị (không chỉ status code), để bắt lại R1 nếu tái diễn sau khi sửa.
- Test đăng nhập đúng liên tục (ví dụ 12 lần trong 1 giờ, cùng tài khoản, các IP khác nhau) để xác nhận R2 có bị khoá oan không.
- Test race condition thật (2 request đăng ký cùng email gần như đồng thời) — hiện chỉ có unit-level qua exception giả lập, nên thử với `Http::pool`/2 tiến trình nếu có thời gian.
- Kiểm tra kỹ số điện thoại có khoảng trắng/dấu gạch ở `parent_phone` (regex `[0-9+\-\s()]{8,20}` khá lỏng, không chuẩn hoá qua `PhoneNumber` như SĐT chính) — không phải lỗi nhưng nên thử input dị dạng.
- Sau khi Dev sửa R1, chạy lại toàn bộ `LoginForm.test.tsx` (FE) với mock PHẢN ÁNH ĐÚNG response thật (bao gồm `errors.login`) để tránh test giả xanh tái diễn.

---

## Review lần 2 (sau commit `10c1b82`, diff `git diff 623504a 10c1b82`)

**Đã chạy lại:** `backend/vendor/bin/pint --test` (pass) · `vendor/bin/pest` toàn bộ (162/162 pass, 494 assertions) · `frontend pnpm -r run typecheck` (pass) · `pnpm -r run test` (tất cả pass, apps/web 37 test, apps/admin 5, packages 59).

### Xác nhận R1 — ĐÃ SỬA ĐÚNG
- `ApiExceptionRenderer::validationMessage()` (`backend/app/Support/ApiExceptionRenderer.php:85-108`): chỉ nâng message field lên top-level khi lỗi có **đúng 1 field** và field đó có **đúng 1 message**; nhiều field vẫn giữ câu chung "Dữ liệu gửi lên không hợp lệ.".
- Xác minh bằng request thật (test tạm, đã xoá sau khi xác minh): `POST /auth/login` sai mật khẩu nay trả `message: "Thông tin đăng nhập hoặc mật khẩu không đúng."` ở top-level (không còn là câu chung), đúng như đề xuất trong R1. `POST /auth/register` thiếu nhiều field cùng lúc vẫn trả `message: "Dữ liệu gửi lên không hợp lệ."` — không tự chọn 1 lỗi đại diện, đúng như lo ngại ban đầu về hồi quy.
- **(a) Kiểm tra không đổi hành vi ở endpoint T01/T02 khác:** Rà toàn bộ `app/Http/Requests` và `app/Http/Controllers`, hiện chỉ có `LoginRequest`/`RegisterRequest` (T03) dùng Form Request; không endpoint T01/T02 nào khác ném `ValidationException` có ý nghĩa nghiệp vụ nên không có nơi nào khác bị ảnh hưởng. Dev đã tự thêm `tests/Feature/T01/ErrorEnvelopeTest.php` (test mới: `422 ValidationException nhieu field van giu message chung`) để khoá lại hành vi multi-field, cùng với 4 test khác (404/405/429/500) xác nhận các nhánh lỗi không liên quan tới `ValidationException` (route lạ, method không hỗ trợ, throttle, 500) không bị ảnh hưởng bởi thay đổi này — đã chạy lại, cả 6 test PASS.
- **(a) FE RegisterForm khi 422 1-field:** Đã trace `applyApiErrorToForm()` (`frontend/apps/web/lib/auth/mapApiError.ts`) — khi `errors` có mặt và field khớp field đã biết (`KNOWN_FIELDS`), luôn gọi `setError()` và KHÔNG đẩy vào `leftoverMessages`, nên `bannerMessage` = `null` → không hiện banner trùng dù message field đã được nâng lên top-level. Test cập nhật `RegisterForm.test.tsx` có assertion tường minh `expect(screen.queryByText("Dữ liệu gửi lên không hợp lệ.")).not.toBeInTheDocument()` cho ca 1-field lẫn ca nhiều field — đã chạy, PASS. Không có hồi quy.
- Ghi chú nhỏ (không chặn): comment trong `LoginForm.tsx:57-62` và mock trong `LoginForm.test.tsx` viết rằng backend "luôn trả message chung ở top-level" cho case đăng nhập sai — theo xác minh thực tế ở trên, sau khi sửa R1, backend **đã** nâng đúng message ("Thông tin đăng nhập hoặc mật khẩu không đúng.") lên top-level cho case này (vì đúng 1 field/1 message), nên comment/mock hơi lỗi thời so với hành vi thật hiện tại. Không gây bug (code đọc `err.errors?.login?.[0] ?? err.message`, cả 2 nhánh đều ra cùng nội dung nên kết quả hiển thị vẫn đúng) — chỉ là tài liệu/mock chưa khớp 100% thực tế, có thể gây nhầm lẫn cho người đọc sau. **NIT**, không yêu cầu sửa ngay.

### Xác nhận R2/R3 — ĐÃ SỬA ĐÚNG HƯỚNG, nhưng phát hiện thêm 1 gap (R7, mới)
- `throttle:login` middleware nay chỉ còn lớp IP (`AppServiceProvider.php:91-99`); lớp tài khoản chuyển vào `LoginService::authenticate()` (`RateLimiter::tooManyAttempts()` trước khi chạm DB, `hit()` chỉ khi sai, `clear()` khi đăng nhập thành công) — đúng đề xuất, đúng thứ tự (kiểm throttle ở bước 0, trước cả bước 1 sai mật khẩu).
- Test mới `dang nhap dung nhieu lan lien tiep KHONG bi throttle` (12 lần đăng nhập ĐÚNG liên tiếp, cùng tài khoản) — PASS, xác nhận đã sửa đúng ý R2/R3.

**R7 [SHOULD, mới] — Khoá throttle theo tài khoản KHÔNG chuẩn hoá qua `PhoneNumber`, có thể lách ~3x ngưỡng 10 lần sai/giờ bằng cách đổi định dạng SĐT**
- Vị trí: `backend/app/Services/Auth/LoginService.php:51` — `$throttleKey = 'login:'.mb_strtolower(trim($login));` dùng thẳng chuỗi `$login` thô (chỉ lowercase + trim), trong khi `findByLogin()` (dòng 105-116) chuẩn hoá SĐT qua `PhoneNumber::fromInput($login)->value()` (về dạng `0xxxxxxxxx`) TRƯỚC khi tra DB.
- Đã xác minh bằng request thật (test tạm, đã xoá): với 1 tài khoản có `phone = '0912345678'`, gửi 10 lần sai bằng chuỗi `"0912345678"` → lần 11 bị `429`. Nhưng đổi sang `"+84912345678"` (cùng tài khoản, cùng số, khác định dạng) ngay sau đó thì KHÔNG bị `429` (trả `422` bình thường) — vì khoá throttle là `login:0912345678` khác với `login:+84912345678`, dù `findByLogin()` tra ra CÙNG 1 user cho cả 2 chuỗi. Kẻ tấn công biết trước số điện thoại mục tiêu có thể luân phiên giữa 3 dạng hợp lệ (`0xxxxxxxxx`, `84xxxxxxxxx`, `+84xxxxxxxxx`) để đạt ~30 lần thử sai/giờ thay vì 10 — làm suy yếu (không phải vô hiệu hoàn toàn, lớp IP 50/giờ vẫn là trần chung) mục tiêu chống dò mật khẩu của S18/api-contract §1.6. Email không bị ảnh hưởng (không có biến thể định dạng tương đương).
- Đề xuất: Chuẩn hoá khoá throttle theo CÙNG logic `findByLogin()` dùng để tra DB — ví dụ tách 1 hàm `normalizeLoginForThrottle()` dùng chung giữa việc tính khoá throttle và tra DB (tránh lặp/lệch logic sau này), hoặc đơn giản là tính khoá SAU khi đã thử chuẩn hoá qua `PhoneNumber`/email:
  ```php
  private function throttleKeyFor(string $login): string
  {
      $login = trim($login);
      if (str_contains($login, '@')) {
          return 'login:'.mb_strtolower($login);
      }
      try {
          return 'login:'.PhoneNumber::fromInput($login)->value();
      } catch (InvalidArgumentException) {
          return 'login:'.mb_strtolower($login); // giữ nguyên nếu không parse được, vẫn giới hạn theo chuỗi thô
      }
  }
  ```
  Thêm test kiểu `test('doi dinh dang SDT (+84/84/0) khong lach duoc throttle theo tai khoan')` để khoá lại hành vi, tương tự cách `ThrottleTest.php` đã test biến thể IP.
- Mức độ: **SHOULD**, không phải BLOCKER — ngưỡng vẫn hữu hạn (không phải bypass hoàn toàn vô hạn), lớp IP (50/giờ) vẫn là trần chung, và ở giai đoạn T03 chưa có OTP/khoá tài khoản tự động sau N lần sai (đó là phạm vi T04/tương lai). Nhưng nên vá SỚM (lý tưởng là trước khi merge, muộn nhất là trước T04/T27 vì 2 task đó dùng lại đúng pattern throttle theo tài khoản cho OTP/quên mật khẩu — nếu không thống nhất cách chuẩn hoá ngay bây giờ, nguy cơ lặp lại lỗi này ở nơi khác).
- Về race condition (câu hỏi (b) còn lại): `RateLimiter::tooManyAttempts()` + `hit()` không atomic với nhau (check-then-act), nên về lý thuyết nhiều request song song sát ngưỡng có thể vượt nhẹ quá 10 trước khi bị chặn — đây là hạn chế VỐN CÓ của `Illuminate\Cache\RateLimiter` (cùng pattern với mọi limiter khác đã có từ T01, kể cả limiter cũ dùng middleware), không phải hồi quy do R2 gây ra, và mức độ rủi ro tương đương với toàn bộ hệ thống throttle hiện tại. Chấp nhận được ở MVP, không yêu cầu sửa riêng cho T03.

### (c) Mock FE còn lệch envelope thật không?
- `RegisterForm.test.tsx`: đã cập nhật đúng — message chung khớp chữ với `ApiExceptionRenderer` (`"Dữ liệu gửi lên không hợp lệ."`), message field có dấu chấm cuối câu khớp `RegistrationService`/`RegisterRequest::messages()`, message `CAPTCHA_FAILED` khớp nguyên văn `RegistrationService::register()`. Khớp thật.
- `LoginForm.test.tsx`: 3/4 mock khớp thật (429 dùng đúng message mặc định `messageForStatus()`, ACCOUNT_LOCKED khớp đúng `LoginService`, sai mật khẩu có `errors.login` khớp thật). Riêng mock sai mật khẩu vẫn đặt `message: "Dữ liệu gửi lên không hợp lệ."` — như phân tích ở mục R1 phía trên, đây là 1 trong 2 khả năng hợp lệ về mặt hình dạng envelope (tổng quát hoá tốt cho trường hợp `errors` có nhưng message top-level chưa chắc đã được nâng — ví dụ nếu sau này `genericFailure()` đổi thành nhiều field), nhưng không khớp CHÍNH XÁC với response thật hiện tại (message thật đã được nâng lên cùng nội dung `errors.login[0]`). Không gây bug vì code ưu tiên đọc `errors.login[0]`. **NIT**, có thể sửa mock cho khớp 100% khi tiện.

## Kết luận cuối cùng: PASS CÓ ĐIỀU KIỆN

R1, R2, R3, R6 đã được sửa đúng và xác minh lại bằng request/test thật (không chỉ đọc code). Không phát sinh hồi quy ở các endpoint/luồng khác đã kiểm (T01 error envelope, RegisterForm multi-field). Có 1 phát hiện mới (R7, SHOULD) — khoá throttle theo tài khoản không chuẩn hoá định dạng SĐT, cho phép lách ~3x ngưỡng 10 lần sai/giờ bằng cách đổi `0xxx`/`84xxx`/`+84xxx`. Không chặn merge T03/FW1 phần 1 hiện tại (ngưỡng vẫn hữu hạn, lớp IP vẫn là trần chung, chưa có tính năng phụ thuộc trực tiếp), nhưng nên vá trước khi bắt đầu T04 (OTP) và T27 (quên mật khẩu) vì cả 2 sẽ tái dùng đúng pattern "throttle theo tài khoản trong Service" — nếu không thống nhất cách chuẩn hoá khoá ngay, rủi ro lặp lại ở nhiều nơi hơn.

**Điều kiện để chuyển "PASS" không kèm điều kiện:** vá R7 (chuẩn hoá khoá throttle qua cùng logic `findByLogin()`) + thêm test biến thể định dạng SĐT lách throttle, hoặc PO/Architect chấp nhận rủi ro còn lại bằng văn bản (ghi vào `docs/board.md` mục "việc đã hoãn" nếu chọn không vá ngay).

## Gợi ý cho QA (bổ sung sau lần 2)
- Thử lách throttle đăng nhập bằng cách đổi định dạng SĐT (`0912345678` → `+84912345678` → `84912345678`) trên cùng 1 tài khoản để xác nhận R7 có còn tái diễn sau khi Dev vá hay không.
- Xác nhận banner đăng nhập sai hiển thị đúng "Thông tin đăng nhập hoặc mật khẩu không đúng." (không phải "Dữ liệu gửi lên không hợp lệ.") trên UI thật (trình duyệt), không chỉ qua test giả lập.
