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

## T28 (đăng nhập quản trị) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-07)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T28-1 | Low | Throttle đăng nhập sai quản trị cũng khoá được tài khoản staff từ người ngoài (10 lần/giờ), như M1 của T03; chưa có captcha/cảnh báo khi chạm ngưỡng; request bị 429 chưa ghi audit | Hoãn v2 |
| T28-2 | Low | MFA dùng chung trần OTP 5/giờ, 10/ngày với mọi purpose: admin đăng nhập nhiều lần/ngày có thể tự khoá MFA; chưa có "khoá xác thực 24h" có audit | Hoãn v2 |
| T28-3 | Low | Session cũ sau đổi mật khẩu/regenerate (`regenerate(false)`) còn nằm ở store đến hết TTL (bị AuthenticateSession huỷ khi dùng lại); không có tombstone để báo `SESSION_REVOKED` cho staff | Hoãn v2 |
| T28-4 | Low | Nhận diện thiết bị mới của giáo viên dựa vào `X-Device-Id` do client tự khai (kẻ có mật khẩu có thể tái dùng UUID đã biết để tránh email cảnh báo; cookie thiết bị ký phía server sẽ tốt hơn) | Hoãn v2 |
| T28-5 | Info | Staff không bị một-phiên: nhiều phiên song song được phép; thông điệp WRONG_PORTAL của host api học sinh (T03) còn nêu "trang học sinh" | Theo dõi |
| T28-6 | Low | Request không có `Accept: application/json` tới route cần đăng nhập trả 500 "Route [login] not defined" (có từ trước T28, review R6). Đề xuất `shouldRenderJsonWhen` cho `api/*` hoặc `redirectGuestsTo(fn () => null)` + test 401 | Hoãn v2 |

## T17 (cổng thanh toán MoMo) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T17-1 | Medium | Danh sách trường ký của **phản hồi query** (`MoMoSigner::QUERY_RESPONSE_FIELDS`, đang = danh sách IPN) và vector chữ ký **chưa đối chiếu tài liệu/sandbox MoMo** (không truy cập được offline). Sai danh sách → verify fail-closed (không bao giờ nhầm thành công) nhưng đối soát sẽ không chạy được | **Phải kiểm phản hồi query với sandbox MoMo thật trước khi làm T20** (nếu MoMo không ký phản hồi query: cần PO/Security đổi ADR §7b); đối chiếu cả vector chính thức. PO quyết định |
| T17-2 | Low | Đã xử lý (R3): mặc định `MOMO_PAY_URL_HOSTS` chỉ `payment.momo.vn`; sandbox đặt rõ trong `.env.example` local. Guard production chưa ép giá trị này | Chấp nhận |
| T17-3 | Low | Chưa dùng tham số hạn link MoMo (`orderExpireTime`) và chưa xác nhận mã resultCode "giao dịch không tồn tại/hết hạn" (đã tách `EXPIRED_CODES` [1005, 42] và `FAILED_CODES`; mã 8000/10/11/99/1005/42/1001-1007 CHƯA đối chiếu tài liệu; query gặp mã lạ → Pending; IPN mã lạ → Failed theo ADR); hạn mức 1.000–50.000.000đ lấy từ config, cần xác nhận | Kiểm ở sandbox |
| T17-4 | Info | Chưa có `MOMO_IP_ALLOWLIST` (tuỳ chọn, phụ thuộc IP thật qua proxy, S10) và chưa giới hạn tần suất gọi query (thuộc T20) | Theo dõi |

## T27 (quên/đổi mật khẩu học sinh) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T27-1 | Low | Throttle `otp-verify` cho `reset` theo tài khoản (5/phút, 20/ngày) cho phép kẻ ngoài cố tình khoá việc đặt lại mật khẩu của nạn nhân (DoS nhẹ); chặn dò mã vẫn do giới hạn 5 lần/mã. Chưa có cảnh báo khi chạm ngưỡng | Hoãn v2 |
| T27-2 | Low | Chưa gửi email cảnh báo "mật khẩu vừa được thay đổi" sau reset/đổi (câu hỏi mở US-015); chưa kiểm N mật khẩu gần nhất (chỉ chặn trùng mật khẩu hiện tại khi đổi) | Hoãn v2 |
| T27-3 | Low | `forgot` gửi OTP sau response (`defer`) nên timing đồng đều, nhưng queue mail và lỗi gửi bị nuốt (chỉ log): người dùng không biết mã không tới. Cần giám sát tỉ lệ lỗi gửi OTP ở vận hành | Theo dõi |
| T27-4 | Low | Reset thành công không xoá bộ đếm đăng nhập sai (`login-fail:*`) của tài khoản | Hoãn v2 |
| T27-5 | Medium | `reset` (không captcha) còn lộ tài khoản tồn tại qua THÔNG ĐIỆP: tài khoản có mã hiệu lực + mã sai → "Mã OTP không đúng", không tồn tại → "đã hết hạn" (kẻ ngoài gọi `forgot` rồi `reset` mã bậy). Phần TIMING đã sửa (QA BUG-1: `dummyHash()` cache liên request qua `Cache::rememberForever` theo cost, mỗi nhánh đúng 1 lần `Hash::check`, có test đếm băm); còn lại phần thông điệp. Giữ AC3 theo quyết định PO; v2 cân nhắc thông điệp chung hoặc captcha ở reset | Hoãn v2 (PO) |
| T27-6 | Low | R4 review: `StudentSessionService::killSession` (tombstone + destroy) chạy trong transaction của reset/change; commit lỗi sau đó → văng phiên oan. Đề xuất `DB::afterCommit` trong `revoke()` (đụng code T05, chạy lại test T05) | Hoãn v2 |
| T27-7 | Low | R6 review: cooldown OTP 60s dùng chung mọi purpose nên quên mật khẩu ngay sau đăng ký (<60s) không nhận mã dù vẫn 202; mã `reset_password` còn hiệu lực chưa bị vô hiệu khi `change()` | Hoãn v2 |
