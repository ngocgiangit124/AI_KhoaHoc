# US-016: Quản lý tài khoản staff (Giáo Viên / Quản lý trang / Admin) và xác thực 2 lớp cho Admin, Quản lý trang

**Trạng thái:** Draft
**Ưu tiên:** Should

## User story
Là Admin, tôi muốn tạo, khóa/mở khóa và đặt lại mật khẩu cho các tài khoản Giáo Viên/Quản lý trang/Admin, đồng thời xem được nhật ký các thao tác nhạy cảm, để quản lý nhân sự nội bộ an toàn và hạn chế rủi ro lộ dữ liệu cá nhân của học sinh (phần lớn là trẻ vị thành niên).

## Bối cảnh
Phát sinh từ review bảo mật `docs/security/audit-2026-09-25.md` (phát hiện S15): trước đó thiết kế chưa có quy trình cấp/thu hồi tài khoản staff (chỉ có seeder), và Admin/Quản lý trang — nhóm truy cập được PII hàng loạt của học sinh — chỉ được bảo vệ bằng mật khẩu, không có xác thực 2 lớp. Tương ứng task T28 (đăng nhập quản trị, MFA) và T33 (màn quản lý tài khoản staff) ở `docs/architecture/tasks.md`, dựa trên ma trận quyền và gate `manage-system` ở `docs/adr/ADR-004-nen-tang-api-nextjs-sanctum-phan-quyen.md` §3.

Trước khi có màn quản lý này, việc tạo/khóa/mở khóa tài khoản staff đầu tiên chạy tạm bằng CLI `php artisan staff:create|lock|unlock` (chỉ chạy trên server, có ghi audit) — story này bổ sung giao diện quản trị để vận hành hằng ngày, thay vì phải nhờ Dev chạy lệnh.

## Business rules
- BR1: Chỉ **Admin** (gate `manage-system`) được tạo/khóa/mở khóa/đặt lại mật khẩu tài khoản staff và xem màn quản lý tài khoản staff; **Quản lý trang và Giáo Viên không có quyền này** (theo ma trận quyền ADR-004 §3 — khác với phân hệ đơn hàng ở US-010 nơi Quản lý trang ngang quyền Admin).
- BR2: Tài khoản staff mới tạo (vai trò Giáo Viên/Quản lý trang/Admin) có mật khẩu ngẫu nhiên do hệ thống sinh, `must_change_password = true`, buộc đổi mật khẩu ở lần đăng nhập đầu tiên (`PUT /admin/auth/password`) trước khi dùng được bất kỳ chức năng nào khác.
- BR3 (Mặc định an toàn — chờ PO xác nhận): Admin và Quản lý trang bắt buộc xác thực OTP gửi qua email mỗi lần đăng nhập (`staff_login_mfa`, cấu hình bởi feature flag `FEATURE_STAFF_MFA`), vì đây là 2 vai trò xuất được PII hàng loạt của học sinh (S15). Giáo Viên không bắt buộc MFA nhưng nhận email cảnh báo khi đăng nhập từ thiết bị mới.
- BR4: Khóa tài khoản staff (`status = locked`) có hiệu lực ngay: phiên hiện tại của người bị khóa (nếu có) bị từ chối truy cập (`403 ACCOUNT_LOCKED`) ở request tiếp theo, và không đăng nhập lại được tới khi Admin mở khóa.
- BR5 (Mặc định an toàn — chờ PO xác nhận): Admin không được tự khóa chính tài khoản của mình.
- BR6 (Mặc định an toàn — chờ PO xác nhận): Hệ thống luôn phải còn tối thiểu 1 tài khoản Admin đang `active` — chặn thao tác khóa (hoặc đổi vai trò) làm mất tài khoản Admin `active` cuối cùng.
- BR7: Mọi thao tác tạo/khóa/mở khóa/đặt lại mật khẩu tài khoản staff đều ghi vào `audit_logs` (actor, action, subject, thời điểm); không bao giờ ghi mật khẩu (cũ hoặc mới) vào log/audit.
- BR8: Màn xem `audit_logs` chỉ hỗ trợ **xem** (read-only) — không có API sửa/xóa bản ghi audit (đúng thiết kế "chỉ ghi thêm" ở `data-model.md` §3.1).
- BR9: Email/số điện thoại của tài khoản staff mới tạo phải duy nhất trong toàn hệ thống `users` (dùng chung ràng buộc unique với tài khoản Học Sinh).
- BR10: Đặt lại mật khẩu cho 1 staff bởi Admin sẽ hủy toàn bộ phiên hiện tại của staff đó (theo Sanctum `AuthenticateSession` — ADR-003) và đặt lại `must_change_password = true`.

## Acceptance criteria
- AC1: Given Admin đang ở màn quản lý tài khoản staff, When tạo mới tài khoản với họ tên, email hợp lệ chưa tồn tại và vai trò (`giao_vien`/`quan_ly_trang`/`admin`), Then tài khoản được tạo ở trạng thái `active`, `must_change_password = true`, mật khẩu khởi tạo do hệ thống sinh ngẫu nhiên, và bản ghi `audit_logs` (`user.create`) được ghi nhận.
- AC2: Given email đã tồn tại trong hệ thống (bất kể vai trò nào), When Admin tạo tài khoản staff mới với email đó, Then hệ thống báo lỗi "Email đã được sử dụng" tại field tương ứng và không tạo tài khoản.
- AC3: Given danh sách tài khoản staff, When Admin bấm "Khóa tài khoản" với 1 tài khoản Giáo Viên/Quản lý trang/Admin khác, Then `status` chuyển `locked`, tài khoản đó bị từ chối truy cập ở request kế tiếp, và ghi `audit_logs` (`user.lock`).
- AC4: Given Admin đang thao tác trên chính tài khoản của mình, When bấm "Khóa tài khoản" cho chính mình, Then hệ thống từ chối thao tác kèm thông báo rõ ràng, không khóa tài khoản (BR5).
- AC5: Given hệ thống chỉ còn đúng 1 tài khoản Admin đang `active`, When một Admin khác (hoặc chính người đó qua kênh khác) thao tác khóa tài khoản Admin cuối cùng này, Then hệ thống từ chối và báo lỗi rõ ràng (BR6).
- AC6: Given 1 tài khoản staff đang bị khóa, When Admin bấm "Mở khóa", Then `status` chuyển lại `active`, staff đăng nhập lại được bình thường, ghi `audit_logs` (`user.unlock`).
- AC7: Given Admin cần cấp lại mật khẩu cho 1 staff (staff quên mật khẩu, chưa có tự phục vụ như US-015 dành cho học sinh), When Admin bấm "Đặt lại mật khẩu" và xác nhận, Then hệ thống sinh mật khẩu ngẫu nhiên mới, đặt `must_change_password = true`, hủy phiên hiện tại của staff đó, và ghi `audit_logs`.
- AC8: Given Admin hoặc Quản lý trang đăng nhập đúng mật khẩu tại `admin.vitaminvui.vn`, When hệ thống yêu cầu MFA (BR3), Then phải nhập đúng OTP gửi qua email trong thời hạn hiệu lực mới được cấp phiên đầy đủ; sai OTP hoặc hết hạn thì không vào được hệ thống, ghi `audit_logs` (`staff.mfa_failed`) khi sai.
- AC9: Given Admin đang xem màn nhật ký thao tác (audit log), When lọc theo hành động/khoảng thời gian/người thực hiện, Then chỉ hiển thị các bản ghi khớp điều kiện lọc, và giao diện không có bất kỳ nút sửa/xóa nào cho các bản ghi đó.
- AC10: Given Giáo Viên đăng nhập thành công từ một thiết bị/trình duyệt mới, When đăng nhập hoàn tất, Then hệ thống gửi email cảnh báo thiết bị mới (không chặn đăng nhập, chỉ cảnh báo).

## Trường hợp biên & lỗi
- Admin cố tạo tài khoản với vai trò `hoc_sinh` qua màn quản lý staff → phải bị chặn (màn này chỉ tạo được 3 vai trò staff; học sinh chỉ tự đăng ký theo US-001).
- Admin bấm "Khóa"/"Đặt lại mật khẩu" 2 lần liên tiếp rất nhanh (double submit) → không được gây lỗi trạng thái không nhất quán hoặc ghi audit trùng lặp bất thường.
- Mất kết nối khi đang tạo tài khoản staff mới → không được tạo trùng khi Admin bấm lại (ràng buộc unique email/SĐT xử lý đúng khi race).
- Staff bị khóa đang có phiên hoạt động (đang thao tác dở, ví dụ đang soạn khóa học) → bị từ chối truy cập ngay ở request tiếp theo, giao diện hiển thị thông báo rõ ràng (không phải lỗi hệ thống chung chung).
- OTP MFA của Admin/Quản lý trang bị nhập sai nhiều lần → áp dụng cùng giới hạn OTP chung của hệ thống (5 lần/phút, 20 lần/ngày theo US-001 BR7/S9), ghi `audit_logs` (`staff.mfa_failed`).
- Xóa cứng tài khoản staff → không hỗ trợ ở MVP (chỉ khóa, xem "Ngoài phạm vi").
- Danh sách `audit_logs` tăng trưởng lớn theo thời gian → đã có job dọn dữ liệu sau 24 tháng (T30, ngoài phạm vi story này), nhưng màn xem audit log cần phân trang để không bị chậm khi dữ liệu lớn.

## Phân quyền
| Vai trò | Xem danh sách staff | Tạo tài khoản staff | Khóa/Mở khóa | Đặt lại mật khẩu staff | Xem audit log |
|---|---|---|---|---|---|
| Admin | Có | Có | Có | Có | Có |
| Quản lý trang | Không | Không | Không | Không | Không |
| Giáo Viên | Không | Không | Không | Không | Không |
| Học Sinh | Không | Không | Không | Không | Không |

## Ảnh hưởng dữ liệu
- Bảng `users`: dùng lại các cột đã có (`role`, `status`, `must_change_password`, `password_changed_at`) — không cần migration mới.
- Bảng `audit_logs`: bổ sung các giá trị `action` chưa được liệt kê đầy đủ trong `data-model.md` §3.1 (hiện chỉ có `user.lock`): cần thêm `user.create` (staff), `user.unlock`, `staff.password_reset` — Dev/Architect xác nhận khi hiện thực.
- Dùng lại cơ chế OTP (`purpose = staff_login_mfa`) đã thiết kế trong `otp_codes`.
- Dùng lại cơ chế hủy phiên staff qua Sanctum `AuthenticateSession` (host `admin-api`) đã mô tả ở ADR-003/ADR-004, không tạo bảng phiên riêng.

## Ngoài phạm vi
- Tự đăng ký làm staff (không có form công khai; chỉ Admin tạo qua màn này hoặc CLI).
- Xóa cứng tài khoản staff.
- Phân quyền chi tiết hơn 4 vai trò cố định hiện có (ví dụ quyền tùy biến theo từng người) — ngoài phạm vi theo ADR-004 (4 vai trò cố định, thêm vai trò mới cần ADR).
- Xác thực 2 lớp bằng ứng dụng TOTP (Google Authenticator...) — MFA hiện tại chỉ dùng OTP email.
- Quy trình offboarding tự động (thu hồi quyền truy cập các hệ thống khác ngoài VitaminVui).
- Quản lý mật khẩu/tài khoản của Học Sinh (thuộc US-001 và US-015).

## Câu hỏi mở
- [ ] Khi Admin tạo tài khoản staff mới, mật khẩu khởi tạo được gửi cho staff qua kênh nào (email tự động tới địa chỉ vừa tạo, hay chỉ hiển thị một lần cho Admin để tự gửi thủ công)?
- [ ] Có giới hạn số lượng tài khoản Admin được tạo cùng lúc không, hay không giới hạn?
- [ ] MFA bằng OTP email cho Admin/Quản lý trang có bắt buộc bật ngay từ ngày go-live MVP hay có thể tạm tắt bằng feature flag `FEATURE_STAFF_MFA` ở giai đoạn đầu rồi bật sau?
- [ ] Có cần quy trình offboarding checklist (ví dụ nhắc thu hồi quyền truy cập tài liệu/công cụ khác ngoài hệ thống) hiển thị khi khóa tài khoản không, hay chỉ cần khóa trong hệ thống này là đủ?
- [ ] Quản lý trang có được xem (không sửa) danh sách tài khoản staff/audit log không, hay hoàn toàn không thấy màn này như mặc định đang áp dụng (BR1)?

## Ghi chú cho Designer / Dev / QA
- Designer: màn danh sách tài khoản staff (lọc theo vai trò/trạng thái), form tạo mới (chọn vai trò trong 3 lựa chọn), modal xác nhận cho từng thao tác nhạy cảm (khóa/mở khóa/đặt lại mật khẩu) có cảnh báo hậu quả rõ ràng; màn đăng nhập quản trị có bước nhập OTP MFA (phối hợp T28) và màn bắt buộc đổi mật khẩu lần đầu; màn xem audit log dạng bảng có bộ lọc, không có nút sửa/xóa.
- Dev: dùng Gate `manage-system` (chỉ admin) đã định nghĩa ở ADR-004 §3; ghi audit qua `AuditLogger` đã thiết kế sẵn, không viết Observer riêng; đặt lại/tạo mật khẩu dùng `must_change_password=true` + tái sử dụng `PUT /admin/auth/password` đã có ở T28; MFA dùng OTP `purpose=staff_login_mfa` đã có trong `otp_codes`; API quản lý tài khoản staff (tạo/khóa/mở khóa/đặt lại mật khẩu) tương ứng CLI `staff:create|lock|unlock` đã có, đảm bảo cùng logic nghiệp vụ (Service dùng chung, không viết 2 lần).
- QA: kiểm thử Admin không tự khóa được chính mình; kiểm thử không thể khóa tài khoản Admin `active` cuối cùng; kiểm thử staff bị khóa giữa phiên bị từ chối truy cập ngay ở request tiếp theo; kiểm thử MFA sai OTP nhiều lần bị áp dụng đúng giới hạn OTP chung; kiểm thử Quản lý trang/Giáo Viên gọi thẳng API quản lý staff (bỏ qua UI) → 403.
