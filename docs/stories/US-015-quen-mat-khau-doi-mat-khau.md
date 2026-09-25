# US-015: Quên mật khẩu / đổi mật khẩu (Học sinh)

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh, tôi muốn tự đặt lại mật khẩu khi quên và tự đổi mật khẩu khi đang đăng nhập, để tôi có thể lấy lại quyền truy cập tài khoản một cách an toàn mà không cần nhờ người khác can thiệp.

## Bối cảnh
Phát sinh từ review bảo mật `docs/security/audit-2026-09-25.md` (phát hiện S11): US-014 (giới hạn 1 thiết bị/1 phiên) và ADR-003 quy định khi phiên học sinh bị thay thế, thông điệp khuyên "đổi mật khẩu ngay", nhưng MVP trước đó **chưa có** chức năng quên/đổi mật khẩu — học sinh bị chiếm tài khoản không có cách nào tự lấy lại. Story này lấp khoảng trống đó, tương ứng task T27, đã có sẵn hợp đồng API ở `docs/architecture/api-contract.md` §2.2 (`POST /auth/password/forgot`, `POST /auth/password/reset`, `PUT /auth/password`) và cơ chế phiên ở `docs/adr/ADR-003-mot-thiet-bi-mot-phien-hoc-sinh.md`.

Phạm vi story này **chỉ áp dụng cho vai trò Học Sinh** (host `api`). Đổi mật khẩu lần đầu / được Admin đặt lại mật khẩu cho tài khoản Giáo Viên/Quản lý trang/Admin thuộc US-016.

## Business rules
- BR1: Quên mật khẩu dùng OTP 6 số gửi qua **email** (kênh SMS chưa có nhà cung cấp thật — theo cấu hình `auth.otp.channels`, giống US-001 BR7).
- BR2: Thông điệp phản hồi cho bước "Quên mật khẩu" **luôn giống nhau** dù email/SĐT nhập vào có tồn tại tài khoản hay không, để chống dò tài khoản (kế thừa US-001 BR5).
- BR3: Đặt lại mật khẩu thành công (qua OTP) hoặc đổi mật khẩu thành công (khi đang đăng nhập) đều phải **hủy mọi phiên đang hoạt động khác** của tài khoản đó theo cơ chế tombstone `password_changed` ở ADR-003 — học sinh ở các thiết bị khác (nếu đang có, dù theo thiết kế 1 phiên chỉ có tối đa 1 phiên hợp lệ) nhận `401 SESSION_REVOKED` ở request tiếp theo.
- BR4: Đổi mật khẩu khi đang đăng nhập bắt buộc nhập đúng **mật khẩu hiện tại**, mật khẩu mới ≥ 8 ký tự (theo US-001 BR3) và xác nhận mật khẩu khớp.
- BR5: Sau khi đổi mật khẩu khi đang đăng nhập, phiên hiện tại của chính người vừa đổi được **giữ lại** (bind lại theo ADR-003) — không tự đăng xuất người vừa thực hiện thao tác.
- BR6 (Mặc định an toàn — chờ PO xác nhận): Bước "Quên mật khẩu" bắt buộc captcha (Turnstile) trước khi gửi OTP, giống luồng đăng ký ở US-001, để chống dò/spam gửi OTP hàng loạt.
- BR7: OTP `purpose = reset_password` dùng chung cơ chế giới hạn với OTP xác thực tài khoản (US-001 BR7/AC8): tăng `attempts` nguyên tử trước khi so, tối đa 5 lần sai/phút và 20 lần/ngày theo tài khoản; gửi mã: cooldown 60 giây, tối đa 5 lần/giờ và 10 lần/ngày; vượt trần ngày → tạm khóa xác thực 24 giờ và ghi `audit_logs`.
- BR8: Tài khoản đang ở trạng thái `locked` hoặc đã bị ẩn danh hóa (US-018) vẫn nhận được thông điệp chung như BR2 ở bước "Quên mật khẩu" (không tiết lộ trạng thái); tài khoản đó **không hoàn tất được** việc đặt lại mật khẩu (chặn ở bước xác nhận OTP hoặc ngay sau đó).

## Acceptance criteria
- AC1: Given khách/học sinh quên mật khẩu, When nhập email hoặc số điện thoại đã đăng ký cùng captcha và submit "Quên mật khẩu", Then hệ thống luôn trả về thông điệp chung dạng "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn", và nếu tài khoản thực sự tồn tại + đang hoạt động thì gửi OTP `purpose=reset_password` qua email.
- AC2: Given học sinh đã nhận OTP hợp lệ, When nhập đúng OTP cùng mật khẩu mới ≥ 8 ký tự và xác nhận khớp trong thời hạn hiệu lực OTP, Then mật khẩu được cập nhật, mọi phiên đang hoạt động của tài khoản bị hủy (tombstone `password_changed`), và học sinh phải đăng nhập lại bằng mật khẩu mới.
- AC3: Given học sinh nhập sai OTP hoặc OTP đã hết hạn, When submit form đặt lại mật khẩu, Then hệ thống báo lỗi rõ ràng (mã sai / đã hết hạn) và **không** đổi mật khẩu.
- AC4: Given học sinh đang đăng nhập muốn đổi mật khẩu, When nhập đúng mật khẩu hiện tại, mật khẩu mới hợp lệ và xác nhận khớp rồi submit, Then mật khẩu được đổi ngay, phiên hiện tại của người vừa đổi vẫn còn hiệu lực, các phiên khác (nếu có) bị hủy.
- AC5: Given học sinh nhập sai mật khẩu hiện tại khi đổi mật khẩu, When submit, Then hệ thống báo lỗi "Mật khẩu hiện tại không đúng" và không đổi mật khẩu.
- AC6: Given học sinh gửi yêu cầu "Quên mật khẩu" vượt ngưỡng cho phép (cooldown 60 giây, > 5 lần/giờ hoặc > 10 lần/ngày — BR7), When gửi thêm, Then hệ thống từ chối gửi thêm OTP và báo rõ cần chờ.
- AC7: Given 2 request đặt lại mật khẩu cùng dùng 1 mã OTP gửi đồng thời (race condition), When cả 2 cùng submit gần như cùng lúc, Then chỉ đúng 1 request thành công (mã bị tiêu thụ có điều kiện `consumed_at IS NULL`), request còn lại nhận lỗi mã đã được sử dụng.
- AC8: Given tài khoản đang bị khóa (`status = locked`) hoặc đã bị ẩn danh hóa (US-018), When ai đó thực hiện "Quên mật khẩu" với email/SĐT của tài khoản này, Then hệ thống vẫn trả thông điệp chung như AC1 (không tiết lộ trạng thái) và không cho hoàn tất đặt lại mật khẩu thành công cho tài khoản đó.

## Trường hợp biên & lỗi
- Email/SĐT không tồn tại trong hệ thống → phản hồi giống hệt trường hợp tồn tại (AC1/BR2), không có sự khác biệt về thời gian phản hồi đáng kể (tránh timing attack — lưu ý kỹ thuật cho Dev).
- Spam bấm "Quên mật khẩu" bởi bot → captcha (BR6) + throttle theo tài khoản và theo IP (2 lớp, giống US-001 AC6/ADR-004 §2.4).
- Mất kết nối/đóng trình duyệt giữa lúc nhập OTP và nhập mật khẩu mới → OTP hết hạn theo TTL, học sinh phải yêu cầu gửi lại từ đầu.
- Học sinh vừa bị đăng xuất cưỡng bức do đăng nhập thiết bị khác (US-014) rồi lập tức dùng "Quên mật khẩu" → luồng vẫn hoạt động bình thường (không phụ thuộc trạng thái phiên hiện tại).
- Tài khoản chưa xác thực email (`email_verified_at = null`) yêu cầu "Quên mật khẩu" → vẫn nhận được OTP `reset_password` (mục đích khác với OTP `verify_account`), không bị chặn bởi điều kiện xác thực tài khoản.
- Đổi mật khẩu mới trùng với mật khẩu cũ → hành vi cụ thể (chấp nhận hay từ chối) là câu hỏi mở.
- Đặt lại mật khẩu thành công nhưng bước hủy phiên khác gặp lỗi hệ thống tạm thời → phải đảm bảo không để lộ 2 mật khẩu cùng hợp lệ; log cảnh báo để vận hành theo dõi (tương tự nguyên tắc ở ADR-003 khi bước 4 của luồng đăng nhập lỗi).

## Phân quyền
| Vai trò | Quên mật khẩu (chưa đăng nhập) | Đổi mật khẩu (đang đăng nhập) |
|---|---|---|
| Khách (chưa đăng nhập) | Có (cho tài khoản Học Sinh) | – |
| Học Sinh | Có (cho chính mình) | Có (cho chính mình) |
| Giáo Viên | Không (ngoài phạm vi, xem US-016) | Không (ngoài phạm vi, xem US-016) |
| Quản lý trang | Không (ngoài phạm vi, xem US-016) | Không (ngoài phạm vi, xem US-016) |
| Admin | Không (ngoài phạm vi, xem US-016) | Không (ngoài phạm vi, xem US-016) |

## Ảnh hưởng dữ liệu
- Bảng `otp_codes`: thêm giá trị `purpose = reset_password` (đã dự kiến sẵn trong `data-model.md` §3.1).
- Bảng `users`: dùng lại cột `password`, `password_changed_at` (đã có trong `data-model.md`); không cần migration mới cho story này.
- Dùng lại cơ chế `current_session_id`/tombstone của ADR-003 (không tạo bảng mới) để hủy phiên khác khi đổi/đặt lại mật khẩu.
- Ghi `audit_logs` cho hành động đặt lại/đổi mật khẩu là điểm cần Dev xác nhận có bắt buộc hay không (US-001/ADR-004 hiện chưa liệt kê action này trong danh sách mẫu — cần bổ sung nếu Dev/Architect thấy cần).

## Ngoài phạm vi
- Đổi/đặt lại mật khẩu cho tài khoản Staff (Admin/Quản lý trang/Giáo Viên) — thuộc US-016.
- Đổi email/số điện thoại đăng nhập.
- Đăng nhập bằng liên kết một lần (magic link) thay mật khẩu.
- Xác thực 2 lớp bằng ứng dụng TOTP (Google Authenticator...) khi đổi mật khẩu.

## Câu hỏi mở
- [ ] Mật khẩu mới có được phép trùng với mật khẩu cũ (hoặc N mật khẩu gần nhất) không, hay không kiểm tra ở MVP?
- [ ] Thời hạn hiệu lực OTP `reset_password` là bao lâu — dùng chung với OTP xác thực tài khoản (mặc định 10 phút theo US-001) hay cần ngắn hơn vì đây là thao tác nhạy cảm hơn?
- [ ] Có cần gửi email cảnh báo "Mật khẩu của bạn vừa được thay đổi" tới hộp thư sau khi đổi/đặt lại mật khẩu thành công không, hay chỉ hiển thị trên giao diện là đủ?
- [ ] Có ghi `audit_logs` cho hành động đổi/đặt lại mật khẩu của học sinh không (khác với hành động tương tự của staff đã có trong thiết kế)?

## Ghi chú cho Designer / Dev / QA
- Designer: màn "Quên mật khẩu" (nhập email/SĐT + captcha) → màn nhập OTP + mật khẩu mới + xác nhận; màn "Đổi mật khẩu" trong trang tài khoản (mật khẩu hiện tại + mật khẩu mới + xác nhận); thông báo rõ ràng khi các thiết bị khác bị đăng xuất do đổi mật khẩu.
- Dev: tái sử dụng `OtpService`/`OtpSender` đã thiết kế cho US-001 với `purpose=reset_password`, không viết lại logic OTP riêng; mọi thao tác hủy phiên phải đi qua `StudentSessionService`/tombstone theo đúng ADR-003, không tự viết cơ chế hủy phiên khác; theo đúng route đã có ở `api-contract.md` §2.2 (`POST /auth/password/forgot`, `POST /auth/password/reset`, `PUT /auth/password`).
- QA: kiểm thử thông điệp giống nhau dù tài khoản tồn tại hay không; kiểm thử kịch bản 2 cookie jar (A đăng nhập, B đặt lại mật khẩu bằng OTP cho cùng tài khoản → A phải nhận 401 ở request tiếp theo); kiểm thử race 2 request dùng cùng 1 OTP; kiểm thử throttle/captcha khi gửi yêu cầu quên mật khẩu dồn dập.
