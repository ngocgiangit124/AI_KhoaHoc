# ADR-003: Giới hạn 1 thiết bị/1 phiên cho Học Sinh bằng "phiên hiện hành" lưu trên `users` + huỷ phiên cũ + tombstone

**Trạng thái:** Accepted (đã sửa theo Security S11) · **Cập nhật:** 2026-09-25
**Story:** US-014, US-001 (đăng nhập), US-006 (AC7), US-015 (đổi/đặt lại mật khẩu) · **Phụ thuộc:** ADR-004 (Sanctum SPA cookie, host api riêng cho học sinh)

## Bối cảnh

- Mỗi tài khoản **Học Sinh** chỉ có 1 phiên hợp lệ. Đăng nhập nơi mới thì phiên cũ mất hiệu lực ngay (BR1, BR2). Giáo Viên / Quản lý trang / Admin **không** bị giới hạn (BR4).
- Thiết bị cũ phải nhận thông báo **có lý do** ("Tài khoản của bạn đã đăng nhập ở thiết bị khác") ở thao tác tiếp theo (BR3, AC2).
- Mất mạng tạm thời không được làm mất phiên (AC5). Đăng nhập bấm 2 lần không được gây vòng lặp đăng xuất. Lỗi khi ghi phiên mới không được để 2 phiên cùng hợp lệ.
- Security S11 chỉ ra 3 lỗi của bản trước:
  - (a) Nhánh "cột NULL thì nhận lại phiên" khiến phiên cũ ở máy công cộng **sống lại** sau khi chủ tài khoản đăng xuất ở máy khác.
  - (b) Không huỷ phiên khi đổi mật khẩu hoặc khoá tài khoản.
  - (c) Phiên staff 7 ngày không giới hạn idle (đã xử lý ở ADR-004 §2.2).

## Các phương án

| Phương án | Ưu | Nhược |
|---|---|---|
| A. `Auth::logoutOtherDevices()` + `AuthenticateSession` | Có sẵn | Rehash mật khẩu mỗi lần đăng nhập; không biết lý do để báo; áp cho cả guard |
| B. Chỉ xoá session cũ trong store | Phiên cũ chết thật | Mất lý do để báo `SESSION_REPLACED` |
| C. Sanctum token, thu hồi token cũ | Hợp mobile | Cần BFF; trái ADR-004 |
| **D. `users.current_session_id` + middleware + huỷ session cũ trong store + tombstone lý do trong cache** | Phiên cũ chết thật (không thể sống lại); vẫn báo đúng lý do; chỉ áp cho học sinh; chạy với Redis/database | Thêm 1 khoá cache ngắn hạn cho mỗi lần thay phiên |

## Quyết định

Chọn **D**.

### Dữ liệu
- `users.current_session_id` varchar(255) null — giá trị: id session hiện hành, hoặc `logged_out` sau khi đăng xuất / bị huỷ phiên. **Không bao giờ quay về NULL** sau lần bind đầu tiên.
- `users.current_device_id` varchar(64) null.
- `device_id`: UUID v4 do frontend sinh 1 lần, lưu `localStorage`, gửi trong body đăng nhập và header `X-Device-Id` ở mọi request.
  - Server validate đúng định dạng UUID, ≤ 64 ký tự; sai thì coi như không có.
  - **Chỉ dùng để chọn thông điệp**, không dùng để cấp quyền.
- Tombstone trong cache (Redis DB cache):
  - Khoá `session_replaced:{sha256(old_session_id)}`, TTL = `SESSION_LIFETIME`.
  - Giá trị `{reason: 'replaced'|'password_changed'|'locked', new_device_id, at}`.

### Đăng nhập (`LoginService` → `StudentSessionService::bind`) — chỉ ở host api, chỉ vai trò `hoc_sinh`
1. Kiểm tra thông tin đăng nhập (throttle 2 lớp, captcha nếu bật, thông điệp chung — US-001, api-contract §2.2).
2. `Auth::login($user)` (**không remember-me**) rồi `$request->session()->regenerate()`.
3. Trong transaction: `lockForUpdate` dòng user, đọc `old = current_session_id`, rồi `UPDATE users SET current_session_id = <id mới>, current_device_id = ?, last_login_at = now()`.
4. Sau commit, nếu `old` là một session id thật (khác `logged_out`, khác id mới):
   - Ghi tombstone `{reason:'replaced', new_device_id}`.
   - `Session::getHandler()->destroy($old)` → phiên cũ **bị xoá khỏi store**, không thể dùng lại.
5. Nếu bước 3 lỗi: `Auth::logout()` + invalidate session mới, trả 500. Phiên cũ vẫn là phiên duy nhất, **không bao giờ có 2 phiên hợp lệ**.
   - Nếu bước 4 lỗi (Redis tạm lỗi): phiên cũ vẫn bị middleware chặn vì không khớp `current_session_id`. Log cảnh báo.

### Middleware `EnforceSingleStudentSession` (alias `student.single_session`)

```
if user chưa đăng nhập hoặc role != hoc_sinh → next()
if user.current_session_id === session()->getId() → next()
// Mọi trường hợp khác (khác id, 'logged_out', NULL) → từ chối. KHÔNG có nhánh "nhận nuôi".
reason = (X-Device-Id hợp lệ và == user.current_device_id) ? SESSION_EXPIRED : SESSION_REPLACED
Auth::guard('web')->logout(); session()->invalidate(); session()->regenerateToken();
return 401 { code: reason, message: ... }
```

### Khi phiên cũ đã bị xoá khỏi store (trường hợp thường gặp sau bước 4)
- Request từ thiết bị cũ không còn đăng nhập, nên `auth:sanctum` ném `AuthenticationException`.
- Renderer trong `bootstrap/app.php` lấy **session id từ cookie đã giải mã** (`$request->cookies->get(config('session.cookie'))`, trước khi StartSession cấp id mới), rồi tra tombstone:
  - Có tombstone `replaced`:
    - `X-Device-Id` == `new_device_id` → `401 SESSION_EXPIRED` (đăng nhập bấm 2 lần trên cùng thiết bị, không báo nhầm "thiết bị khác").
    - Ngược lại → `401 SESSION_REPLACED` với thông điệp: "Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay."
  - Tombstone `password_changed` → `401 SESSION_REVOKED` ("Mật khẩu đã được thay đổi, vui lòng đăng nhập lại").
  - Tombstone `locked` → `403 ACCOUNT_LOCKED`.
  - Không có tombstone → `401 UNAUTHENTICATED`.

### Đăng xuất (AC3)
`logout` → `UPDATE users SET current_session_id = 'logged_out' WHERE id=? AND current_session_id = <id hiện tại>`, invalidate session hiện tại. Không tạo tombstone.

### Đổi / đặt lại mật khẩu, khoá tài khoản (US-015, S11)
- **Học sinh:** tombstone với reason tương ứng + `destroy(current_session_id)` + đặt `logged_out`.
  - Đổi mật khẩu khi đang đăng nhập: sau khi huỷ, bind lại phiên hiện tại (regenerate + bind) để người vừa đổi không bị văng.
- **Staff (nhiều phiên):** dùng middleware `AuthenticateSession` của Sanctum (`config/sanctum.php` → `middleware.authenticate_session`) cho host admin-api. Session lưu hash mật khẩu, nên mật khẩu đổi thì mọi phiên khác tự đăng xuất.
- **Khoá tài khoản (mọi vai trò):** `account.active` chặn ngay ở request tiếp theo (403 `ACCOUNT_LOCKED`). Với học sinh, huỷ thêm session trong store.

### Với frontend Next.js tách rời
- "Phiên" là cookie `vv_session` host-only trên `api.vitaminvui.vn`, `HttpOnly`. Next.js không đọc được, nên không có token nào ở `localStorage` để sao chép.
- Request SSR (nếu có) chuyển tiếp đúng header `Cookie` của trình duyệt → cùng session id (xem quy tắc no-store ở ADR-004 §2.5).
- Next.js có **1 interceptor dùng chung** trong `packages/api-client`:
  - `401 SESSION_REPLACED` → dừng player/quiz, hiện overlay không đóng được (design US-014 §2.1; design đang ghi `reason=session_replaced`, **thống nhất dùng trường `code`**).
  - `SESSION_EXPIRED` / `SESSION_REVOKED` / `UNAUTHENTICATED` → chuyển `/dang-nhap`.
  - Lỗi mạng (không có response) **không** được coi là mất phiên (AC5).

### Tác động tới video (US-006 AC7)
- Mọi API học tập (`/learn/*`, `playback`, `heartbeat`) đều qua nhóm middleware chuẩn, nên thiết bị cũ bị chặn ở heartbeat kế tiếp (≤ 20 giây).
- URL HLS đã cấp trước đó còn hiệu lực tới hết TTL (**15 phút** và ràng IP — ADR-002 §4). Rủi ro còn lại này được chấp nhận.
- Tiến độ được lưu theo từng heartbeat → đăng nhập lại học tiếp bình thường (AC4).

### Quy tắc cho Dev
- Chỉ `StudentSessionService` được ghi `current_session_id` và tombstone. Bất kỳ luồng nào gọi `session()->regenerate()` cho học sinh đều phải gọi `bind()`.
- Không bật `logoutOtherDevices` cho học sinh. `AuthenticateSession` chỉ dùng cho host admin-api.
- Test bắt buộc (T05):
  - A đăng nhập → B đăng nhập → A nhận `SESSION_REPLACED`.
  - **A → B → B đăng xuất → A gọi `/auth/me` phải 401** (không sống lại).
  - Đăng nhập 2 lần trên cùng `device_id` → `SESSION_EXPIRED`, không báo "thiết bị khác".
  - Đổi mật khẩu → phiên khác nhận `SESSION_REVOKED`.
  - Giáo viên/admin đăng nhập 2 nơi → cả 2 cùng hoạt động.
  - `X-Device-Id` sai định dạng bị bỏ qua.

## Hệ quả
- (+) Phiên cũ bị huỷ thật, không thể sống lại; vẫn báo đúng lý do; an toàn khi lỗi DB.
- (−) Thêm tombstone trong cache (nhỏ, tự hết hạn); cần Redis DB cache không bị `cache:clear` xoá nhầm lúc cao điểm (tách DB — ADR-004 §6).
- (−) Không có danh sách thiết bị/đăng xuất từ xa (ngoài phạm vi US-014).
- **Chờ PO:** có gửi email cảnh báo khi phiên bị thay thế không (câu hỏi mở US-014) — mặc định **không** (chỉ báo trên UI). Nếu bật: listener của event `StudentSessionReplaced` gửi mail qua queue.
