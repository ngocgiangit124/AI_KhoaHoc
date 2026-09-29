---
name: apiclient-retry-after-and-otp-t04
description: ApiError giờ có retryAfterSeconds (đọc từ header Retry-After); các giả định OTP T04 của FW1 phần 2 đã đối chiếu với backend thật
metadata:
  type: project
---

Ở FW1 phần 2 (`/xac-thuc-otp`, worktree `fw1-otp`), sau khi merge T04 (OTP backend thật)
vào, đã đối chiếu 4 giả định cũ ghi ở `docs/board.md` mục 4:

- Đúng nguyên trạng: `resend_available_at` (trả bởi `POST /auth/otp/send`, 202) là ISO 8601
  có offset; `POST /auth/register` KHÔNG trả `resend_available_at`; FE tự che email/SĐT
  hiển thị (backend chỉ che `parent_email`/`parent_phone`, không che `email`/`phone` chính
  của user).
- Sai một phần: "429 không có thời điểm mở khoá". Từ T04 (sau sửa L3, xem
  `docs/security/review-T04.md`), MỌI 429 (kể cả `DomainException` ném từ
  `OtpService::tooManySendException()`, không chỉ route `throttle:`) đều có header
  `Retry-After`, và `cors.php.exposed_headers` đã thêm `Retry-After` nên trình duyệt đọc
  được. Khuyến nghị security (đã áp dụng): vẫn ưu tiên `resend_available_at` làm nguồn
  cooldown CHÍNH; `Retry-After` chỉ là thông tin PHỤ.

**Thay đổi packages/api-client** (`frontend/packages/api-client/src/errors.ts`,
`http.ts`): `ApiError` có thêm field `retryAfterSeconds: number | undefined`, đọc từ
header `Retry-After` của response trong `parseJsonResponse()`. Constructor
`new ApiError(status, body, retryAfterSeconds?)` — tham số thứ 3 optional nên không phá
test cũ dùng `new ApiError(status, body)`.

**Cách dùng ở form OTP** (`apps/web/app/xac-thuc-otp/VerifyOtpForm.tsx`):
- 429 ở `otp/verify` (khoá tài khoản): banner = message server + gợi ý phụ
  `(thử lại sau khoảng X phút/giây)` nếu có `retryAfterSeconds` — dùng hàm
  `appendRetryAfterHint()`.
- 429 ở `otp/send` (bấm "Gửi lại mã"): response lỗi KHÔNG có `resend_available_at` (chỉ
  202 mới có) — dùng `retryAfterSeconds ?? resendCooldownSeconds` (config/public) làm mốc
  đếm ngược mới, tránh nút "Gửi lại mã" hiện lại ngay lập tức sau 429.

**Why:** tránh 2 lỗi UX: (1) 429 lockout không có gợi ý thời gian nào cho người dùng, (2)
"Gửi lại mã" bị 429 nhưng UI không khoá lại, cho bấm dồn dập vô ích.

**How to apply:** Khi làm màn khác có luồng OTP/throttle tương tự (đặt lại mật khẩu T27,
MFA staff T28), tái dùng `ApiError.retryAfterSeconds` thay vì tự đọc header thủ công. Nếu
thấy `ApiError` được dùng ở đâu đó so sánh bằng `toEqual`/snapshot đầy đủ object, kiểm tra
lại vì field mới có thể đổi kết quả so sánh sâu (hiện tại chưa có chỗ nào làm vậy, đã kiểm
`authFetch.test.ts`).

Liên quan: [[vitaminvui_fe0_workspace]].
