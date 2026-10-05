# REVIEW: FW1 phần 1 (Đăng ký / Đăng nhập / Đăng xuất — apps/web + packages/ui)
**Kết luận:** APPROVE (không có BLOCKER; 4 SHOULD nên sửa trước khi chạy với backend thật)
**Phạm vi:** working tree chưa commit, chỉ `frontend/` · ~30 file (page.tsx, proxy.ts, package.json, `lib/auth/*`, `components/auth/*`, `app/dang-ky`, `app/dang-nhap`, `packages/ui` FormField/TextInput/PasswordInput/Select/TurnstileWidget). Review bằng đọc code; không chạy lint/test/build (tin báo cáo của Dev). Chưa có e2e, chưa thử với backend thật.

## Tổng quan
Code gọn, đúng ranh giới: form dùng react-hook-form + zod chỉ để UX, lỗi 422 map về field, thông điệp đăng nhập chung (BR5), mật khẩu bị xoá sau lỗi, Turnstile token dùng 1 lần và được mount lại. Không có `any`, không `dangerouslySetInnerHTML`, token không vào storage. Các điểm cần soi đều xử lý đúng hướng; còn vài lệch nhỏ với contract mới và một chỗ lỗi có thể "câm".

## Phát hiện

### R1 [SHOULD] Lỗi 422 của field phụ huynh/referral đang ẩn sẽ không hiển thị, form "câm"
- Vị trí: `apps/web/components/auth/RegisterForm.tsx` (`onSubmit`, catch) + `lib/auth/errors.ts` (`REGISTER_FIELD_MAP`)
- Vấn đề: nếu client cho là đủ tuổi (field `parent_*` không render) mà server cho là chưa đủ (config `/config/public` cache `revalidate: 60`, đổi `PRIVACY_PARENT_CONSENT_AGE`, hoặc lệch ngày quanh nửa đêm), server trả `parent_phone`/`parent_email` required. `setError` gọi trên field không có trong DOM → người dùng bấm nút, captcha reset, không thấy gì. Tương tự `referral_code` khi flag tắt.
- Đề xuất: khi field lỗi không được render (`parent_*` khi `!isMinor`, `referral_code` khi `!referralEnabled`) thì đưa message vào `banner`. Hoặc: nếu server báo lỗi `parent_*` thì ép hiện khối phụ huynh (state `forceParent`). Thêm 1 test cho nhánh này.
- Ngoài ra `setError` từ server không dời focus: gọi `setFocus` vào field lỗi đầu tiên (hoặc focus banner) để người dùng bàn phím/screen reader biết có lỗi.

### R2 [SHOULD] Banner "chờ phụ huynh" quyết định bằng tuổi client + query string, không dùng `parent_consent_status` của server (điểm 6)
- Vị trí: `RegisterForm.tsx` (`const minor = isBelowConsentAge(...)`; `router.replace('/?dang-ky=...')`), `components/auth/RegisterSuccessBanner.tsx`, `lib/auth/api.ts` (`AuthUser { name? }`)
- Vấn đề: contract §2.2 giờ có shape `user` đầy đủ, `registerStudent` đã nhận về nhưng bỏ đi. Hệ quả: (a) nếu client/server lệch tuổi (xem R1) banner nói sai trạng thái phụ huynh; (b) `?dang-ky=phu-huynh` do người dùng sửa tay/F5 vẫn hiện lại banner gây hiểu nhầm; (c) ghi chú "Contract chưa liệt kê field" trong `api.ts` đã lỗi thời.
- Đề xuất: khai báo type + zod schema `authUserSchema` (`id, name, email, phone, role, grade_level, is_verified, parent_consent_status: 'not_required'|'pending'|'granted'|'revoked'`), parse response register/login/me (lỗi parse → coi như thất bại hiển thị chung, không crash). Dùng `parent_consent_status === 'pending'` để chọn banner `phu-huynh`. Với chống F5 lặp banner: dùng `router.replace('/')` + sessionStorage cờ một lần, hoặc đọc trạng thái từ `/auth/me` (`is_verified`, `parent_consent_status`) để hiển thị banner thay vì query. Design US-001 §1 còn yêu cầu nút "Xác thực ngay" → `/xac-thuc-otp` (T04, chưa có): ghi rõ là việc chuyển sang FW1 phần 2.

### R3 [SHOULD] `fetchCurrentUser` nuốt `SESSION_REPLACED` (điểm 1)
- Vị trí: `lib/auth/api.ts` `fetchCurrentUser`
- Đánh giá: dùng `fetch` thẳng là ĐÚNG ý — `authFetch` sẽ phát `login-required` khi 401 `UNAUTHENTICATED`, đá khách khỏi trang công khai. GET nên không cần CSRF; `credentials: include`, `no-store` và `X-Device-Id` đã đủ. Nhưng hiện mọi lỗi (kể cả 401 `SESSION_REPLACED`, ADR-003 / US-014) đều thành "khách" → học sinh bị đăng xuất do đăng nhập máy khác chỉ thấy nút "Đăng nhập" mà không có overlay chặn toàn màn hình như thiết kế.
- Đề xuất: đọc body khi `!res.ok`; nếu `code === 'SESSION_REPLACED'` gọi `dispatchAuthEventIfNeeded` (export từ api-client) rồi trả `null`; các code khác (UNAUTHENTICATED/SESSION_EXPIRED) vẫn im lặng. Cần xác nhận với backend T04 `/auth/me` trả code nào cho khách (hiện route chưa tồn tại → 404 → luôn là khách; sau login xong thanh nav vẫn hiện "Đăng nhập/Đăng ký" cho tới khi T04 xong — chấp nhận được nhưng nên ghi vào board). Cũng nên validate shape `me` bằng schema (R2) thay cho `as AuthUser`.

### R4 [SHOULD] CSP: nới `script-src`/`connect-src`/`frame-src` cho MỌI trang, và `script-src` host bị vô hiệu (điểm 3)
- Vị trí: `apps/web/proxy.ts`
- Đánh giá: với `'strict-dynamic'` trình duyệt CSP3 bỏ qua allowlist host trong `script-src`; script Turnstile do `TurnstileWidget` chèn động từ bundle có nonce nên vẫn chạy. Vì vậy `https://challenges.cloudflare.com` trong `script-src` chỉ có tác dụng cho trình duyệt cũ không hỗ trợ strict-dynamic (ở đó nó lại là allowlist rộng cho cả domain). `connect-src` và `frame-src` thì cần thật. Không có bước nào cần `unsafe-inline`.
- Vấn đề: cả 3 nguồn đang áp lên toàn bộ trang (checkout, học video…) trong khi chỉ `/dang-ky` dùng Turnstile; ADR-004 §2.6 (dòng 87) chưa ghi nhận thay đổi.
- Đề xuất: giữ `script-src` đúng ADR (bỏ host Cloudflare), chỉ thêm `connect-src`/`frame-src` Cloudflare khi `request.nextUrl.pathname` là `/dang-ky` (hoặc trang dùng captcha khác như quên mật khẩu US-015), và cập nhật ADR-004 + thêm assertion CSP vào e2e `home.spec.ts`/test mới. Kiểm thủ công trên trình duyệt thật rằng Turnstile render không có violation trong console (chưa ai chạy).

### R5 [NIT] Regex SĐT client chặt hơn `PhoneNumber::normalize` (điểm 5)
- Vị trí: `lib/auth/schemas.ts` `VN_PHONE_RE`
- Client chấp nhận `0[35789]xxxxxxxx` và `+84[35789]xxxxxxxx`; backend còn chấp nhận `84…` không `+`, khoảng trắng, dấu chấm, gạch, ngoặc (`0912 345 678`, `091.234.5678`). Không có chiều ngược lại (client cho qua mà server từ chối) → an toàn, không lỗi sai nghiệp vụ. Nhưng người dùng dán số có khoảng trắng sẽ bị chặn dù server nhận được. Đề xuất: bỏ `[\s.\-()]` trước khi test regex (không đổi giá trị gửi lên, server tự chuẩn hoá), hoặc ghi rõ hint "10 số, bắt đầu 03/05/07/08/09". Email: client max 255, server 254 (không đáng kể).

### R6 [NIT] A11y / mobile (điểm 9)
- Nút "Đăng xuất" `size="sm"` cao 36px, link "Đăng nhập" trong `AuthNav` không có vùng chạm 44px (design-system yêu cầu 44px).
- Trường bắt buộc chỉ đánh dấu bằng `*` `aria-hidden`; form `noValidate` nên thiếu `aria-required="true"` — thêm vào `FormField`/control.
- `PasswordInput`: vừa `aria-pressed` vừa đổi chữ "Ẩn/Hiện" → đọc trùng nghĩa. Chọn một: giữ `aria-pressed` với nhãn cố định "Hiện mật khẩu".
- Lỗi field `<p>` không có `role="alert"`/`aria-live`; chỉ banner (Alert có `role="alert"`) được đọc.
- Trang `/dieu-khoan` và `/chinh-sach-du-lieu` chưa tồn tại → link trong checkbox mở tab 404. Dev đã ghi chú; cần PO/BA bổ sung task (US-017 BR1 yêu cầu link hoạt động trước khi go-live).
- Khung 480px, input 16px (`text-base`, không bị iOS zoom), `h-11`: ổn ở 375px. Chưa chụp kiểm thật — nên QA xem khối phụ huynh + Turnstile (300px) không tràn.

### R7 [NIT] Khác
- `AuthNav` gọi `/auth/me` mỗi lần mở trang chủ, mọi khách đều tạo 1 preflight (header `X-Device-Id`) + 1 lỗi 401 trong console. Chấp nhận; cân nhắc cookie cờ không-httpOnly "đã đăng nhập" nếu số request thành vấn đề.
- `LoginForm`: người đã đăng nhập mở `/dang-nhap` sẽ nhận lỗi từ `guest` middleware (thông điệp server) — nên redirect khi `/auth/me` có user (FW1 phần 2).

## Đối chiếu các điểm Dev yêu cầu soi
| # | Điểm | Kết quả |
|---|---|---|
| 1 | `/auth/me` dùng fetch thẳng | Đúng ý (tránh `login-required`); thiếu xử lý `SESSION_REPLACED`, chưa validate shape → R3 |
| 2 | Xoá cache CSRF sau login/register/logout | Đúng: chỉ xoá khi thành công (login/register) và luôn xoá khi logout (`finally`); phiên regenerate nên token cũ vô nghĩa, đã có retry 419 làm lưới an toàn. Không còn vấn đề |
| 3 | CSP Turnstile | Chạy được với strict-dynamic nhưng nới toàn site, host `script-src` thừa → R4 |
| 4 | Field gửi lên vs `RegisterRequest` | Khớp: name, date_of_birth (Y-m-d), email, phone, grade_level (số), password(+_confirmation), parent_*, referral_code, accept_terms/privacy (true), captcha_token, device_id (≤64, UUID 36). `Password::defaults()` = min 8 khớp client. `parent_*` chỉ gửi khi dưới tuổi, `referral_code` chỉ khi flag bật, đều đúng; rủi ro lệch tuổi → R1. Server dùng `age_timezone=Asia/Ho_Chi_Minh`, client `todayInVietnam()` cùng múi giờ |
| 5 | Regex SĐT | Client chặt hơn server, không lệch chiều nguy hiểm → R5 |
| 6 | Banner theo tuổi client | R2 |
| 7 | `safeRedirect` / `?next=` | OK: chặn `//`, `/\`, ký tự điều khiển, không bắt đầu `/`; `LoginForm` luôn đi qua `safeRedirect`; `router.replace` chỉ nhận đường dẫn nội bộ. Không phát hiện bypass |
| 8 | XSS khi hiển thị `name` | OK: render bằng JSX text, không `dangerouslySetInnerHTML`; `name` không được dùng trong URL/attr |
| 9 | A11y, 375px | R6 (chỉ NIT) |
| 10 | Package mới | OK: chỉ `react-hook-form`, `@hookform/resolvers` (zod đã có); không thêm package Turnstile (tự nạp script). Nên dùng version pin như phần còn lại của repo (`next`, `react`, `zod` đang pin chính xác; hai package mới dùng `^`) — NIT |

## Đối chiếu acceptance criteria (FW1 phần 1 / US-001)
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| Đăng ký đủ field + 2 checkbox tách riêng, không tick sẵn | `RegisterForm`, `ConsentCheckboxGroup` | Link điều khoản/chính sách 404 (chưa có trang) |
| Dưới ngưỡng tuổi → 2 field phụ huynh, ≥1 | `isBelowConsentAge`, schema superRefine | Cần xử lý trường hợp lệch server (R1) |
| Turnstile | `TurnstileWidget` | Chưa kiểm thật trên trình duyệt (R4) |
| Đăng ký xong tự đăng nhập, về `/` + banner | `router.replace('/?dang-ky=…')` | Banner theo client (R2); nút "Xác thực ngay" chưa có (T04) |
| Đăng nhập, lỗi chung (BR5), khoá, sai cổng | `loginErrorMessage` | OK |
| Đăng xuất | `LogoutButton` + `logoutStudent` | OK; 401 coi như đã đăng xuất |
| `?next=` an toàn | `safeRedirect` | OK |
| Quên mật khẩu, OTP, đổi mật khẩu | — | Ngoài phạm vi phần 1 (US-015, T04) |

## Gợi ý cho QA
- Chạy thật với backend T03: đăng ký người đủ tuổi / dưới 18 / đúng sát sinh nhật 18 quanh nửa đêm (giờ VN); kiểm CSRF sau login (không được 419 ở thao tác POST kế tiếp), logout rồi login lại.
- Cố tình làm client và server lệch ngưỡng tuổi để thấy R1.
- Turnstile trên trình duyệt thật + CSP: mở console, không có violation; chặn `challenges.cloudflare.com` → nút bị disable kèm thông điệp rõ.
- `/dang-nhap?next=//evil.com`, `?next=/\evil.com`, `?next=%2F%2Fevil.com`, `?next=https://evil.com` → đều về `/`.
- Tên chứa `<script>`/`"><img onerror>` hiển thị đúng dạng chữ ở "Xin chào".
- 375px: form đăng ký đầy đủ khối phụ huynh + Turnstile, bàn phím iOS, tab bằng bàn phím và screen reader cho lỗi field.
- Đăng nhập máy B trong lúc máy A đang ở trang chủ → A phải thấy overlay (R3).
