# US-001: Đăng ký và đăng nhập tài khoản học sinh

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là khách truy cập website, tôi muốn đăng ký tài khoản và đăng nhập để có thể mua khóa học và theo dõi quá trình học tập của mình.

## Bối cảnh
VitaminVui là website bán khóa học Toán cho học sinh lớp 6–12. Đây là tính năng nền tảng đầu tiên, chưa có hệ thống tài khoản nào tồn tại. Các vai trò đã xác định trong CLAUDE.md: admin, quản lý trang, Giáo Viên, Học Sinh. Story này chỉ xử lý luồng tự đăng ký của Học Sinh (giáo viên/quản lý trang/admin do admin tạo, xem story quản trị người dùng riêng — ngoài phạm vi story này).

## Business rules
- BR1: Học sinh có thể đăng nhập bằng **email hoặc số điện thoại** + mật khẩu (PO xác nhận có hỗ trợ đăng nhập bằng SĐT). Cả email và số điện thoại đều phải duy nhất trong hệ thống (không trùng giữa các tài khoản).
- BR2: Học sinh bắt buộc chọn "Lớp đang học" (6–12) khi đăng ký để hệ thống gợi ý khóa học phù hợp.
- BR3: Mật khẩu tối thiểu 8 ký tự, không giới hạn ký tự đặc biệt.
- BR4: Tài khoản tạo qua form đăng ký công khai luôn có role mặc định `hoc_sinh`, không thể tự chọn role khác.
- BR5: Thông báo lỗi đăng nhập sai không được tiết lộ email/SĐT có tồn tại hay không (chống dò tài khoản).
- BR6: Form đăng ký có thêm trường "Mã giới thiệu" (không bắt buộc). Mã giới thiệu được lưu lại cùng tài khoản mới tạo; MVP chỉ lưu lại để đối soát, **chưa** có logic tính thưởng/chiết khấu tự động (xem câu hỏi mở về mục đích sử dụng).
- BR7 (PO xác nhận CÓ xác thực email/OTP khi đăng ký): Tài khoản mới tạo ở trạng thái **chưa xác thực** (`email_verified_at = null`). Hệ thống gửi mã OTP/liên kết xác thực qua email (hoặc SMS nếu đăng ký bằng SĐT) ngay sau khi đăng ký. Học sinh phải xác thực thành công trước khi được **mua khóa học** (checkout); vẫn cho phép đăng nhập và duyệt danh mục ở trạng thái chưa xác thực để không cản trở trải nghiệm khám phá sản phẩm. Thời hạn hiệu lực OTP và số lần gửi lại tối đa cụ thể vẫn là câu hỏi mở.
- BR8 (Quyết định của PO): Học sinh khai **ngày sinh** khi đăng ký. Nếu tại thời điểm đăng ký học sinh dưới 18 tuổi, form bắt buộc thêm 2 trường **số điện thoại phụ huynh** và **email phụ huynh** (không bắt buộc cả hai, tối thiểu 1 trong 2) để hệ thống có thể liên hệ khi cần (ví dụ xác nhận thanh toán, hỗ trợ). PO đã xác nhận **không** tạo tài khoản/vai trò "Phụ huynh" riêng — phụ huynh không có tài khoản và không đăng nhập vào hệ thống; đây chỉ là thông tin liên hệ lưu kèm hồ sơ học sinh.

## Acceptance criteria
- AC1: Given khách chưa có tài khoản, When điền họ tên, ngày sinh, email hợp lệ chưa tồn tại, số điện thoại chưa tồn tại, lớp học (6–12), mật khẩu ≥ 8 ký tự và xác nhận mật khẩu khớp rồi submit, Then tài khoản được tạo với role `hoc_sinh`, trạng thái `email_verified_at = null`, hệ thống tự đăng nhập, gửi OTP xác thực, và chuyển về trang chủ kèm lời nhắc xác thực tài khoản.
- AC2: Given email hoặc số điện thoại đã tồn tại trong hệ thống, When đăng ký với thông tin đó, Then hiển thị lỗi tương ứng ("Email đã được sử dụng" / "Số điện thoại đã được sử dụng") ngay tại field và không tạo tài khoản mới.
- AC3: Given tài khoản đã tồn tại, When đăng nhập đúng email **hoặc** số điện thoại cùng mật khẩu, Then vào được hệ thống và tên hiển thị xuất hiện ở header.
- AC4: Given sai thông tin đăng nhập (email/SĐT hoặc mật khẩu), When đăng nhập, Then hiển thị lỗi chung "Thông tin đăng nhập hoặc mật khẩu không đúng".
- AC5: Given mật khẩu và xác nhận mật khẩu không khớp, When submit form đăng ký, Then hiển thị lỗi tại field xác nhận mật khẩu và không tạo tài khoản.
- AC6: Given đăng nhập sai liên tiếp quá số lần cho phép (đề xuất 5 lần/15 phút — chờ PO chốt số cụ thể, xem câu hỏi mở), When thử đăng nhập lần tiếp theo, Then hệ thống tạm khóa và yêu cầu chờ hoặc xác thực bổ sung.
- AC7: Given khách nhập mã giới thiệu hợp lệ (hoặc để trống) khi đăng ký, When submit, Then tài khoản vẫn được tạo bình thường và mã giới thiệu (nếu có) được lưu lại kèm tài khoản.
- AC8: Given tài khoản mới đăng ký nhận được OTP xác thực, When học sinh nhập đúng OTP trong thời hạn hiệu lực, Then `email_verified_at`/`phone_verified_at` được ghi nhận và học sinh có thể mua khóa học bình thường.
- AC9: Given tài khoản chưa xác thực OTP, When học sinh cố vào trang checkout (US-005) để thanh toán, Then hệ thống chặn và yêu cầu xác thực tài khoản trước, kèm nút gửi lại OTP.
- AC10: Given học sinh khai ngày sinh cho thấy dưới 18 tuổi, When submit form đăng ký mà không điền SĐT hoặc email phụ huynh, Then hệ thống báo lỗi yêu cầu bổ sung ít nhất 1 thông tin liên hệ phụ huynh trước khi tạo tài khoản.

## Trường hợp biên & lỗi
- Bỏ trống email/SĐT/mật khẩu/họ tên/lớp học/ngày sinh → báo lỗi từng field, không submit được.
- Email sai định dạng, số điện thoại không đúng định dạng Việt Nam → báo lỗi định dạng.
- Chọn lớp ngoài khoảng 6–12 (nếu can thiệp trực tiếp vào request) → server phải từ chối, không chỉ chặn ở UI.
- Họ tên chứa ký tự đặc biệt/HTML/script → phải được escape khi hiển thị, không gây XSS.
- Đăng ký spam hàng loạt (bot) → cần rate limit hoặc captcha (đề xuất, ngưỡng cụ thể vẫn là câu hỏi mở).
- Tài khoản bị vô hiệu hóa bởi admin (nếu có ở story sau) → đăng nhập phải báo "Tài khoản đã bị khóa" thay vì lỗi sai mật khẩu.
- Mất kết nối CSDL khi tạo tài khoản → hiển thị lỗi hệ thống, không để mất dữ liệu đã nhập trên form.
- Học sinh không nhận được OTP (email vào spam, SMS lỗi) → cần cơ chế gửi lại OTP với giới hạn số lần/khoảng cách thời gian giữa các lần gửi (chống spam gửi OTP).
- Học sinh khai ngày sinh sai/gian dối để né khai báo phụ huynh → chấp nhận là hạn chế đã biết ở MVP (không có cách xác minh tuyệt đối), ghi nhận rủi ro.
- Học sinh sinh nhật đúng ngày đăng ký chuyển từ dưới 18 sang đủ 18 tuổi → tính tuổi tại thời điểm đăng ký theo ngày hiện tại của server.

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Xoá |
|---|---|---|---|---|
| Khách (chưa đăng nhập) | Form đăng ký/đăng nhập | Tài khoản của chính mình | – | – |
| Học Sinh | Thông tin tài khoản của mình | – | Thông tin của mình (story khác) | – |
| Giáo Viên | – | – | – | – |
| Quản lý trang | – | – | – | – |
| Admin | Danh sách tài khoản (story khác) | – | – | – |

## Ảnh hưởng dữ liệu
- Bảng `users`: cần các field `name`, `email` (unique, nullable nếu chỉ đăng ký bằng SĐT — cần xác nhận), `phone` (unique), `password`, `role` (enum: admin, quan_ly_trang, giao_vien, hoc_sinh), `grade_level` (tinyint, 6–12, nullable với role khác học sinh), `date_of_birth` (date), `status` (active/locked), `email_verified_at` (nullable timestamp), `phone_verified_at` (nullable timestamp), `parent_phone` (nullable), `parent_email` (nullable), `referral_code_used` (nullable string), timestamps.
- Bảng `otp_codes` (hoặc tương đương): `id`, `user_id`, `channel` (email/sms), `code_hash`, `expires_at`, `consumed_at`, `attempts`, timestamps — phục vụ AC8/AC9.
- Cân nhắc bảng `login_attempts` hoặc dùng cơ chế throttle sẵn có của Laravel cho AC6.
- Liên quan US-014 (giới hạn 1 thiết bị/1 phiên đăng nhập): luồng đăng nhập ở story này cần phối hợp với cơ chế vô hiệu hóa phiên cũ mô tả ở US-014.

## Ngoài phạm vi
- Đăng nhập bằng Google/Facebook.
- Quên mật khẩu / đặt lại mật khẩu (tách thành US-015).
- Admin tạo tài khoản Giáo Viên/Quản lý trang (tách thành US-016).
- Tài khoản/vai trò Phụ huynh riêng (PO đã xác nhận **không làm** ở MVP): phụ huynh không có tài khoản, không đăng nhập, không tự xem tiến độ con; hệ thống chỉ lưu SĐT/email phụ huynh làm thông tin liên hệ (BR8).
- Logic tính thưởng/chiết khấu cho mã giới thiệu (chỉ lưu dữ liệu ở MVP, xem US-013 về mã giảm giá — cần PO làm rõ 2 cơ chế này có liên quan nhau không).
- Bước đồng ý xử lý dữ liệu cá nhân đầy đủ (checkbox tách riêng, bằng chứng lưu vết) và luồng xác nhận của phụ huynh cho học sinh dưới 18 tuổi (tách thành US-017); quyền xuất/xóa dữ liệu cá nhân của học sinh (tách thành US-018).

## Câu hỏi mở
- [ ] Ngưỡng rate limit/captcha chống bot đăng ký cụ thể là bao nhiêu?
- [ ] Thời hạn hiệu lực OTP xác thực là bao lâu (ví dụ 5–10 phút) và số lần gửi lại tối đa trong bao lâu?
- [ ] Mã giới thiệu (referral code) dùng để làm gì: chỉ lưu vết nguồn giới thiệu, hay có cơ chế thưởng/chiết khấu cho người giới thiệu và/hoặc người được giới thiệu? Có cần validate mã tồn tại trong hệ thống trước khi cho đăng ký không? Có trùng với khái niệm "mã giảm giá" ở US-013 không?
- [ ] Đăng ký bắt buộc cả email và số điện thoại, hay chỉ cần 1 trong 2 (email hoặc SĐT)?

## Ghi chú cho Designer / Dev / QA
- Designer: cần các màn hình đăng ký (kèm ngày sinh, SĐT/email phụ huynh nếu dưới 18 tuổi), xác thực OTP, và đăng nhập (chọn đăng nhập bằng email hoặc SĐT); thêm field "Mã giới thiệu (không bắt buộc)".
- Dev: dùng cơ chế hash mật khẩu và throttle sẵn có của Laravel (Bcrypt, `RateLimiter`); không tự viết logic hash. Phối hợp với US-014 khi hiện thực đăng nhập (vô hiệu hóa phiên cũ). Tách rõ dịch vụ gửi OTP (email/SMS) để dễ thay nhà cung cấp SMS sau này.
- QA: kiểm thử riêng biên lớp học (5 phải lỗi, 6 hợp lệ, 12 hợp lệ, 13 phải lỗi); kiểm thử chống trùng email/SĐT khi đăng ký đồng thời (race condition); kiểm thử tính tuổi biên (17 tuổi 364 ngày vs 18 tuổi); kiểm thử luồng OTP hết hạn/nhập sai nhiều lần.
