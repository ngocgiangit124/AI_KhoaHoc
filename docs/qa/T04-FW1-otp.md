# QA: T04 (OTP backend + GET /auth/me) + FW1 phần OTP (apps/web)

**Kết quả (lượt 1):** FAIL (0 Critical, 0 High; 2 Major, 2 Minor). **Sau kiểm lại: PASS** (xem mục cuối). Lõi OTP (backend và luồng UI) PASS. Hai Major là tích hợp/log, không chặn luồng xác thực.

**Phạm vi:** US-001 AC1, AC8, AC9 (chỉ route giả), các AC OTP (hết hạn, sai nhiều lần, giới hạn gửi lại, đổi liên hệ); api-contract §2.2 "Bổ sung từ T03/T04"; review `docs/review/T04-FW1-otp.md` (R1..R6 đã được Dev xử lý, đã xác nhận bằng test và e2e). Security tạm dừng: nợ `backlog-v2.md` (T04-1..5) không tính fail.

## Số liệu

| | Kết quả |
|---|---|
| `composer ci` (Pint + Larastan + Pest) | **312 passed + 1 skipped (BUG-1) / 1221 assertions**; Pint, Larastan sạch (trước khi QA thêm: 306 passed) |
| Frontend lint / typecheck | sạch / sạch (5 project) |
| Frontend vitest | api-client 36, ui 23, admin 5, web 76: đều pass |
| `next build` apps/web | pass |
| Playwright e2e `otp.spec.ts`, backend thật + Mailpit, `api.localhost:3000` | **9/9 pass** (1 test là `test.fail` có chủ đích cho BUG-2, nên vẫn "pass") |

Test QA thêm: `backend/tests/Feature/T04/QaGapsTest.php` (7 test, 1 skip cho BUG-1), `frontend/apps/web/e2e/otp.spec.ts` (9 test). Không sửa code ứng dụng.

## Độ phủ acceptance criteria

| AC | Test tự động | Chạy thật (curl / Playwright) | Kết quả |
|---|---|---|---|
| AC1 đăng ký tự gửi OTP, về `/` + lời nhắc | `OtpFlowTest` AC1, đăng ký vẫn 201 khi gửi lỗi; `QaGapsTest` (tên có dấu, escape HTML trong mail) | curl: 201, mail tới Mailpit có mã 6 số và tên tiếng Việt; e2e đăng ký qua UI -> banner "Vui lòng xác thực…" -> "Xác thực ngay" | PASS |
| AC8 nhập đúng OTP trong hạn -> ghi `email_verified_at`, `is_verified=true` | `OtpFlowTest` AC8, consume 1 lần, `MeAndVerifiedMiddlewareTest`; `QaGapsTest` (biên hết hạn -1s đúng / +1s sai, verify lần 2 khi đã xác thực) | curl 200 user phẳng `is_verified:true`; e2e về `/` + "Xác thực tài khoản thành công", F5 không hiện lại, vào `/xac-thuc-otp` khi đã xác thực -> về `/` | PASS |
| AC9 chặn checkout khi chưa xác thực | `MeAndVerifiedMiddlewareTest` (route giả: 403 `ACCOUNT_NOT_VERIFIED`, cho qua khi verified, đổi email -> chặn lại, khách 401) | - | PASS ở mức middleware. Chưa gắn route thật (T14/T18); nút "gửi lại OTP" ở trang checkout chưa kiểm được |
| OTP hết hạn | `OtpFlowTest` hết hạn 422 | curl: đặt `expires_at` quá khứ -> 422 "Mã OTP đã hết hạn. Bấm 'Gửi lại mã'…" | PASS |
| Sai nhiều lần | `OtpFlowTest` 5 lần rồi mã đúng vẫn 429, `OtpConcurrencyTest` 20 process song song, throttle 5/phút, 20/ngày | curl: 5 sai -> 422 x5, mã đúng lần 6 -> 429 `TOO_MANY_ATTEMPTS`; sau khi hết cửa sổ 1 phút mã đúng vẫn 429 (khoá theo mã); e2e: 5 sai -> thông điệp + gợi ý "Gửi lại mã", mã đúng vẫn bị chặn, sau 60s gửi lại -> mã mới xác thực được | PASS |
| Giới hạn gửi lại | `OtpLimitsTest` cooldown 60s, 5/giờ, 10/ngày | curl: gửi lại ngay -> 429 + `Retry-After: 50`; sau cooldown 202 + `resend_available_at`; e2e: sau gửi nút khoá "Gửi lại mã sau mm:ss" | PASS (xem BUG-2 cho trường hợp F5) |
| Đổi liên hệ | `ContactTest` (email, SĐT, trùng, giữ nguyên, mass assignment, audit); `QaGapsTest` (chuẩn hoá hoa/thường/khoảng trắng, SĐT sai định dạng) | curl: email trùng (kể cả khác hoa/thường) 422 field `email`; chỉ SĐT -> 200 `resend_available_at:null`; đổi email -> mã mới tới email mới, mã cũ 422; e2e: trùng -> lỗi dưới field, đổi mới -> mã mới, mã cũ bị từ chối; chỉ SĐT -> "Đã cập nhật thông tin liên hệ." và mã email cũ vẫn dùng được | PASS |
| `GET /auth/me` | `MeAndVerifiedMiddlewareTest` (shape phẳng, không lộ field phụ huynh, giáo viên 403, khoá 403) | curl: khách 401 `UNAUTHENTICATED`, sau đăng ký 200 user phẳng, sau logout 401 | PASS |
| CSRF / Origin | `OtpFlowTest`, `CorsTest` | curl: thiếu CSRF -> 419 | PASS |
| Log không có mã OTP | `OtpSecurityTest` S21 | `grep` laravel.log trong container: tìm thấy mã trong stack trace (BUG-1) | **FAIL** |
| FE: banner, AuthNav, đổi liên hệ, a11y 375px | vitest 76 | e2e: tên "Xin chào, …" ở header, Đăng xuất, lỗi `/auth/me` 502 -> "Không tải được thông tin" + Thử lại (không đá sang đăng nhập, R4), 6 ô nằm trong 375px (>= 40px), không tràn ngang (`/`, `/xac-thuc-otp`, form đổi liên hệ), F5 giữa chừng giữ đăng nhập, đăng xuất -> `/xac-thuc-otp` -> `/dang-nhap?next=…` -> đăng nhập quay lại OTP | PASS |

## Bug phát hiện

### BUG-1 (Major): mã OTP người dùng nhập bị ghi vào `laravel.log` (stack trace có đối số) khi hết lượt nhập
- Tái hiện: đăng ký, nhập sai 5 lần, nhập mã đúng lần 6 (429 "Bạn đã nhập sai quá nhiều lần…"). Mở `backend/storage/logs/laravel.log`.
- Mong đợi: không có mã 6 số trong log (S21, yêu cầu của chính test `OtpSecurityTest` S21).
- Thực tế: `local.ERROR: Bạn đã nhập sai quá nhiều lần…` kèm stack trace `OtpService->consume(Object(User), Object(OtpPurpose), '547465', …)` và `OtpService->verifyAccount(Object(User), '547465')`. `DomainException` 429 (hết lượt) bị `report()` như lỗi 500 và trace chứa đối số mã. Cũng ồn log: một tình huống nghiệp vụ bình thường ghi mức ERROR. `zend.exception_ignore_args` ở container php là `0` (không có php.ini ghi đè trong `infra/`), nên production dựng cùng image cũng sẽ ghi.
- Ảnh hưởng: mã nhập sau khi hết lượt đã vô hiệu nên khả năng lạm dụng thấp, nhưng mã sai/đúng người dùng gõ nằm trong log (PII/secret nhẹ). Test `OtpSecurityTest` S21 không đi qua nhánh này nên không bắt được.
- Vị trí nghi ngờ: `backend/app/Services/Auth/Otp/OtpService.php` (`throwExhaustedOrExpired`, `consume`, `verifyAccount`), cấu hình báo cáo exception ở `backend/bootstrap/app.php` (thiếu `dontReport(DomainException)` cho 4xx), và `infra/` (đặt `zend.exception_ignore_args=On` cho php production).
- Test tái hiện đã viết, đang `skip`: `QaGapsTest` "BUG-1 S21: nhap ma (dung) sau khi het 5 luot…". Bỏ `->skip(...)` khi sửa xong. Đề xuất: không report `DomainException` có status < 500; và/hoặc `zend.exception_ignore_args=On`.

### BUG-2 (Major): trình duyệt không đọc được `Retry-After`, nên UI không bật đếm ngược sau 429 gửi lại mã
- Tái hiện: đăng nhập user chưa xác thực, vào `/xac-thuc-otp`, bấm "Gửi lại mã" (gửi thành công), F5, bấm "Gửi lại mã" lần nữa trong vòng 60s.
- Mong đợi (review R5b): báo lỗi và nút chuyển sang "Gửi lại mã sau mm:ss" theo `Retry-After`.
- Thực tế: hiện "Bạn thao tác quá nhanh, vui lòng thử lại sau." nhưng nút vẫn bấm được, không có đếm ngược; mỗi lần bấm lại tiêu thêm một lượt throttle `otp-send`.
- Nguyên nhân: `backend/config/cors.php:34` `'exposed_headers' => ['X-Request-Id']` không có `Retry-After`. Web (`api.localhost:3000`) gọi API khác origin (`:8000`) nên `res.headers.get("Retry-After")` trong `packages/api-client/src/http.ts:15` luôn `null` trong trình duyệt. Vitest dùng `Response` giả có header nên không lộ.
- Đề xuất: thêm `Retry-After` (và `X-RateLimit-*` nếu cần) vào `exposed_headers`. Cân nhắc thêm `otp_resend_available_at` ở `/auth/me` để F5 không mất cooldown (R5a vẫn còn: sau F5 nút luôn bấm được, kể cả khi đang cooldown).
- Test: `otp.spec.ts` "gửi lại khi còn cooldown (sau F5…)" đang đánh dấu `test.fail(true, …)`; bỏ dòng đó sau khi sửa.

### BUG-3 (Minor): `POST /auth/otp/verify` khi tài khoản đã xác thực trả "Mã OTP đã hết hạn…"
- Tái hiện: tài khoản đã xác thực gọi verify với mã bất kỳ -> 422 `code`: "Mã OTP đã hết hạn. Bấm 'Gửi lại mã'…". Trong khi `/auth/otp/send` cùng trạng thái trả 422 "đã xác thực".
- Mong đợi: thông điệp đúng ngữ cảnh ("Tài khoản đã được xác thực") hoặc 204/200 idempotent.
- Ảnh hưởng thấp: UI chuyển về `/` khi `is_verified`, chỉ gặp khi hai tab. Vị trí: `OtpService::verifyAccount` / `throwExhaustedOrExpired`.

### BUG-4 (Minor): request không hợp lệ về định dạng (`code` = `12a456`) vẫn trừ throttle `otp-verify`
- Quan sát: gọi sai định dạng ngay sau 5 request vẫn nhận 429 throttle (đúng thiết kế throttle trước validation). Chấp nhận được, chỉ ghi nhận: lỗi định dạng ở client không nên tiêu lượt, nhưng FE đã chặn (chỉ gửi khi đủ 6 số) nên không ảnh hưởng người dùng thật.

## Việc đã kiểm, không có lỗi
- Race 20 process (`OtpConcurrencyTest`): tối đa 5 lần so, đúng 1 consume thành công.
- Mail OTP queue có mã hoá payload (S21), nội dung không chứa đường dẫn/PII thừa; `LogSmsOtpSender` chỉ ghi `***`.
- Mã cũ bị huỷ khi gửi mã mới hoặc đổi email; mã gắn đúng user và đúng đích đã gửi.
- Production chỉ email: `channel=sms` -> 422, đổi chỉ SĐT không huỷ mã email, app không boot nếu bật `sms` ở production.
- Audit `account.verified`, `account.contact_changed` ghi, không chứa mã/email mới (kiểm DB: 2 và 1 dòng như kỳ vọng cho user thử).

## Không kiểm được / còn hở
- AC9 trên checkout thật (T14/T18) và nút "gửi lại OTP" trên trang checkout; `student.single_session` còn pass-through (T05).
- Kênh SMS thật (production chỉ email); chỉ kiểm `LogSmsOtpSender` bằng test.
- Mail thật qua SMTP nhà cung cấp (chỉ Mailpit); hiển thị mail trên client thật (Gmail, Outlook).
- Bàn phím iOS, autofill `one-time-code` trên thiết bị thật, screen reader; chỉ Chromium headless 375px.
- `/auth/me` trả 429 hoặc `SESSION_REPLACED` trên UI (chỉ kiểm 502).
- Chưa chạy lại `auth.spec.ts` (e2e đăng ký/đăng nhập T03) do limiter đăng ký 30/giờ/IP đã dùng (QA chỉ đăng ký 2 tài khoản qua API/UI ở lượt này; các user e2e còn lại seed bằng tinker).
- MySQL 8.0 (cloud) vs 8.4: chỉ chạy Docker local 8.4.

## Rủi ro & đề xuất
- Sửa BUG-1 và BUG-2 trước khi PO duyệt commit; cả hai là sửa nhỏ (`dontReport` + `exception_ignore_args`; `exposed_headers`).
- Môi trường sau QA: DB dev có thêm 12 user `otp-e2e-1..12@example.com` (mật khẩu `matkhau-123`, một số đã xác thực) và vài user `qa-otp-*@example.com`; Mailpit chứa các mail OTP thử. Chạy lại `otp.spec.ts` cần seed lại 12 user bằng tinker (xoá và tạo bằng factory, `email_verified_at = null`). Lệnh chạy e2e: `docker run --network host --add-host api.localhost:127.0.0.1 -e CI=1 -e E2E_REAL_BACKEND=1 -v frontend:/workspace -w /workspace/apps/web mcr.microsoft.com/playwright:v1.63.0-noble ./node_modules/.bin/playwright test e2e/otp.spec.ts --workers=1 --retries=0`.
- Đề xuất bổ sung S21 test: quét log sau mọi nhánh lỗi OTP (hết lượt, hết hạn, 503), không chỉ nhánh thành công.

---

## Kiểm lại sau khi Dev sửa BUG-1, BUG-2, BUG-3 (2026-10-05)

**Kết quả kiểm lại:** PASS (BUG-1, BUG-2, BUG-3 đã hết; BUG-4 giữ nguyên là ghi nhận, không phải lỗi)

| Hạng mục | Kết quả |
|---|---|
| `composer ci` | 316 passed, 0 skipped (1247 assertions); Pint, Larastan sạch. Test BUG-1 trong `QaGapsTest` đã bỏ skip và pass |
| Frontend lint, typecheck, vitest, `next build` web | sạch, sạch (sau khi QA sửa 2 lỗi kiểu trong `otp.spec.ts`), api-client 36 + ui 23 + admin 5 + web 76 pass, build pass |
| Playwright `otp.spec.ts` thật | 11/11 pass (lượt đầy đủ: 10/11 + test cooldown sau F5 chạy lại pass riêng vì QA đã xác thực nhầm user seed số 11 bằng curl trước đó; không phải lỗi ứng dụng). Không còn `test.fail` |

- BUG-1: curl thật trên stack mới (zend.exception_ignore_args=1): đăng nhập user seed, gửi mã, sai 5 lần, mã đúng lần 6 (429 throttle) và lần nữa sau 62s (429 hết lượt mã), hết hạn (422), đổi liên hệ (200), verify đúng (200). `laravel.log` không tăng dòng nào so với mốc trước thử (0 entry mới), không có mã 6 số. Hai dòng có mã còn lại trong log là entry cũ trước bản sửa (dòng 77790-77791).
- BUG-2: header phản hồi có `Access-Control-Expose-Headers: X-Request-Id, Retry-After`; e2e "gửi lại khi còn cooldown (sau F5)" pass không cần `test.fail`: UI hiện lỗi 429 và nút chuyển sang "Gửi lại mã sau mm:ss" nên `Retry-After` đọc được trong trình duyệt thật.
- BUG-3: verify khi đã xác thực -> 422 field `code` "Tài khoản đã được xác thực."
- Còn lại (R5a): F5 vẫn mất cooldown trên UI cho tới khi bấm và nhận 429; đã giảm nhẹ nhờ BUG-2, không chặn.
- Môi trường: DB dev có 12 user `otp-e2e-1..12` (đã seed lại; seed script `/tmp/seed.php` xoá theo email, SĐT và email `-moi-`). Không flush Redis.
