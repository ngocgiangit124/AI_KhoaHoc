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

## T04 (OTP) — điểm nghi ngờ ghi nhận, không chặn (2026-10-06)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T04-1 | Medium | `PUT /auth/contact` không yêu cầu mật khẩu hiện tại: kẻ có phiên bị đánh cắp đổi được email/SĐT (và về sau là email nhận mã đặt lại mật khẩu T27). Đề xuất: bắt `current_password` hoặc OTP tới liên hệ cũ khi tài khoản đã xác thực | Hoãn v2 (trước T27) |
| T04-2 | Low | Mã OTP lưu bcrypt (`Hash::make`) theo data-model: không gian 10^6 nên lộ DB là dò ra mã trong giây lát (chỉ có giá trị trong 10 phút). Đề xuất HMAC-SHA256 với khoá riêng nếu muốn cứng hơn | Hoãn v2 |
| T04-3 | Low | Đếm verify 5/phút và 20/ngày theo throttle middleware (cache): đếm cả request sai định dạng, khoá hết ngày thay vì "khoá xác thực 24h" có audit như data-model §3.1; trần ngày chưa ghi audit cho verify | Hoãn v2 |
| T04-4 | Low | `otp-send` throttle đếm cả request 422 (vd. kênh sai) vào cooldown 1/phút | Hoãn v2 |
| T04-5 | Info | Cảnh báo/khoá xác thực 24h theo IP + user khi bị dò hàng loạt; chưa có metric/cảnh báo | Hoãn v2 |

## T05 (một phiên học sinh) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T05-1 | Low | Request mang cookie của session đã bị xoá (có tombstone) vẫn được StartSession tái tạo một session rỗng cùng id cũ (hành vi mặc định Laravel với id lạ). Không cấp quyền nhưng id đó vẫn được dùng lại (fixation nhẹ); login gọi `regenerate()` (Laravel tự xoá payload cũ khi `login()`; dev giữ snapshot để khôi phục nếu bind lỗi) nên không bị lợi dụng. Đề xuất: bỏ id lạ/rỗng ở middleware đầu pipeline | Hoãn v2 |
| T05-2 | Low | Tombstone nằm ở cache còn session nằm ở store `session`: nếu cache bị `cache:clear`/mất thì thiết bị cũ nhận `UNAUTHENTICATED` thay vì `SESSION_REPLACED` (vẫn bị chặn, chỉ sai thông điệp). Cần Redis DB cache tách riêng đúng ADR-004 §6 | Hoãn v2 (T31) |
| T05-3 | Low | `device_id` chỉ so sánh để chọn thông điệp (đúng ADR); kẻ biết UUID thiết bị chủ có thể ép thông báo `SESSION_EXPIRED` thay `SESSION_REPLACED` (không cấp quyền) | Chấp nhận |
| T05-4 | Info | `StudentSessionService::revoke()` đã sẵn cho T27 (đổi/đặt lại mật khẩu) và luồng khoá học sinh; hiện chưa có lệnh/endpoint khoá học sinh nào gọi nó (`staff:lock` chỉ cho staff/GV). Khi có chức năng khoá học sinh (quản trị) phải gọi `revoke($user, 'locked')`; đổi mật khẩu khi đang đăng nhập phải bind lại phiên hiện tại (ADR-003) | Theo dõi ở T27 |
| T05-5 | Info | Chưa có test song song thật (2 process login cùng lúc) cho `lockForUpdate` của `bind()` | QA bổ sung nếu cần |
