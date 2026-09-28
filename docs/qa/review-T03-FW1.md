# REVIEW: T03 (Đăng ký/đăng nhập học sinh — backend) + FW1 phần 1 (Đăng ký/Đăng nhập/Đăng xuất — apps/web)

**Kết luận:** REQUEST CHANGES
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
