# REVIEW: FW1 (phần còn lại, design v2) — `frontend/apps/web`
**Kết luận:** REQUEST CHANGES
**Phạm vi:** thay đổi chưa commit trên `main` (quên/đặt lại/đổi mật khẩu, Tài khoản, màn chặn, hộp thoại phiên, trang giữ chỗ pháp lý, proxy CSP, e2e) · ~75 file web. Không review `apps/admin`, `OtpInput`/`AdminFrame` (FA3), `backend/`.
**Kiểm tra đã chạy (uptime ~3):** `lint` sạch, `typecheck` sạch, `test` 24 file / 212 test pass. Không chạy build/e2e.

## Tổng quan
Chất lượng tốt: thông điệp forgot/reset đúng chuẩn "không lộ tài khoản" (nguyên `message` server, mọi 422 không phải field password đều gộp thành lỗi mã), mật khẩu hiện tại bị xoá sau lỗi, hộp thoại phiên không đóng được (Esc/nền/X đều chặn, `<dialog>` modal nên nền inert), `next` luôn qua `safeRedirect`, test có ý nghĩa (case giữ mật khẩu khi mã chết, 429, session_kept=false, event REVOKED/REPLACED). Có 1 lỗi chặn ở CSP Turnstile khi điều hướng mềm, và vài lỗi UX/a11y nên sửa trong story.

## Phát hiện

### R1 [BLOCKER] CSP Turnstile theo route không có tác dụng khi vào `/quen-mat-khau` bằng điều hướng mềm (Link)
- Vị trí: `proxy.ts:6` (`CAPTCHA_PATHS`) và `components/auth/LoginForm.tsx:106` (`<Link href={routes.forgotPassword}>`), cùng loại ở `LoginForm.tsx:121` (tới `/dang-ky`, có sẵn từ trước), `UiLink`/`SiteHeader` "Đăng ký".
- Vấn đề: CSP gắn vào tài liệu HTML đầu tiên. Từ `/dang-nhap` bấm "Quên mật khẩu?" (đường vào chính) là điều hướng mềm của App Router, không tải lại tài liệu, nên CSP vẫn là của `/dang-nhap` (không có `challenges.cloudflare.com` trong `frame-src`/`connect-src`). Khi có site key (production), iframe Turnstile bị chặn, `onToken` không bao giờ chạy, nút "Gửi mã" khoá vĩnh viễn (`captchaPending`), chỉ F5 mới chữa. Test proxy (`proxy.test.ts:37`) và mọi e2e đều dùng `page.goto`/tải thẳng nên không bắt được. Cùng lỗi đang tồn tại với `/dang-ky` (từ login hoặc header), nên sửa chung.
- Đề xuất: link tới các route có captcha phải là điều hướng cứng, hoặc mở CSP rộng hơn. Cách gọn nhất: trong `UiLinkProvider` (app) và các `Link` cục bộ, dùng `<a href>` khi `href` thuộc tập `CAPTCHA_PATHS` (export tập này từ `lib/routes.ts` để proxy và link dùng chung):
  ~~~tsx
  // lib/routes.ts
  export const CAPTCHA_PATHS = [routes.register, routes.forgotPassword, routes.resetPassword] as const;
  // LoginForm: <a href={routes.forgotPassword}> thay <Link>; tương tự "Đăng ký ngay" và nút Đăng ký ở header
  ~~~
  Thêm e2e: bật `NEXT_PUBLIC_TURNSTILE_SITE_KEY` (key test của Cloudflare), vào `/dang-nhap` rồi bấm link, kiểm không có CSP violation và nút "Gửi mã" mở được.

### R2 [SHOULD] Đặt lại mật khẩu thành công: nháy thông báo sai "Bạn chưa yêu cầu mã đặt lại mật khẩu" trước khi sang trang đăng nhập
- Vị trí: `components/auth/ResetPasswordForm.tsx:113` (`clearResetLogin()`), `:80` (`if (!login)`), `:34` (`useSyncExternalStore` không có subscribe).
- Vấn đề: sau `clearResetLogin()` rồi `setDone(true)`, lần render kế `readResetLogin()` trả `null` nên form bị thay bằng Alert "Bạn chưa yêu cầu mã…" trong lúc `router.push` đang tải trang động (có thể vài trăm ms đến vài giây trên mạng chậm). Test `PasswordForms.test.tsx:106` chỉ kiểm `login` đã xoá, không kiểm màn hình.
- Đề xuất: không xoá sessionStorage trước khi rời trang. Gọi `clearResetLogin()` ở trang đích (`LoginForm` khi có `notice === "dat-lai-xong"`), hoặc giữ `login` trong `useState` khởi tạo từ storage và dùng `if (!login && !done)`. Thêm assert trong test: sau thành công không có text "chưa yêu cầu mã".

### R3 [SHOULD] "Gửi lại mã" bước 2 với Turnstile ẩn: lỗi tải/yêu cầu tương tác không được báo
- Vị trí: `ResetPasswordForm.tsx:243` (không truyền `onError`), `:136-139`.
- Vấn đề: (a) Nếu script Turnstile lỗi/bị chặn (kể cả do R1), token luôn `null` và người dùng chỉ thấy lặp lại "Đang xác minh chống spam, vui lòng thử lại sau vài giây" mãi mãi, không có đường ra. (b) Khi Cloudflare buộc tương tác, widget hiện ở CUỐI form (dưới "Quay lại đăng nhập") còn nút bấm ở trên; không có chữ nào bảo người dùng làm bước đó. `ForgotPasswordForm` đã làm đúng (`onError` + dòng hướng dẫn).
- Đề xuất: truyền `onError={() => setNotice({ tone: "danger", text: CAPTCHA_FAILED_MESSAGE })}`; khi `captchaToken === null` mà người dùng bấm gửi lại, ghi "Nếu thấy ô xác minh bên dưới, hãy tích vào đó rồi bấm Gửi lại mã" và đặt widget ngay sau khối `ResendCode`. Có thể dùng `before-interactive-callback` nếu muốn cuộn tới widget.

### R4 [SHOULD] Form mới không chuyển focus tới ô lỗi sau khi submit; lỗi dưới ô không được đọc
- Vị trí: `ForgotPasswordForm.tsx:44-48`, `ResetPasswordForm.tsx:99-107` (chỉ focus ô mã), `account/ChangePasswordForm.tsx:36-40`, `account/ChangeContactForm.tsx:57-58`.
- Vấn đề: `Field` hiển thị lỗi bằng `<p>` thường (không `role=alert`/`aria-live`), và các form này không đưa focus vào ô lỗi đầu tiên hay hộp tóm tắt (như `RegisterForm` đã làm với `SUMMARY_ID`). Người dùng bàn phím/đọc màn hình bấm gửi mà không nghe/thấy gì. Form ngắn nên không cần hộp tóm tắt (design §11.2), nhưng ít nhất phải focus ô lỗi đầu tiên (WCAG 3.3.1/2.4.3).
- Đề xuất: sau `setErrors(next)`, focus ô đầu tiên có lỗi (`ref`/`document.getElementById(id)` qua prop `id` của `Field`), cả với lỗi 422 từ server. Làm một hook dùng chung (`useFocusFirstError`).

### R5 [SHOULD] Liên kết chữ độc lập chưa đạt vùng chạm 44px (§6)
- Vị trí: "Quên mật khẩu?" `LoginForm.tsx:106`, "Đổi" `ResetPasswordForm.tsx:~184` (`text-sm`, cao ~20px), "Quay lại đăng nhập" (`ForgotPasswordForm.tsx:76,107`, `ResetPasswordForm.tsx:90,247`), "Đăng ký ngay".
- Đề xuất: thêm `inline-flex min-h-11 items-center` (hoặc `-my-3 py-3` để không đổi nhịp dòng) cho các link đứng riêng một dòng.

### R6 [SHOULD] Hộp thoại phiên: có lối thoát bằng nút Back, và chưa nói rõ điều kiện cho QA/FW4-5
- Vị trí: `components/shell/SessionEndedGate.tsx:36` (`ended.path !== pathname` → `setEnded(null)`).
- Vấn đề: bấm Back của trình duyệt đổi `pathname` nên hộp thoại biến mất và trang cũ (đã render, có dữ liệu người dùng) hiện lại, nên "không đóng được" chỉ đúng với Esc/nền/X. Thực tế chỉ khi có request kế tiếp mới bật lại hộp thoại. Ngoài ra `<dialog>` modal làm nền inert nhưng KHÔNG dừng video/âm thanh đang phát (mô tả design: "video dừng").
- Đề xuất: chỉ tắt hộp thoại khi người dùng đi tới đúng trang đăng nhập (`isAuthPath(pathname)`), không phải mọi thay đổi path; hoặc `window.location.replace` đã dọn state. Để lại ghi chú cho FW4: `SessionEndedGate` phải phát một sự kiện/context để player tự `pause()`.

### R7 [SHOULD] Thiếu test: soft navigation/CSP, thông báo sai ở R2, `loginUrl` với query
- Vị trí: `proxy.test.ts`, `PasswordForms.test.tsx`, `SessionEndedGate.test.tsx`.
- Đề xuất: bổ sung test R2; test `loginUrl` khi `next` là `/dang-nhap?x=1` (hiện `isAuthPath(next)` so với chuỗi có query nên không lọc: `SessionEndedGate.tsx:10-12, 25`; nên dùng `new URL(next, "http://x").pathname`).

### R8 [NIT] 429 ở đổi liên hệ luôn nói "nhập sai mật khẩu"
- Vị trí: `account/ChangeContactForm.tsx:74-76`, `:88`.
- Vấn đề: theo api-contract 429 còn đến từ trần OTP (5/giờ, 10/ngày) hoặc limiter `contact` 10/giờ, không chỉ sai mật khẩu; thông điệp có thể sai. Design §12.5 ghi đúng câu này nên chỉ NIT: dùng `err.message` của server nếu có, hoặc câu chung "Bạn đã thử quá nhiều lần".

### R9 [NIT] Đặt lại mật khẩu: khoá 429 cố định 60 giây, bỏ qua `Retry-After`
- Vị trí: `ResetPasswordForm.tsx:126` (`setThrottleMs(60_000)`), `lib/auth/errors.ts` `classifyResetError` (không trả `retryAfterSeconds`).
- Vấn đề: throttle `otp-verify` có mốc 20/ngày → 24h; sau 60s người dùng bấm lại, nhận 429 tiếp. Trả `retryAfterSeconds` từ classify và dùng làm thời gian khoá như `ChangeContactForm`.

### R10 [NIT] `login` trong sessionStorage — chấp nhận được, nên dọn thêm
- Đánh giá: cần thiết (design §12.8 cấm đặt lên URL; bước 2 cần `login` để gọi `reset`/`forgot`). Chỉ lộ cho JS cùng origin trong tab (rủi ro tương đương XSS đã có), xoá khi thành công. Không lộ tồn tại tài khoản (lưu cả khi tài khoản không tồn tại, hành vi giống nhau).
- Còn thiếu: không dọn khi bỏ dở, khi đăng nhập thành công hoặc đăng xuất ở tab dùng chung máy; bước 2 hiện nguyên email/SĐT (`ResetPasswordForm.tsx:~181`). Đề xuất `clearResetLogin()` trong `LoginForm` khi đăng nhập thành công/`LogoutButton`, và hiển thị bản che (`maskEmail` đã có ở `lib/auth/otp`) nếu PO đồng ý (design hiện ghi "Tài khoản: …").

### R11 [NIT] Chuỗi cứng "10 phút" ở bước quên mật khẩu
- Vị trí: `ForgotPasswordForm.tsx:68`, `ResetPasswordForm.tsx:~196`. Màn OTP dùng `config.otp.ttl_minutes`; nên truyền từ config nếu TTL `reset_password` trùng, nếu không thì bỏ con số.

### R12 [NIT] Sau forgot có màn trung gian, design nói "luôn chuyển sang bước 2"
- Vị trí: `ForgotPasswordForm.tsx:66-82`. Chấp nhận được vì cho đọc thông điệp chung của server (contract note c); chỉ ghi để PO/QA biết có thêm một bước bấm.

### R13 [NIT] `seed-e2e-auth.sh` không có chốt môi trường
- Vị trí: `e2e/seed-e2e-auth.sh`. Chạy `forceDelete` qua tinker trong container `php` của `infra`; chỉ khớp email `qa-*/otp-e2e-*@example.com` nên an toàn, nhưng nên `abort` nếu `app()->environment('production')`.

## Đối chiếu yêu cầu
| Yêu cầu | Kết quả | Ghi chú |
|---|---|---|
| Forgot: thông điệp chung, không enumerate (US-015 AC1, ghi chú c) | Đạt | Hiện `message` server; fallback chung; 403/429/422 phân loại không lộ gì. Chỉ lệch nhỏ R12 |
| Reset: mọi lỗi mã → OTP_EXPIRED + Gửi lại mã, giữ mật khẩu (T27-5) | Đạt | `classifyResetError`: 422 không phải field password → `code`; test có |
| Mật khẩu phổ biến `errors.password[0]` (cụm 1 b) | Đạt | reset, đổi mật khẩu; đăng ký ngoài phạm vi đọc kỹ |
| Đổi liên hệ: `current_password`, 422/429, gọi lại `/auth/me`, xoá mật khẩu sau lỗi (cụm 1 a) | Đạt | R8 nhỏ |
| Đổi mật khẩu `session_kept=false` → `/auth/me` | Đạt | |
| OTP một ô, OTP_INVALID/EXPIRED (PO 2026-10-07) | Đạt (ngoài phạm vi OtpInput) | `OtpVerifyForm.test` đủ case |
| SESSION_REPLACED/REVOKED hộp thoại không đóng được | Đạt có điều kiện | R6 (Back, video) |
| Turnstile ẩn ở "Gửi lại mã" bước 2 | Có code, lỗi vận hành | R1, R3 |
| CSP Turnstile chỉ đúng route | Đúng theo route tải thẳng, sai khi điều hướng mềm | R1 (BLOCKER) |
| Open redirect `next` | Đạt | `safeRedirect` ở LoginForm; `SessionEndedGate` dùng `URLSearchParams` + `safeRedirect`; trang chặn dùng hằng số |
| Mật khẩu không giữ lâu / không log | Đạt | không `console`; ChangeContact xoá ngay; reset giữ mật khẩu mới trong state theo thiết kế, huỷ khi rời trang |
| Nền ô ly AA (§3.1) | Đạt | `bg-oly-page` ở `main`, form/đoạn dài trong `Sheet`/`Alert`; `ink-soft` trực tiếp trên lưới đạt 5,9:1; không dùng chữ `success` trực tiếp |
| Trang giữ chỗ điều khoản/chính sách | Đạt | nói rõ chưa có nội dung, có lối đi, không 404; `force-dynamic` hợp CSP nonce |
| a11y 44px, tóm tắt lỗi focus, aria-live | Chưa đạt | R4, R5 |
| `/tai-khoan`, `/can-xac-thuc`, `/cho-phu-huynh` không lộ dữ liệu | Đạt | `noindex`, dữ liệu chỉ từ `/auth/me` của chính người dùng; khách → đăng nhập |

## Điều kiện cho QA (Dev chưa `next build`, dùng chung `.next` với dev server)
- Chạy `next build` + `start` ở thư mục build riêng (hoặc dừng dev server) rồi mới e2e; xác nhận `proxy.ts` (CSP nonce) hoạt động ở chế độ production (`NODE_ENV=production`), vì HSTS và CSP khác dev.
- Test với site key Turnstile bật (không để `null`): vào `/dang-nhap` rồi bấm link tới `/quen-mat-khau` và `/dang-ky`, mở console xem CSP violation (R1); gửi lại mã ở bước 2 với widget ẩn và khi buộc tương tác (R3).
- Chạy `seed-e2e-auth.sh` trước mỗi lượt e2e xác thực/OTP/mật khẩu (các test đổi email, mật khẩu của chính user seed).

## Gợi ý cho QA
- Quên mật khẩu: tài khoản không tồn tại / bị khoá / email chưa xác thực / đã xác thực cho cùng một màn hình và thời gian phản hồi; học sinh đang đăng nhập vào `/quen-mat-khau` (403 banner); tải thẳng `/quen-mat-khau/dat-lai` khi chưa có `login`.
- Bước 2: dán mã `633 723` (khoảng trắng); sai mã 5 lần; gửi lại rồi nhập mã mới (mật khẩu phải còn); 422 mật khẩu phổ biến (`12345678`, `matkhau123`); thành công thì xem có nháy thông báo sai (R2) và các tab khác nhận hộp thoại `SESSION_REVOKED`.
- Tài khoản: đổi email (cần mật khẩu), đổi SĐT riêng, email trùng, sai mật khẩu hiện tại nhiều lần để ra 429; cookie phiên xoay thì `/auth/me` vẫn đúng; thiết bị thứ hai nhận hộp thoại REVOKED.
- Phiên: đăng nhập thiết bị khác trong khi đang ở `/khoa-hoc/...`, `/tai-khoan`, `/xac-thuc-otp`; thử Esc/Tab/click nền/Back; bàn phím chỉ Tab trong hộp thoại.
- `CourseCtaProvider`: 403 `ACCOUNT_NOT_VERIFIED` mở hộp thoại tại chỗ (không còn `router.push`); `PARENT_CONSENT_REQUIRED` với cờ bật, nút "Gửi lại email cho phụ huynh" luôn khoá (chưa có API T29).
- a11y: tab/bàn phím qua các form mới, đọc màn hình lỗi dưới ô (R4), kích thước link (R5), tương phản ở giao diện tối.

## Dev đã sửa (nextjs-dev, 2026-10-07)
- **R1 [BLOCKER]:** `lib/routes.ts` export `CAPTCHA_PATHS` + `isCaptchaHref`; `proxy.ts` dùng chung. Component mới `components/shell/AppLink.tsx` render `<a href>` (tải lại tài liệu → đúng CSP) khi đích thuộc `CAPTCHA_PATHS`, còn lại `next/link`; gắn làm `UiLinkProvider` ở `AppProviders` (phủ header "Đăng ký", footer, `ButtonLink`, `CatalogBusy`) và dùng trực tiếp ở `LoginForm` ("Quên mật khẩu?", "Đăng ký ngay"), `ResetPasswordForm`. Forgot xong chuyển sang bước 2 bằng `window.location.assign` (R12 + CSP bước 2). Không mở Cloudflare cho mọi trang khách: giữ CSP chặt (ADR-004 §2.6), chi phí chỉ là một lần tải lại tài liệu khi vào 3 trang. Test: `AppLink.test.tsx`, `LoginForm.test` (link là `<a>`), e2e `fw1-v2-qa` "R1" (từ `/dang-nhap` bấm "Quên mật khẩu?", "Đăng ký ngay", nút "Đăng ký" ở header: không CSP violation, nút mở khoá nhờ token Turnstile).
- **R2:** bước 2 không xoá `login` trước khi rời trang; `LoginForm` dọn khi có `trang-thai=dat-lai-xong`. Test assert không có "chưa yêu cầu mã".
- **R3:** widget Turnstile ẩn có `onError` (thông báo lỗi), đặt trước ô mật khẩu ngay sau khối "Gửi lại mã"; khi chưa có token, thông báo hướng dẫn tích ô xác minh rồi bấm lại.
- **R4:** `lib/focus.ts` (`focusFirstError`) dùng ở forgot, reset, đổi mật khẩu, đổi liên hệ (lỗi client và 422 server); các `Field` có `id`. Chưa thêm `aria-live` vào `Field` (v2, dùng chung): lỗi được đọc qua `aria-describedby` khi ô nhận focus; nếu muốn `role=alert` thì giao designer.
- **R5:** link đứng riêng có `inline-flex min-h-11 items-center` (Quên mật khẩu?, Đăng ký ngay, Quay lại đăng nhập, Đổi).
- **R6:** hộp thoại chỉ đóng khi tới trang đăng nhập/đăng ký/quên mật khẩu (không đóng theo Back); lưu `href` lúc xảy ra sự kiện. Ghi chú trong code: FW4/FW5 phải `pause()` player khi hộp thoại mở.
- **R7:** test Back, `loginUrl` có query (`isAuthPath` so pathname đã bỏ query), R2.
- **R8:** 429 đổi liên hệ dùng câu chung "Bạn đã thử quá nhiều lần". **R9:** reset dùng `Retry-After` (`classifyResetError` trả `retryAfterSeconds`). **R10:** `LoginForm` (thành công) và `LogoutButton` gọi `clearResetLogin()`; không che email ở bước 2 (chờ PO). **R11:** bỏ chữ "10 phút" ở bước forgot/reset (bản preview của designer giữ nguyên). **R13:** `seed-e2e-auth.sh` từ chối ngoài `local`/`testing`.
- **Kiểm tra:** tsc, lint, unit web 25 file / 221 test xanh; e2e thật `--workers=1` auth + otp + password + session + fw1-v2-qa: 61 pass (đã seed). Không build trên `.next` dùng chung.

## Re-review (laravel-reviewer, 2026-10-07)
**Kết luận:** APPROVE (không còn BLOCKER; 1 NIT mới). Chỉ đọc code, không build/chạy test.

| Mục | Kết quả | Ghi chú |
|---|---|---|
| R1 | Đạt | `CAPTCHA_PATHS`/`isCaptchaHref` dùng chung với `proxy.ts`; `AppLink` render `<a>` cho 3 đích captcha (bỏ query/hash), còn lại `next/link`, gắn làm `UiLinkProvider` nên phủ header, footer, `ButtonLink`, `CatalogBusy`. Rà toàn `app/ components/ lib/`: không còn `router.push/replace`, `redirect()` server hay `Link` nào (ngoài bản xem trước `/v2`, route riêng) trỏ tới `/dang-ky`, `/quen-mat-khau/**`; forgot→reset dùng `window.location.assign`. AppLink không đổi active state (header so `pathname`, không phụ thuộc loại link); mất prefetch chỉ ở 3 đích captcha (chấp nhận), `ref`/props truyền qua `...rest`. |
| R2 | Đạt | `login` không xoá trước khi rời; `LoginForm` dọn khi `trang-thai=dat-lai-xong`. |
| R3 | Đạt | `onError` + hướng dẫn tích ô xác minh, widget đặt ngay sau khối gửi lại. |
| R4 | Đạt (chấp nhận hoãn aria-live) | `focusFirstError` ở 4 form, kể cả lỗi 422 server; ô có `id`. Focus vào ô `aria-invalid` + `aria-describedby` làm trình đọc màn hình đọc lỗi, nên đủ cho 3.3.1 với form ngắn. `role=alert` trong `Field` là thay đổi component dùng chung (admin cũng dùng): để designer/FA3 quyết, ghi backlog. |
| R5, R6, R7 | Đạt | `min-h-11` cho link; hộp thoại chỉ đóng khi tới trang auth (Back không đóng), có ghi chú pause player cho FW4/5; `isAuthPath` bỏ query. |
| R8, R9, R11, R13 | Đạt | 429 câu chung; reset dùng `Retry-After`; seed từ chối ngoài local/testing. |
| R10 | Chấp nhận | Dọn `login` khi đăng nhập/đăng xuất. Chưa che email ở bước 2 vì design ghi "Tài khoản: …", chờ PO: không chặn. |
| R12 | Đạt | Chuyển thẳng sang bước 2 theo design. |

### R14 [NIT] `next=` trỏ tới route captcha vẫn điều hướng mềm
- Vị trí: `components/auth/LoginForm.tsx:86` (`router.replace(safeRedirect(next, "/"))`).
- `/dang-nhap?next=/dang-ky` (hoặc `/quen-mat-khau`) hợp lệ với `safeRedirect`; sau đăng nhập đi mềm vào route captcha và Turnstile bị chặn. Hiếm (người đã đăng nhập vào trang đăng ký không có lý do; forgot đã 403). Gợi ý: `isCaptchaHref(target) ? window.location.assign(target) : router.replace(target)`.

**Cho QA:** vẫn cần `next build` + chạy bản production riêng (Dev chưa build) và kiểm Turnstile thật (site key bật) cho đường header "Đăng ký" từ trang khóa học và "Quên mật khẩu?" từ `/dang-nhap`.
