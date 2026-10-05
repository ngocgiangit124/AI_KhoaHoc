# Nợ bảo mật hoãn sang v2

PO quyết định (2026-10-05): tạm dừng bước `laravel-security` và việc sửa lỗi bảo mật không nghiêm trọng để đẩy tiến độ.
Các mục dưới đây được chấp nhận rủi ro tạm thời. **Review lại toàn bộ và sửa khi hệ thống hoàn thành (v2), bắt buộc trước production/go-live.**
Task có cờ [SEC] trong `tasks.md` vẫn đi qua dev → reviewer → QA, chỉ bỏ cổng `laravel-security` cho đến khi PO bật lại (sau khi BA hoàn chỉnh hệ thống).

## T03 (nguồn: `docs/security/review-T03.md`, kết luận PASS có điều kiện, không có Critical/High)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| M1 | Medium | Khoá được tài khoản người khác (10 lượt sai/giờ theo tài khoản; 50 lượt/IP khoá cả trường dùng chung NAT). Đề xuất: khoá theo tài khoản+IP, vượt ngưỡng thì bắt captcha ở login thay vì 429; sửa contract §1.6 | Hoãn v2 (cần PO chọn) |
| M2 | Medium | Né giới hạn theo tài khoản bằng email có dấu (collation `utf8mb4_0900_ai_ci`, `accountKey()` tạo bộ đếm khác nhau). Sửa: đếm theo user id / ASCII-fold | Hoãn v2 (xem mục "Ghi chú tiến trình") |
| M3 | Medium | Bộ đếm không nguyên tử (kiểm trước, đếm sau) nên request đồng thời vượt ngưỡng. Sửa: `RateLimiter::hit()` trước khi so mật khẩu | Hoãn v2 (xem mục "Ghi chú tiến trình") |
| L1 | Low | Khoá IPv6 theo địa chỉ đầy đủ, nên gộp /64 | Hoãn v2 |
| L2 | Low | `QueryException` ở nhánh rethrow đăng ký ghi SQL kèm binding (email, SĐT, hash) vào log | Hoãn v2 |
| L3 | Low | Khai tuổi giả để bỏ qua phụ huynh (không đối chiếu `grade_level`); liên hệ phụ huynh được trùng của chính học sinh (R6 review) | Hoãn v2, cần pháp chế |
| L4 | Low | Turnstile không kiểm `hostname`/`action`; guard chưa chặn `fake` ở staging, chưa báo thiếu `TURNSTILE_SECRET` | Hoãn v2 (trước T31) |
| L5 | Low | Test "11 IP" dùng `X-Forwarded-For` giả nên thực chất cùng 1 IP; nên dùng `REMOTE_ADDR` | Hoãn v2 |
| Info | — | Dummy hash tạo lười theo worker; bcrypt chỉ dùng 72 byte đầu trong khi `max:128`; chưa có `uncompromised()`; IP/UA trong `consents` cần thời hạn lưu và xử lý khi xoá tài khoản (T34, pháp chế); kiểm CI sau khi bỏ `DB_PASSWORD` cố định trong `phpunit.xml` | Hoãn v2 |

Test QA gợi ý khi làm v2: 11 lượt sai bằng 11 cách viết có dấu của một email → lượt 11 phải 429; 30 request sai đồng thời → tối đa 10 lần so mật khẩu; 10 lượt sai từ IP-1 rồi đăng nhập đúng từ IP-2 (theo phương án M1); test throttle bằng `REMOTE_ADDR`; log không có PII khi `QueryException`; Turnstile `hostname` sai → `CAPTCHA_FAILED`.

## Ghi chú tiến trình
Chưa có dòng code nào của M1–M3, L1–L5 được viết (đã xác nhận 2026-10-05). Code T03 hiện chỉ gồm R1–R5 của review (limiter chỉ đếm lượt sai, regex index cho race unique, captcha fake yêu cầu token, no-store). `composer ci` xanh: 194 test.
