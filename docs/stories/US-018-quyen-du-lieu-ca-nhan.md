# US-018: Quyền dữ liệu cá nhân — xuất dữ liệu, xóa/ẩn danh tài khoản

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh, tôi muốn tự tải xuống dữ liệu cá nhân của mình và yêu cầu xóa/ẩn danh tài khoản khi không còn muốn sử dụng dịch vụ, để tôi có thể thực hiện quyền kiểm soát dữ liệu cá nhân của bản thân.

## Bối cảnh
Phát sinh từ review bảo mật `docs/security/audit-2026-09-25.md` (phát hiện S7 — High, tuân thủ): hệ thống thu thập và lưu trữ nhiều dữ liệu cá nhân của học sinh (phần lớn là trẻ vị thành niên) và thông tin liên hệ của phụ huynh, nhưng trước đó chưa có cơ chế cho chủ thể dữ liệu tự xem/tải/xóa dữ liệu của mình. Tương ứng task T34 (chức năng tự phục vụ cho học sinh, `/me/data-export`, `/me/account/delete`) và T30 (job tự động dọn tài khoản chưa xác thực) ở `docs/architecture/tasks.md`, dựa trên cột `users.anonymized_at` và service `AccountAnonymizer` đã dự kiến trong `docs/architecture/data-model.md` và `docs/architecture/api-contract.md`.

Story này liên quan chặt tới US-017 (đồng ý xử lý dữ liệu) và US-010 (đơn hàng — dữ liệu chứng từ không được xóa cứng). Nội dung pháp lý cụ thể (nghĩa vụ xóa dữ liệu, thời hạn lưu chứng từ, quyền của phụ huynh đối với dữ liệu con dưới 18 tuổi) **cần pháp chế xác nhận**; story chỉ mô tả cơ chế kỹ thuật theo mặc định an toàn.

## Business rules
- BR1: Học sinh đã đăng nhập có thể yêu cầu xuất toàn bộ dữ liệu cá nhân của **chính mình** dưới dạng file (JSON) qua `GET /me/data-export`, bao gồm tối thiểu: thông tin hồ sơ (họ tên, email, SĐT, ngày sinh, lớp), liên hệ phụ huynh đã khai, lịch sử đơn hàng, lịch sử ghi danh (enrollment), tiến độ học tập, lịch sử đồng ý (`consents`). Danh sách trường đầy đủ — Mặc định an toàn, chờ PO/pháp chế xác nhận.
- BR2: Yêu cầu xóa tài khoản (`POST /me/account/delete`) phải được xác nhận bằng OTP gửi tới email/SĐT đã xác thực của chính học sinh trước khi hoàn tất, để tránh xóa nhầm hoặc bị người khác lợi dụng khi chiếm được phiên tạm thời.
- BR3 (Mặc định an toàn — chờ kế toán/pháp chế xác nhận): "Xóa tài khoản" trong hệ thống thực chất là **ẩn danh hóa** (`users.anonymized_at` được set, ghi đè/xóa các trường PII trực tiếp: họ tên, email, SĐT, ngày sinh, liên hệ phụ huynh), **không xóa cứng** dữ liệu đơn hàng/chứng từ (`orders`, `order_items`, `order_status_logs`, `payment_attempts`, `coupon_usages`) để giữ chứng từ kế toán (US-010).
- BR4: Sau khi tài khoản bị ẩn danh hóa, học sinh không đăng nhập lại được bằng thông tin cũ; việc email/SĐT có được giải phóng để người khác đăng ký lại hay không là câu hỏi mở.
- BR5: Tài khoản học sinh **chưa xác thực OTP** (US-001) và **không có bất kỳ đơn hàng/enrollment nào** sẽ tự động bị **xóa cứng** sau **7 ngày** kể từ khi tạo (job `users:purge-unverified`, tương ứng T30) — đây là cơ chế tự động, không cần thao tác của học sinh. Tài khoản đã có đơn hàng/enrollment (kể cả chưa xác thực) **không** bị job này xóa.
- BR6: Mọi thao tác xuất dữ liệu và xóa/ẩn danh tài khoản đều ghi vào `audit_logs`.
- BR7 (Mặc định an toàn — chờ PO xác nhận): Giới hạn số lần yêu cầu xuất dữ liệu trong 1 khoảng thời gian (ví dụ để tránh lạm dụng tải liên tục gây tải hệ thống) — ngưỡng cụ thể là câu hỏi mở.
- BR8: Phụ huynh **không có tài khoản đăng nhập** vào hệ thống (theo US-001 BR8, US-017), nên **không thể tự thao tác** xuất dữ liệu/xóa tài khoản của con qua giao diện của hệ thống. Yêu cầu từ phụ huynh (nếu có) được tiếp nhận qua kênh hỗ trợ ngoài hệ thống (email/hotline) và do Admin xử lý thay mặt sau khi xác minh — quy trình xác minh cụ thể **cần pháp chế xác nhận**, không tự kết luận ở story này.

## Acceptance criteria
- AC1: Given học sinh đã đăng nhập, When bấm "Tải dữ liệu của tôi" trong trang tài khoản, Then hệ thống tạo và trả về file chứa dữ liệu cá nhân của chính học sinh đó theo phạm vi đã định (BR1), không chứa dữ liệu của bất kỳ học sinh nào khác.
- AC2: Given học sinh muốn xóa tài khoản, When bấm "Xóa tài khoản" và hệ thống gửi OTP xác nhận, Then học sinh phải nhập đúng OTP trong thời hạn hiệu lực mới hoàn tất được yêu cầu xóa.
- AC3: Given học sinh xác nhận đúng OTP xóa tài khoản, When hoàn tất, Then tài khoản chuyển trạng thái đã ẩn danh hóa (`anonymized_at` được set), các trường PII bị ẩn danh hóa, học sinh bị đăng xuất khỏi phiên hiện tại, và không thể đăng nhập lại bằng thông tin cũ.
- AC4: Given tài khoản đã bị ẩn danh hóa, When admin/quản lý trang mở chi tiết đơn hàng liên quan tới tài khoản đó (US-010), Then đơn hàng và chứng từ vẫn còn nguyên vẹn, chỉ thông tin liên hệ học sinh hiển thị đã ẩn danh.
- AC5: Given học sinh nhập sai OTP hoặc để OTP hết hạn khi xác nhận xóa tài khoản, When submit, Then yêu cầu xóa không được thực hiện, tài khoản vẫn hoạt động bình thường.
- AC6: Given một tài khoản học sinh được tạo nhưng chưa xác thực OTP và không có đơn hàng/enrollment nào sau 7 ngày, When job dọn dữ liệu tự động chạy, Then tài khoản bị xóa cứng khỏi hệ thống.
- AC7: Given một tài khoản chưa xác thực nhưng đã có ít nhất 1 đơn hàng hoặc enrollment, When job dọn dữ liệu tự động chạy sau 7 ngày, Then tài khoản **không** bị xóa (giữ lại vì đã có giao dịch).
- AC8: Given thao tác xuất dữ liệu hoặc xóa/ẩn danh tài khoản đã thực hiện, When admin xem màn nhật ký thao tác (US-016), Then thấy đúng 1 bản ghi `audit_logs` ghi nhận actor là chính học sinh, hành động và thời điểm tương ứng.

## Trường hợp biên & lỗi
- Học sinh yêu cầu xuất dữ liệu khi có nhiều bảng liên quan (nhiều đơn hàng, enrollment, tiến độ) → cần thời gian xử lý hợp lý, không được để request treo/timeout; cân nhắc chạy nền tương tự cơ chế xuất báo cáo ở US-010.
- Học sinh xóa tài khoản khi đang có đơn hàng ở trạng thái `pending` (chưa thanh toán xong) → hành vi cụ thể (hủy đơn pending hay giữ nguyên) là câu hỏi mở.
- Học sinh xóa tài khoản khi đang có enrollment `pending_approval` (chờ duyệt khóa miễn phí, US-012) → trạng thái enrollment đó chuyển sang gì là câu hỏi mở.
- Học sinh dưới 18 tuổi tự ý xóa tài khoản mà không có xác nhận của phụ huynh → có cần thêm bước xác nhận phụ huynh cho hành động xóa tài khoản không (liên quan US-017 và pháp chế) là câu hỏi mở.
- Học sinh bấm "Xóa tài khoản" 2 lần liên tiếp (double submit) → không được gây lỗi ẩn danh hóa 2 lần hoặc ghi audit trùng lặp bất thường.
- Mất kết nối giữa lúc xác nhận OTP và lúc hoàn tất ẩn danh hóa → phải đảm bảo tính toàn vẹn trong 1 transaction, không để tài khoản ở trạng thái nửa vời (một phần PII đã xóa, một phần còn).
- Job tự động xóa tài khoản chưa xác thực chạy đúng lúc học sinh đang cố xác thực OTP ở giây cuối cùng của ngày thứ 7 → cần quy tắc rõ ràng về mốc thời gian tính từ `created_at`, không phụ thuộc thời điểm job chạy.

## Phân quyền
| Vai trò | Xuất dữ liệu của mình | Xóa/ẩn danh tài khoản của mình | Xem/xuất dữ liệu của học sinh khác | Ẩn danh hóa tài khoản thay học sinh khác |
|---|---|---|---|---|
| Học Sinh | Có (chỉ của mình) | Có (chỉ của mình) | Không | Không |
| Phụ huynh (không có tài khoản) | Không (chỉ qua kênh hỗ trợ ngoài hệ thống — BR8) | Không (chỉ qua kênh hỗ trợ ngoài hệ thống — BR8) | Không | Không |
| Giáo Viên | – | – | Không | Không |
| Quản lý trang | – | – | Không (chỉ xem đơn hàng đã ẩn danh qua US-010, không phải qua story này) | Không |
| Admin | – | – | Không (chỉ xem đơn hàng đã ẩn danh qua US-010, không phải qua story này) | Không (MVP chưa có công cụ Admin tự ẩn danh hóa thay học sinh) |

## Ảnh hưởng dữ liệu
- Cột `users.anonymized_at` (đã dự kiến trong `data-model.md` §3.1).
- Bảng `otp_codes`: cần bổ sung giá trị `purpose` mới cho xác nhận xóa tài khoản (hiện `data-model.md` mới liệt kê `verify_account`/`reset_password`/`staff_login_mfa`/`parent_consent` — chưa có purpose cho xóa tài khoản, cần Dev/Architect bổ sung, ví dụ `delete_account`).
- Bảng `audit_logs`: bổ sung action cho `user.data_export` và `user.anonymize` (chưa được liệt kê tường minh trong danh sách mẫu hiện có).
- Service `AccountAnonymizer` (đã có khung namespace `Privacy` trong `api-contract.md`) — chạy trong 1 transaction, phối hợp `StudentSessionService`/ADR-003 để hủy phiên sau khi ẩn danh hóa.
- Job `users:purge-unverified` (đã thiết kế ở `data-model.md` §7, task T30) — xóa cứng tài khoản chưa xác thực > 7 ngày, chỉ khi không có FK tham chiếu từ `orders`/`enrollments`.

## Ngoài phạm vi
- Phụ huynh tự thao tác xuất/xóa dữ liệu qua giao diện hệ thống (phụ huynh không có tài khoản — theo US-001, US-017); MVP chỉ có kênh hỗ trợ ngoài hệ thống do Admin xử lý thủ công.
- Xóa cứng toàn bộ dữ liệu bao gồm chứng từ kế toán (không được phép — giữ theo BR3).
- Chỉnh sửa hồ sơ cá nhân (đổi tên/lớp/ngày sinh...) — nếu cần, tách thành story riêng "Xem/sửa hồ sơ cá nhân".
- Xuất dữ liệu ở định dạng khác ngoài JSON (ví dụ PDF trình bày đẹp) — MVP chỉ cần định dạng máy đọc được.
- Công cụ để Admin chủ động ẩn danh hóa thay tài khoản học sinh (chỉ học sinh tự thao tác ở MVP).

## Câu hỏi mở
- [ ] Danh sách trường dữ liệu chính xác cần có trong file xuất (data export) là gì? Có cần bao gồm log truy cập/audit liên quan tới chính học sinh đó không?
- [ ] Enrollment đang `active` của tài khoản bị ẩn danh hóa có bị thu hồi quyền truy cập nội dung ngay không, hay vẫn giữ nguyên vì đã thanh toán?
- [ ] Đơn hàng `pending` hoặc enrollment `pending_approval` của tài khoản chuẩn bị bị xóa/ẩn danh thì xử lý thế nào (tự hủy, hay chặn không cho xóa tài khoản tới khi các giao dịch dở dang kết thúc)?
- [ ] Email/SĐT của tài khoản đã ẩn danh hóa có được giải phóng để người khác đăng ký lại không, hay khóa vĩnh viễn?
- [ ] Học sinh dưới 18 tuổi tự xóa tài khoản có cần thêm xác nhận của phụ huynh không (liên quan US-017 và pháp chế)?
- [ ] Yêu cầu từ phụ huynh (xuất/xóa dữ liệu của con) được tiếp nhận và xác minh qua quy trình cụ thể nào — **cần pháp chế xác nhận** nghĩa vụ và cách xác minh danh tính phụ huynh.
- [ ] Có giới hạn số lần yêu cầu xuất dữ liệu/ngày không, ngưỡng cụ thể là bao nhiêu?

## Ghi chú cho Designer / Dev / QA
- Designer: màn "Quyền dữ liệu cá nhân" trong trang tài khoản học sinh gồm nút "Tải dữ liệu của tôi" và nút "Xóa tài khoản" dẫn tới bước xác nhận OTP kèm cảnh báo rõ hậu quả (không đăng nhập lại được, dữ liệu bị ẩn danh) trước khi cho xác nhận cuối cùng.
- Dev: tái sử dụng cơ chế OTP đã có (thêm `purpose` mới, ví dụ `delete_account`) thay vì viết luồng xác thực riêng; `AccountAnonymizer` chạy trong 1 transaction, ghi audit trước khi hủy phiên; phối hợp `StudentSessionService`/ADR-003 để hủy toàn bộ phiên sau khi ẩn danh hóa; job `users:purge-unverified` (T30) chỉ xóa tài khoản không có bất kỳ tham chiếu FK nào từ `orders`/`enrollments`.
- QA: kiểm thử file xuất dữ liệu chỉ chứa đúng dữ liệu của người yêu cầu (không lẫn học sinh khác); kiểm thử tài khoản sau ẩn danh hóa không đăng nhập lại được bằng email/SĐT cũ; kiểm thử đơn hàng liên quan vẫn còn nguyên sau khi tài khoản bị ẩn danh; kiểm thử job tự động xóa tài khoản chưa xác thực không đụng tới tài khoản đã có đơn hàng/enrollment.
