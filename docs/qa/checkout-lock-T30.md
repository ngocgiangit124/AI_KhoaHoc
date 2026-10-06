# QA: Khoá thanh toán tạm (V2) + T30 (audit:purge, users:purge-unverified)
**Kết quả:** PASS (T18 + T30 không race: 60 test, 355 assertion; T18 `--group=race`: 5 test, 60 assertion; DB local-f; pint sạch). Không có bug.

## Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| Cờ tắt, tổng > 0 -> 503 PAYMENT_DISABLED, không tạo đơn/attempt; preview can_checkout=false + notice | CheckoutApiTest (V2) | PASS |
| Cờ tắt, đơn 0đ vẫn chạy | CheckoutApiTest (V2) | PASS |
| Đã có đơn pending rồi tắt cờ: 503, đơn cũ không superseded, không attempt mới | CheckoutApiTest (V2) | PASS |
| `config/public` trả `paid_checkout_enabled` đúng cả 2 trạng thái | CheckoutApiTest (V2) | PASS |
| Cờ tắt + expected_total sai -> 409 CHECKOUT_CHANGED, không phải 503 (MỚI) | CheckoutApiTest | PASS |
| Cờ tắt + mã đang giữ chỗ bởi đơn pending cũ: 503, coupon_hold_until không đổi, mã còn trong giỏ, used_count 0; HS khác thấy mã hết chỗ -> 409 (MỚI) | CheckoutApiTest | PASS |
| Bật lại cờ: đơn pending cũ dùng lại (200 reused, cùng order_code + pay_url, 1 đơn, 1 attempt) (MỚI) | CheckoutApiTest | PASS |
| Race checkout/markPaid khi cờ bật | CheckoutRaceTest (race) | PASS |
| audit:purge: xoá > 24 tháng, dry-run, chunk | PurgeCommandsTest | PASS |
| audit:purge nhiều lô (chunk=2), dry-run khớp số thật, ranh giới 24 tháng, --months=0/abc bị từ chối (MỚI) | PurgeCommandsTest | PASS |
| users:purge-unverified: loại trừ có đơn/ghi danh/mới/đã xác thực/giáo viên | PurgeCommandsTest | PASS |
| User vừa ghi danh sau khi chọn lô: không xoá, consents còn nguyên (MỚI) | PurgeCommandsTest | PASS |
| Nhiều dòng consents: xoá sạch của user bị xoá, không đụng consents user khác (MỚI) | PurgeCommandsTest | PASS |
| Số user > purge_chunk (chunk=2, 7 ứng viên xen 2 user được giữ): xoá đủ 7, giữ đúng (MỚI) | PurgeCommandsTest | PASS |
| --dry-run khớp số xoá thật; parent_consent=pending bị tính; --days=0 bị từ chối (MỚI) | PurgeCommandsTest | PASS |
| Lô lỗi: thử từng user, bỏ qua user lỗi, trả FAILURE | PurgeCommandsTest | PASS |
| Lịch withoutOverlapping + onOneServer | PurgeCommandsTest | PASS |
| Không có luồng staff tạo / đổi vai trò thành học sinh (MỚI) | PurgeCommandsTest | PASS |

## Xác nhận luồng "admin tạo hộ học sinh"
Không có. `StaffAccountService::create` và `changeRole` đều ném ValidationException khi role = Student (có test); route admin không có endpoint tạo học sinh. Học sinh chỉ vào hệ thống qua đăng ký + OTP, nên không có tài khoản bị purge sau 7 ngày do admin cấp quyền hộ. (Ghi danh miễn phí là luồng của chính học sinh.)

## Bug phát hiện
Không có. R3 (consents mất khi race) và R4 (lô lỗi) của reviewer đã được sửa trong code (purgeBatch khoá hàng trước, fallback từng user, FAILURE) và có test xác nhận.

## Rủi ro & đề xuất (không chặn)
- 503 PAYMENT_DISABLED không có Retry-After (R2 review): FE phải dựa vào `code` / `paid_checkout_enabled`; nên kiểm monitor có đếm 5xx.
- T20 (`/orders/{code}/pay`) khi làm phải kiểm cùng cờ, vì đơn pending cũ vẫn sống tới expires_at (test hiện chỉ phủ /checkout).
- phpstan của dự án chỉ quét app/config/database/routes; chạy ép lên file test ra nhiễu Pest (`artisan()` không có trên TestCall), không phải lỗi thật. Pint sạch.
- Test purge chưa phủ deadlock/đua thật giữa 2 tiến trình (chỉ mô phỏng bằng gọi purgeBatch sau khi đổi dữ liệu).
- Chưa kiểm hành vi MySQL 8.4 riêng (chạy trên DB f local).
