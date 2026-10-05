# QA: T03 (backend đăng ký/đăng nhập học sinh) + FW1 phần 1 (apps/web: đăng ký, đăng nhập, đăng xuất)

**Kết quả:** FAIL (0 Critical, 0 High bảo mật mới; 3 Major + 2 Minor, chủ yếu ở tích hợp FE/BE và môi trường local, backend thuần PASS)

**Phạm vi:** US-001 (AC1-AC7, AC10; AC8/AC9 thuộc T04, ngoài phạm vi), US-017 BR1 (2 checkbox đồng ý), api-contract §2.2 + "Bổ sung từ T03", `docs/review/T03.md`, `docs/review/FW1-part1.md`. Bước security tạm dừng: lỗi trong `docs/security/backlog-v2.md` không tính fail.

## Số liệu

| | Trước | Sau (QA bổ sung) |
|---|---|---|
| `composer ci` (Pint + Larastan + Pest) | 194 test / 680 assertions | **228 passed + 3 skipped (BUG-1) / 825 assertions**, Pint/Larastan sạch |
| Frontend lint / typecheck / vitest | - | sạch / sạch / web 52 + ui 18 + admin 5 đều pass |
| `next build` (apps/web, admin) | - | pass (web cần NEXT_PUBLIC_API_URL/SITE_URL/STATIC_URL) |
| Playwright e2e với backend thật | chỉ có `home.spec.ts` | `e2e/auth.spec.ts` 21 test, **21/21 pass** sau khi dựng môi trường workaround BUG-3 (xem dưới) |

Test thêm: `backend/tests/Feature/T03/QaGapsTest.php` (34 test + 3 skipped cho BUG-1) và `frontend/apps/web/e2e/auth.spec.ts`. Không sửa code ứng dụng.

## Độ phủ acceptance criteria

| AC | Test tự động | Chạy thật (curl / Playwright) | Kết quả |
|---|---|---|---|
| AC1 tạo tài khoản, role hoc_sinh, chưa xác thực, tự đăng nhập, về `/` + lời nhắc | `RegisterTest` AC1; `QaGapsTest` (consents, rollback, họ tên 150/151, tiếng Việt) ; e2e AC1 (kể cả F5 không hiện lại banner) | curl 201 + cookie `vv_session`; UI về `/` + banner | PASS (gửi OTP chờ T04) |
| AC2 email/SĐT trùng | `RegisterTest` AC2 + race; `QaGapsTest` (SĐT `+84 912 345 678`, email đổi hoa/thường, trùng cả hai) | curl 422 đúng field; **race thật 4 request song song cùng email: 1 x 201, 3 x 422, không 500**; e2e AC2 (lỗi dưới field, giữ dữ liệu, xoá mật khẩu) | PASS |
| AC3 đăng nhập email hoặc SĐT, tên ở header | `LoginTest` AC3 (nhiều dạng SĐT) ; `QaGapsTest` shape response | curl email + `+84…`; e2e đăng nhập bằng email và SĐT | PASS phần đăng nhập. **Tên ở header: không kiểm được** (`/auth/me` chưa có, T04; nav luôn là khách) |
| AC4 thông điệp chung | `LoginTest` AC4; `QaGapsTest` (SĐT sai định dạng, email không tồn tại, nguyên văn story) | curl + e2e: cùng thông điệp, mật khẩu bị xoá | PASS |
| AC5 xác nhận mật khẩu không khớp | `RegisterTest` AC5; `QaGapsTest` (thiếu `password_confirmation`) | e2e: lỗi tại field xác nhận (do validate client) | PASS có ghi chú BUG-5 |
| AC6 khoá khi sai liên tiếp | `LoginTest` throttle 2 lớp (đúng 10 lần/giờ theo contract, chỉ đếm lượt sai, xoá khi đúng) | curl: 10 lần sai -> 429 `TOO_MANY_ATTEMPTS` + `Retry-After: 3582`; mật khẩu đúng khi đang bị chặn vẫn 429 (theo contract) | PASS theo contract; ngưỡng 5/15 phút của AC6 chờ PO chốt |
| AC7 mã giới thiệu | `RegisterTest` AC7; `QaGapsTest` (50 ký tự được, 51 bị từ chối) | - | PASS |
| AC8/AC9 OTP, chặn checkout | - | - | Ngoài phạm vi (T04) |
| AC10 dưới 18 tuổi cần liên hệ phụ huynh | `RegisterTest` (biên 17 tuổi 364 ngày / đúng 18 tuổi, config ngưỡng); `QaGapsTest` (định dạng sai, `+84`, mã hoá) | curl: 15 tuổi thiếu PH -> 422 cả 2 field; có `parent_email` -> 201 `pending`; e2e: khối phụ huynh hiện/ẩn theo ngày sinh, banner phụ huynh | PASS |
| US-017 BR1 2 checkbox tách riêng | `RegisterTest` (thiếu từng cái, không gửi) | curl 422 `accept_privacy`; e2e | PASS (link `/dieu-khoan`, `/chinh-sach-du-lieu` vẫn 404, đã ghi nhận) |
| BR3/BR4/S17 mass assignment | `RegisterTest` S17; `QaGapsTest` mật khẩu 7/8/128/129, ký tự đặc biệt | - | PASS |
| BR5/S20 WRONG_PORTAL, ACCOUNT_LOCKED | `LoginTest` | curl: teacher/admin seed -> 403 `WRONG_PORTAL` (sai mật khẩu -> 422 chung); khoá + mật khẩu đúng -> 403 `ACCOUNT_LOCKED`, sai -> 422 chung; e2e WRONG_PORTAL | PASS |
| Logout, fixation | `LoginTest` | curl: login đổi cookie, logout 204, cookie cũ dùng lại -> 401; đã đăng nhập gọi login -> 403 FORBIDDEN | PASS |
| Origin lạ / thiếu CSRF | `RegisterTest`, `CorsTest` | curl: Origin lạ -> 400 `ORIGIN_NOT_ALLOWED`; thiếu CSRF -> 419 | PASS |
| XSS họ tên | `RegisterTest` | e2e: `<img onerror>`/`<script>` không thực thi (không có dialog) | PASS |
| `?next=` open redirect | `lib/auth` vitest | e2e: `//evil.com`, `/\evil.com`, `%2F%2Fevil.com`, `https://evil.com`, `javascript:alert(1)` đều ở lại origin; `/dang-nhap?x=1` được giữ | PASS |
| CSP | - | e2e: `/dang-ky` có Cloudflare ở `connect-src`/`frame-src`, `script-src` không có host Cloudflare, `/` và `/dang-nhap` không có; console không vi phạm CSP, Turnstile (test key) render và trả token | PASS |
| 375px | - | e2e: `/`, `/dang-nhap`, `/dang-ky` (kể cả khối phụ huynh) không tràn ngang; ảnh chụp `/dang-ky` ổn, không bể layout | PASS |

## Bug phát hiện

### BUG-1: Email chứa khoảng trắng, tab hoặc comment RFC được chấp nhận và lưu nguyên văn
- Mức độ: Minor (có thể coi Major vì phá tính duy nhất của email, AC2/BR1)
- Tái hiện: đăng ký `email = "an @example.com"`, `"an\t@example.com"`, `"(c)an@example.com"` -> 201, DB lưu đúng chuỗi đó. Cũng lọt qua `unique`, nên cùng 1 hộp thư có thể đăng ký nhiều tài khoản bằng cách thêm comment/khoảng trắng; email lỗi định dạng sẽ gây vấn đề khi gửi OTP (T04).
- Mong đợi: 422 `email` "Email không đúng định dạng".
- Thực tế: 201.
- Vị trí nghi ngờ: `backend/app/Http/Requests/Auth/RegisterRequest.php` rule `email:rfc` (cho phép FWS/CFWS). Đề xuất `email:rfc,strict,filter` hoặc `email:filter`; áp dụng tương tự `parent_email` và (nếu có) mọi nơi dùng `email:rfc`.
- Test đã viết sẵn nhưng đang `skip`: `QaGapsTest` "BUG-1: …" (bỏ `->skip()` khi Dev sửa).

### BUG-2: Local/dev không đăng ký được qua UI: `captcha_site_key` là chuỗi rỗng khiến nút "Tạo tài khoản" bị khoá vĩnh viễn
- Mức độ: Major (chặn luồng đăng ký trên mọi môi trường chưa đặt `TURNSTILE_SITE_KEY`; không lộ ở vitest vì mock dùng `null`)
- Tái hiện: backend `.env` mặc định `TURNSTILE_SITE_KEY=` -> `GET /config/public` trả `"captcha_site_key":""`. Mở `/dang-ky`, nhập đủ dữ liệu: nút "Tạo tài khoản" `disabled` mãi, không có widget Turnstile, không có thông báo gì.
- Nguyên nhân: contract/`config.ts` quy ước `null` khi chưa cấu hình, nhưng `env()` trả `""` nên (1) `app/dang-ky/page.tsx` dùng `config.captcha_site_key ?? env.NEXT_PUBLIC_TURNSTILE_SITE_KEY` (`??` không rơi về env khi `""`), (2) `RegisterForm.tsx` `captchaPending = captchaSiteKey !== null && captchaToken === null` coi `""` là "đã bật captcha". Ngay cả khi mở khoá nút, `FakeCaptchaVerifier` từ chối token rỗng (đúng theo R3) nên không có đường nào đăng ký được khi không có site key.
- Mong đợi: có hướng dẫn/mặc định chạy được ở local (vd. `.env.example` đặt `TURNSTILE_SITE_KEY=1x00000000000000000000AA`, key test Cloudflare luôn pass), và FE/BE thống nhất "chưa cấu hình" = `null` (backend `config('services.turnstile.site_key') ?: null`, FE dùng `||`, `captchaPending = !!captchaSiteKey && …`).
- Vị trí: `backend/app/Http/Controllers/Api/V1/PublicConfigController.php:26`, `frontend/apps/web/app/dang-ky/page.tsx` (dòng truyền `captchaSiteKey`), `frontend/apps/web/components/auth/RegisterForm.tsx` (`captchaPending`).
- Workaround QA: đặt tạm `TURNSTILE_SITE_KEY=1x00000000000000000000AA` trong `backend/.env` (đã hoàn nguyên).

### BUG-3: Local dev: trình duyệt không lưu cookie phiên của API (mọi POST từ UI trả 419) vì `localhost:3000` và `api.localhost:8000` là cross-site
- Mức độ: Major (môi trường local/tài liệu; production dùng `vitaminvui.vn` + `api.vitaminvui.vn` cùng site nên không bị)
- Tái hiện: chạy web ở `http://localhost:3000` (cấu hình mặc định, `FRONTEND_URL=http://localhost:3000`) và API `http://api.localhost:8000`. Mở `/dang-nhap`, đăng nhập: `GET /csrf-token` 200 có Set-Cookie nhưng `context.cookies()` rỗng, request POST không gửi Cookie -> 419 `CSRF_TOKEN_MISMATCH` ("Phiên làm việc đã hết hạn"). Đăng ký cũng vậy. Xác nhận nguyên nhân: mở web ở `http://api.localhost:3000` (cùng site với `api.localhost:8000`) và đổi `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS` tương ứng thì cookie được lưu, đăng nhập hoạt động, 21/21 e2e pass. Chromium coi `*.localhost` như TLD không có registrable domain nên `localhost` và `api.localhost` khác site, cookie `SameSite=Lax` bị chặn ở request cross-site. Tương tự `admin.localhost:3001` và `admin-api.localhost:8000` cũng khác site (admin chưa kiểm).
- Mong đợi: tài liệu/hạ tầng local cho phép chạy được từ trình duyệt thật. Đề xuất: dùng cặp host cùng registrable domain, ví dụ web `app.vitaminvui.test`, API `api.vitaminvui.test` (hoặc mở web ở `api.localhost:3000`), cập nhật `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `.env.example`, README, CSP/connect-src và `playwright.config.ts` (đang `baseURL: localhost:3000`).
- Hệ quả: FE0/FW1 chưa từng chạy e2e có cookie với backend thật; `auth.spec.ts` hiện mặc định `E2E_BASE_URL=http://api.localhost:3000`, cần đặt kèm `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS` của backend.

### BUG-4 (Minor): `GET /csrf-token` giới hạn 30/phút/IP ảnh hưởng người dùng chung IP
- Quan sát: sau ~30 lần gọi trong 1 phút, `csrf-token` trả 429, mọi POST sau đó 419. FE cache token nên một người dùng bình thường không chạm, nhưng lớp học/IP NAT dùng chung (đã nêu ở review R1 cho login) có thể chạm giờ cao điểm. Đề xuất: nâng trần hoặc tính theo session+IP; FE xử lý 429 của csrf-token bằng thông điệp rõ ràng thay vì "Phiên làm việc đã hết hạn".
- Vị trí: limiter `csrf` trong `backend/app/Providers/AppServiceProvider.php`.

### BUG-5 (Minor): lỗi `confirmed` của server nằm ở field `password`, không phải `password_confirmation`
- AC5: "hiển thị lỗi tại field xác nhận mật khẩu". Validate client chặn trước nên người dùng UI thấy đúng chỗ; nhưng nếu lọt xuống server (client bị bypass/đổi), `422 errors.password = ["Xác nhận mật khẩu không khớp."]` hiển thị dưới ô Mật khẩu.
- Vị trí: `backend/app/Http/Requests/Auth/RegisterRequest.php` rule `confirmed` (Laravel gắn lỗi vào field gốc). Đề xuất: ánh xạ lỗi `password.confirmed` sang `password_confirmation` (hoặc FE map khi message khớp), hoặc ghi vào contract.

## Việc đã kiểm, không có lỗi
- Race thật 4 request song song cùng email: đúng 1 tài khoản, các request còn lại 422 sạch, không 500.
- Mất đồng bộ giữa 3 dạng SĐT (`0912…`, `+84912…`, `84 912…`, có dấu chấm) trong đăng ký/đăng nhập/throttle: cùng một khoá.
- Response register/login có `Cache-Control: no-store, private`; không lộ `password`, `parent_*`; shape đúng `id, name, email, phone, role, grade_level, is_verified, parent_consent_status`.
- R1-R4 của review T03 và R1-R3 của FW1 phần 1 đã được Dev xử lý (limiter chỉ đếm lượt sai, bắt unique theo tên khoá, captcha fake từ chối token rỗng, kiểm session trước captcha, field phụ huynh bị ẩn ép hiện khối + banner); đã có test và hành vi thật khớp.
- Không thấy lỗi bảo mật Critical/High mới.

## Không kiểm được / còn hở
- Tên hiển thị ở header (AC3) và nút Đăng xuất qua UI: `/auth/me` chưa có (T04), `AuthNav` luôn hiện khách. Logout chỉ kiểm bằng curl và Pest.
- Turnstile thật (chỉ dùng key test Cloudflare `1x00000000000000000000AA`, token giả, driver captcha fake ở backend); chưa kiểm khi chặn `challenges.cloudflare.com`; chưa kiểm `CAPTCHA_FAILED` với Cloudflare thật.
- Bàn phím iOS, screen reader, tab bằng bàn phím (chỉ kiểm bố cục 375px bằng Chromium headless).
- Chỉ chạy Chromium; chưa Firefox/Safari. Chưa kiểm app admin với backend thật.
- Ngày sinh biên quanh nửa đêm UTC/VN chỉ kiểm bằng test Pest (đồng hồ test), không có kiểm tay quanh 00:00 thực.
- Ngưỡng AC6 (5 lần/15 phút) chưa được PO chốt; hiện theo contract 10 lần/giờ + 50/giờ/IP.
- Cloud (MySQL 8.0 vs 8.4): chỉ chạy trên Docker local MySQL 8.4.

## Rủi ro & đề xuất
- Sửa BUG-2 và BUG-3 trước khi PO duyệt FW1 phần 1: nếu không, không ai chạy được đăng ký/đăng nhập qua trình duyệt ở local, và e2e có backend thật không chạy được trên CI mặc định.
- Thêm 1 e2e có backend thật vào CI (job riêng với host cùng site) để các lỗi tích hợp cookie/captcha như BUG-2/3 không lọt qua vitest dùng mock.
- Trạng thái môi trường sau QA: DB dev đã được seed (`db:seed`, 3 tài khoản demo) và chứa các tài khoản `*@qa.test`, `qa-*@example.com` do test tạo; `student@vitaminvui.test` và IP Docker bị limiter login khoá khoảng 1 giờ (không xoá được vì flush Redis bị chặn); `backend/.env` đã hoàn nguyên, container `php` đã tạo lại, nginx đã restart (nginx giữ IP cũ của php sau khi php tạo lại, bị 502 đến khi restart).

---

## Kiểm lại sau khi Dev sửa BUG-1..5 (2026-10-05)

**Kết quả kiểm lại:** PASS có điều kiện (5/5 bug đã hết; 7/21 e2e web chưa chạy lại được vì limiter register 30/giờ/IP đã cạn do chính QA, không phải lỗi ứng dụng)

| Hạng mục | Kết quả |
|---|---|
| `composer ci` | 232 passed, 0 skipped (840 assertions); Pint/Larastan sạch. Các test BUG-1 không còn bị skip |
| Frontend lint / typecheck | sạch |
| Frontend vitest | api-client 34, ui 18, admin 5, web 55 đều pass |
| `next build` web + admin | pass (đọc `.env.local` mới) |
| Playwright web, backend thật, `api.localhost:3000` | 14/21 pass. 7 fail đều ở bước đăng ký phụ trợ: UI hiện đúng "Bạn thao tác quá nhanh, vui lòng thử lại sau." (429 từ `throttle:register` 30/giờ/IP, IP Docker đã dùng hết bởi các lượt chạy trước). Đã thử không xoá Redis (bị chặn), cần chờ hết giờ rồi chạy lại `auth.spec.ts` |
| Playwright admin, backend thật | 2/2 pass (`admin-api.localhost:3001`, CORS chặn origin web, CSRF + CSP nonce) |

Các test e2e đã pass sau sửa (đáng chú ý): AC1 (nút "Tạo tài khoản" bật được với key test Cloudflare, đăng ký về `/` + banner), AC10, AC5, US-017, trường rỗng, XSS họ tên, WRONG_PORTAL, CSP `/dang-ky` (Cloudflare chỉ ở connect-src/frame-src), không vi phạm CSP, 375px x3.

Xác minh từng bug:
- BUG-1: hết. `RegisterRequest` dùng `email:rfc,strict` + regex an toàn; 3 test trước đây skip nay chạy và pass.
- BUG-2: hết. `/config/public` trả `captcha_site_key` = key test, nút đăng ký bật được trên UI thật (e2e AC1 pass). Chưa kiểm lại nhánh "không có key" ngoài vitest (55 pass).
- BUG-3: hết. Web ở `api.localhost:3000`, cookie được lưu, đăng nhập/đăng ký chạy; admin e2e thật pass.
- BUG-4: hết. Limiter `csrf` còn 120/phút/IP (`AppServiceProvider.php:159`).
- BUG-5: hết. Test `RegisterTest` AC5 nay yêu cầu lỗi ở `password_confirmation`, pass.

Việc còn lại: chạy lại `E2E_REAL_BACKEND=1` `auth.spec.ts` sau khi limiter register hết hạn (khoảng 1 giờ) để xác nhận 7 test còn lại; các test này đã pass trước đó ở lượt 21/21, chỉ hạ tầng giới hạn tốc độ cản lại lần này. Gợi ý: e2e auth nên gọi API tạo user (hoặc factory seed) thay vì đăng ký qua UI cho mỗi test để không chạm trần 30/giờ.
