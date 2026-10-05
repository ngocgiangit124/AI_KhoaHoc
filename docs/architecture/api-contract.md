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

IP thật lấy qua `TrustProxies` với danh sách IP cụ thể (không `*`).

### 1.7 Lỗi thống nhất
```json
{ "message": "Thông điệp tiếng Việt cho người dùng", "code": "SESSION_REPLACED", "errors": { "field": ["..."] }, "request_id": "..." }
```
Lỗi nghiệp vụ ném `App\Exceptions\DomainException($code, $message, $status, $context)`, render trong `bootstrap/app.php`.

| HTTP | code | Khi nào |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Chưa đăng nhập / phiên hết hạn |
| 401 | `SESSION_REPLACED` | HS bị đăng xuất vì đăng nhập thiết bị khác (ADR-003) |
| 401 | `SESSION_EXPIRED` | Phiên cũ trên cùng thiết bị (bấm đăng nhập 2 lần) |
| 401 | `SESSION_REVOKED` | Đã đổi/đặt lại mật khẩu |
| 401 | `STAFF_IDLE_TIMEOUT` | Phiên quản trị quá 120 phút không hoạt động hoặc quá 12 giờ |
| 403 | `ACCOUNT_LOCKED` | Tài khoản bị khoá — **chỉ trả khi mật khẩu đúng** (S20) |
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
| GET | /subjects | `Catalog\SubjectController@index` | public | — | Chuyên đề `active` |
| GET | /courses | `Catalog\CourseController@index` | public, throttle:catalog | `CourseSearchRequest`: grade (6–12), subject_ids[] (≤ 20), q (≤ 100, escape LIKE — S24), sort (`newest`\|`popular`\|`featured`), page | 25 khóa `published` |
| GET | /courses/{course:slug} | `Catalog\CourseController@show` | public | — | Chi tiết công khai + outline (tên bài, thời lượng, `is_preview`) + GV + `enrollments_count`. **Không chứa URL/ID video**. Khóa `unpublished`/xoá → 404 |
| GET | /courses/{course:slug}/viewer-state | `Catalog\CourseController@viewerState` | student (không cần verified) | — | `viewer_state` (`can_buy`\|`in_cart`\|`can_register_free`\|`pending_approval`\|`owned`) + `resume_lesson_id`. Tách khỏi `show` để `show` cache được (S16) |
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
| POST | /auth/register | `Auth\RegisterController` | guest, throttle:register | `RegisterRequest`: name (≤150), date_of_birth, email, phone (VN), grade_level (6–12), password (min 8, confirmed), parent_phone/parent_email (≥1 khi dưới `privacy.parent_consent_age`), referral_code (chỉ khi flag bật), **accept_terms (accepted)**, **accept_privacy (accepted)**, **captcha_token**, device_id. `role`/`status`/`*_verified_at` **không nằm trong validated()** (S17) | 201 user; tự đăng nhập + bind phiên; tạo `consents` (self); gửi OTP; nếu dưới ngưỡng tuổi → `parent_consent_status=pending` + gửi email xác nhận cho phụ huynh (US-017) |
| POST | /auth/login | `Auth\LoginController@store` | guest, throttle:login | `LoginRequest`: login (email hoặc SĐT), password, device_id | 200 user. Sai → 422 thông điệp chung. Khoá → 403 `ACCOUNT_LOCKED` **chỉ khi mật khẩu đúng**. Vai trò không phải `hoc_sinh` → 403 `WRONG_PORTAL` (sau khi mật khẩu đúng) |
| POST | /auth/logout | `Auth\LoginController@destroy` | auth:sanctum | — | 204 |
| GET | /auth/me | `Auth\MeController` | student | — | **T04:** đúng shape `user` ở khối "Bổ sung từ T03" (phẳng, không `parent_*`). `cart_count` thêm ở T16, thông tin phụ huynh **đã che** thêm ở T29 (thêm field = tương thích) |
| POST | /auth/otp/send | `Auth\OtpController@send` | student, throttle:otp-send | `SendOtpRequest`: channel ∈ `config('auth.otp.channels')` (production MVP: chỉ `email`) | 202 `{ resend_available_at }` (ISO 8601 có offset). Mã chỉ gửi tới email/SĐT **hiện tại** của tài khoản, chưa xác thực; đã xác thực → 422 field `channel` |
| POST | /auth/otp/verify | `Auth\OtpController@verify` | student, throttle:otp-verify | `VerifyOtpRequest`: code (6 số) | 200 user phẳng (`is_verified=true`). Tăng `attempts` nguyên tử trước khi so (data-model §3.1). Sai → 422 `VALIDATION_ERROR` field `code`; hết hạn/không có mã → 422 field `code` (thông điệp hết hạn); hết 5 lượt của mã → 429 `TOO_MANY_ATTEMPTS` (phải gửi mã mới); vượt throttle → 429 + `Retry-After` |
| PUT | /auth/contact | `Auth\ContactController@update` | student | email/phone mới | 200 `{ resend_available_at: string\|null }`. Huỷ MỌI OTP cũ, reset `*_verified_at` tương ứng, gửi OTP mới (S9). Request: `email` và/hoặc `phone` (≥ 1; unique; chuẩn hoá như đăng ký). Không đổi gì → 200 `null`; chỉ đổi SĐT khi kênh `sms` tắt (production) → reset `phone_verified_at`, không gửi mã, `null`. Không áp cooldown 60s (sửa nhầm email sau đăng ký) nhưng vẫn áp trần 5/giờ, 10/ngày (vượt → 429 và không đổi gì); limiter `contact` 10/giờ/user |
| POST | /auth/password/forgot | `Auth\PasswordResetController@request` | guest, throttle:password-reset | login, captcha_token | 202 **luôn cùng thông điệp** dù tài khoản có tồn tại hay không. **US-015 — chờ BA viết story** |
| POST | /auth/password/reset | `Auth\PasswordResetController@reset` | guest, throttle:otp-verify | login, code, password (confirmed) | 200; huỷ mọi phiên (tombstone `password_changed`) |
| PUT | /auth/password | `Auth\PasswordController@update` | student | current_password, password (confirmed) | 200; huỷ phiên khác, bind lại phiên hiện tại (ADR-003) |

**Bổ sung từ T03 (2026-10-05):**
- `user` trong response register/login (phẳng): `id, name, email, phone, role, grade_level, is_verified (= email_verified_at != null), parent_consent_status`. Không có `parent_*` (chỉ `/auth/me` trả bản đã che).
- `password`: min 8, **max 128**; lỗi xác nhận không khớp nằm ở field `password_confirmation` (rule `same:password`, bắt buộc). Email (và `parent_email`) dùng `email:rfc,strict` + chặn khoảng trắng/comment RFC. Số điện thoại chấp nhận `+84`/`84`/dấu cách, lưu dạng `0xxxxxxxxx`; email lưu lowercase.
- Đã đăng nhập mà gọi register/login (route `guest`) → **403 `FORBIDDEN`**.
- Captcha kiểm **trước** mọi rule khác (kể cả unique); thiếu Origin hợp lệ → 400 `ORIGIN_NOT_ALLOWED` trước khi gọi captcha. Token Turnstile dùng 1 lần nên frontend phải reset widget sau mọi lỗi 422/`CAPTCHA_FAILED`.
- Login: bộ đếm "sai 10/giờ/`login` + 50/giờ/IP" chỉ tăng ở lượt SAI, xoá bộ đếm tài khoản khi đúng; `throttle:login` ở route chỉ là chống flood (120/phút/IP). Vượt → 429 `TOO_MANY_ATTEMPTS` + `Retry-After`.
- Response register/login có `Cache-Control: no-store, private`.

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
| POST | /courses/{course}/free-enrollments | `Enrollment\FreeEnrollmentController@store` | role:hoc_sinh, account.verified, parent.consent · `EnrollmentPolicy@requestFree` | — | 201 `pending_approval` · 409 |
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

**Nội dung & danh mục:**

| Method | URI | Controller@action | Policy | Request / ghi chú |
|---|---|---|---|---|
| GET | /admin/subjects | `Admin\SubjectController@index` | `SubjectPolicy@viewAny` | GV chỉ nhận `active` |
| POST/PUT/DELETE | /admin/subjects[/{subject}] | | staff | `SubjectRequest`: name (trim, 1–100, văn bản thuần). Xoá khi đang gán → 409 |
| PATCH | /admin/subjects/{subject}/status | | staff | |
| GET | /admin/teachers | `Admin\TeacherController@index` | staff | Chỉ `id`, `name` (không email/SĐT) |
| GET | /admin/courses | `Admin\CourseController@index` | `CoursePolicy@viewAny` | Scope `visibleTo`; q escape LIKE |
| POST | /admin/courses | `store` | `CoursePolicy@create` | `StoreCourseRequest`: title, grade_level, subject_ids[] (exists active), short_description, description (HTML → Purifier §4), price (int 0..50.000.000), thumbnail (§4 quy tắc ảnh), teacher_ids[] (**chỉ staff**; GV gửi thì bị bỏ qua, tự thêm chính mình) |
| GET/PUT | /admin/courses/{course} | `show`/`update` | `CoursePolicy@view`/`update` | `UpdateCourseRequest` với 2 bộ rule: staff / GV (GV **không có** `price`, `grade_level` khi khóa đã từng publish, không bao giờ có `status`, `manual_order`). Đổi giá → `audit_logs` |
| DELETE | /admin/courses/{course} | `destroy` | `CoursePolicy@delete` | Có enrollment → 409. Ghi audit |
| POST | /admin/courses/{course}/publish · /unpublish | `CoursePublicationController` | `CoursePolicy@publish` | Ghi audit |
| PATCH | /admin/courses/{course}/manual-order | | staff | int \| null |
| PUT | /admin/courses/{course}/teachers | `CourseTeacherController@update` | `manageTeachers` | teacher_ids[] (role giao_vien, ≥1). Ghi audit |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters[/{chapter}] | `ChapterController` | `manageContent` (theo `$chapter->course`) | |
| PUT | /admin/courses/{course}/curriculum/order | `CurriculumOrderController` | `manageContent` | `[{chapter_id, lesson_ids[]}]`: mọi `chapter_id`/`lesson_id` thuộc `$course` và chưa xoá; **tập ID gửi lên phải bằng đúng tập hiện có** (không thêm, không thiếu); 1 transaction. Sai → 422 (S5) |
| (scopeBindings) POST/PUT/DELETE | /admin/courses/{course}/chapters/{chapter}/lessons[/{lesson}] | `LessonController` | `manageContent` (theo `$lesson->course`) | `LessonRequest`: title, is_preview, video_source (`none`\|`upload`\|`external_link`), external_url (chỉ khi `is_preview` — mặc định; parse ra provider + ID, host whitelist), duration_seconds. **Không nhận** `video_asset_id`, `course_id`, `chapter_id` của khóa khác |
| POST | /admin/courses/{course}/lessons/{lesson}/video-uploads | `LessonVideoUploadController@store` | `manageContent` | filename (hiển thị), size (≤ `max_upload_mb`), kiểm hạn mức 20 GB/ngày → {video_asset_id, upload: {tus_endpoint, headers, expires_at ≤ 6h}} |
| GET | /admin/courses/{course}/lessons/{lesson}/video | | `manageContent` | Trạng thái xử lý video |
| GET | /admin/courses/{course}/lessons/{lesson}/playback | `Learn\PlaybackController@admin` | `LessonPolicy@watch` | |
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes[/{quiz}] | `Admin\QuizController` | `manageContent` (theo `$quiz->course`) | title, lesson_id **hoặc** chapter_id (exists trong `$course`), time_limit_minutes (null/1–300; bỏ qua khi flag tắt). `course_id` suy ra, không từ request |
| (scopeBindings) CRUD | /admin/courses/{course}/quizzes/{quiz}/questions[/{question}] | `Admin\QuizQuestionController` | `manageContent` | content/explanation văn bản thuần ≤ 5.000, đúng 4 options (≤ 1.000) và đúng 1 đáp án đúng, ≤ 200 câu/quiz. Copy-on-write |
| GET | /admin/enrollment-requests | `Admin\EnrollmentRequestController@index` | `EnrollmentPolicy@viewAnyRequests` | course_id?; GV chỉ khóa mình; sắp `requested_at asc`. Tên HS hiển thị, email/SĐT **che** |
| POST | /admin/enrollment-requests/{enrollment}/approve · /reject | | `EnrollmentPolicy@decide` (theo `$enrollment->course`) | reason ≤ 1000 văn bản thuần. Lần 2 → 409 |

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
