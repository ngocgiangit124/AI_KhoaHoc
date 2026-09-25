# US-014: Giới hạn đăng nhập một thiết bị/một phiên tại một thời điểm

**Trạng thái:** Draft
**Ưu tiên:** Should

## User story
Là chủ sở hữu hệ thống (đại diện qua Admin), tôi muốn mỗi tài khoản học sinh chỉ được đăng nhập hoạt động trên 1 thiết bị/phiên tại một thời điểm để hạn chế chia sẻ tài khoản trái phép, bảo vệ doanh thu khóa học; là học sinh, tôi được thông báo rõ ràng khi tài khoản của mình bị đăng nhập ở nơi khác.

## Bối cảnh
Phát sinh từ quyết định của PO ở US-006 (cần giới hạn thiết bị để chống chia sẻ tài khoản khi xem video bài giảng). Đây là quy tắc áp dụng ở tầng phiên đăng nhập (session), liên quan trực tiếp tới luồng đăng nhập ở US-001, nên tách thành story riêng để không làm phình US-001/US-006.

## Business rules
- BR1: Tại một thời điểm, mỗi tài khoản **Học Sinh** chỉ có tối đa 1 phiên đăng nhập hợp lệ (1 thiết bị/trình duyệt).
- BR2: Khi học sinh đăng nhập thành công trên thiết bị/trình duyệt mới, phiên đăng nhập cũ (nếu có) bị vô hiệu hóa ngay lập tức.
- BR3: Thiết bị bị đăng xuất cưỡng bức nhận được thông báo rõ ràng khi thực hiện thao tác tiếp theo (ví dụ tải lại trang hoặc gọi API), không chỉ im lặng văng ra không rõ lý do.
- BR4 (Quyết định của PO): Quy tắc này **chỉ áp dụng cho vai trò Học Sinh** (đối tượng chính mua/dùng khóa học). Giáo Viên, Quản lý trang và Admin **không bị giới hạn** 1 thiết bị/1 phiên — các vai trò quản trị/giảng dạy này được phép đăng nhập đồng thời trên nhiều thiết bị/tab để phục vụ công việc.

## Acceptance criteria
- AC1: Given học sinh đang đăng nhập trên thiết bị A, When học sinh đăng nhập cùng tài khoản trên thiết bị B, Then phiên trên thiết bị A bị vô hiệu hóa ngay, thiết bị B trở thành phiên hoạt động duy nhất.
- AC2: Given phiên trên thiết bị A vừa bị vô hiệu hóa do đăng nhập ở thiết bị B, When thiết bị A thực hiện thao tác tiếp theo (ví dụ chuyển trang, gọi API xem video), Then hệ thống trả về yêu cầu đăng nhập lại kèm thông báo "Tài khoản của bạn đã đăng nhập ở thiết bị khác".
- AC3: Given học sinh chủ động đăng xuất trên thiết bị A, When đăng nhập lại trên thiết bị A, Then không có phiên nào khác bị ảnh hưởng ngoài phiên hiện tại của chính học sinh đó.
- AC4: Given học sinh đang xem video dở trên thiết bị A (US-006) và bị đăng xuất do đăng nhập thiết bị B, When quay lại thiết bị A và đăng nhập lại, Then tiến độ xem đã lưu trước đó không bị mất, học sinh học tiếp bình thường.
- AC5: Given học sinh mất kết nối mạng tạm thời trên thiết bị đang hoạt động (không có đăng nhập mới thực sự xảy ra), When kết nối lại, Then phiên hiện tại vẫn còn hiệu lực, không bị vô hiệu hóa nhầm.

## Trường hợp biên & lỗi
- Học sinh dùng chung 1 thiết bị nhưng 2 trình duyệt khác nhau (ví dụ Chrome và Safari) → tính là 2 phiên khác nhau theo đúng quy tắc (phiên đăng nhập sau vô hiệu hóa phiên trước).
- Cơ chế "remember me"/ghi nhớ đăng nhập (nếu có ở US-001) cũng phải tuân theo quy tắc 1 phiên duy nhất — đăng nhập ghi nhớ ở thiết bị mới vẫn vô hiệu hóa phiên cũ.
- Học sinh bấm đăng nhập nhiều lần liên tiếp rất nhanh trên cùng 1 thiết bị (double submit) → không được coi là "thiết bị mới" tự vô hiệu hóa lẫn nhau gây vòng lặp đăng xuất.
- Lỗi hệ thống khi ghi nhận phiên mới (ví dụ lỗi ghi CSDL) → không được để lọt trường hợp 2 phiên cùng hợp lệ song song (ưu tiên an toàn: nếu không chắc chắn, vô hiệu hóa phiên cũ trước khi xác nhận phiên mới thành công).

## Phân quyền
| Vai trò | Áp dụng giới hạn 1 thiết bị/1 phiên |
|---|---|
| Học Sinh | Có (bắt buộc) |
| Giáo Viên | Không áp dụng (Quyết định của PO) |
| Quản lý trang | Không áp dụng (Quyết định của PO) |
| Admin | Không áp dụng (Quyết định của PO) |

## Ảnh hưởng dữ liệu
- Cần cơ chế lưu phiên đăng nhập hiện tại hợp lệ theo user — ví dụ bảng `user_sessions` (`user_id`, `session_token`/`device_id`, `created_at`, `last_active_at`) hoặc tận dụng bảng `sessions` mặc định của Laravel kết hợp field `users.current_session_id`/`current_token_id`. Chi tiết kỹ thuật cụ thể (dùng Sanctum token, session driver, hay Redis) để `laravel-architect` quyết định.
- Liên quan trực tiếp tới luồng đăng nhập ở US-001 (nơi phiên mới được tạo) và trải nghiệm xem video ở US-006 (nơi phiên bị vô hiệu hóa có thể chặn truy cập giữa chừng).
- Liên quan US-015 (quên/đổi mật khẩu): đổi hoặc đặt lại mật khẩu cũng phải hủy phiên hiện hành theo cùng cơ chế tombstone mô tả ở đây (xem ADR-003).

## Ngoài phạm vi
- Cho học sinh tự quản lý/xem danh sách thiết bị đã đăng nhập và chủ động đăng xuất từ xa (tính năng nâng cao, không thuộc MVP).
- Giới hạn theo địa chỉ IP.
- Áp dụng quy tắc 1 thiết bị/1 phiên cho vai trò Giáo Viên/Quản lý trang/Admin (PO đã xác nhận không áp dụng ở MVP, xem BR4).

## Quyết định của PO
- Quy tắc 1 thiết bị/1 phiên chỉ áp dụng cho Học Sinh; Giáo Viên, Quản lý trang, Admin không bị giới hạn.

## Câu hỏi mở
- [ ] Khi phiên bị vô hiệu hóa, có cần gửi thông báo email/SMS cảnh báo bảo mật cho học sinh không, hay chỉ thông báo trên giao diện là đủ?

## Ghi chú cho Designer / Dev / QA
- Designer: cần thiết kế thông báo/màn hình khi bị đăng xuất cưỡng bức, dùng ngôn ngữ rõ ràng tránh gây hiểu lầm là lỗi hệ thống (ví dụ: "Tài khoản của bạn vừa đăng nhập trên một thiết bị khác. Nếu không phải bạn, vui lòng đổi mật khẩu ngay.").
- Dev: nên tận dụng cơ chế session/token có sẵn của Laravel (Sanctum/Passport) thay vì tự xây dựng cơ chế phiên từ đầu; cân nhắc kỹ khi kết hợp với luồng "ghi nhớ đăng nhập" (nếu có) và với US-001 (đăng nhập bằng email hoặc SĐT).
- QA: kiểm thử đăng nhập đồng thời trên 2 thiết bị thật (không chỉ giả lập), kiểm thử mất mạng tạm thời không làm mất phiên sai, kiểm thử học sinh đang xem video (US-006) bị đăng xuất giữa chừng và học tiếp sau khi đăng nhập lại.
