# API contract & cấu trúc code — VitaminVui MVP

**Liên quan:** [README.md](README.md) · [data-model.md](data-model.md) · [tasks.md](tasks.md) · [review-traceability.md](review-traceability.md) · ADR-001..004
**Cập nhật 2026-09-25:** sửa theo review Security (`docs/security/audit-2026-09-25.md`) và DBA (`docs/db/design-review.md`); Laravel 13 / MySQL 8.4; admin tách origin.

## 1. Quy ước API

### 1.1 Host & version
- Laravel 13 chỉ làm **API JSON** cho 2 frontend Next.js tách rời (ADR-004). Không có Blade cho người dùng (chỉ template email). Tên component `<x-...>` trong `docs/design/` chỉ là tham chiếu giao diện.
- **2 host API, cùng 1 app** (ADR-004 §2.1):

  | Host | Frontend gọi | Route file | Phiên |
  |---|---|---|---|
  | `api.vitaminvui.vn` | web học sinh `vitaminvui.vn` | `routes/api.php` | cookie `vv_session` |
  | `admin-api.vitaminvui.vn` | admin `admin.vitaminvui.vn` | `routes/admin.php` | cookie `vv_admin_session` |

  Route quản trị chỉ tồn tại trên host admin-api. Webhook nằm trên host api.
- **Versioning:** tiền tố `/api/v1`.
  - Trong v1 chỉ được *thêm* trường hoặc endpoint.
  - Đổi tên/xoá trường, đổi kiểu dữ liệu, đổi ý nghĩa mã lỗi → phải lên `/api/v2` và chạy song song.
  - URL webhook cố định, không đổi theo version.

### 1.2 Xác thực & CSRF
- Sanctum SPA cookie session. Cookie **host-only**, `HttpOnly`, `Secure`.
- Frontend gọi `GET /api/v1/csrf-token` → `{ "token": "..." }`. Mọi request thay đổi dữ liệu phải gửi header `X-CSRF-TOKEN`.
- Mọi request gửi `credentials: 'include'`, `Accept: application/json`, `X-Device-Id` (UUID).
- 419 = token CSRF hết hạn: gọi lại `csrf-token` rồi thử lại **1 lần**.

### 1.3 Nhóm middleware chuẩn (S19)
Test kiến trúc bắt buộc (T02) kiểm mọi route dưới đây có đúng nhóm middleware.

| Nhóm | Middleware |
|---|---|
| `public` (host api) | `throttle:<tên>` theo từng route; response công khai `Cache-Control: public, max-age=60`, **không đọc cookie** |
| `student` (host api, cần đăng nhập) | `auth:sanctum, account.active, student.single_session, no_store` (+ `role:hoc_sinh`, `account.verified`, `parent.consent` khi route yêu cầu) |
| `staff` (host admin-api, cần đăng nhập) | `admin.origin, auth:sanctum, account.active, staff.idle, staff.mfa_passed, staff.password_fresh, no_store, role:admin,quan_ly_trang,giao_vien` + Sanctum `AuthenticateSession` |
| `webhook` | `throttle:webhook`, giới hạn body 16 KB; không có session/CSRF |

Ngoại lệ duy nhất: `POST /auth/logout` và `POST /admin/auth/logout` chỉ cần `auth:sanctum`. Mọi action còn gọi `$this->authorize()`; middleware `role` chỉ là lớp chặn thô.

### 1.4 Response
- **Hình dạng (chốt 2026-09-25):**
  - **Object đơn lẻ trả phẳng, không bọc `data`.** Ví dụ `GET /csrf-token`, `GET /config/public`, `GET /auth/me`, `GET /courses/{slug}`, `GET /orders/{code}`. Backend gọi `JsonResource::withoutWrapping()` trong `AppServiceProvider` (T01).
  - **Danh sách luôn là `{ "data": [...], "meta": {...}, "links": {...} }`** (§1.5), kể cả danh sách không phân trang (vd `GET /subjects` → `{ "data": [...] }`).
  - **Lỗi** theo envelope §1.7.
- API Resource, `snake_case`. Thời gian dạng ISO 8601 có offset (`2026-09-25T14:30:00+07:00`). Tiền là số nguyên VNĐ.
- Mọi response có header `X-Request-Id`; lỗi 5xx kèm `request_id` trong body.
- Response đã xác thực luôn có `Cache-Control: no-store, private` và `Vary: Cookie, Origin` (S16).

### 1.5 Phân trang
- **Length-aware** (`paginate()`) cho danh mục (25/trang), "Khóa học của tôi" (12), "Đơn hàng của tôi" (10), danh sách quản trị nhỏ (chuyên đề, mã giảm giá, yêu cầu duyệt: 25):
  ```json
  { "data": [], "meta": { "current_page": 1, "per_page": 25, "total": 1234, "last_page": 50 }, "links": { "next": "...", "prev": null } }
  ```
- **Cursor** (`cursorPaginate()`, keyset theo `created_at desc, id desc`) cho **danh sách đơn quản trị** (DBA #9):
  ```json
  { "data": [], "meta": { "per_page": 25, "next_cursor": "eyJ...", "prev_cursor": null, "total": 1234 } }
  ```
  `total` lấy bằng câu `COUNT(*)` riêng trên cùng bộ lọc. Bộ lọc khoảng ngày là bắt buộc và ≤ 366 ngày. UI chỉ có nút Trước/Tiếp.
- `per_page` chỉ cho phép ở admin (25/50). Sắp xếp luôn có khoá phụ `id`.

### 1.6 Rate limit (2 lớp: tài khoản + IP — S9, S10, S18)

| Limiter | Theo tài khoản / định danh | Theo IP |
|---|---|---|
| `login` (cả 2 host) | 10 lần sai/giờ/`login` (thông điệp chung, không khoá cứng tài khoản) | 50/giờ |
| `register` | — (có captcha Turnstile) | 30/giờ |
| `password-reset` | 5/giờ/định danh (captcha) | 30/giờ |
| `otp-send` | cooldown 60s, 5/giờ, **10/ngày**/user | 30/giờ |
| `otp-verify` | 5/phút, **20/ngày**/user; vượt → khoá xác thực 24h | 60/giờ |
| `coupon` | 10/phút, **30 lần sai/ngày**/user | 60/giờ |
| `checkout`, `pay` | 10/phút/user | — |
| `check-payment` | 1 lần/30s/đơn | — |
| `playback` | 30/phút/user | — |
| `heartbeat` | 6/phút/user/bài | — |
| `catalog` | — | 120/phút |
| `csrf` | — | 120/phút (PO chốt 2026-10-05, lớp học dùng chung NAT) |
| `webhook` | — | 120/phút |
| `export` | 10 lần tạo/ngày/user | — |
| `admin-password` (T28) | 5/phút, 20/giờ/user | — |

IP thật lấy qua `TrustProxies` với danh sách IP cụ thể (không `*`).

### 1.7 Lỗi thống nhất
```json
{ "message": "Thông điệp tiếng Việt cho người dùng", "code": "SESSION_REPLACED", "errors": { "field": ["..."] }, "request_id": "..." }
```
Lỗi nghiệp vụ ném `App\Exceptions\DomainException($code, $message, $status, $context)`, render trong `bootstrap/app.php`.

| HTTP | code | Khi nào |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Chưa đăng nhập / phiên hết hạn |
| 401 | `SESSION_REPLACED` | HS bị đăng xuất vì đăng nhập thiết bị khác (ADR-003). Tombstone `replaced` (renderer `AuthenticationException`) hoặc phiên không khớp `current_session_id` (middleware `student.single_session`) với `X-Device-Id` khác thiết bị đang giữ phiên |
| 401 | `SESSION_EXPIRED` | Phiên cũ trên cùng thiết bị (`X-Device-Id` hợp lệ == thiết bị đang giữ phiên; bấm đăng nhập 2 lần). FE: chuyển `/dang-nhap`, không báo "thiết bị khác" |
| 401 | `SESSION_REVOKED` | Đã đổi/đặt lại mật khẩu (tombstone `password_changed`) |
| 401 | `STAFF_IDLE_TIMEOUT` | Phiên quản trị quá 120 phút không hoạt động hoặc quá 12 giờ |
| 403 | `ACCOUNT_LOCKED` | Tài khoản bị khoá — ở login **chỉ trả khi mật khẩu đúng** (S20); route đã đăng nhập: `account.active`; phiên HS bị huỷ do khoá (tombstone `locked`) |
| 403 | `ACCOUNT_NOT_VERIFIED` | Chưa xác thực OTP mà checkout/đăng ký học miễn phí |
| 403 | `PARENT_CONSENT_REQUIRED` | HS dưới ngưỡng tuổi chưa có xác nhận phụ huynh (US-017, chờ pháp chế) |
| 403 | `MFA_REQUIRED` | Staff chưa nhập OTP đăng nhập |
| 403 | `PASSWORD_CHANGE_REQUIRED` | Staff phải đổi mật khẩu lần đầu |
| 403 | `WRONG_PORTAL` | Đăng nhập đúng mật khẩu nhưng sai trang (HS ở admin hoặc staff ở web) |
| 403 | `ORIGIN_NOT_ALLOWED` | Gọi admin-api từ origin khác `ADMIN_URL` |
| 403 | `FORBIDDEN` | Policy từ chối |
| 403 | `COURSE_NOT_OWNED` | Chưa sở hữu khóa → frontend chuyển về trang chi tiết |
| 404 | `NOT_FOUND` | |
| 409 | `CHECKOUT_CHANGED` | Giỏ/giá/mã thay đổi — kèm `preview` mới |
| 409 | `COUPON_EXHAUSTED` | Hết chỗ mã khi tạo đơn/tạo link mới (ADR-001 §6) |
| 409 | `ALREADY_IN_CART`, `ALREADY_OWNED`, `ENROLLMENT_PENDING`, `ALREADY_PROCESSED` | |
| 409 | `COURSE_UNAVAILABLE` | Khóa đã xoá mềm: không duyệt được yêu cầu / không cấp quyền sau thanh toán (T14; T19 chuyển đơn needs_review/hoàn tiền) |
| 422 | `COURSE_NOT_FREE` | Xin học miễn phí cho khóa có phí (T14) |
| 409 | `SUBJECT_IN_USE` | Xoá chuyên đề đang gán khóa học (T06) |
| 409 | `COURSE_HAS_ENROLLMENTS`, `INVALID_COURSE_STATE` | Xoá khóa đã có enrollment; ngừng bán khóa chưa xuất bản (T08) |
| 422 | `COURSE_NOT_PUBLISHABLE` | Xuất bản khóa chưa có chương/bài (T08) |
| 413 | `PAYLOAD_TOO_LARGE` | |
| 422 | `VALIDATION_ERROR`, `CAPTCHA_FAILED` | |
| 422 | `COUPON_INVALID` (**gộp**: không tồn tại / chưa bắt đầu / đã vô hiệu — S18), `COUPON_EXPIRED`, `COUPON_ALREADY_USED`, `COUPON_NOT_APPLICABLE` | |
| 422 | `AMOUNT_BELOW_GATEWAY_MIN` | Tổng sau giảm 1–999đ (chờ PO) |
| 429 | `TOO_MANY_ATTEMPTS` | |
| 503 | `OTP_DELIVERY_FAILED` | Không gửi được mã OTP (mail/queue/SMS lỗi). Mã vừa tạo bị xoá, không tính vào cooldown/trần giờ/ngày; gửi lại được ngay |
| 502 | `PAYMENT_GATEWAY_UNAVAILABLE` | Không tạo/không tra được giao dịch MoMo |

## 2. Route

### 2.1 Công khai (host api)

| Method | URI | Controller@action | Middleware / Policy | Request | Response |
|---|---|---|---|---|---|
| GET | /csrf-token | `Auth\CsrfController` | — | — | `{token}` (không cache) |
| GET | /config/public | `PublicConfigController@show` | public | — | **Object phẳng**, chỉ gồm các khoá trong allowlist (ví dụ ngay dưới bảng) |
| GET | /subjects | `Catalog\SubjectController@index` | public | — | `{data:[{id,name,slug}]}` chuyên đề `active`, sắp `name asc, id asc`, không phân trang. Cùng nhóm cache với `/courses` |
| GET | /courses | `Catalog\CourseController@index` | public, throttle:catalog | `CourseSearchRequest`: grade (6–12), subject_ids[] (≤ 20), q (≤ 100, escape LIKE — S24), sort (`newest`\|`popular`\|`featured`), page | 25 khóa `published` **T10 chốt:** `subject_ids[]` = OR (khóa thuộc ít nhất 1 chuyên đề); id chuyên đề ẩn/không tồn tại không khớp khóa nào (kết quả rỗng, không lỗi); `q` bỏ dấu + chữ thường, tách từ (≤ 8), mọi từ phải khớp `search_text` (AND); tham số sai (`grade`=99, `sort` lạ, `page`<1, `q`>100) → **422** `VALIDATION_ERROR`; `sort`: `newest` (mặc định `published_at desc, id desc`), `popular` (`enrollments_count desc` + như trên), `featured` (`manual_order` tăng dần, null xếp sau + như trên). Item: `{id,title,slug,short_description,grade_level,price,is_free,thumbnail_url,enrollments_count,published_at,subjects:[{id,name,slug}],teachers:[{id,name}]}`; chỉ chuyên đề active. Response `{data, meta:{current_page,per_page,total,last_page}, links:{next,prev}}`. Header: `Cache-Control: public, max-age=60` + `ETag` (If-None-Match → 304), `Vary: Origin` (không bao giờ Cookie), không Set-Cookie; lỗi 4xx không có `public` |
| GET | /courses/{course:slug} | `Catalog\CourseController@show` | public | — | Chi tiết công khai + outline (tên bài, thời lượng, `is_preview`) + GV + `enrollments_count`. **Không chứa URL/ID video**. Khóa `unpublished`/xoá → 404 **T10 chốt (object phẳng):** `{id,title,slug,short_description,description,grade_level,price,is_free,thumbnail_url,enrollments_count,published_at,subjects,teachers:[{id,name,bio,avatar_url}],lessons_count,total_duration_seconds,has_preview,outline:[{id,title,position,lessons:[{id,title,position,duration_seconds,is_preview}]}]}`. Chương/bài xoá mềm không lộ; không có `video_source`/provider/asset. Slug sai định dạng hoặc không published → 404 `NOT_FOUND` (không cache public). `description` sanitize khi ghi (T08) và lọc lại khi trả ra (HtmlSanitizer); FE vẫn DOMPurify |
| GET | /courses/{course:slug}/viewer-state | `Catalog\CourseController@viewerState` | student (không cần verified) | — | `viewer_state` (`can_buy`\|`in_cart`\|`can_register_free`\|`pending_approval`\|`owned`) + `resume_lesson_id`. Tách khỏi `show` để `show` cache được (S16) **T10 chốt:** `{viewer_state, resume_lesson_id}`; ưu tiên `owned` > `pending_approval` > `can_register_free` (price=0) > `can_buy`. `in_cart` chưa trả (nối ở T16). `resume_lesson_id` chỉ khi `owned`: bài có `lesson_progress.last_accessed_at` mới nhất (bài chưa xoá), chưa có thì bài đầu theo (chương, bài). Khóa không published: chỉ người `owned` thấy, người khác 404. Luôn `no-store, private` |
| GET | /preview/lessons/{lesson}/playback | `Learn\PlaybackController@preview` | public, throttle:playback (theo IP) · `LessonPolicy@preview` (bài `is_preview`, chưa xoá, khóa published) | — | `PlaybackInfo` (không ràng IP) |

Ví dụ `GET /api/v1/config/public` (phẳng — khớp giả định của FE0; thêm khoá mới = thay đổi tương thích, xoá/đổi tên khoá = v2):
```json
{
  "referral_code_enabled": true,
  "quiz_time_limit_enabled": true,
  "otp": { "ttl_minutes": 10, "resend_cooldown_seconds": 60 },
  "grades": [6, 7, 8, 9, 10, 11, 12],
  "captcha_site_key": null,
  "policy_version": "2026-09",
  "parent_consent_age": 18
}
```
`captcha_site_key` = `null` khi chưa cấu hình Turnstile (local).

### 2.2 Xác thực học sinh (host api)

| Method | URI | Controller@action | Middleware | Request | Response |
|---|---|---|---|---|---|
| POST | /auth/register | `Auth\RegisterController` | guest.student (T05: đã đăng nhập hợp lệ → 403 `FORBIDDEN`; phiên cũ đã bị thay thế/đăng xuất coi như khách), throttle:register | `RegisterRequest`: name (≤150), date_of_birth, email, phone (VN), grade_level (6–12), password (min 8, confirmed), parent_phone/parent_email (≥1 khi dưới `privacy.parent_consent_age`), referral_code (chỉ khi flag bật), **accept_terms (accepted)**, **accept_privacy (accepted)**, **captcha_token**, device_id. `role`/`status`/`*_verified_at` **không nằm trong validated()** (S17) | 201 user; tự đăng nhập + bind phiên; tạo `consents` (self); gửi OTP; nếu dưới ngưỡng tuổi → `parent_consent_status=pending` + gửi email xác nhận cho phụ huynh (US-017) |
| POST | /auth/login | `Auth\LoginController@store` | throttle:login (T05: **không** `guest` — đăng nhập lại khi cookie cũ còn sống là hợp lệ, ADR-003) | `LoginRequest`: login (email hoặc SĐT), password, device_id (UUID; sai định dạng bị bỏ qua, không 422) | 200 user; bind phiên 1 thiết bị, huỷ session cũ + tombstone. Sai → 422 thông điệp chung. Khoá → 403 `ACCOUNT_LOCKED` **chỉ khi mật khẩu đúng**. Vai trò không phải `hoc_sinh` → 403 `WRONG_PORTAL` (sau khi mật khẩu đúng) |
| POST | /auth/logout | `Auth\LoginController@destroy` | auth:sanctum | — | 204 |
| GET | /auth/me | `Auth\MeController` | student | — | **T04:** đúng shape `user` ở khối "Bổ sung từ T03" (phẳng, không `parent_*`). `cart_count` thêm ở T16, thông tin phụ huynh **đã che** thêm ở T29 (thêm field = tương thích) |
| POST | /auth/otp/send | `Auth\OtpController@send` | student, throttle:otp-send | `SendOtpRequest`: channel ∈ `config('auth.otp.channels')` (production MVP: chỉ `email`) | 202 `{ resend_available_at }` (ISO 8601 có offset). Mã chỉ gửi tới email/SĐT **hiện tại** của tài khoản, chưa xác thực; đã xác thực → 422 field `channel` |
| POST | /auth/otp/verify | `Auth\OtpController@verify` | student, throttle:otp-verify | `VerifyOtpRequest`: code (6 số) | 200 user phẳng (`is_verified=true`). Tăng `attempts` nguyên tử trước khi so (data-model §3.1). Sai → 422 `VALIDATION_ERROR` field `code`; hết hạn/không có mã → 422 field `code` (thông điệp hết hạn); hết 5 lượt của mã → 429 `TOO_MANY_ATTEMPTS` (phải gửi mã mới); vượt throttle → 429 + `Retry-After` |
| PUT | /auth/contact | `Auth\ContactController@update` | student | email/phone mới | 200 `{ resend_available_at: string\|null }`. Huỷ MỌI OTP cũ, reset `*_verified_at` tương ứng, gửi OTP mới (S9). Request: `email` và/hoặc `phone` (≥ 1; unique; chuẩn hoá như đăng ký). Không đổi gì → 200 `null`; chỉ đổi SĐT khi kênh `sms` tắt (production) → reset `phone_verified_at`, không gửi mã, `null`. Không áp cooldown 60s (sửa nhầm email sau đăng ký) nhưng vẫn áp trần 5/giờ, 10/ngày (vượt → 429 và không đổi gì); limiter `contact` 10/giờ/user |
| POST | /auth/password/forgot | `Auth\PasswordResetController@request` | guest.student, throttle:password-reset | `ForgotPasswordRequest`: login, captcha_token | 202 `{ message, resend_available_at }` **luôn giống nhau** dù tài khoản có tồn tại/bị khoá hay không (US-015). Chi tiết ở khối T27 |
| POST | /auth/password/reset | `Auth\PasswordResetController@reset` | guest.student, throttle:otp-verify | `ResetPasswordRequest`: login, code (6 số), password, password_confirmation | 200 `{ message }`; huỷ mọi phiên (tombstone `password_changed`). Không tự đăng nhập: FE chuyển sang `/dang-nhap` |
| PUT | /auth/password | `Auth\PasswordController@update` | student, throttle:password-change | `ChangePasswordRequest`: current_password, password, password_confirmation | 200 `{ message, session_kept }`; huỷ phiên khác, bind lại phiên hiện tại (ADR-003) |

**Bổ sung từ T03 (2026-10-05):**
- `user` trong response register/login (phẳng): `id, name, email, phone, role, grade_level, is_verified (= email_verified_at != null), parent_consent_status`. Không có `parent_*` (chỉ `/auth/me` trả bản đã che).
- `password`: min 8, **max 128**; lỗi xác nhận không khớp nằm ở field `password_confirmation` (rule `same:password`, bắt buộc). Email (và `parent_email`) dùng `email:rfc,strict` + chặn khoảng trắng/comment RFC. Số điện thoại chấp nhận `+84`/`84`/dấu cách, lưu dạng `0xxxxxxxxx`; email lưu lowercase.
- Đã đăng nhập mà gọi register/login (route `guest`) → **403 `FORBIDDEN`**.
- Captcha kiểm **trước** mọi rule khác (kể cả unique); thiếu Origin hợp lệ → 400 `ORIGIN_NOT_ALLOWED` trước khi gọi captcha. Token Turnstile dùng 1 lần nên frontend phải reset widget sau mọi lỗi 422/`CAPTCHA_FAILED`.
- Login: bộ đếm "sai 10/giờ/`login` + 50/giờ/IP" chỉ tăng ở lượt SAI, xoá bộ đếm tài khoản khi đúng; `throttle:login` ở route chỉ là chống flood (120/phút/IP). Vượt → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`.
- Response register/login có `Cache-Control: no-store, private`.

**Bổ sung từ T05 (2026-10-05):** `POST /auth/register` tạo tài khoản xong mới bind phiên; nếu bind lỗi (DB) thì vẫn trả **201** nhưng **không có phiên** (không Set-Cookie phiên đăng nhập). FE: sau 201, gọi `/auth/me`; nhận 401 thì chuyển sang `/dang-nhap` (không đăng ký lại vì sẽ trùng email/SĐT). `device_id` ở register/login chỉ nhận chuỗi (mảng → 422), chuỗi sai định dạng/quá dài bị bỏ qua.

**Bổ sung từ T27 (2026-10-05) — quên/đặt lại/đổi mật khẩu (US-015):**
- `forgot`: captcha kiểm trước mọi rule (`CAPTCHA_FAILED` 422; thiếu Origin → 400 `ORIGIN_NOT_ALLOWED`). Luôn 202 `{ message: "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.", resend_available_at }`; `resend_available_at` luôn = now + 60s (không lộ gì). OTP `reset_password` gửi qua email SAU khi trả response (`defer`), chỉ cho học sinh `active`, chưa ẩn danh hoá, kể cả email chưa xác thực. Locked/GV/không tồn tại → vẫn 202, không gửi. Lỗi gửi/trần OTP của tài khoản bị nuốt (không lộ). Giới hạn: cooldown 1/phút, 5/giờ theo tài khoản (email và SĐT của cùng 1 người dùng chung hạn mức; tài khoản không tồn tại theo `LoginService::accountKey`), 30/giờ/IP → 429 `TOO_MANY_ATTEMPTS`-style + `Retry-After` (giống nhau cho tồn tại/không tồn tại). Học sinh đang đăng nhập hợp lệ gọi → 403 `FORBIDDEN`.
- `reset`: `password` min 8 max 128, lỗi xác nhận ở `password_confirmation` (quy ước T03). Sai mã → 422 field `code` ("Mã OTP không đúng…"), hết hạn/không có mã/tài khoản không tồn tại/bị khoá → 422 field `code` ("Mã OTP đã hết hạn…", cùng thông điệp để không lộ tồn tại), hết 5 lượt của mã → 429 `TOO_MANY_ATTEMPTS`. Mã gắn với email lúc gửi (đổi email sau đó → mã vô hiệu). Throttle `otp-verify` theo tài khoản (5/phút, 20/ngày) + 60/giờ/IP. Thành công: đổi mật khẩu + `password_changed_at` + huỷ phiên hiện hành (tombstone `password_changed` → phiên cũ nhận 401 `SESSION_REVOKED`) trong cùng transaction với việc tiêu thụ mã; audit `account.password_reset`.
- `PUT /auth/password`: sai mật khẩu hiện tại → 422 field `current_password` ("Mật khẩu hiện tại không đúng."); mật khẩu mới trùng hiện tại → 422 field `password` (chốt: từ chối; chưa kiểm N mật khẩu gần nhất). Thành công: huỷ phiên khác, `session_regenerate` + bind lại phiên hiện tại (cookie phiên MỚI trong Set-Cookie; giữ `current_device_id`), 200 `session_kept: true`. Nếu bind lại lỗi (DB) vẫn 200 nhưng `session_kept: false` và không còn phiên: FE gọi `/auth/me`, 401 → `/dang-nhap`. Throttle `password-change` 5/phút, 20/giờ/user. Audit `account.password_changed` / `account.password_change_failed`.
- Chưa làm (ghi `docs/security/backlog-v2.md`): email cảnh báo "mật khẩu vừa đổi" (câu hỏi mở US-015).

**Bổ sung từ T04 (2026-10-06):**
- `is_verified` = đã xác thực OTP ít nhất một kênh liên hệ (`email_verified_at` HOẶC `phone_verified_at`; production MVP chỉ có email nên thực tế = email). Đổi email/SĐT reset cột tương ứng. Middleware `account.verified` dùng cùng định nghĩa (`User::isVerified()`), lỗi 403 `ACCOUNT_NOT_VERIFIED`.
- Đăng ký (201) tự gửi OTP email; lỗi gửi không làm hỏng đăng ký (học sinh bấm gửi lại).
- Trần OTP (nguồn sự thật là bảng `otp_codes`, không phụ thuộc cache): gửi mã cooldown 60s, ≤ 5/giờ, ≤ 10/ngày/user (mọi purpose); vượt → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`, trần ngày ghi audit `otp.send_limit_reached`. Mỗi mã tối đa 5 lần so (`auth.otp.max_attempts_per_code`); verify throttle 5/phút, 20/ngày/user, 60/giờ/IP. Mã hiệu lực 10 phút. Gửi mã mới huỷ mã cũ cùng purpose.
- `channel` mặc định `email` nếu không gửi. `sms` chỉ hợp lệ khi có trong `AUTH_OTP_CHANNELS` (local/testing, nhà cung cấp giả lập); production → 422 field `channel`, và app không boot nếu cấu hình bật `sms` ở production.
- Đổi liên hệ chỉ huỷ mã của kênh có đích bị đổi (đổi email → mã email; đổi SĐT → mã sms); production đổi chỉ SĐT không huỷ mã email đang chờ. Verify (so đúng + consume + ghi `*_verified_at`) chạy trong một transaction có khoá hàng user và kiểm lại đích mã.
- Không có mã lỗi OTP riêng cho sai/hết hạn: sai/hết hạn là 422 `VALIDATION_ERROR` field `code`; hết lượt là 429 `TOO_MANY_ATTEMPTS`.

### 2.3 Học sinh — giỏ hàng, checkout, đơn (host api, nhóm `student` + `role:hoc_sinh`)

| Method | URI | Controller@action | Middleware / Policy | Request | Response |
|---|---|---|---|---|---|
| GET | /cart | `Cart\CartController@show` | | — | items (kèm cờ `unavailable`), coupon, `pricing`, `notices[]` |
| POST | /cart/items | `Cart\CartItemController@store` | | `AddCartItemRequest`: course_id (exists, published, price > 0) | 201 · 409 `ALREADY_IN_CART`/`ALREADY_OWNED` |
| DELETE | /cart/items/{course} | `Cart\CartItemController@destroy` | | — | cart (tự gỡ mã nếu hết điều kiện) |
| PUT | /cart/coupon | `Cart\CartCouponController@update` | throttle:coupon | `ApplyCouponRequest`: code (≤ 50) | cart · 422 `COUPON_*` (mã không tồn tại/chưa bắt đầu/vô hiệu → cùng `COUPON_INVALID`) |
| DELETE | /cart/coupon | `Cart\CartCouponController@destroy` | | — | cart |
| GET | /checkout/preview | `Checkout\CheckoutController@preview` | account.verified, parent.consent | — | items hợp lệ + `removed_items[]` + pricing |
| POST | /checkout | `Checkout\CheckoutController@store` | account.verified, parent.consent, throttle:checkout | `CheckoutRequest`: expected_total (int), gateway ∈ `enabled_gateways` | 201 {order_code, total, payment: {pay_url, expires_at}} · 409 `CHECKOUT_CHANGED`/`COUPON_EXHAUSTED` · 502 |
| POST | /orders/{order:code}/pay | `Checkout\OrderPaymentController@store` | account.verified, throttle:pay · `OrderPolicy@pay` | — | {pay_url, expires_at}. Khoá `carts → orders` khi quyết định/tạo attempt; đối soát link cũ trước; kiểm lại sức chứa mã khi quá `coupon_hold_until` (ADR-001 §6, §7) |
| POST | /orders/{order:code}/check-payment | `Checkout\OrderPaymentController@check` | throttle:check-payment · `OrderPolicy@view` | — | Đối soát ngay attempt mới nhất rồi trả trạng thái đơn |
| GET | /orders | `Order\MyOrderController@index` | | page | Đơn của tôi |
| GET | /orders/{order:code} | `Order\MyOrderController@show` | `OrderPolicy@view` | — | Chi tiết + `status`, `status_reason`, `expires_at`, `payment` {gateway, link_expired, can_retry} |

`pay_url` chỉ được frontend mở khi host thuộc allowlist MoMo (S23).

### 2.4 Học sinh — học tập (host api, nhóm `student`)

| Method | URI | Controller@action | Middleware / Policy | Request | Response |
|---|---|---|---|---|---|
| POST | /courses/{course}/free-enrollments | `Enrollment\FreeEnrollmentController@store` | role:hoc_sinh, account.verified, parent.consent · `EnrollmentPolicy@requestFree` | — | 201 `pending_approval` · 409 · **T14 chốt:** body rỗng (mọi field body bị bỏ qua). 201 phẳng `{id, course_id, status:"pending_approval", requested_at}`. Lỗi: 403 `ACCOUNT_NOT_VERIFIED` (middleware `account.verified` chỉ gắn ở route này, không chặn xem/học; chỉ cần 1 kênh email/SĐT đã xác thực), 404 `NOT_FOUND` (khóa không tồn tại/xoá/không `published`), 422 `COURSE_NOT_FREE` (price ≠ 0), 409 `ENROLLMENT_PENDING` (đang chờ duyệt; cả khi 2 request đồng thời — unique 1062 được dịch), 409 `ALREADY_OWNED` (đã `active`). Bị từ chối/thu hồi rồi thì xin lại được (tạo dòng mới). `parent.consent` chưa gắn (chưa có US-017) · |
| GET | /me/courses | `Learn\MyCourseController@index` | role:hoc_sinh | page | Khóa có enrollment active + % tiến độ, sắp theo học gần nhất; kèm pending/rejected |
| GET | /me/courses/{course}/progress | `Learn\MyCourseController@progress` | `CoursePolicy@learn` | — | Bài đã xong + quiz và điểm cao nhất |
| GET | /learn/courses/{course} | `Learn\LearnCourseController@show` | `CoursePolicy@learn` | — | Outline + trạng thái bài + `resume_lesson_id` |
| GET | /learn/lessons/{lesson} | `Learn\LessonController@show` | `LessonPolicy@watch` | — | Bài + prev/next + quiz gắn kèm (**Resource không có `is_correct`/`explanation`** — I3) + progress |
| GET | /learn/lessons/{lesson}/playback | `Learn\PlaybackController@show` | throttle:playback · `LessonPolicy@watch` | — | `PlaybackInfo` {kind, url, expires_at (15 phút), resume_at_seconds}; ràng IP; ghi log `playback` |
| POST | /learn/lessons/{lesson}/heartbeat | `Learn\ProgressController@heartbeat` | throttle:heartbeat · `LessonPolicy@watch` | `HeartbeatRequest`: position_seconds (0..duration), watched_delta_seconds (0..60) | {status, completed, course_percent} |
| POST | /learn/quizzes/{quiz}/attempts | `Quiz\AttemptController@store` | `QuizPolicy@take` | — | Lượt đang làm (tạo hoặc resume): câu hỏi **không có đáp án đúng**, answers, expires_at, server_now |
| PUT | /learn/quiz-attempts/{attempt}/answers/{question} | `Quiz\AnswerController@update` | `QuizAttemptPolicy@answer` | `SaveAnswerRequest`: option_id thuộc đúng question; question (ép int) thuộc `question_ids` | 204 |
| POST | /learn/quiz-attempts/{attempt}/submit | `Quiz\AttemptController@submit` | `QuizAttemptPolicy@submit` | — | Kết quả (idempotent) |
| GET | /learn/quiz-attempts/{attempt} | `Quiz\AttemptController@show` | `QuizAttemptPolicy@view` | — | Kết quả / trạng thái |
| GET | /learn/quizzes/{quiz}/attempts | `Quiz\AttemptController@index` | `QuizPolicy@take` | — | Lịch sử + điểm cao nhất |

### 2.5 Quản trị (host admin-api, nhóm `staff`) — UI Next.js `admin.vitaminvui.vn` (Quyết định của PO)

**Quy tắc chung (S5):**
- Mọi nhóm route cha–con dùng `->scopeBindings()`.
- Policy kiểm trên **khóa gốc của bản ghi con**.
- `course_id`, `video_asset_id`, `created_by` không bao giờ lấy từ request.
- Mọi danh sách ID trong payload đều kiểm phạm vi bằng `Rule::exists(...)->where(...)`.

**Đăng nhập quản trị:**

| Method | URI | Controller@action | Middleware | Request / ghi chú |
|---|---|---|---|---|
| GET | /csrf-token | `Auth\CsrfController` | admin.origin | |
| POST | /admin/auth/login | `Admin\Auth\LoginController@store` | admin.origin, guest, throttle:login | login, password. Vai trò `hoc_sinh` → `WRONG_PORTAL`. Admin/QLT → phiên ở trạng thái "chờ MFA" + gửi OTP email (`staff_login_mfa`) → 200 `{mfa_required: true}`. GV → đăng nhập xong, email cảnh báo nếu thiết bị mới. Ghi `audit_logs` `staff.login` / `staff.login_failed` |
| POST | /admin/auth/mfa/verify | `Admin\Auth\MfaController@verify` | admin.origin, auth:sanctum, throttle:otp-verify | code → đánh dấu `mfa_passed` trong session. **Chờ PO xác nhận bật MFA** (mặc định bật cho admin/QLT) |
| PUT | /admin/auth/password | `Admin\Auth\PasswordController@update` | admin.origin, auth:sanctum | Bắt buộc khi `must_change_password`; huỷ phiên khác (AuthenticateSession) |
| POST | /admin/auth/logout | | auth:sanctum | |
| GET | /admin/auth/me | | staff | user + role + quyền UI |

**Bổ sung từ T28 (2026-10-07) — chốt cho FE-ADMIN FA1:**
- Thứ tự middleware thật: `admin.origin` (chạy trước `auth:sanctum` nên Origin lạ luôn 403 `ORIGIN_NOT_ALLOWED`), `auth:sanctum`, `role:admin,quan_ly_trang,giao_vien`, Sanctum `AuthenticateSession`, `account.active`, `staff.idle`, `staff.mfa_passed`, `staff.password_fresh`, `no_store`.
- `POST /admin/auth/login` body `{login, password, device_id?}`. UI chỉ nhập/hiển thị Email; backend dùng chung `LoginService::findByLogin` nên `login` là SĐT cũng được chấp nhận cho staff (không đổi API). **Không có `guest`** (đăng nhập lại khi cookie cũ còn sống là hợp lệ, như T05). Đếm sai: 10/giờ/tài khoản + 50/giờ/IP (khoá riêng với host api) → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`.
  - Admin/QLT (khi `FEATURE_STAFF_MFA`): 200 `{ "mfa_required": true, "resend_available_at": ISO8601 }` (không có `user`). Đã có phiên "chờ MFA".
  - Giáo viên (hoặc MFA tắt): 200 `{ "mfa_required": false, "user": StaffUser }`.
  - Sai thông tin: 422 `VALIDATION_ERROR` field `login`. Học sinh (mật khẩu đúng): 403 `WRONG_PORTAL`. Khoá (mật khẩu đúng): 403 `ACCOUNT_LOCKED`. Gửi OTP lỗi: 503 `OTP_DELIVERY_FAILED` (không cấp phiên).
- `StaffUser` = `{ id, name, email, role, must_change_password, permissions: {manage_system, manage_subjects, manage_all_courses, manage_coupons, view_orders, export_orders, export_orders_with_contact}, session: {idle_timeout_minutes, expires_at} }` (`permissions` chỉ để ẩn/hiện UI).
- `POST /admin/auth/mfa/verify` body `{code}` (6 số): 200 `{ "mfa_required": false, "user": StaffUser }`; sai/hết hạn 422 field `code`; hết 5 lượt của mã 429 `TOO_MANY_ATTEMPTS`; throttle 5/phút, 20/ngày/user. Gọi lại khi đã qua MFA vẫn 200. **Cookie phiên đổi giá trị** sau verify (trình duyệt tự cập nhật).
- `POST /admin/auth/mfa/resend` (mới, không body): 202 `{ resend_available_at }`; cooldown 60s, 5/giờ, 10/ngày → 429 + `Retry-After`; đã qua MFA → 409 `ALREADY_PROCESSED`.
- `PUT /admin/auth/password` (bắt buộc `current_password`, kể cả màn đổi mật khẩu lần đầu) body `{current_password, password, password_confirmation}` (min 8, max 128, phải khác mật khẩu cũ; lỗi khớp ở `password_confirmation`): 200 `{ "user": StaffUser }` (cookie đổi id), huỷ mọi phiên khác (chúng nhận 401 `UNAUTHENTICATED`). Sai mật khẩu hiện tại 422 field `current_password`. Throttle 5/phút, 20/giờ/user. Yêu cầu đã qua MFA (chặt hơn bản đầu), không yêu cầu `password_fresh`.
- `POST /admin/auth/logout` → 204. Route cố ý không có `staff.idle`/`AuthenticateSession`: phiên hết hạn idle/đổi mật khẩu/chờ MFA/phải đổi mật khẩu vẫn đăng xuất được (204); chỉ khi hoàn toàn không có phiên đăng nhập mới 401.
- `GET /admin/auth/me` → StaffUser phẳng. Trạng thái phiên FE dò theo mã lỗi: `MFA_REQUIRED` (403) → màn OTP; `PASSWORD_CHANGE_REQUIRED` (403) → màn đổi mật khẩu; `STAFF_IDLE_TIMEOUT` (401, lần đầu) rồi `UNAUTHENTICATED` → màn đăng nhập; `ACCOUNT_LOCKED` (403).
- Giáo viên đăng nhập từ thiết bị mới (khoá bởi `X-Device-Id`/`device_id`, thiếu thì User-Agent) → email cảnh báo (không chặn).
- Audit: `staff.login` (hoàn tất, `changes.mfa`), `staff.login_mfa_sent`, `staff.login_failed` (`reason`: bad_credentials|wrong_portal|locked), `staff.mfa_failed` (`reason`), `staff.logout`, `staff.password_changed`, `staff.password_change_failed`.

**Nội dung & danh mục:**

| Method | URI | Controller@action | Policy | Request / ghi chú |
|---|---|---|---|---|
| GET | /admin/subjects | `Admin\SubjectController@index` | `SubjectPolicy@viewAny` | GV chỉ nhận `active` (bỏ qua `status`). Query: `q` (≤100, escape LIKE), `status`, `per_page` (25\|50), `all=1` (không phân trang → `{data}` không có meta/links, dùng cho ô chọn chuyên đề). Mặc định `paginate(25)` sắp `name asc, id asc`. Item: `{id, name, slug, status, courses_count (chỉ staff), created_at, updated_at}` |
| POST/PUT/DELETE | /admin/subjects[/{subject}] | | staff | `SubjectRequest`: name (trim + gộp khoảng trắng, 1–100, văn bản thuần: có `<`/`>`/thẻ HTML/ký tự điều khiển → 422). Trùng tên (không phân biệt hoa/thường/dấu) → 422 `errors.name` "Chuyên đề đã tồn tại." POST → 201 (status `active`, slug sinh từ tên, thêm `-2`, `-3` khi trùng). PUT đổi tên **không đổi slug**. DELETE → 204; đang gán khóa học (kể cả khóa đã xoá mềm) → 409 `SUBJECT_IN_USE` (gợi ý chuyển Ẩn). Quyền kiểm trước validate (GV → 403 `FORBIDDEN`). Audit: `subject.create`, `subject.delete` (+ `subject.update`, `subject.status`) |
| PATCH | /admin/subjects/{subject}/status | | staff | body `{status: active\|hidden}` → 200 Subject |
| GET | /admin/teachers | `Admin\TeacherController@index` | staff | Chỉ `id`, `name` (không email/SĐT) |
| GET | /admin/teachers | `Admin\TeacherController@index` | staff (GV → 403 `FORBIDDEN`) | Giáo viên `active` cho ô chọn: `{data:[{id,name}]}` (không phân trang, tối đa 500), `q` (≤100, escape LIKE). Chốt T08 |
| GET | /admin/courses | `Admin\CourseController@index` | `CoursePolicy@viewAny` | Scope `visibleTo` (GV chỉ khóa có tên trong `course_teacher`). Query: `q` (≤100, không dấu, escape LIKE), `status` (draft\|published\|unpublished), `grade_level` (6–12), `subject_id`, `teacher_id` (chỉ staff), `per_page` (25\|50), `page`. Sắp `created_at desc, id desc`. Length-aware. Item (`CourseListResource`): `{id,title,slug,short_description,grade_level,price,thumbnail_url,status,published_at,manual_order (chỉ staff),enrollments_count,subjects:[{id,name,slug}],teachers:[{id,name}],created_by,created_at,updated_at}` — không có `description` |
| POST | /admin/courses | `store` | `CoursePolicy@create` (quyền kiểm trước validate) | `StoreCourseRequest`, **multipart/form-data** khi có ảnh: title (≤255, văn bản thuần), grade_level (6–12), subject_ids[] (1–20, exists **active**), short_description (nullable ≤500, văn bản thuần), description (HTML → Purifier §4, không rỗng sau khi lọc), price (int 0..50.000.000), thumbnail (**bắt buộc khi tạo**, §4 quy tắc ảnh), teacher_ids[] (**chỉ staff**, bắt buộc ≥1, role `giao_vien` + `active`; GV gửi thì bị bỏ qua, tự thêm chính mình). `status`/`slug`/`created_by`/`manual_order` không nhận. → 201 `CourseResource` (status `draft`, slug sinh từ title thêm `-2`, `-3` khi trùng kể cả khóa đã xoá mềm). Audit `course.create` |
| GET/PUT | /admin/courses/{course} | `show`/`update` | `CoursePolicy@view`/`update` (GV không được gán → 403 `FORBIDDEN`) | `CourseResource` = item list + `description` (lọc lại khi trả ra), `chapters_count`, `lessons_count`, `abilities:{update,delete,publish,manage_teachers,edit_price,edit_grade_level}` (chỉ để ẩn/hiện UI). `UpdateCourseRequest` 2 bộ rule, tất cả `sometimes` (gửi trường nào sửa trường đó): **staff** title, grade_level, short_description, description, price, thumbnail, subject_ids[], teacher_ids[] (đổi qua `CourseTeacherService`); **GV** title, short_description, description, thumbnail, subject_ids[], `grade_level` chỉ khi `published_at` null; GV **không có** `price`/`teacher_ids`, không ai có `status`/`manual_order`/`slug` (gửi thì bị bỏ qua im lặng). **Upload ảnh khi sửa: `POST` multipart kèm `_method=PUT`** (PHP không parse multipart của PUT); không gửi `thumbnail` thì giữ ảnh cũ, gửi thì ảnh cũ bị xoá. Đổi giá → audit `course.price_change` `{price:{from,to}}`; mọi thay đổi → `course.update` `{fields:[...]}` |
| DELETE | /admin/courses/{course} | `destroy` | `CoursePolicy@delete` (staff) | 204, xoá mềm khóa + chương + bài. Có enrollment **bất kỳ trạng thái** (kể cả pending/rejected) → 409 `COURSE_HAS_ENROLLMENTS` (gợi ý Ngừng bán). Audit `course.delete` |
| POST | /admin/courses/{course}/publish · /unpublish | `CoursePublicationController` | `CoursePolicy@publish` (staff) | 200 `CourseResource`. Publish: chưa có ≥1 chương và ≥1 bài (chưa xoá) → 422 `COURSE_NOT_PUBLISHABLE` "Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản."; đã `published` → 409 `ALREADY_PROCESSED`; `published_at` chỉ đặt lần đầu. Unpublish: khóa chưa published (draft) → 409 `INVALID_COURSE_STATE`; đã `unpublished` → 409 `ALREADY_PROCESSED`. Audit `course.publish`/`course.unpublish` |
| PATCH | /admin/courses/{course}/manual-order | `updateManualOrder` | staff | body `{manual_order: int 0..1.000.000 \| null}` (bắt buộc có khoá) → 200 `CourseResource`. Audit `course.manual_order` |
| PUT | /admin/courses/{course}/teachers | `CourseTeacherController@update` | `manageTeachers` (staff) | `{teacher_ids[]}` (≥1, role `giao_vien` + `active`) → 200 `CourseResource`; sai → 422 `errors.teacher_ids`. Gán lại đúng danh sách cũ không ghi audit. Giáo viên đã gán sẵn mà sau đó bị khoá vẫn được giữ khi gửi lại danh sách (cũng áp cho `teacher_ids` ở PUT khóa); chỉ giáo viên MỚI thêm phải `active` (lỗi `errors.teacher_ids`, không phải `teacher_ids.N`); muốn gỡ người bị khoá thì bỏ khỏi danh sách. Audit `course.teachers` `{teacher_ids:{from,to}}`, `course_teacher.added_by` = người gán |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters[/{chapter}] | `ChapterController` | `manageContent` (theo `$chapter->course`) | |
| PUT | /admin/courses/{course}/curriculum/order | `CurriculumOrderController` | `manageContent` | `[{chapter_id, lesson_ids[]}]`: mọi `chapter_id`/`lesson_id` thuộc `$course` và chưa xoá; **tập ID gửi lên phải bằng đúng tập hiện có** (không thêm, không thiếu); 1 transaction. Sai → 422 (S5) |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters/{chapter}/lessons[/{lesson}] | `LessonController` | `manageContent` (theo `$lesson->course`) | `LessonRequest`: title, is_preview, video_source (`none`\|`upload`\|`external_link`), external_url (chỉ khi `is_preview` — mặc định; parse ra provider + ID, host whitelist), duration_seconds. **Không nhận** `video_asset_id`, `course_id`, `chapter_id` của khóa khác |
| POST | /admin/courses/{course}/lessons/{lesson}/video-uploads | `LessonVideoUploadController@store` | `manageContent` | filename (hiển thị), size (≤ `max_upload_mb`), kiểm hạn mức 20 GB/ngày → {video_asset_id, upload: {tus_endpoint, headers, expires_at ≤ 6h}} |
| GET | /admin/courses/{course}/lessons/{lesson}/video | | `manageContent` | Trạng thái xử lý video |
| GET | /admin/courses/{course}/lessons/{lesson}/playback | `Learn\PlaybackController@admin` | `LessonPolicy@watch` | |
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes[/{quiz}] | `Admin\QuizController` | `manageContent` (theo `$quiz->course`) | title, lesson_id **hoặc** chapter_id (exists trong `$course`), time_limit_minutes (null/1–300; bỏ qua khi flag tắt). `course_id` suy ra, không từ request |
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes/{quiz}/questions[/{question}] | `Admin\QuizQuestionController` | `manageContent` | content/explanation văn bản thuần ≤ 5.000, đúng 4 options (≤ 1.000) và đúng 1 đáp án đúng, ≤ 200 câu/quiz. Copy-on-write |
| GET | /admin/enrollment-requests | `Admin\EnrollmentRequestController@index` | `EnrollmentPolicy@viewAnyRequests` | course_id?; GV chỉ khóa mình; sắp `requested_at asc`. Tên HS hiển thị, email/SĐT **che** **T14 chốt:** query `course_id` (int), `status` (`pending_approval` mặc định \| `active` \| `rejected`), `per_page` (1–100, mặc định 25); sai → 422 tiếng Việt. Giáo viên: không có `course_id` → chỉ khóa mình phụ trách; `course_id` khóa không phụ trách (kể cả không tồn tại) → 403 `FORBIDDEN`; staff lọc khóa không tồn tại → danh sách rỗng. Item: `{id, status, requested_at, approved_at, rejection_reason, course:{id,title,slug}, student:{id,name,grade_level,email_masked,phone_masked}}` (email `n***@domain`, SĐT `******5678`). Response `{data, meta, links}` |
| POST | /admin/enrollment-requests/{enrollment}/approve · /reject | | `EnrollmentPolicy@decide` (theo `$enrollment->course`) | reason ≤ 1000 văn bản thuần. Lần 2 → 409 **T14 chốt:** `reason` tuỳ chọn, `null`/rỗng → `null`; chứa thẻ HTML/ký tự điều khiển → 422; `approve` không lưu `reason` (chỉ ghi audit). Quyền kiểm TRƯỚC validate (GV không phụ trách → 403 dù payload sai). Thành công 200 trả item như `GET` (phẳng) với `status` mới (`active`\|`rejected`). Với `rejected`: `approved_by/approved_at` luôn `null` (người và thời điểm từ chối xem ở audit `enrollment.reject`). `approve` khóa đã xoá mềm → 409 `COURSE_UNAVAILABLE` (vẫn `reject` được). `approve` khi khóa đã chuyển sang có phí (price > 0) lúc yêu cầu còn chờ → 422 `COURSE_NOT_FREE`, yêu cầu giữ `pending_approval` (vẫn `reject` được). Danh sách bỏ yêu cầu của khóa đã xoá mềm. Đã xử lý (kể cả double click/2 người cùng bấm) → 409 `ALREADY_PROCESSED`; không tồn tại → 404. Duyệt: `approved_by/approved_at/activated_at`, `courses.enrollments_count` +1 cùng transaction; từ chối: `rejection_reason`, count không đổi; audit `enrollment.approve`/`enrollment.reject` |

**Mã giảm giá & đơn hàng:**

| Method | URI | Controller@action | Policy | Request / ghi chú |
|---|---|---|---|---|
| GET | /admin/coupons | `Admin\CouponController@index` | staff | filter state |
| POST/PUT | /admin/coupons[/{coupon}] | | staff | `CouponRequest`: code (4–50, `[A-Z0-9_-]`), discount_type, discount_value, max_uses, valid_from, valid_until. **Mã 100% hoặc fixed ≥ giá khóa rẻ nhất đang bán: bắt buộc `max_uses` + `valid_until`** (S18). course_ids[]/subject_ids[] exists. Mã đã có lượt dùng: không đổi code/type/value. Ghi audit |
| POST | /admin/coupons/{coupon}/deactivate | | staff | Ghi audit |
| DELETE | /admin/coupons/{coupon} | | staff | Chỉ khi chưa có order tham chiếu |
| GET | /admin/orders | `Admin\OrderController@index` | `OrderPolicy@viewAny` | `OrderFilterRequest`: from, to (**bắt buộc**, ≤ 366 ngày), status[], q, pending_older_than_hours, needs_review, cursor, per_page. **Cursor pagination** (§1.5). `q`: dạng mã đơn → `orders.code`; có `@` → email chính xác; toàn số → SĐT chuẩn hoá; còn lại → `users.name LIKE 'từ%'` (escape). Luôn áp bộ lọc ngày/trạng thái trước (DBA 2.4). **Email/SĐT HS che** (`09****123`, `ng***@gmail.com`) — S14 |
| GET | /admin/orders/{order:code} | `show` | staff | Chi tiết + items + attempts + status logs; email/SĐT HS **đầy đủ**; ghi `audit_logs` `order.view_pii`. Không bao giờ trả thông tin phụ huynh |
| POST | /admin/orders/{order:code}/refund | `Admin\OrderRefundController@store` | `OrderPolicy@refund` | note (tuỳ chọn, ≤ 1000), confirm=true. Lần 2 → 409. Ghi audit |
| POST | /admin/order-exports | `Admin\OrderExportController@store` | `OrderPolicy@export`, throttle:export (10/ngày) | cùng bộ lọc + format (`csv`\|`xlsx`) + `include_contact` (**chỉ admin**, bắt buộc `reason`) → 202 {export_id}. Ghi audit (người, bộ lọc, IP). Cảnh báo log khi > 10.000 dòng |
| GET | /admin/exports/{export} | `Admin\ExportController@show` | chủ export | trạng thái + `download_url` (temporary signed URL 10 phút, sinh đúng host admin-api sau proxy) |
| GET | /admin/exports/{export}/download | `Admin\ExportController@download` | `signed` + staff + chủ export | Tên file `orders-YYYYMMDD-HHmm.csv/xlsx`, `Content-Disposition: attachment`. Ghi audit `export.download` |

Tài khoản staff: UI quản lý thuộc **US-016 (chờ BA)**. Trước khi có, dùng `php artisan staff:create|lock|unlock` (chỉ chạy trên server, có ghi audit).

### 2.6 Webhook (host api, nhóm `webhook`)

| Method | URI | Controller | Ghi chú |
|---|---|---|---|
| POST | /webhooks/payments/{gateway} | `Webhook\PaymentWebhookController` | `->whereIn('gateway', enabled_gateways)` (lạ → 404); body ≤ 16 KB; 120/phút/IP; IP allowlist MoMo chỉ bật khi đã xác nhận IP thật qua proxy. ADR-001 |
| POST | /webhooks/video/{provider} | `Webhook\VideoWebhookController` | `->whereIn('provider', ['internal','bunny'])`; không tin payload, gọi lại `getVideo()` |

### 2.7 VideoLab (host `video.vitaminvui.vn`, `routes/videolab.php`, không nhóm `web` — ADR-002 §3a)

| Method | URI | Ghi chú |
|---|---|---|
| POST | /videolab/library/{libraryId}/videos | Header `AccessKey`. **Chỉ mạng nội bộ** (Nginx allow IP app server) |
| GET / DELETE | /videolab/library/{libraryId}/videos/{guid} | Như trên |
| POST / HEAD / PATCH | /videolab/tus[/{guid}] | Công khai; TUS subset; chữ ký HMAC, TTL ≤ 6h, `max_bytes`, 1 upload/guid; CORS riêng |
| GET | /videolab/cdn/{token}/{expires}/{guid}/{path} | Công khai; `guid` = `[0-9a-f-]{36}`, `path` = `playlist\.m3u8\|[0-9]{3,4}p/[A-Za-z0-9_]{1,40}\.(m3u8\|ts)`; kiểm `realpath` |

### 2.8 Dữ liệu cá nhân & đồng ý (US-017, US-018 — **chờ BA viết story, chờ pháp chế**; hợp đồng dự kiến)

| Method | URI | Host | Ghi chú |
|---|---|---|---|
| GET | /parent-consents/{token} | api (public) | Token 1 lần (signed, 72h) gửi email phụ huynh; trả thông tin tối thiểu (tên HS che bớt, nội dung đồng ý) |
| POST | /parent-consents/{token} | api (public, throttle) | Phụ huynh xác nhận/từ chối → `consents` (`granted_by=parent`), cập nhật `parent_consent_status` |
| POST | /me/parent-consent/resend | api (student) | Gửi lại email phụ huynh (giới hạn 3/ngày) |
| GET | /me/consents · POST /me/consents/{type}/revoke | api (student) | Xem/rút lại đồng ý |
| GET | /me/data-export | api (student) | Xuất dữ liệu cá nhân của chính mình (JSON) |
| POST | /me/account/delete | api (student) | Yêu cầu xoá tài khoản → xác nhận OTP → ẩn danh hoá (`anonymized_at`), giữ đơn hàng |

## 3. Cấu trúc code (backend `backend/`)

Quy ước: **Service theo domain**, controller mỏng (Form Request → authorize → service → Resource), `$fillable` tường minh (S17), không Repository, Event chỉ cho tác dụng phụ bất đồng bộ (ADR-004 §4).

```
app/
  Enums/                UserRole, UserStatus, CourseStatus, VideoSource, VideoAssetStatus, EnrollmentStatus,
                        EnrollmentSource, LessonProgressStatus, OrderStatus, PaymentAttemptStatus,
                        CouponDiscountType, ExportStatus, ConsentType, ParentConsentStatus, OtpPurpose
  Exceptions/           DomainException
  Http/
    Controllers/Api/V1/{Auth,Catalog,Cart,Checkout,Order,Enrollment,Learn,Quiz,Webhook,Privacy}/
    Controllers/Admin/V1/{Auth,Subject,Course,Curriculum,Quiz,Enrollment,Coupon,Order,Export}/
    Middleware/         ConfigureHostContext (global), EnsureAdminOrigin, EnsureRole, EnsureAccountActive,
                        EnsureAccountVerified, EnsureParentConsent, EnforceSingleStudentSession, StaffIdleTimeout,
                        EnsureStaffMfaPassed, EnsurePasswordFresh, NoStoreForAuthenticated, AssignRequestId
    Requests/           (mỗi action ghi dữ liệu có 1 Form Request; GV/staff dùng rule khác nhau khi cần)
    Resources/          (Resource riêng cho lượt quiz đang làm — không is_correct/explanation)
  Models/               ... + Consent, AuditLog
  Policies/             CoursePolicy, LessonPolicy, QuizPolicy, QuizAttemptPolicy, SubjectPolicy, EnrollmentPolicy,
                        CouponPolicy, OrderPolicy, ExportPolicy
  Services/
    Audit/              AuditLogger (loại PII/secret khỏi `changes`)
    Auth/               RegistrationService, LoginService, StudentSessionService (ADR-003), StaffAuthService (MFA,
                        idle, must_change_password), PasswordService (đổi/đặt lại — US-015), OtpService,
                        Otp/OtpSender, Otp/MailOtpSender, Otp/LogSmsOtpSender (chỉ local/testing, ghi '***'),
                        Captcha/CaptchaVerifier, Captcha/TurnstileVerifier, Captcha/FakeCaptchaVerifier (testing),
                        PhoneNumber
    Privacy/            ConsentService, ParentConsentService, DataExportService, AccountAnonymizer (US-017/018)
    Uploads/            ImageUploadService (kiểm MIME + mã hoá lại WebP + bỏ EXIF + tên UUID, disk `uploads`)
    Content/            HtmlSanitizer (Purifier profile `course_description`)
    Catalog/ Courses/ Enrollment/ Learning/ Quiz/ Cart/ Checkout/ Orders/ Payments/ Video/   (như trước)
    Orders/             ... OrderExportService (che PII, chống CSV injection), PiiMasker
  Support/              Like (escape LIKE), CsvCell (tiền tố `'` chống injection)
  Jobs/                 ExportOrdersJob, SyncVideoAssetStatusJob, ReconcilePaymentAttemptJob
  Mail/                 OtpMail (ShouldBeEncrypted), OrderPaidMail (ShouldBeEncrypted), ParentConsentMail,
                        StaffNewDeviceMail, EnrollmentDecisionMail
  Console/Commands/     staff:create|lock|unlock, orders:expire-pending, quizzes:auto-submit-expired,
                        payments:reconcile, payments:purge-webhook-events, counters:recount, exports:purge,
                        videos:check-stuck, otp:prune, users:purge-unverified, audit:purge
  VideoLab/             Module độc lập (ADR-002)
routes/                 api.php (host api), admin.php (host admin-api), videolab.php, console.php
config/                 features.php, payments.php, video.php, orders.php, privacy.php (policy_version,
                        parent_consent_age=18, retention), auth.php (otp.channels, ttl, limits), captcha.php
```

**Trách nhiệm dễ đoán sai:** `LessonAccessService::canWatch` là nơi duy nhất chứa quy tắc xem video (không cache); `PricingCalculator` là hàm thuần dùng chung cho giỏ/checkout/đơn; `OrderFulfillmentService::markPaid` là đường duy nhất tạo enrollment từ đơn; `EnrollmentService` là nơi duy nhất đổi `enrollments.status`; `StudentSessionService` là nơi duy nhất ghi `current_session_id`; controller không gọi thẳng MoMo/VideoLab.

## 4. Hợp đồng nội dung người dùng tải lên / nhập (S2, S8)

**Ảnh (thumbnail khóa, avatar, sau này ảnh câu hỏi):**
```php
'thumbnail' => ['nullable', 'file', 'max:2048',
    'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
    'dimensions:max_width=4000,max_height=4000'],
```
- Không dùng rule `image` trơn. **Không nhận SVG/GIF/HTML.**
- `ImageUploadService` đọc lại bằng GD/Imagick (qua `intervention/image`) và mã hoá lại sang **WebP** (tối đa 1600px). Bước này bỏ toàn bộ metadata EXIF.
- Lưu `{uuid}.webp` trên disk `uploads`, phục vụ từ `STATIC_URL` (tên miền riêng, không cookie) với header `Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox`, `X-Content-Type-Options: nosniff`.
- Test: `.svg`, `.html`, PNG chứa `<svg>` đổi đuôi, polyglot → 422; file hợp lệ → tên ngẫu nhiên, không còn EXIF.

**`courses.description` (rich text, duy nhất được là HTML):**
- Purifier profile `course_description`:
  - `HTML.Allowed = p,br,strong,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]`
  - `URI.AllowedSchemes = http,https,mailto`
  - `HTML.TargetBlank = true`, `HTML.Nofollow = true` (ép `rel="nofollow noopener noreferrer"`)
  - **Không** cho `img`, `iframe`, `style`, `class`, `on*`.
- Sanitize khi ghi **và** khi trả ra trong `CourseResource`. Frontend chạy DOMPurify trước `dangerouslySetInnerHTML`.

**Quiz (`content`, `explanation`, options):**
- **Văn bản thuần** có đoạn `$...$`, không HTML.
- Frontend tách đoạn LaTeX rồi gọi `katex.renderToString(tex, { trust: false, strict: 'warn', maxSize: 10, maxExpand: 1000, throwOnError: false })`; phần còn lại render như text của React.

**Mọi trường văn bản khác** (tên HS, tên chuyên đề, bio, lý do từ chối, ghi chú hoàn tiền, tên mã giảm giá): lưu văn bản thuần, render mặc định của React. **Cấm `dangerouslySetInnerHTML`.**

**Xuất CSV/XLSX (S14):**
- Ô chuỗi (sau khi bỏ khoảng trắng đầu) bắt đầu bằng `=`, `+`, `-`, `@`, `\t`, `\r` → thêm tiền tố `'`. Chỉ áp cho cột chuỗi, không áp cho cột số tiền.
- XLSX ghi ô kiểu string (openspout).
- Không bao giờ xuất thông tin phụ huynh; email/SĐT HS chỉ có khi `include_contact` (admin + lý do).
