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
- **Header bảo mật (middleware `SecurityHeaders`, mọi response của Laravel, kể cả lỗi HTML):** `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()`, và `Strict-Transport-Security: max-age=31536000; includeSubDomains` khi request là HTTPS (cụm 4 C4-L2). CSP này chỉ cho API; Next.js (web/admin) có CSP riêng.
- **`GET /up` (health check hạ tầng, cụm 4 C4-L2):** trả JSON tối giản `{"status":"ok"}` (200) hoặc `{"status":"error"}` (500), không có trang HTML. Không thuộc `/api/v1`, không throttle ở Laravel, **Nginx chỉ cho IP giám sát/load balancer** (`allow <IP_MONITOR_LB>; deny all`), IP khác nhận 403. Không dùng cho frontend. Probe sâu hơn (DB, Redis) dùng `ops:health`.

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
| `coupon` | 10/phút, **30 lần sai/ngày**/user; **150 lần sai/ngày/IP** (`ORDERS_COUPON_FAILS_PER_IP_PER_DAY`, 0 = tắt; vượt → 429 `TOO_MANY_ATTEMPTS`, Sửa lỗi nhỏ 3) | 60/giờ |
| `cart` (`GET /cart`, `POST /cart/items`, `DELETE /cart/items/{course}`, `DELETE /cart/coupon`, `GET /checkout/preview`; Sửa lỗi nhỏ 3, cụm 3 L2) | 60/phút/user (429 + `Retry-After`) | — |
| `checkout`, `pay` | 10/phút/user | — |
| `check-payment` | 1 lần/30s/đơn | — |
| `playback` | 30/phút/user | — |
| `heartbeat` | 6/phút/user/bài. Thêm (cụm 2 L1): tổng giây được CỘNG trên mọi bài ≤ 165 (`max_speed*(60+nhịp 20s)+slack`) trong cửa sổ 60 giây/user (`learning.heartbeat.user_credit_*`); phần vượt cộng 0, vẫn 200 | — |
| `catalog` | — | 120/phút. **SSR (T26+):** request mang `X-Internal-Token` đúng (= `INTERNAL_API_TOKEN`) + `X-Client-IP` hợp lệ được tính 120/phút/IP khách và chung trần 6000/phút (`CATALOG_SSR_TOTAL_PER_MINUTE`) cho toàn bộ nguồn SSR; token đúng nhưng thiếu/sai định dạng `X-Client-IP` → CHỈ tính trần tổng (ADR-004 §2.8); token sai/thiếu → như cũ theo IP kết nối (bỏ qua `X-Client-IP`). Không tin `X-Forwarded-For`. |
| `csrf` | — | 120/phút (PO chốt 2026-10-05, lớp học dùng chung NAT) |
| `webhook` | — | 120/phút |
| `export` | 10 lần tạo/ngày/user | — |
| `teacher-profile` (T36: PATCH hồ sơ, xoá ảnh, đồng ý/rút, bật trang chủ; `/admin/me/teacher-profile*` và `/admin/teacher-profiles/{user}*`) | 30/phút/user | — |
| `teacher-avatar` (T36: `POST .../avatar`, lần lỗi 422 cũng tính) | 10/phút/user | — |
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
| 409 | `TEACHER_HOMEPAGE_LIMIT` | Bật hiển thị trang chủ khi đã có `teacher_profile.homepage_max` (6) giáo viên được bật (US-020, T36) |
| 409 | `CONSENT_VERSION_CHANGED` | Giáo viên gửi đồng ý với `version` khác câu chữ hiện hành → FE tải lại hồ sơ, hiện câu mới (US-020, T36) |
| 422 | `NOT_TEACHER` | Sửa nội dung hồ sơ / bật trang chủ cho tài khoản không còn vai trò `giao_vien` (US-020, T36) |
| 409 | `COURSE_HAS_ENROLLMENTS`, `INVALID_COURSE_STATE` | Xoá khóa đã có enrollment; ngừng bán khóa chưa xuất bản (T08) |
| 409 | `COURSE_HAS_PENDING_ORDERS` | Xoá khóa đang nằm trong đơn `pending` chưa hết hạn (T18) |
| 409 | `PAYMENT_IN_PROGRESS` | Đang tạo giao dịch thanh toán cho đơn (T18) |
| 422 | `CART_EMPTY`, `ZERO_TOTAL_DISABLED` | Checkout giỏ trống / đơn 0đ khi cờ tắt (T18) |
| 422 | `COURSE_NOT_PUBLISHABLE` | Xuất bản khóa chưa có chương/bài (T08) |
| 413 | `PAYLOAD_TOO_LARGE` | |
| 422 | `VALIDATION_ERROR`, `CAPTCHA_FAILED` | Câu `message`/`errors` trong `VALIDATION_ERROR` bằng tiếng Việt (`lang/vi/validation.php`, locale `vi` mặc định) |
| 422 | `OTP_INVALID`, `OTP_EXPIRED` | Mã OTP sai / hết hạn-không có mã-bị vô hiệu (verify tài khoản, đổi email/SĐT, reset mật khẩu, MFA staff). Vẫn 422 và vẫn có `errors.code[]` (như `VALIDATION_ERROR` cũ) nên FE cũ không vỡ; FE phân biệt theo `code` của envelope. Hết lượt của mã vẫn là 429 `TOO_MANY_ATTEMPTS`; "đã xác thực" vẫn là `VALIDATION_ERROR` field `code`. Reset mật khẩu (không captcha): MỌI lỗi mã (sai, hết 5 lượt, tài khoản không tồn tại/bị khoá/không có mã) → 422 `OTP_EXPIRED` cùng thông điệp, không có `OTP_INVALID`/429 riêng (không lộ tồn tại, T27-5) |
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
| GET | /courses | `Catalog\CourseController@index` | public, throttle:catalog | `CourseSearchRequest`: grade (6–12), subject_ids[] (≤ 20), q (≤ 100, escape LIKE — S24), sort (`newest`\|`popular`\|`featured`), page | 25 khóa `published` **T10 chốt:** `subject_ids[]` = OR (khóa thuộc ít nhất 1 chuyên đề); id chuyên đề ẩn/không tồn tại không khớp khóa nào (kết quả rỗng, không lỗi); `q` bỏ dấu + chữ thường, tách từ (≤ 8), mọi từ phải khớp `search_text` (AND); tham số sai (`grade`=99, `sort` lạ, `page`<1, `q`>100) → **422** `VALIDATION_ERROR`; `sort`: `newest` (mặc định `published_at desc, id desc`), `popular` (`enrollments_count desc` + như trên), `featured` (`manual_order` tăng dần, null xếp sau + như trên). Item: `{id,title,slug,short_description,grade_level,price,is_free,thumbnail_url,enrollments_count,published_at,subjects:[{id,name,slug}],teachers:[{id,name}]}`; chỉ chuyên đề active. **US-020 (T36) thêm `teacher_id`** (int ≥ 1, tuỳ chọn): chỉ khóa có giáo viên đó trong `course_teacher` (AND với các bộ lọc khác); id không tồn tại/không phải giáo viên → danh sách rỗng, không lỗi; sai kiểu → 422; `links` giữ `teacher_id`. Response `{data, meta:{current_page,per_page,total,last_page}, links:{next,prev}}`. Header: `Cache-Control: public, max-age=60` + `ETag` (If-None-Match → 304), `Vary: Origin` (không bao giờ Cookie), không Set-Cookie; lỗi 4xx không có `public` |
| GET | /courses/{course:slug} | `Catalog\CourseController@show` | public | — | Chi tiết công khai + outline (tên bài, thời lượng, `is_preview`) + GV + `enrollments_count`. **Không chứa URL/ID video**. Khóa `unpublished`/xoá → 404 **T10 chốt (object phẳng):** `{id,title,slug,short_description,description,grade_level,price,is_free,thumbnail_url,enrollments_count,published_at,subjects,teachers:[{id,name,bio,avatar_url}],lessons_count,total_duration_seconds,has_preview,outline:[{id,title,position,lessons:[{id,title,position,duration_seconds,is_preview}]}]}`. Chương/bài xoá mềm không lộ; không có `video_source`/provider/asset. Slug sai định dạng hoặc không published → 404 `NOT_FOUND` (không cache public). `description` sanitize khi ghi (T08) và lọc lại khi trả ra (HtmlSanitizer); FE vẫn DOMPurify. **US-020 (T36, đổi hành vi, tương thích):** `teachers[].bio`/`avatar_url` lấy từ `teacher_profiles` qua `PublicTeacher` và là **`null` khi giáo viên chưa đồng ý công khai/đã rút** (key vẫn có); `bio` là văn bản thuần nhiều dòng (`\n`), FE render text (`white-space: pre-line`), cấm `dangerouslySetInnerHTML` |
| GET | /home/teachers | `Catalog\HomeTeacherController@index` | public, throttle:catalog (cùng nhóm cache với `/courses`) | — | **US-020 (T36).** Khu vực giáo viên trang chủ, ≤ `teacher_profile.homepage_max` (6) người. Chi tiết §2.9 |
| GET | /courses/{course:slug}/viewer-state | `Catalog\CourseController@viewerState` | student (không cần verified) | — | `viewer_state` (`can_buy`\|`in_cart`\|`can_register_free`\|`pending_approval`\|`owned`) + `resume_lesson_id`. Tách khỏi `show` để `show` cache được (S16) **T10 chốt:** `{viewer_state, resume_lesson_id}`; ưu tiên `owned` > `pending_approval` > `can_register_free` (price=0) > `can_buy`. **T16:** `in_cart` khi khóa có phí đang trong giỏ (sau `can_register_free`, trước `can_buy`). `resume_lesson_id` chỉ khi `owned`: bài có `lesson_progress.last_accessed_at` mới nhất (bài chưa xoá), chưa có thì bài đầu theo (chương, bài). Khóa không published: chỉ người `owned` thấy, người khác 404. Luôn `no-store, private` |
| GET | /preview/lessons/{lesson}/playback | `Learn\PlaybackController@preview` | public, throttle:playback (theo IP) · `LessonPolicy@preview` (bài `is_preview`, chưa xoá, khóa published) | — | `PlaybackInfo` (không ràng IP) | **T13 chốt:** cùng shape với `/learn/.../playback` (`resume_at_seconds` luôn 0); không session/cookie; mọi lý do từ chối (không preview, khóa nháp, bài/chương/khóa xoá) đều 404 `NOT_FOUND`; throttle 30/phút/IP.

Ví dụ `GET /api/v1/config/public` (phẳng — khớp giả định của FE0; thêm khoá mới = thay đổi tương thích, xoá/đổi tên khoá = v2):
```json
{
  "referral_code_enabled": true,
  "quiz_time_limit_enabled": true,
  "otp": { "ttl_minutes": 10, "resend_cooldown_seconds": 60 },
  "grades": [6, 7, 8, 9, 10, 11, 12],
  "captcha_site_key": null,
  "policy_version": "2026-09",
  "parent_consent_age": 18,
  "paid_checkout_enabled": false
}
```
`paid_checkout_enabled` (thêm 2026-10-06): `false` khi cờ `FEATURE_PAID_CHECKOUT` tắt (V2 — chờ MoMo) → FE ẩn/vô hiệu nút "Mua"/"Thanh toán" cho khóa có phí. `viewer_state` giữ nguyên (không thêm giá trị mới).
`captcha_site_key` = `null` khi chưa cấu hình Turnstile (local).

### 2.2 Xác thực học sinh (host api)

| Method | URI | Controller@action | Middleware | Request | Response |
|---|---|---|---|---|---|
| POST | /auth/register | `Auth\RegisterController` | guest.student (T05: đã đăng nhập hợp lệ → 403 `FORBIDDEN`; phiên cũ đã bị thay thế/đăng xuất coi như khách), throttle:register | `RegisterRequest`: name (≤150), date_of_birth, email, phone (VN), grade_level (6–12), password (min 8, confirmed), parent_phone/parent_email (≥1 khi dưới `privacy.parent_consent_age`), referral_code (chỉ khi flag bật), **accept_terms (accepted)**, **accept_privacy (accepted)**, **captcha_token**, device_id. `role`/`status`/`*_verified_at` **không nằm trong validated()** (S17) | 201 user; tự đăng nhập + bind phiên; tạo `consents` (self); gửi OTP; nếu dưới ngưỡng tuổi → `parent_consent_status=pending` + gửi email xác nhận cho phụ huynh (US-017) |
| POST | /auth/login | `Auth\LoginController@store` | throttle:login (T05: **không** `guest` — đăng nhập lại khi cookie cũ còn sống là hợp lệ, ADR-003) | `LoginRequest`: login (email hoặc SĐT), password, device_id (UUID; sai định dạng bị bỏ qua, không 422) | 200 user; bind phiên 1 thiết bị, huỷ session cũ + tombstone. Sai → 422 thông điệp chung. Khoá → 403 `ACCOUNT_LOCKED` **chỉ khi mật khẩu đúng**. Vai trò không phải `hoc_sinh` → 403 `WRONG_PORTAL` (sau khi mật khẩu đúng) |
| POST | /auth/logout | `Auth\LoginController@destroy` | auth:sanctum | — | 204 |
| GET | /auth/me | `Auth\MeController` | student | — | **T04:** đúng shape `user` ở khối "Bổ sung từ T03" (phẳng, không `parent_*`). **T16:** thêm `cart_count` (số dòng trong giỏ, đếm cả dòng không khả dụng).  thông tin phụ huynh **đã che** thêm ở T29 (thêm field = tương thích) |
| POST | /auth/otp/send | `Auth\OtpController@send` | student, throttle:otp-send | `SendOtpRequest`: channel ∈ `config('auth.otp.channels')` (production MVP: chỉ `email`) | 202 `{ resend_available_at }` (ISO 8601 có offset). Mã chỉ gửi tới email/SĐT **hiện tại** của tài khoản, chưa xác thực; đã xác thực → 422 field `channel` |
| POST | /auth/otp/verify | `Auth\OtpController@verify` | student, throttle:otp-verify | `VerifyOtpRequest`: code (6 số) | 200 user phẳng (`is_verified=true`). Tăng `attempts` nguyên tử trước khi so (data-model §3.1). Sai → 422 `OTP_INVALID` (field `code`); hết hạn/không có mã → 422 `OTP_EXPIRED` (field `code`, thông điệp hết hạn); hết 5 lượt của mã → 429 `TOO_MANY_ATTEMPTS` (phải gửi mã mới); vượt throttle → 429 + `Retry-After` |
| PUT | /auth/contact | `Auth\ContactController@update` | student, throttle:password-change + throttle:contact | **`current_password` (BẮT BUỘC, đổi 2026-10-06 — H1 Bảo mật cụm 1)**, email/phone mới | 200 `{ resend_available_at: string\|null }`. Thiếu/sai `current_password` → 422 field `current_password` ("Mật khẩu hiện tại không đúng."), không đổi gì, ghi audit `account.contact_change_failed`; sai nhiều lần → 429 + `Retry-After` (chung hạn mức với `PUT /auth/password`). Bắt buộc cả với tài khoản chưa xác thực. Đổi email: gửi thư báo tới email CŨ nếu đã xác thực (email mới chỉ ghi dạng che) và huỷ mọi phiên khác (phiên hiện tại giữ, cookie phiên được xoay; phiên khác nhận 401 `SESSION_REVOKED`). Huỷ MỌI OTP cũ, reset `*_verified_at` tương ứng, gửi OTP mới (S9). Request: `email` và/hoặc `phone` (≥ 1; unique; chuẩn hoá như đăng ký). Không đổi gì → 200 `null`; chỉ đổi SĐT khi kênh `sms` tắt (production) → reset `phone_verified_at`, không gửi mã, `null`. Không áp cooldown 60s (sửa nhầm email sau đăng ký) nhưng vẫn áp trần 5/giờ, 10/ngày (vượt → 429 và không đổi gì); limiter `contact` 10/giờ/user |
| POST | /auth/password/forgot | `Auth\PasswordResetController@request` | guest.student, throttle:password-reset | `ForgotPasswordRequest`: login, captcha_token | 202 `{ message, resend_available_at }` **luôn giống nhau** dù tài khoản có tồn tại/bị khoá hay không (US-015). Chi tiết ở khối T27 |
| POST | /auth/password/reset | `Auth\PasswordResetController@reset` | guest.student, throttle:otp-verify | `ResetPasswordRequest`: login, code (6 số), password, password_confirmation | 200 `{ message }`; huỷ mọi phiên (tombstone `password_changed`). Không tự đăng nhập: FE chuyển sang `/dang-nhap` |
| PUT | /auth/password | `Auth\PasswordController@update` | student, throttle:password-change | `ChangePasswordRequest`: current_password, password, password_confirmation | 200 `{ message, session_kept }`; huỷ phiên khác, bind lại phiên hiện tại (ADR-003) |

**Bảo mật cụm 1 (2026-10-06) — đổi contract:** (1) `PUT /auth/contact` thêm `current_password` bắt buộc (H1); (2) `forgot`/`reset` chỉ qua email đã xác thực (H1); (3) mật khẩu học sinh giữ min 8 nhưng bị chặn nếu nằm trong danh sách phổ biến cục bộ (422 field `password`, thông điệp "Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác."), staff min 12 (M1); (4) 401 `SESSION_REVOKED` thêm lý do "email đã thay đổi"; (5) cookie phiên production mang tiền tố `__Host-` (chỉ tên cookie, FE dùng `credentials: include` nên không đổi).

**Bổ sung từ T03 (2026-10-05):**
- `user` trong response register/login (phẳng): `id, name, email, phone, role, grade_level, is_verified (= email_verified_at != null), parent_consent_status`. Không có `parent_*` (chỉ `/auth/me` trả bản đã che).
- `password`: min 8, **max 128**; lỗi xác nhận không khớp nằm ở field `password_confirmation` (rule `same:password`, bắt buộc). Email (và `parent_email`) dùng `email:rfc,strict` + chặn khoảng trắng/comment RFC. Số điện thoại chấp nhận `+84`/`84`/dấu cách, lưu dạng `0xxxxxxxxx`; email lưu lowercase.
- Đã đăng nhập mà gọi register/login (route `guest`) → **403 `FORBIDDEN`**.
- Captcha kiểm **trước** mọi rule khác (kể cả unique); thiếu Origin hợp lệ → 400 `ORIGIN_NOT_ALLOWED` trước khi gọi captcha. Token Turnstile dùng 1 lần nên frontend phải reset widget sau mọi lỗi 422/`CAPTCHA_FAILED`.
- Login: bộ đếm "sai 10/giờ/`login` + 50/giờ/IP" chỉ tăng ở lượt SAI, xoá bộ đếm tài khoản khi đúng; `throttle:login` ở route chỉ là chống flood (120/phút/IP). Vượt → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`.
- Response register/login có `Cache-Control: no-store, private`.

**Bổ sung từ T05 (2026-10-05):** `POST /auth/register` tạo tài khoản xong mới bind phiên; nếu bind lỗi (DB) thì vẫn trả **201** nhưng **không có phiên** (không Set-Cookie phiên đăng nhập). FE: sau 201, gọi `/auth/me`; nhận 401 thì chuyển sang `/dang-nhap` (không đăng ký lại vì sẽ trùng email/SĐT). `device_id` ở register/login chỉ nhận chuỗi (mảng → 422), chuỗi sai định dạng/quá dài bị bỏ qua.

**Bổ sung từ T27 (2026-10-05) — quên/đặt lại/đổi mật khẩu (US-015):**
- `forgot`: captcha kiểm trước mọi rule (`CAPTCHA_FAILED` 422; thiếu Origin → 400 `ORIGIN_NOT_ALLOWED`). Luôn 202 `{ message: "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.", resend_available_at }`; `resend_available_at` luôn = now + 60s (không lộ gì). OTP `reset_password` gửi qua email SAU khi trả response (`defer`), chỉ cho học sinh `active`, chưa ẩn danh hoá, có email **đã xác thực** (đổi 2026-10-06, H1: email chưa xác thực, ví dụ vừa đổi qua `/auth/contact`, xử lý như không có kênh). Locked/GV/không tồn tại/email chưa xác thực → vẫn 202 y hệt, không gửi. Lỗi gửi/trần OTP của tài khoản bị nuốt (không lộ). Giới hạn: cooldown 1/phút, 5/giờ theo tài khoản (email và SĐT của cùng 1 người dùng chung hạn mức; tài khoản không tồn tại theo `LoginService::accountKey`), 30/giờ/IP → 429 `TOO_MANY_ATTEMPTS`-style + `Retry-After` (giống nhau cho tồn tại/không tồn tại). Học sinh đang đăng nhập hợp lệ gọi → 403 `FORBIDDEN`.
- `reset`: `password` min 8 max 128 và **không nằm trong danh sách mật khẩu phổ biến cục bộ** (422 field `password`; áp cả đăng ký và `PUT /auth/password`, 2026-10-06 M1). Email chưa xác thực → `OTP_EXPIRED` như không có mã (H1). lỗi xác nhận ở `password_confirmation` (quy ước T03). Mọi lỗi mã (sai mã, hết hạn, không có mã, tài khoản không tồn tại/bị khoá, hết 5 lượt của mã) → 422 `OTP_EXPIRED` field `code` ("Mã OTP đã hết hạn…", cùng thông điệp để không lộ tồn tại; T27-5). Chỉ throttle `otp-verify` mới trả 429, giống nhau cho tồn tại/không tồn tại. Mã gắn với email lúc gửi (đổi email sau đó → mã vô hiệu). Throttle `otp-verify` theo tài khoản (5/phút, 20/ngày) + 60/giờ/IP. Thành công: đổi mật khẩu + `password_changed_at` + huỷ phiên hiện hành (tombstone `password_changed` → phiên cũ nhận 401 `SESSION_REVOKED`) trong cùng transaction với việc tiêu thụ mã; audit `account.password_reset`.
- `PUT /auth/password`: sai mật khẩu hiện tại → 422 field `current_password` ("Mật khẩu hiện tại không đúng."); mật khẩu mới trùng hiện tại → 422 field `password` (chốt: từ chối; chưa kiểm N mật khẩu gần nhất). Thành công: huỷ phiên khác, `session_regenerate` + bind lại phiên hiện tại (cookie phiên MỚI trong Set-Cookie; giữ `current_device_id`), 200 `session_kept: true`. Nếu bind lại lỗi (DB) vẫn 200 nhưng `session_kept: false` và không còn phiên: FE gọi `/auth/me`, 401 → `/dang-nhap`. Throttle `password-change` 5/phút, 20/giờ/user. Audit `account.password_changed` / `account.password_change_failed`.
- Chưa làm (ghi `docs/security/backlog-v2.md`): email cảnh báo "mật khẩu vừa đổi" (câu hỏi mở US-015).

**Bổ sung từ T04 (2026-10-06):**
- `is_verified` = đã xác thực OTP ít nhất một kênh liên hệ (`email_verified_at` HOẶC `phone_verified_at`; production MVP chỉ có email nên thực tế = email). Đổi email/SĐT reset cột tương ứng. Middleware `account.verified` dùng cùng định nghĩa (`User::isVerified()`), lỗi 403 `ACCOUNT_NOT_VERIFIED`.
- Đăng ký (201) tự gửi OTP email; lỗi gửi không làm hỏng đăng ký (học sinh bấm gửi lại).
- Trần OTP (nguồn sự thật là bảng `otp_codes`, không phụ thuộc cache): gửi mã cooldown 60s, ≤ 5/giờ, ≤ 10/ngày/user (mọi purpose); vượt → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`, trần ngày ghi audit `otp.send_limit_reached`. Mỗi mã tối đa 5 lần so (`auth.otp.max_attempts_per_code`); verify throttle 5/phút, 20/ngày/user, 60/giờ/IP. Mã hiệu lực 10 phút. Gửi mã mới huỷ mã cũ cùng purpose. **Nới cho e2e:** `AUTH_OTP_E2E_RELAXED=true` chỉ có hiệu lực khi `APP_ENV` là `local`/`testing` (cooldown 1s, 1000/giờ, 10000/ngày, limiter route tương ứng); production luôn bỏ qua biến này.
- `channel` mặc định `email` nếu không gửi. `sms` chỉ hợp lệ khi có trong `AUTH_OTP_CHANNELS` (local/testing, nhà cung cấp giả lập); production → 422 field `channel`, và app không boot nếu cấu hình bật `sms` ở production.
- Đổi liên hệ chỉ huỷ mã của kênh có đích bị đổi (đổi email → mã email; đổi SĐT → mã sms); production đổi chỉ SĐT không huỷ mã email đang chờ. Verify (so đúng + consume + ghi `*_verified_at`) chạy trong một transaction có khoá hàng user và kiểm lại đích mã.
- Không có mã lỗi OTP riêng cho sai/hết hạn: sai/hết hạn là 422 `VALIDATION_ERROR` field `code`; hết lượt là 429 `TOO_MANY_ATTEMPTS`.

### 2.3 Học sinh — giỏ hàng, checkout, đơn (host api, nhóm `student` + `role:hoc_sinh`)

| Method | URI | Controller@action | Middleware / Policy | Request | Response |
|---|---|---|---|---|---|
| GET | /cart | `Cart\CartController@show` | | — | **T16 chốt** (phẳng, mọi route giỏ trả cùng shape): `{items: [{course_id, title, slug, grade_level, thumbnail_url, price, unavailable, discount_amount, final_amount, added_at}], coupon: null \| {code, name, discount_type, discount_value, discount_amount, applies_to_course_ids[]}, pricing: {subtotal, discount, total}, notices: [{code, message}]}`. Mới thêm trước. `unavailable=true` (khóa xoá/ngừng bán/đổi sang miễn phí/đã sở hữu): không tính vào `pricing`, `discount_amount`/`final_amount` = null. `notices.code` ∈ `ITEMS_UNAVAILABLE`, `COUPON_REMOVED` (mã bị tự gỡ vì hết điều kiện — GET /cart tự gỡ và ghi `carts.coupon_id` = null). Giỏ chưa có → items rỗng, không tạo giỏ. Role khác học sinh → 403 |
| POST | /cart/items | `Cart\CartItemController@store` | | `AddCartItemRequest`: course_id (exists, published, price > 0) | **T16:** 201 + cart · course_id không tồn tại/chưa xuất bản/đã xoá/miễn phí/sai kiểu → 422 `VALIDATION_ERROR` `errors.course_id` · 409 `ALREADY_IN_CART`/`ALREADY_OWNED` (chỉ enrollment `active`) |
| DELETE | /cart/items/{course} | `Cart\CartItemController@destroy` | | — | **T16:** `{course}` là id số; 200 + cart (idempotent, khóa đã xoá mềm vẫn gỡ được); tự gỡ mã nếu hết điều kiện kèm notice `COUPON_REMOVED` |
| PUT | /cart/coupon | `Cart\CartCouponController@update` | throttle:coupon | `ApplyCouponRequest`: code (≤ 50) | **T16:** 200 + cart (idempotent; mã mới thay mã cũ; lỗi giữ nguyên mã cũ) · 422: `COUPON_INVALID` (không tồn tại/chưa bắt đầu/vô hiệu), `COUPON_EXPIRED` (hết hạn **hoặc** hết lượt — khác `message`), `COUPON_ALREADY_USED`, `COUPON_NOT_APPLICABLE` (giỏ rỗng/không khóa nào thuộc phạm vi/phạm vi rỗng/mã fixed đưa tổng về 0đ mà thiếu `max_uses`+`valid_until`; fixed lớn hơn phần áp dụng vẫn áp được, giảm = min) · 429 `TOO_MANY_ATTEMPTS` + `Retry-After` (>30 lần SAI/ngày/HS; lần đúng không tính) và throttle 10/phút. Mã chuẩn hoá `trim`+hoa. Không trừ/giữ lượt dùng ở giỏ |
| DELETE | /cart/coupon | `Cart\CartCouponController@destroy` | | — | 200 + cart (idempotent) |
| GET | /checkout/preview | `Checkout\CheckoutController@preview` | account.verified, parent.consent | — | **T18 chốt** (phẳng; **2026-10-06:** khi `FEATURE_PAID_CHECKOUT` tắt và `requires_payment=true` thì `can_checkout=false` + notice `PAYMENT_DISABLED`; đơn 0đ không ảnh hưởng): `{items[] (như giỏ, hợp lệ), removed_items[] (khóa không còn khả dụng, bị loại khỏi đơn), coupon, pricing:{subtotal,discount,total}, notices[], can_checkout, requires_payment}`. Không ghi DB/không khoá. Mã hết chỗ (đơn pending còn hạn giữ chỗ của HS khác tính vào `max_uses`) bị ẩn khỏi preview kèm notice `COUPON_EXHAUSTED`. Giỏ trống → 200 items rỗng, `can_checkout=false` (FE chuyển về giỏ — AC3). `parent.consent` chỉ có hiệu lực khi `FEATURE_PARENT_CONSENT_ENFORCED=true` (mặc định tắt ở v1: mọi HS qua); khi bật chỉ cho `parent_consent_status` ∈ {not_required, granted}, ngược lại 403 `PARENT_CONSENT_REQUIRED` |
| POST | /checkout | `Checkout\CheckoutController@store` | account.verified, parent.consent, throttle:checkout | `CheckoutRequest`: expected_total (int ≥ 0, tổng HS đã thấy ở preview), gateway (tuỳ chọn, ∈ `enabled_gateways`, mặc định cổng đầu) | **T18 chốt:** **201** đơn mới / **200** dùng lại đơn pending cũ cùng nội dung (cùng khóa, số tiền từng dòng, mã, cổng; chưa quá `expires_at`): `{order_code, status: pending\|paid, total, reused, payment: {gateway, pay_url, expires_at} \| null, link_expired}`. `payment` null khi (a) đơn **0đ**: đã `paid` ngay (`payment_method=none`, `status_reason=zero_amount`, cấp enrollment + ghi `coupon_usages`, dọn giỏ), không gọi cổng; cờ `FEATURE_ZERO_TOTAL_CHECKOUT` tắt → 422 `ZERO_TOTAL_DISABLED`; (b) `link_expired=true`: link cũ hết hạn nhưng chưa được cổng xác nhận → HS gọi `POST /orders/{code}/pay` (T20, đối soát rồi tạo link mới). Lỗi: **503 `PAYMENT_DISABLED`** ("Thanh toán trực tuyến đang tạm khoá." — cờ `FEATURE_PAID_CHECKOUT` tắt và tổng > 0; không tạo đơn/attempt; kiểm sau CHECKOUT_CHANGED; đây là tắt chủ động, không phải sự cố: FE KHÔNG auto-retry, hiển thị message và dùng `paid_checkout_enabled` của `/config/public` để ẩn nút mua) · 422 `CART_EMPTY` (giỏ trống/không còn khóa hợp lệ) · 422 `VALIDATION_ERROR` (expected_total sai kiểu; `gateway` ngoài allowlist: nếu `enabled_gateways` khác rỗng thì kiểm ở request, nếu rỗng thì chỉ kiểm khi tổng > 0, sau 409 và 503 — **đơn 0đ không cần cổng nên vẫn 201 khi `PAYMENT_GATEWAYS` rỗng**, cụm 3 M1) · 422 `AMOUNT_BELOW_GATEWAY_MIN`/`AMOUNT_ABOVE_GATEWAY_MAX` (kiểm trước khi tạo đơn) · **409 `CHECKOUT_CHANGED`**: `errors = {reasons: [PRICE_CHANGED\|COUPON_REMOVED\|COUPON_EXHAUSTED\|ITEMS_CHANGED], preview: <shape của GET /checkout/preview>}` (mã hết hạn/hết lượt/hết chỗ đã bị gỡ khỏi giỏ và commit; HS xem `preview` rồi gọi lại với `expected_total` mới) · 409 `PAYMENT_IN_PROGRESS` (request khác đang tạo giao dịch, thử lại sau vài giây) · **502 `PAYMENT_GATEWAY_UNAVAILABLE`** `errors = {order_code}` (đơn vẫn `pending`, attempt `error`; gọi lại POST /checkout sẽ dùng lại đơn và tạo attempt mới). Đã có đơn pending khác nội dung hoặc quá hạn → đơn cũ `cancelled` (`status_reason=superseded`), tạo đơn mới. Quyết định giá dưới khoá `carts → orders → courses(SHARE) → coupons`; giá/số tiền chốt vào `order_items`; `coupon_hold_until` = now + min(`link_ttl`, `payments.coupon_hold_minutes`=30). `used_count`/`coupon_usages` chỉ ghi khi đơn `paid` |
| POST | /orders/{order:code}/pay | `Checkout\OrderPaymentController@store` | account.verified, throttle:pay · `OrderPolicy@pay` | — | {pay_url, expires_at}. Khoá `carts → orders` khi quyết định/tạo attempt; đối soát link cũ trước; kiểm lại sức chứa mã khi quá `coupon_hold_until` (ADR-001 §6, §7) |
| POST | /orders/{order:code}/check-payment | `Checkout\OrderPaymentController@check` | throttle:check-payment · `OrderPolicy@view` | — | Đối soát ngay attempt mới nhất rồi trả trạng thái đơn |
| GET | /orders | `Order\MyOrderController@index` | | page | Đơn của tôi |
| GET | /orders/{order:code} | `Order\MyOrderController@show` | `OrderPolicy@view` | — | Chi tiết + `status`, `status_reason`, `expires_at`, `payment` {gateway, link_expired, can_retry} |

`pay_url` chỉ được frontend mở khi host thuộc allowlist MoMo (S23).

### 2.4 Học sinh — học tập (host api, nhóm `student`)

| Method | URI | Controller@action | Middleware / Policy | Request | Response |
|---|---|---|---|---|---|
| POST | /courses/{course}/free-enrollments | `Enrollment\FreeEnrollmentController@store` | role:hoc_sinh, account.verified, parent.consent · `EnrollmentPolicy@requestFree` | — | 201 `pending_approval` · 409 · **T14 chốt:** body rỗng (mọi field body bị bỏ qua). 201 phẳng `{id, course_id, status:"pending_approval", requested_at}`. Lỗi: 403 `ACCOUNT_NOT_VERIFIED` (middleware `account.verified` chỉ gắn ở route này, không chặn xem/học; chỉ cần 1 kênh email/SĐT đã xác thực), 404 `NOT_FOUND` (khóa không tồn tại/xoá/không `published`), 422 `COURSE_NOT_FREE` (price ≠ 0), 409 `ENROLLMENT_PENDING` (đang chờ duyệt; cả khi 2 request đồng thời — unique 1062 được dịch), 409 `ALREADY_OWNED` (đã `active`). Bị từ chối/thu hồi rồi thì xin lại được (tạo dòng mới). `parent.consent` chưa gắn (chưa có US-017) · |
| GET | /me/courses | `Learn\MyCourseController@index` | role:hoc_sinh | page | Khóa có enrollment active + % tiến độ, sắp theo học gần nhất; kèm pending/rejected | **T23 chốt:** throttle `me-courses` 60/phút/người; `Cache-Control: no-store, private`. Query `page` (≥1), `per_page` (1..30, mặc định 12), sai → 422 `VALIDATION_ERROR`. Response 200 `{data:[{course:{id,title,slug,grade_level,thumbnail_url,is_published}, enrollment:{id,status:'active',activated_at,last_accessed_at}, progress:{percent 0..100 (làm tròn xuống), completed_lessons, total_lessons, is_completed (100% và total>0), has_content (total>0)}, resume_lesson_id|null, best_quiz_score|null (thang 10, max các lượt đã nộp của mọi quiz trong khóa)}], meta:{current_page,per_page,total,last_page}, pending:[{enrollment_id,status:'pending_approval',course,requested_at}], rejected:[{enrollment_id,status:'rejected',course,requested_at,rejection_reason}]}`. Sắp: `last_accessed_at` mới nhất trước, chưa học xếp sau (theo `activated_at` mới nhất). Chỉ enrollment `active` (revoked/rejected/pending không vào `data`); khóa đã gỡ xuất bản vẫn hiện (`is_published=false`); khóa xoá mềm ẩn. `pending`/`rejected` không phân trang (tối đa 20, mỗi khóa 1 dòng; khóa bị từ chối mà đã xin lại thì không còn ở `rejected`). Rỗng: `data=[]` (FE gợi ý link danh mục). |
| GET | /me/courses/{course}/progress | `Learn\MyCourseController@progress` | `LessonAccessService::assertCanLearnCourse` (T13; không dùng Policy để có mã `COURSE_NOT_OWNED`) | — | Bài đã xong + quiz và điểm cao nhất | **T23 chốt:** 200 `{course:{id,title,slug,grade_level,thumbnail_url,is_published}, enrollment:{activated_at,last_accessed_at}, progress:{percent,completed_lessons,total_lessons,is_completed,has_content}, resume_lesson_id|null, chapters:[{id,title,position,completed_lessons,total_lessons,lessons:[{id,title,position,duration_seconds,status:'not_started'|'in_progress'|'completed',watched_seconds,completed_at|null}]}], quizzes:[{id,title,chapter_id|null,lesson_id|null,question_count,attempted,attempts_count (lượt đã nộp),best_score|null (thang 10)}]}`. Lỗi: 403 `COURSE_NOT_OWNED` (chưa có enrollment active, kể cả đang chờ duyệt/bị thu hồi), 404 (khóa không tồn tại/đã xoá; khóa chưa published mà không sở hữu), 429. Không có đáp án/giải thích/URL video. |
| GET | /learn/courses/{course} | `Learn\LearnCourseController@show` | `LessonAccessService::assertCanLearnCourse` (T13; không dùng Policy để có mã `COURSE_NOT_OWNED`) | — | Outline + trạng thái bài + `resume_lesson_id` | **T13 chốt:** `{course:{id,title,slug}, course_percent, resume_lesson_id, chapters:[{id,title,position,quizzes[],lessons:[{id,title,position,is_preview,duration_seconds,video_ready,status:not_started\|in_progress\|completed,quizzes[]}]}]}`; quiz `{id,title,time_limit_minutes,question_count}`. Không có URL/ID video. Quyền: enrollment active, ngược lại 403 `COURSE_NOT_OWNED` (khóa không published và không sở hữu → 404). `resume_lesson_id` (AC6): bài gần nhất chưa xong; nếu bài gần nhất đã xong → bài chưa xong kế tiếp (vòng về đầu); xong hết → bài gần nhất; chưa học → bài đầu; khóa rỗng → null. Bài/chương xoá mềm bị loại. Route `whereNumber`.
| GET | /learn/lessons/{lesson} | `Learn\LessonController@show` | `LessonAccessService::assertCanWatch` (T13; không dùng Policy để có mã `COURSE_NOT_OWNED`) | — | Bài + prev/next + quiz gắn kèm (**Resource không có `is_correct`/`explanation`** — I3) + progress | **T13 chốt:** `{lesson:{id,course_id,chapter_id,chapter_title,title,position,is_preview,duration_seconds,video_ready}, course:{id,title,slug}, can_track, prev:{id,title}\|null, next, quizzes[], progress:{status,watched_seconds,last_position_seconds,completed_at}\|null}`. Chủ khóa xem mọi bài; người chưa sở hữu chỉ xem bài `is_preview` của khóa published (`can_track=false`, prev/next chỉ trong các bài preview); bài khác → 403 `COURSE_NOT_OWNED`; bài/chương/khóa xoá mềm hoặc khóa nháp (không sở hữu) → 404. Quiz chỉ có đếm câu hỏi (không `is_correct`/`explanation`).
| GET | /learn/lessons/{lesson}/playback | `Learn\PlaybackController@show` | throttle:playback · `LessonAccessService::assertCanWatch` (T13; không dùng Policy để có mã `COURSE_NOT_OWNED`) | — | `PlaybackInfo` {kind, url, expires_at (15 phút), resume_at_seconds}; ràng IP; ghi log `playback` | **T13 chốt:** response `{kind:'hls'\|'embed', url, expires_at (ISO8601, null với embed), resume_at_seconds}`, `Cache-Control: no-store, private`. Ràng IP khi `video.bind_ip` và bài không preview; TTL `video.playback_ttl_minutes`. `resume_at_seconds` = `last_position_seconds`, về 0 khi vị trí ≥ thời lượng−5s hoặc xem bài preview chưa sở hữu. Lỗi: 403 `COURSE_NOT_OWNED`, 404 `NOT_FOUND`, 404 `VIDEO_NOT_AVAILABLE` (bài không có video), 409 `VIDEO_NOT_READY` (asset chưa `ready`), 503 `VIDEO_PROVIDER_UNAVAILABLE`, 429. Link ngoài trả `embed` dựng lại từ ID (không trả URL nhập). Log channel `playback`; cảnh báo >3 IP hoặc >60 bài/giờ/user.
| POST | /learn/lessons/{lesson}/heartbeat | `Learn\ProgressController@heartbeat` | throttle:heartbeat · `LessonAccessService::assertCanWatch` (T13; không dùng Policy để có mã `COURSE_NOT_OWNED`) | `HeartbeatRequest`: position_seconds (0..duration), watched_delta_seconds (0..60) | {status, completed, course_percent} | **T13 chốt:** body BẮT BUỘC số NGUYÊN (`position_seconds` 0..86400, `watched_delta_seconds` 0..60; số thực/âm/quá lớn → 422 `VALIDATION_ERROR`), FE phải `Math.floor`. Response 200 `{status:'in_progress'\|'completed', completed:boolean, course_percent:0..100}`. Chỉ chủ khóa (enrollment active) ghi tiến độ: người xem preview chưa sở hữu → 403 `COURSE_NOT_OWNED` (FE đọc `can_track` ở `GET /learn/lessons/{id}`, không gọi heartbeat khi `false`); thu hồi giữa phiên → 403 `COURSE_NOT_OWNED` (dừng player); bài/khóa xoá mềm → 404; phiên bị thay thế → 401 `SESSION_REPLACED`. Chống gian lận: số giây cộng thêm ≤ min(delta, 2×giây thật từ heartbeat trước + 5s; heartbeat đến trong <5s không có +5s; trùng vị trí trong <3s = gửi lại, cộng 0); lần đầu coi như 20s; `watched_seconds` ≤ thời lượng; `position_seconds` kẹp theo thời lượng. Hoàn thành khi `watched ≥ 90% × duration` (bài chưa có thời lượng không tự hoàn thành), không quay lại `in_progress`. FE nên gửi mỗi ~20s (throttle 6/phút/user/bài). `course_percent` = bài completed / tổng bài chưa xoá, làm tròn xuống.
| POST | /learn/quizzes/{quiz}/attempts | `Quiz\AttemptController@store` | `QuizPolicy@take` | — | Lượt đang làm (tạo hoặc resume): câu hỏi **không có đáp án đúng**, answers, expires_at, server_now | | **T22 chốt:** body rỗng. **201** lượt mới / **200** lượt đang làm được tiếp tục (resume). Shape `AttemptInProgress`: `{id, quiz_id, status:'in_progress', started_at, expires_at (null nếu không giới hạn; ISO8601), server_now, remaining_seconds (null nếu không giới hạn), total_questions, answers:{"<question_id>":option_id} (luôn là object), questions:[{id,position,content,options:[{id,position,content}]}]}` — KHÔNG có `is_correct`/`explanation`. Đồng hồ FE tính theo `server_now`/`remaining_seconds`, hết giờ gọi `submit`. Lỗi: 403 `COURSE_NOT_OWNED`, 404 (quiz/khóa xoá hoặc khóa nháp), 422 `QUIZ_NOT_READY` (quiz chưa có câu). Khi `FEATURE_QUIZ_TIME_LIMIT` tắt: không giới hạn thời gian. Thứ tự câu = `position` lúc bắt đầu, chốt cho cả lượt (câu bị admin sửa sau đó không đổi trong lượt này)
| PUT | /learn/quiz-attempts/{attempt}/answers/{question} | `Quiz\AnswerController@update` | `QuizAttemptPolicy@answer` | `SaveAnswerRequest`: option_id thuộc đúng question; question (ép int) thuộc `question_ids` | 204 | | **T22 chốt:** body `{option_id: int}`; 204 (idempotent, ghi đè được). Lỗi: 404 (lượt không phải của mình), 403 `COURSE_NOT_OWNED`, 409 `QUIZ_ATTEMPT_SUBMITTED` (đã nộp), 409 `QUIZ_ATTEMPT_EXPIRED` (quá hạn + ân hạn; lượt đã được tự nộp, FE chuyển sang GET kết quả), 422 `QUIZ_QUESTION_NOT_IN_ATTEMPT`, 422 `QUIZ_OPTION_INVALID`, 422 `VALIDATION_ERROR` (`errors.option_id`). Throttle 120/phút/lượt. Throttle chung: POST start/submit 30/phút/người, GET show/history 60/phút/người (429)
| POST | /learn/quiz-attempts/{attempt}/submit | `Quiz\AttemptController@submit` | `QuizAttemptPolicy@submit` | — | Kết quả (idempotent) | | **T22 chốt:** body rỗng, **luôn 200** (nộp lại lượt đã nộp → trả lại đúng kết quả đã chốt; double submit/2 tab chỉ chấm 1 lần). Shape `AttemptResult`: `{id, quiz_id, status:'submitted', started_at, submitted_at, auto_submitted, server_now, total_questions, correct_count, unanswered_count, score (thang 10, 2 chữ số), questions:[{id,position,content,explanation,selected_option_id|null,correct_option_id,is_correct,options:[{id,position,content}]}]}`. Nộp sau `expires_at + ân hạn` (`QUIZ_SUBMIT_GRACE_SECONDS`, mặc định 30 s): vẫn 200, chấm theo đáp án đã autosave, `auto_submitted=true`, `submitted_at=expires_at`. Lỗi 404, 403
| GET | /learn/quiz-attempts/{attempt} | `Quiz\AttemptController@show` | `QuizAttemptPolicy@view` | — | Kết quả / trạng thái | | **T22 chốt:** lượt đang làm → shape `AttemptInProgress` (như start); đã nộp → `AttemptResult`. Phân biệt bằng `status`. Lượt quá hạn được tự nộp rồi trả kết quả. Lượt người khác → 404
| GET | /learn/quizzes/{quiz}/attempts | `Quiz\AttemptController@index` | `QuizPolicy@take` | — | Lịch sử + điểm cao nhất | | **T22 chốt:** `{data:[{id,status:'in_progress'|'submitted',started_at,expires_at,submitted_at,auto_submitted,total_questions,correct_count,score}] (mới nhất trước, tối đa 50), best_score (null nếu chưa nộp lượt nào), attempts_count (số lượt đã nộp)}`. Không có câu hỏi/đáp án. Lỗi 403 `COURSE_NOT_OWNED`, 404

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
- `PUT /admin/auth/password` (bắt buộc `current_password`, kể cả màn đổi mật khẩu lần đầu) body `{current_password, password, password_confirmation}` (**đổi 2026-10-06, M1: min 12**, max 128, không nằm trong danh sách phổ biến cục bộ, không chứa phần trước `@` của email; phải khác mật khẩu cũ; lỗi khớp ở `password_confirmation`): 200 `{ "user": StaffUser }` (cookie đổi id), huỷ mọi phiên khác (chúng nhận 401 `UNAUTHENTICATED`). Sai mật khẩu hiện tại 422 field `current_password`. Throttle 5/phút, 20/giờ/user. Yêu cầu đã qua MFA (chặt hơn bản đầu), không yêu cầu `password_fresh`.
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
| GET | /admin/courses/{course}/chapters | `ChapterController@index` | `manageContent` | **T09 chốt:** cây cho màn kéo thả `{course_id, chapters:[ChapterResource{id,course_id,title,position,lessons:[LessonResource],created_at,updated_at}]}` (chương/bài chưa xoá, đúng thứ tự `position,id`) |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters[/{chapter}] | `ChapterController` | `manageContent` (theo `$course`; `{chapter}` không thuộc `{course}` → 404) | **T09 chốt:** body `{title}` (văn bản thuần ≤255, bắt buộc, không nhận `position`/`course_id`). POST 201 `ChapterResource` (position = cuối + 1); PUT 200; DELETE 204 xoá mềm chương + bài của chương; có bài đã có `lesson_progress` → 409 `CHAPTER_HAS_PROGRESS` (không xoá gì); khóa `published` mà xoá xong không còn bài nào → 409 `COURSE_LAST_LESSON` (gỡ xuất bản trước). Audit `chapter.create/update/delete` |
| PUT | /admin/courses/{course}/curriculum/order | `CurriculumOrderController` | `manageContent` | `[{chapter_id, lesson_ids[]}]`: mọi `chapter_id`/`lesson_id` thuộc `$course` và chưa xoá; **tập ID gửi lên phải bằng đúng tập hiện có** (không thêm, không thiếu); 1 transaction. Sai → 422 (S5). **T09 chốt:** thân JSON là mảng gốc (không bọc); `lesson_ids` có thể rỗng; sai hình dạng/trùng ID, > 500 chương, hoặc body không phải JSON → 422 `VALIDATION_FAILED` (`errors`); tập ID khác tập hiện có (thiếu/thừa/khóa khác/đã xoá, hoặc người khác vừa thêm/xoá) → 422 `CURRICULUM_MISMATCH` (FE tải lại cây). Thành công 200 `{course_id, chapters:[...]}` (cùng shape GET chapters), `position` đánh lại 1..n liên tục; bài có thể chuyển chương. Khoá dòng khóa học nên an toàn đồng thời. Audit `curriculum.reorder` |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters/{chapter}/lessons[/{lesson}] | `LessonController` | `manageContent` (theo `$lesson->course`) | `LessonRequest`: title, is_preview, video_source (`none`\|`upload`\|`external_link`), external_url (chỉ khi `is_preview` — mặc định; parse ra provider + ID, host whitelist), duration_seconds. **Không nhận** `video_asset_id`, `course_id`, `chapter_id` của khóa khác. **T09 chốt:** POST 201/PUT 200 `LessonResource{id,course_id,chapter_id,title,position,is_preview,video_source,duration_seconds,external_provider,external_video_id,external_embed_url,has_video_asset,video_status,created_at,updated_at}` (`external_embed_url` dựng lại từ ID; không trả URL người nhập). PUT: gửi trường nào sửa trường đó. `title` bắt buộc khi tạo. `video_source=external_link` cần `is_preview=true` và `external_url` https YouTube (`youtube.com/watch?v=`, `/embed/`, `/shorts/`, `/live/`, `youtu.be/`, nocookie) hoặc Vimeo công khai (`vimeo.com/{id}`, KHÔNG nhận link có hash `/{id}/{hash}` của video không công khai; `player.vimeo.com/video/{id}`), cổng 443, không userinfo → ngược lại 422 (`errors.external_url` hoặc `errors.video_source`); sửa bài đang external mà không gửi `external_url` thì giữ ID cũ; bỏ `is_preview` khi đang external → 422. `video_source=upload` chỉ hợp lệ khi bài đã có `video_asset_id` do T11 gắn (tạo mới/chưa có asset → 422 `errors.video_source`): FE tạo bài `none` rồi gọi video-uploads. Đổi sang `none`/`external_link` gỡ `video_asset_id`; `duration_seconds` bị bỏ qua khi `upload` (webhook ghi), 0..86400 còn lại. `video_status` = trạng thái asset hoặc null. DELETE 204 xoá mềm; bài đã có `lesson_progress` → 409 `LESSON_HAS_PROGRESS`; xoá bài cuối của khóa `published` → 409 `COURSE_LAST_LESSON`; audit `lesson.create/update/delete`. Bài/chương đã xoá mềm → 404 |
| POST | /admin/courses/{course}/lessons/{lesson}/video-uploads | `LessonVideoUploadController@store` | `manageContent` | filename (hiển thị), size (≤ `max_upload_mb`), kiểm hạn mức 20 GB/ngày → {video_asset_id, upload: {tus_endpoint, headers, expires_at ≤ 6h}} |
| | | | | **T11 chốt:** body `{filename, size}`; `filename` kết thúc `.mp4/.mov/.mkv/.webm`, `size` 1..1073741824 (byte, 1 GB; PO đổi từ 2 GB ngày 2026-10-07, theo `video.max_upload_mb`) → 422 `errors.filename`/`errors.size`. **201** `{video_asset_id, status:'uploading', upload:{protocol:'tus', tus_endpoint, headers:{AuthorizationSignature, AuthorizationExpire, VideoId, LibraryId}, expires_at}}` (FE gửi nguyên `headers` cho tus-js-client). Bài gắn asset mới, `video_source=upload`, xoá link ngoài + `duration_seconds`; asset cũ thành mồ côi, `videos:prune-orphans` dọn. Lỗi: 422 `VIDEO_TOO_LARGE`, 422 `VIDEO_QUOTA_EXCEEDED` (context `remaining_bytes`), 503 `VIDEO_PROVIDER_UNAVAILABLE`, 403 (GV không được gán), 404 (bài khác khóa/đã xoá), throttle 20/phút |
| GET | /admin/courses/{course}/lessons/{lesson}/video | `LessonVideoUploadController@show` | `manageContent` | **T11 chốt:** `{lesson_id, video_source, has_video_asset, video_asset_id, status (created\|uploading\|processing\|ready\|failed \| null nếu chưa có video), duration_seconds, original_filename, error_message, updated_at}`. FE poll tới `ready`/`failed` |
| GET | /admin/courses/{course}/lessons/{lesson}/playback | `Learn\PlaybackController@admin` | `LessonPolicy@watch` | | **T13 chốt:** scopeBindings (bài phải thuộc khóa, sai/xoá → 404); staff hoặc giáo viên được gán (giáo viên khác 403); không ràng IP, không ghi tiến độ; throttle `playback`; cùng shape và mã lỗi video như trên.
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes[/{quiz}] | `Admin\QuizController` | `manageContent` (theo `$quiz->course`) | title, lesson_id **hoặc** chapter_id (exists trong `$course`), time_limit_minutes (null/1–300; bỏ qua khi flag tắt). `course_id` suy ra, không từ request. **T21 chốt:** route `GET|POST /quizzes`, `GET|PUT|DELETE /quizzes/{quiz}`. Body POST/PUT (PUT thay toàn bộ): `{title (văn bản thuần ≤255, bắt buộc), chapter_id XOR lesson_id (đúng 1, thuộc `{course}`, chưa xoá), time_limit_minutes (null | 1–300). Khi `FEATURE_QUIZ_TIME_LIMIT` tắt: trường bị bỏ qua hoàn toàn, không validate/không 422; POST lưu null, PUT giữ nguyên giá trị cũ}`. `QuizResource{id,course_id,chapter_id,lesson_id,parent_type('chapter'|'lesson'),parent_title,title,time_limit_minutes,position,questions_count,created_at,updated_at}`; `GET /quizzes` → `{data:[QuizResource]}` (sắp `position,id`); `GET /quizzes/{quiz}` thêm `questions:[QuizQuestionResource]` (có đáp án đúng, chỉ admin-api). POST 201, PUT 200, DELETE 204 (xoá mềm; câu hỏi giữ nguyên). Xoá chương/bài ở T09 xoá mềm theo quiz gắn với chúng. Lỗi: 422 `VALIDATION_FAILED` (`errors.title|chapter_id|lesson_id|time_limit_minutes`), 422 `QUIZ_PARENT_INVALID` (chương/bài biến mất giữa chừng), 403, 404 (quiz khác khóa/đã xoá). Audit `quiz.create/update/delete` |
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes/{quiz}/questions[/{question}] | `Admin\QuizQuestionController` | `manageContent` | content/explanation văn bản thuần ≤ 5.000, đúng 4 options (≤ 1.000) và đúng 1 đáp án đúng, ≤ 200 câu/quiz. Copy-on-write. **T21 chốt:** route `GET|POST /questions`, `GET|PUT|DELETE /questions/{question}`. Body POST/PUT (PUT thay toàn bộ): `{content (bắt buộc ≤5.000), explanation (null | ≤5.000), options:[{content (≤1.000), is_correct (bool)}]×4}`; thứ tự mảng = A–D (`position` 1–4), `id` của option gửi lên bị bỏ qua. Văn bản thuần: chứa `$...$` được, `<`/`>` đứng riêng được (`x > 2`) nhưng `<` liền chữ/`/`/`!`/`?` (dạng thẻ HTML) hoặc ký tự điều khiển (trừ xuống dòng, tab), ký tự bidi/độ rộng 0, UTF-8 sai → 422 (dùng `\lt`, `\gt` trong LaTeX). `QuizQuestionResource{id,quiz_id,content,explanation,position,options:[{id,content,is_correct,position}],created_at,updated_at}`. POST 201 (position = cuối + 1); PUT **luôn 200**; DELETE 204. **Copy-on-write: nếu câu đã có lượt làm, PUT trả câu với `id` MỚI (cùng `position`), câu cũ bị xoá mềm → FE phải dùng id trong response; id cũ gọi lại → 404.** Chưa có lượt làm: sửa tại chỗ, giữ id. Lỗi: 422 `VALIDATION_FAILED` (`errors.content|explanation|options|options.N.content|options.N.is_correct`; `errors.options` cho sai số lượng/không đúng 1 đáp án đúng), 422 `QUIZ_QUESTION_LIMIT` (đã đủ 200 câu), 403, 404. Chưa có API đổi thứ tự câu (ngoài MVP T21). Audit `quiz_question.create/update/delete` (không ghi nội dung câu) |
| GET | /admin/enrollment-requests | `Admin\EnrollmentRequestController@index` | `EnrollmentPolicy@viewAnyRequests` | course_id?; GV chỉ khóa mình; sắp `requested_at asc`. Tên HS hiển thị, email/SĐT **che** **T14 chốt:** query `course_id` (int), `status` (`pending_approval` mặc định \| `active` \| `rejected`), `per_page` (1–100, mặc định 25); sai → 422 tiếng Việt. Giáo viên: không có `course_id` → chỉ khóa mình phụ trách; `course_id` khóa không phụ trách (kể cả không tồn tại) → 403 `FORBIDDEN`; staff lọc khóa không tồn tại → danh sách rỗng. Item: `{id, status, requested_at, approved_at, rejection_reason, course:{id,title,slug}, student:{id,name,grade_level,email_masked,phone_masked}}` (email `n***@domain`, SĐT `******5678`). Response `{data, meta, links}` |
| POST | /admin/enrollment-requests/{enrollment}/approve · /reject | | `EnrollmentPolicy@decide` (theo `$enrollment->course`) | reason ≤ 1000 văn bản thuần. Lần 2 → 409 **T14 chốt:** `reason` tuỳ chọn, `null`/rỗng → `null`; chứa thẻ HTML/ký tự điều khiển → 422; `approve` không lưu `reason` (chỉ ghi audit). Quyền kiểm TRƯỚC validate (GV không phụ trách → 403 dù payload sai). Thành công 200 trả item như `GET` (phẳng) với `status` mới (`active`\|`rejected`). Với `rejected`: `approved_by/approved_at` luôn `null` (người và thời điểm từ chối xem ở audit `enrollment.reject`). `approve` khóa đã xoá mềm → 409 `COURSE_UNAVAILABLE` (vẫn `reject` được). `approve` khi khóa đã chuyển sang có phí (price > 0) lúc yêu cầu còn chờ → 422 `COURSE_NOT_FREE`, yêu cầu giữ `pending_approval` (vẫn `reject` được). Danh sách bỏ yêu cầu của khóa đã xoá mềm. Đã xử lý (kể cả double click/2 người cùng bấm) → 409 `ALREADY_PROCESSED`; không tồn tại → 404. Duyệt: `approved_by/approved_at/activated_at`, `courses.enrollments_count` +1 cùng transaction; từ chối: `rejection_reason`, count không đổi; audit `enrollment.approve`/`enrollment.reject` **Email báo kết quả (US-012 AC2/AC3):** sau khi commit, queue `EnrollmentDecisionMail` (tiếng Việt, kèm lý do khi từ chối) tới email đã xác thực của học sinh; học sinh chỉ có SĐT thì bỏ qua; lỗi gửi mail không làm hỏng việc duyệt |

**Mã giảm giá & đơn hàng:**

| Method | URI | Controller@action | Policy | Request / ghi chú |
|---|---|---|---|---|
| GET | /admin/coupons | `Admin\CouponController@index` | `CouponPolicy@viewAny` (chỉ admin/quản lý trang; giáo viên 403) | **T15 chốt:** query `state` (`active`\|`inactive`\|`expired`\|`exhausted`\|`upcoming`), `q` (≤ 100, khớp code/tên, escape LIKE), `per_page` (25\|50). Sắp mới nhất trước. `state` suy ra, ưu tiên `inactive` > `expired` (`valid_until` < now) > `exhausted` (`max_uses` không null và `used_count` ≥ `max_uses`) > `upcoming` (`valid_from` > now) > `active`. Response `{data, meta, links}`, item = **Coupon** (bên dưới) kèm `courses_count`, `subjects_count` (không có mảng `courses`/`subjects`) |
| GET | /admin/coupons/{coupon} | `show` | `CouponPolicy@view` | **T15 bổ sung:** Coupon đầy đủ kèm `courses: [{id,title}]`, `subjects: [{id,name}]` (AC8). Phẳng, không bọc `data` |
| POST | /admin/coupons | `store` | `CouponPolicy@create` | `CouponRequest` (quyền kiểm TRƯỚC validate): `code` (bắt buộc; cắt khoảng trắng + chữ hoa trước khi kiểm; 4–50, `^[A-Z0-9_-]+$`; trùng không phân biệt hoa/thường → 422 `errors.code` "Mã giảm giá đã tồn tại."), `name` (≤ 255, văn bản thuần, tuỳ chọn), `discount_type` (`percent`\|`fixed_amount`), `discount_value` (int ≥ 1; percent ≤ 100; fixed ≤ 100.000.000), `max_uses` (null = không giới hạn; 1–1.000.000), `valid_from` (tuỳ chọn, mặc định bây giờ; ISO 8601), `valid_until` (null = không hết hạn; phải ≥ `valid_from`), `course_ids[]`/`subject_ids[]` (tuỳ chọn, ≤ 200, không trùng, tồn tại; khóa đã xoá mềm bị loại; chuyên đề ẩn vẫn chọn được). `is_restricted` do server suy ra = có ít nhất 1 khóa/chuyên đề; để trống cả hai = toàn bộ khóa học. Mã mới luôn `active`, `used_count` 0. **201** Coupon đầy đủ. Mã 100% hoặc fixed ≥ giá khóa rẻ nhất đang bán (xuất bản, chưa xoá, price > 0): thiếu `max_uses`/`valid_until` → 422 trên đúng trường đó (S18); audit `coupon.create` kèm `high_risk` |
| PUT | /admin/coupons/{coupon} | `update` | `CouponPolicy@update` | Thay toàn bộ như POST (gửi đủ trường; `valid_from` null = giữ nguyên; `course_ids`/`subject_ids` thay thế hoàn toàn). Mã **đã dùng** (`used_count` > 0, hoặc có `coupon_usages`/`orders` tham chiếu — T18 tự được tính) mà đổi `code`/`discount_type`/`discount_value` → **422 `COUPON_LOCKED`**, `errors.fields` = danh sách trường bị đổi (gửi lại đúng giá trị cũ thì không lỗi; đổi tên/hạn/max_uses/phạm vi vẫn được). `max_uses` < `used_count` → 422 `errors.max_uses`. 200 Coupon đầy đủ. Khoá dòng `coupons` (FOR UPDATE) khi sửa. Audit `coupon.update` (diff trước/sau; không ghi nếu không đổi gì) |
| POST | /admin/coupons/{coupon}/deactivate · /activate | `deactivate` / `activate` | `CouponPolicy@update` | Idempotent (lần 2 vẫn 200, không ghi audit lại). 200 Coupon đầy đủ. **T15 bổ sung `activate`** (bật lại). Không ảnh hưởng đơn đã tạo. Audit `coupon.deactivate` / `coupon.activate` |
| DELETE | /admin/coupons/{coupon} | `destroy` | `CouponPolicy@delete` | Chỉ khi chưa từng dùng (`used_count` = 0, không có `coupon_usages`/`orders` tham chiếu) → 204 (đã xoá rồi → 404). Ngược lại **409 `COUPON_IN_USE`** (FK là chốt chặn cuối). Audit `coupon.delete` |

**Coupon** (T15 chốt, phẳng): `{ id, code, name, discount_type, discount_value, max_uses, max_uses_per_user (luôn 1), used_count, valid_from, valid_until, status (active|inactive), state (xem GET list), is_restricted, courses_count?, subjects_count?, courses?: [{id,title}], subjects?: [{id,name}], created_by (id), created_at, updated_at }`. Thời điểm ISO 8601. Phạm vi = khóa trong `courses` **hoặc** thuộc ≥ 1 chuyên đề trong `subjects` khi `is_restricted`. Xoá cứng một chuyên đề (chỉ khi chưa gán khóa) gỡ nó khỏi phạm vi mã (cascade; phạm vi chỉ hẹp lại). UI: nút Xoá chỉ hiện khi `used_count` = 0 (vẫn có thể 409). Lỗi: 403 `FORBIDDEN`, 404, 422 `VALIDATION_ERROR` (`errors.<field>`), 422 `COUPON_LOCKED`, 409 `COUPON_IN_USE`.

| GET | /admin/orders | `Admin\OrderController@index` | `OrderPolicy@viewAny` | `OrderFilterRequest`: from, to (**bắt buộc**, ≤ 366 ngày), status[], q, pending_older_than_hours, needs_review, cursor, per_page. **Cursor pagination** (§1.5). `q`: dạng mã đơn → `orders.code`; có `@` → email chính xác; toàn số → SĐT chuẩn hoá; còn lại → `users.name LIKE 'từ%'` (escape). Luôn áp bộ lọc ngày/trạng thái trước (DBA 2.4). **Email/SĐT HS che** (`09****123`, `ng***@gmail.com`) — S14 |
| GET | /admin/orders/{order:code} | `show` | staff | Chi tiết + items + attempts + status logs; email/SĐT HS **đầy đủ**; ghi `audit_logs` `order.view_pii`. Không bao giờ trả thông tin phụ huynh |
| POST | /admin/orders/{order:code}/refund | `Admin\OrderRefundController@store` | `OrderPolicy@refund` | note (tuỳ chọn, ≤ 1000), confirm=true. Lần 2 → 409. Ghi audit |
| POST | /admin/order-exports | `Admin\OrderExportController@store` | `OrderPolicy@export`, throttle:export (10/ngày) | cùng bộ lọc + format (`csv`\|`xlsx`) + `include_contact` (**chỉ admin**, bắt buộc `reason`) → 202 {export_id}. Ghi audit (người, bộ lọc, IP). Cảnh báo log khi > 10.000 dòng |
| GET | /admin/exports/{export} | `Admin\ExportController@show` | chủ export | trạng thái + `download_url` (temporary signed URL 10 phút, sinh đúng host admin-api sau proxy) |
| GET | /admin/exports/{export}/download | `Admin\ExportController@download` | `signed` + staff + chủ export | Tên file `orders-YYYYMMDD-HHmm.csv/xlsx`, `Content-Disposition: attachment`. Ghi audit `export.download` |

#### Tài khoản staff + nhật ký thao tác (T33, US-016) — chỉ admin (Gate `manage-system`; QLT/GV → 403 `FORBIDDEN`, kiểm TRƯỚC validate)

Nhóm `staff`, route `admin.staff.*` / `admin.audit-logs.index`. Học sinh không bao giờ xuất hiện (id học sinh → 404). `{staff}` là id số. Phản hồi đơn là object phẳng (không bọc `data`); danh sách bọc `{data, meta, links}`.

`StaffAccount` = `{id, name, email, role (admin|quan_ly_trang|giao_vien), status (active|locked), must_change_password, last_login_at, password_changed_at, created_at, is_self}` (không bao giờ có mật khẩu/băm).

| Method | Path | Ghi chú |
|---|---|---|
| GET | /admin/staff | Query `q` (≤100, tên/email, escape LIKE), `role`, `status` (active\|locked), `per_page` (25\|50). Sắp `created_at desc, id desc`, length-aware. Sai query → 422 |
| GET | /admin/staff/{staff} | `StaffAccount` |
| POST | /admin/staff | body `{name (1–100, văn bản thuần), email, role}` → 201 `{...StaffAccount, initial_password}`. `initial_password` (20 ký tự ngẫu nhiên) **chỉ trả một lần** (không gửi email, không lưu) — FE hiển thị + nút sao chép + cảnh báo. Email trùng (mọi vai trò, không phân biệt hoa/thường) → 422 `errors.email` "Email đã được sử dụng."; `role=hoc_sinh`/lạ → 422 `errors.role`. Trạng thái `active`, `must_change_password=true`. Throttle 30/phút. Audit `staff.create` `{role}` |
| POST | /admin/staff/{staff}/lock | 200 `StaffAccount`. Phiên của người bị khoá bị từ chối ngay (`ACCOUNT_LOCKED` 403) và **bị huỷ** (sau khi mở khoá vẫn phải đăng nhập lại). Audit `user.lock` `{status:{from,to}}`. Throttle 30/phút |
| POST | /admin/staff/{staff}/unlock | 200 `StaffAccount`. Audit `user.unlock` |
| PATCH | /admin/staff/{staff}/role | body `{role}` (3 vai trò staff) → 200 `StaffAccount`. Huỷ phiên người bị đổi (đăng nhập lại; MFA theo vai trò mới). Audit `staff.role_change` `{role:{from,to}}`. **Sửa lỗi nhỏ 3 (T33-4):** đổi giáo viên sang vai trò khác thì gỡ mọi dòng `course_teacher` của họ (audit thêm `released_course_ids`; **response 200 cũng trả `released_course_ids: [int]`** (rỗng nếu không gỡ khóa nào) để UI cảnh báo "N khóa không còn giáo viên"); đổi về giáo viên phải gán lại khóa thủ công; khóa có thể còn 0 giáo viên cho tới lần gán kế tiếp |
| POST | /admin/staff/{staff}/reset-password | 200 `{...StaffAccount, initial_password}` (mật khẩu mới, hiển thị một lần), `must_change_password=true`, huỷ mọi phiên, mật khẩu cũ hết hiệu lực. Audit `staff.password_reset` (không chứa mật khẩu) |
| GET | /admin/audit-logs | **Chỉ đọc** (không có route sửa/xoá; PUT/PATCH/DELETE → 404/405). Query `action` (khớp đúng), `actor_id`, `subject_type` (vd `App\Models\User`), `subject_id`, `from`/`to` (`YYYY-MM-DD`, bao gồm cả ngày `to`, theo múi giờ app), `per_page` (25\|50\|100). Sắp `created_at desc, id desc`. **simplePaginate**: `meta` không có `total`/`last_page` (bảng lớn), dùng `links.next`. Item: `{id, action, actor_id, actor_role (cli = lệnh server), actor_name, subject_type, subject_id, changes, ip, user_agent, created_at}` |

Mã lỗi thao tác (đều 409, thông điệp tiếng Việt): `CANNOT_MODIFY_SELF` (tự khoá/đổi vai trò/đặt lại mật khẩu chính mình — đổi mật khẩu của mình dùng `PUT /admin/auth/password`), `LAST_ADMIN` (khoá/hạ quyền admin đang hoạt động cuối cùng; áp cả cho `staff:lock`), `ALREADY_PROCESSED` (khoá tài khoản đã khoá, mở khoá tài khoản đang hoạt động, đổi sang đúng vai trò hiện tại — double submit không ghi audit trùng). Phiên bị huỷ (khoá/đổi vai trò/đặt lại mật khẩu) nhận 401 `UNAUTHENTICATED` ở request kế (hoặc 403 `ACCOUNT_LOCKED` nếu đang khoá).

CLI `staff:create|lock|unlock` dùng chung `StaffAccountService` (cùng audit, cùng quy tắc admin cuối cùng, cùng huỷ phiên).

### 2.6 Webhook (host api, nhóm `webhook`)

| Method | URI | Controller | Ghi chú |
|---|---|---|---|
| POST | /webhooks/payments/{gateway} | `Webhook\PaymentWebhookController` | `->whereIn('gateway', enabled_gateways)` (lạ → 404); body ≤ 16 KB; 120/phút/IP; IP allowlist MoMo chỉ bật khi đã xác nhận IP thật qua proxy. ADR-001 |
| POST | /webhooks/video/{provider} | `Webhook\VideoWebhookController` | `->whereIn('provider', ['internal','bunny'])`; không tin payload, gọi lại `getVideo()`**T11 chốt:** thêm `fake` ở local/testing; provider không nằm trong `video.enabled_providers`/chưa có adapter → 404; payload chỉ cấp `provider_video_id`; luôn 204 (asset lạ cũng 204); provider lỗi khi xác minh → 503 `VIDEO_PROVIDER_UNAVAILABLE` (để họ gửi lại); throttle:webhook; trạng thái chỉ tiến (`ready`/`failed` là cuối) |

### 2.7 VideoLab (host `video.vitaminvui.vn`, `routes/videolab.php`, không nhóm `web` — ADR-002 §3a)

| Method | URI | Ghi chú |
|---|---|---|
| POST | /videolab/library/{libraryId}/videos | Header `AccessKey`. **Chỉ mạng nội bộ** (Nginx allow IP app server) |
| GET / DELETE | /videolab/library/{libraryId}/videos/{guid} | Như trên |
| POST / HEAD / PATCH | /videolab/tus[/{guid}] | Công khai; TUS subset; chữ ký HMAC, TTL ≤ 6h, `max_bytes`, 1 upload/guid; CORS riêng |
| GET | /videolab/cdn/{token}/{expires}/{guid}/{path} | Công khai; `guid` = `[0-9a-f-]{36}`, `path` = `playlist\.m3u8\|[0-9]{3,4}p/[A-Za-z0-9_]{1,40}\.(m3u8\|ts)`; kiểm `realpath` |

**T12 chốt (VideoLab):**
- Host `VIDEOLAB_HOST` (local `video.localhost`), route chỉ đăng ký khi `VIDEOLAB_ENABLED` (mặc định bật khi `VIDEO_PROVIDER=internal`). Lỗi luôn JSON.
- Quản lý (`AccessKey` = `VIDEOLAB_API_KEY`, 401 nếu sai; `{libraryId}` phải = `video.library_id` → 404): `POST …/videos {title, max_bytes?}` → **201** `{guid, videoLibraryId, title, status 0–6, length, uploadStarted, uploadOffset, error}` (`max_bytes` bị kẹp ≤ `video.max_upload_mb`); `GET …/{guid}` 200/404; `DELETE …/{guid}` 204/404 (xoá cả file). Trạng thái: 0 created, 1 uploaded, 2 processing, 3 transcoding, 4 finished, 5 error, 6 upload_failed.
- TUS (`Tus-Resumable: 1.0.0` bắt buộc → 412 kèm `Tus-Version`; extension `creation`; không hỗ trợ `Upload-Defer-Length` → 400). Mọi request gửi 4 header chữ ký (`AuthorizationSignature = hex(HMAC_SHA256(LibraryId . AuthorizationExpire . VideoId, VIDEOLAB_API_KEY))`, `AuthorizationExpire`, `VideoId`, `LibraryId`); chữ ký sai/hết hạn/phiên hết hạn/`VideoId` ≠ `{guid}` → **403**. `OPTIONS /videolab/tus` → 204 + `Tus-Extension`, `Tus-Max-Size`.
  - `POST /videolab/tus` + `Upload-Length` (1..max_bytes; vượt → **413**) → 201 + `Location` (thân rỗng, `Content-Type: text/plain`); chỉ khi video ở status 0 và chưa có phiên (1 upload/guid; ngược lại → **403**).
  - `HEAD /videolab/tus/{guid}` → `Upload-Offset`, `Upload-Length`, `Cache-Control: no-store` (chưa tạo phiên → 404).
  - `PATCH /videolab/tus/{guid}` (`Content-Type: application/offset+octet-stream`, sai → 415; `Upload-Offset` lệch → 409; video đã xong/đang xử lý → 403) → 204 + `Upload-Offset`. **Mỗi PATCH ≤ `VIDEOLAB_CHUNK_MAX_MB` (8 MB) → FE đặt `chunkSize` của tus-js-client ≤ 8 MB** (quá → 413; vượt `Upload-Length` → 413 và file khôi phục về offset cũ). Đủ dung lượng: kiểm magic bytes (MP4/MOV `ftyp`, Matroska/WebM `1A45DFA3`); sai → **422 `VIDEO_INVALID`** (envelope §1.7) + status 6 + webhook; đúng → status 1 + job `TranscodeVideoJob`.
  - CORS: preflight chỉ `ADMIN_URL` (ngoài danh sách → 403), không credentials, expose `Location, Upload-Offset, Tus-Resumable…`.
- CDN: token `base64url(HMAC_SHA256(VIDEOLAB_TOKEN_KEY, "/{guid}/" . expires . ip))` (43 ký tự; `ip` chỉ có khi `PlaybackContext::ip` ≠ null; server chấp nhận token có IP khớp IP kết nối HOẶC token không IP). Sai/hết hạn → 403; video chưa `finished`/file không tồn tại/`realpath` ra ngoài `hls/{guid}/` → 404. `Content-Type` m3u8 = `application/vnd.apple.mpegurl`, ts = `video/mp2t`; `Cache-Control: private, max-age≤300`. CORS: `FRONTEND_URL` + `ADMIN_URL`. `VIDEOLAB_ACCEL_REDIRECT=true` → `X-Accel-Redirect: /_protected_hls/{guid}/{path}`. Master `playlist.m3u8` tham chiếu `{360|720}p/index.m3u8` (tương đối nên dùng chung token).
- Webhook Bunny Stream → `POST /api/v1/webhooks/video/bunny?k=<BUNNY_WEBHOOK_TOKEN>` (US-021; sai/thiếu `k` → 404, không gọi mạng; asset đã `ready`/`failed` không gọi Bunny; webhook trùng cho cùng asset trong 10 giây chỉ xử lý một lần): body JSON `{VideoLibraryId, VideoGuid, Status}`, KHÔNG có chữ ký. App chỉ đọc `VideoGuid` (UUID hợp lệ) rồi gọi lại API Bunny có `AccessKey` để lấy trạng thái/thời lượng thật; payload sai định dạng hoặc `VideoGuid` lạ vẫn trả 204, không đổi dữ liệu; Bunny lỗi khi xác minh → 503 để Bunny gửi lại. Nếu `bunny` chưa bật/chưa đủ `BUNNY_*` → 404.
- Webhook VideoLab → `POST /api/v1/webhooks/video/internal`: body `{VideoLibraryId, VideoGuid, Status}` + header `X-VideoLab-Signature = hex(HMAC_SHA256(body, VIDEOLAB_WEBHOOK_SECRET))`; sai/thiếu chữ ký → bị bỏ qua (vẫn 204). Nghiệp vụ luôn gọi lại `getVideo()`.

### 2.8 Dữ liệu cá nhân & đồng ý (US-017, US-018 — **chờ BA viết story, chờ pháp chế**; hợp đồng dự kiến)

| Method | URI | Host | Ghi chú |
|---|---|---|---|
| GET | /parent-consents/{token} | api (public) | Token 1 lần (signed, 72h) gửi email phụ huynh; trả thông tin tối thiểu (tên HS che bớt, nội dung đồng ý) |
| POST | /parent-consents/{token} | api (public, throttle) | Phụ huynh xác nhận/từ chối → `consents` (`granted_by=parent`), cập nhật `parent_consent_status` |
| POST | /me/parent-consent/resend | api (student) | Gửi lại email phụ huynh (giới hạn 3/ngày) |
| GET | /me/consents · POST /me/consents/{type}/revoke | api (student) | Xem/rút lại đồng ý |
| GET | /me/data-export | api (student) | Xuất dữ liệu cá nhân của chính mình (JSON) |
| POST | /me/account/delete | api (student) | Yêu cầu xoá tài khoản → xác nhận OTP → ẩn danh hoá (`anonymized_at`), giữ đơn hàng |

### 2.9 Hồ sơ giáo viên công khai (US-020, task T36 — thiết kế 2026-10-06, PO đã duyệt)

Thiết kế: `docs/tech/US-020.md`, ADR-005. Dữ liệu: bảng `teacher_profiles` (data-model §3.1).

**Quy tắc chung:**
- **Chốt đồng ý (BR5):** ảnh và bio giáo viên chỉ đi ra API công khai qua `App\Support\PublicTeacher`. Điều kiện: `role = giao_vien` và `teacher_profiles.public_consent_at` khác null. Không thoả thì `avatar_url = null`, `bio = null` (và `headline = null` nếu API có trường này); `id`, `name` vẫn trả. Áp cho `GET /courses/{slug}`, `GET /home/teachers` và mọi API công khai về giáo viên sau này.
- **Cache:** Laravel không cache (không `Cache::remember`), nên API phản ánh ngay khi giáo viên rút đồng ý. Header như `/courses`: `Cache-Control: public, max-age=60`, `ETag`, `Vary: Origin`, không cookie. Độ trễ ≤ 60 s phía web do Next Data Cache `revalidate: 60` (FW9).
- **Văn bản:**
  - `headline` là văn bản thuần 1 dòng, ≤ 120 ký tự;
  - `bio` là văn bản thuần nhiều dòng (`\n`), ≤ 600 ký tự sau khi chuẩn hoá `\r\n` → `\n` và trim;
  - cả hai: chứa `<`, `>`, thẻ HTML, ký tự điều khiển (trừ `\n` trong bio), bidi hoặc zero-width → 422 `VALIDATION_ERROR`; chuỗi rỗng → lưu `null`;
  - FE render bằng text của React (`white-space: pre-line`), **cấm `dangerouslySetInnerHTML`**.
- **Ảnh (`avatar`):**
  - rule như §4 (jpg/png/webp, ≤ 2 MB, ≤ 4000×4000; SVG/GIF/HTML/polyglot → 422);
  - backend cắt giữa thành hình vuông, thu nhỏ còn ≤ 800 px (không phóng to), mã hoá lại WebP, bỏ EXIF, đặt tên UUID;
  - upload bằng `POST` multipart; thay hoặc xoá ảnh thì file cũ bị xoá sau commit;
  - alt do FE dựng: "Ảnh thầy/cô {name}".

#### Công khai (host api)

`GET /api/v1/home/teachers`: nhóm catalog, `throttle:catalog`. Không tham số. Trả `{data}`, không phân trang, tối đa 6 người.

```json
{
  "data": [
    {
      "id": 12,
      "name": "Nguyễn Thị Lan",
      "headline": "Giáo viên Toán THPT chuyên",
      "bio": "10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh 2025.",
      "avatar_url": "https://static.vitaminvui-media.net/3f2b...c1.webp",
      "grade_levels": [9, 10],
      "courses_count": 3
    }
  ]
}
```
- Điều kiện hiện (BR2), phải thoả đồng thời:
  - user: `role = giao_vien`, `status = active`, chưa ẩn danh hoá;
  - hồ sơ: `public_consent_at` khác null, `show_on_homepage = true`, có `avatar_path`, có `bio`;
  - có ít nhất 1 khóa `published` chưa xoá trong `course_teacher`.
- Thứ tự: `homepage_order` tăng dần, người chưa có thứ tự xếp sau, cùng thứ tự thì theo `id` tăng dần (BR3).
- `headline` có thể `null`. `avatar_url`, `bio` luôn khác null ở endpoint này.
- `grade_levels` là các lớp khác nhau của khóa `published`, sắp tăng dần. `courses_count` đếm mỗi khóa `published` một lần.
- Không có ai đủ điều kiện → `{"data": []}` (200). Lỗi 429 `TOO_MANY_ATTEMPTS`. Response lỗi không có `public`.
- Không trả email, SĐT, trạng thái hay cờ nội bộ (BR13).

**T36 chốt khi hiện thực:**
- `PlainText` (mọi trường văn bản thuần của dự án) nay chặn thêm U+2028/U+2029 (trình duyệt vẽ thành xuống dòng); `bio` dùng `PlainText(allowNewlines: true)` chỉ cho `\n`.
- `GET /admin/me/teacher-profile` và `GET /admin/teacher-profiles/{user}` không tạo dòng hồ sơ; dòng được tạo lười ở lần ghi đầu tiên (nội dung, ảnh, đồng ý, bật trang chủ, đặt thứ tự). Tắt hiển thị cho người chưa có dòng là no-op.
- `consent.withdrawn_at` chỉ có giá trị khi `consent.given = false`.
- Danh sách `GET /admin/teacher-profiles` trả `abilities` không có khoá `consent`.
- **`NOT_TEACHER` được ưu tiên.** `PATCH .../homepage` với `show_on_homepage:true` cho người không còn là `giao_vien` luôn 422 `NOT_TEACHER`, kể cả khi cờ đang bật (không đổi giá trị): quy tắc "giá trị không đổi → 200" không áp cho trường hợp này. FE chỉ gửi `homepage_order` (hoặc `show_on_homepage:false`) cho người không còn là giáo viên.
- **Đồng ý lại thì thêm một dòng `consents` mới.** Giáo viên đang đồng ý bản cũ mà POST `consent` đúng `current_version` mới: cập nhật phiên bản ở `teacher_profiles` và INSERT thêm một dòng `consents`; dòng cũ giữ nguyên (`revoked_at` NULL, cùng hiệu lực) tới khi rút (rút thu hồi mọi dòng).
- **Rút đồng ý vô hiệu URL ảnh cũ (M1).** Rút đồng ý: ảnh được sao chép sang tên UUID mới, `avatar_path` đổi, file cũ bị xoá sau commit; URL cũ trả 404 ở miền tĩnh. Giáo viên vẫn thấy ảnh ở "Hồ sơ của tôi" (URL mới); đồng ý lại thì công khai URL mới. Không sao chép được ảnh thì vẫn rút đồng ý (log lỗi, giữ đường dẫn cũ). Miền tĩnh gửi `X-Robots-Tag: noindex, noimageindex`.
- **Người đã đổi vai trò nhưng còn hồ sơ (L1)** tự gỡ được: `DELETE /admin/me/teacher-profile/consent` và `DELETE /admin/me/teacher-profile/avatar` dùng Gate `withdraw-own-teacher-profile` (giáo viên, hoặc user có dòng `teacher_profiles`). Các route `/me` còn lại (GET, PATCH, POST avatar, POST consent) vẫn chỉ cho giáo viên (403 với người khác).
- **Email báo khi Admin/QLT sửa hộ.** Admin/QLT sửa headline, bio hoặc ảnh (thay/xoá) của giáo viên ĐANG đồng ý công khai thì giáo viên nhận email (queue, `ShouldBeEncrypted`) nêu tên người sửa, thời điểm và TÊN các trường đã đổi, không chứa nội dung. Không gửi khi giáo viên tự sửa, chưa đồng ý/đã rút, không đổi gì, hoặc không có email. Lỗi gửi không làm hỏng thao tác.
- **`PlainText` chặn theo lớp ký tự (L2):** `\p{Cc}`, `\p{Cf}` (trừ ZWJ ghép emoji), U+2028/2029, filler (U+3164, U+115F/1160, U+FFA0, U+2800, U+17B4/17B5, U+034F), bộ chọn biến thể lạc (chỉ hợp lệ sau emoji hoặc trong keycap), quá 3 dấu kết hợp liên tiếp. `bio`: tối đa 2 dòng trống liên tiếp (chuẩn hoá `\n{4,}` → `\n\n\n`), cắt khoảng trắng Unicode ở hai đầu và cuối dòng. "Có bio" (điều kiện trang chủ, `no_bio`) tính trên nội dung sau khi bỏ khoảng trắng và ký tự ẩn.
- Lỗi 409 `CONSENT_VERSION_CHANGED` có `context.current_version`; 409 `TEACHER_HOMEPAGE_LIMIT` có `context.max`.

`GET /courses?teacher_id=` và `GET /courses/{slug}`: xem §2.1 (bổ sung T36).

#### Quản trị (host admin-api, nhóm `staff`)

**`TeacherProfile`** (object phẳng, dùng chung cho `/admin/me/teacher-profile` và `/admin/teacher-profiles/{user}`):
```json
{
  "user": { "id": 12, "name": "Nguyễn Thị Lan", "role": "giao_vien", "status": "active" },
  "headline": "Giáo viên Toán THPT chuyên",
  "bio": "10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh 2025.",
  "avatar_url": "https://static.vitaminvui-media.net/3f2b...c1.webp",
  "consent": {
    "given": true,
    "given_at": "2026-10-06T09:15:00+07:00",
    "version": "2026-10",
    "withdrawn_at": null,
    "current_version": "2026-10",
    "current_text": "Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui"
  },
  "show_on_homepage": true,
  "homepage_order": 2,
  "homepage_status": { "visible": false, "reasons": ["no_published_course"] },
  "published_courses_count": 0,
  "last_edited_by": { "id": 1, "name": "Trần Quản Trị", "is_self": false },
  "last_edited_at": "2026-10-06T10:00:00+07:00",
  "updated_at": "2026-10-06T10:00:00+07:00",
  "abilities": { "edit_content": true, "consent": false, "manage_homepage": true }
}
```
- `homepage_status.reasons` lấy từ tập sau, theo đúng thứ tự này:
  - `not_teacher`, `account_locked`, `not_enabled`, `no_consent`, `no_avatar`, `no_bio`, `no_published_course`.
  - `visible = reasons` rỗng (vì số người được bật ≤ 6 nên mọi người đủ điều kiện đều hiện).
  - FE dịch sang tiếng Việt, ví dụ "Chưa hiện: chưa đồng ý công khai".
- `last_edited_by`/`last_edited_at` là lần sửa **nội dung** gần nhất (ảnh/headline/bio) của bất kỳ ai, `null` nếu chưa có (BR6).
- `abilities` chỉ để ẩn/hiện UI:
  - giáo viên ở `/me`: `{edit_content: true, consent: true, manage_homepage: false}`;
  - Admin/QLT: `{edit_content: <là giao_vien>, consent: false, manage_homepage: true}`.
- Giáo viên chưa có dòng hồ sơ vẫn nhận object đủ khoá, giá trị rỗng/false/null.

**Giáo viên: "Hồ sơ của tôi".** Gate `own-teacher-profile`: chỉ `giao_vien`. Admin/QLT gọi các route này → 403 `FORBIDDEN` (BR12, AC8). Quyền được kiểm trước validate.

| Method | URI | Request | Response / lỗi |
|---|---|---|---|
| GET | /admin/me/teacher-profile | — | 200 `TeacherProfile` |
| PATCH | /admin/me/teacher-profile | JSON `{headline?: string\|null, bio?: string\|null}`: **chỉ gửi trường đã đổi** (AC21, không báo xung đột; trường không gửi giữ nguyên). Không có trường nào → 422 | 200 `TeacherProfile`. 422 `VALIDATION_ERROR` (`errors.headline`/`errors.bio`). Throttle 30/phút/user. Audit `teacher_profile.update` khi có thay đổi |
| POST | /admin/me/teacher-profile/avatar | multipart `avatar` | 200 `TeacherProfile` (`avatar_url` mới). 422 `errors.avatar` (định dạng, dung lượng, kích thước, không giải mã được). Throttle 10/phút/user. Audit `teacher_profile.update` `{fields:['avatar'], avatar:'changed'}` |
| DELETE | /admin/me/teacher-profile/avatar | — | 200 `TeacherProfile` (`avatar_url: null`). Không có ảnh vẫn 200, không audit |
| POST | /admin/me/teacher-profile/consent | `{version: "2026-10"}` (= `consent.current_version` vừa hiển thị) | 200 `TeacherProfile`. Khác phiên bản hiện hành → 409 `CONSENT_VERSION_CHANGED`. Đã đồng ý đúng phiên bản → 200, không ghi gì. Ghi `consents` (type `teacher_public_profile`) + audit `teacher_profile.consent`. Throttle 30/phút |
| DELETE | /admin/me/teacher-profile/consent | — | 200 `TeacherProfile` (`consent.given=false`, `withdrawn_at`). Chưa đồng ý vẫn 200, không ghi gì. Nội dung hồ sơ giữ nguyên. Audit `teacher_profile.consent_withdraw` |

**Admin/QLT: quản lý hồ sơ giáo viên.** Gate `manage-teacher-profiles`: admin, quản lý trang. Giáo viên → 403 `FORBIDDEN`, kiểm trước validate (AC18).

`{user}` là id số và phải là user `giao_vien` **hoặc** user còn dòng `teacher_profiles` (đã đổi vai trò nhưng còn hồ sơ: BR9 giữ cờ trang chủ, và để Admin/QLT xoá ảnh hộ, L1). Mọi trường hợp khác → 404 `NOT_FOUND`.

| Method | URI | Request | Response / lỗi |
|---|---|---|---|
| GET | /admin/teacher-profiles | `q` (≤ 100, theo tên, escape LIKE), `homepage` (`1` = chỉ người đang bật), `per_page` (25\|50), `page`. Sai → 422 | `{data:[TeacherProfile không có abilities.consent], meta:{current_page,per_page,total,last_page, homepage:{enabled_count, max}}, links}`. Gồm mọi `giao_vien` (kể cả bị khoá) và người đã đổi vai trò còn bật cờ. Sắp: đang bật trước (`homepage_order` tăng dần, null sau), rồi `name`, `id`. Đếm khóa bằng 1 câu GROUP BY (không N+1) |
| GET | /admin/teacher-profiles/{user} | — | 200 `TeacherProfile` |
| PATCH | /admin/teacher-profiles/{user} | như PATCH của giáo viên | 200. User không còn `giao_vien` → 422 `NOT_TEACHER`. Audit `teacher_profile.update` có `on_behalf: true`. Nội dung có hiệu lực ngay nếu giáo viên đã đồng ý (BR6) |
| POST / DELETE | /admin/teacher-profiles/{user}/avatar | như của giáo viên | như trên, `NOT_TEACHER` khi POST cho người không còn là giáo viên |
| PATCH | /admin/teacher-profiles/{user}/homepage | `{show_on_homepage?: bool, homepage_order?: int 1..999 \| null}`, có ít nhất 1 trường | 200 `TeacherProfile`. Được bật khi chưa đủ điều kiện (AC9, xem `homepage_status`). Bật người thứ 7 → **409 `TEACHER_HOMEPAGE_LIMIT`** "Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước.", không đổi gì (kể cả `homepage_order` gửi kèm). Bật cho user không còn `giao_vien` → 422 `NOT_TEACHER` (tắt và đổi thứ tự vẫn được). Giá trị không đổi → 200, không audit. Audit `teacher_profile.homepage_toggle` / `teacher_profile.homepage_order`. Throttle 30/phút/user |

- **Không có route đồng ý thay người khác.** `/admin/teacher-profiles/{user}/consent` không tồn tại, gọi vào nhận 404/405.
- **Giới hạn 6:** đếm mọi dòng `show_on_homepage = true`, kể cả người bị khoá hoặc đã đổi vai trò (BR9 giữ cờ). Màn quản trị phải hiện những người này để admin tắt được.
- **Khoá khi bật:** mutex khoá mọi dòng `teacher_profiles` theo `user_id` tăng dần (ADR-005), nên hai admin bật cùng lúc vẫn không vượt quá 6.

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
- **Ảnh đại diện giáo viên (US-020, T36):** cùng rule kiểm (field `avatar`), nhưng cắt giữa thành hình vuông và thu nhỏ ≤ 800px (không phóng to). File không còn được tham chiếu, cũ hơn 24h, do `images:prune-orphans` dọn hằng ngày. Production/staging: `STATIC_URL` bắt buộc https và khác host của `APP_URL`/`FRONTEND_URL`/`ADMIN_URL` (`ProductionConfigGuard`).
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
