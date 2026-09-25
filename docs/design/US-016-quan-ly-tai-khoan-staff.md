# Đặc tả UX — US-016: Đăng nhập quản trị (MFA) & Quản lý tài khoản staff

Xem thêm quy ước chung tại `docs/design/design-system.md`. App `admin` (origin riêng `admin.vitaminvui.vn`). Bao gồm 2 phần: (A) đăng nhập quản trị + MFA + buộc đổi mật khẩu lần đầu — áp dụng cho **mọi** staff (Admin/Quản lý trang/Giáo viên); (B) quản lý tài khoản staff + xem nhật ký thao tác — chỉ **Admin**.

## 1. Luồng người dùng

```
Phần A — Đăng nhập quản trị (mọi staff)
admin.vitaminvui.vn/dang-nhap → nhập email + mật khẩu → submit
  → Vai trò hoc_sinh (nhầm cổng) → lỗi "Vui lòng đăng nhập tại trang dành cho bạn" (WRONG_PORTAL)
  → Sai email/mật khẩu → lỗi chung "Thông tin đăng nhập hoặc mật khẩu không đúng"
  → Đúng, vai trò Admin/Quản lý trang → màn MFA (nhập OTP gửi qua email) → đúng OTP → tiếp tục
  → Đúng, vai trò Giáo viên → vào thẳng (không MFA); nếu là thiết bị mới, hệ thống âm thầm gửi email cảnh báo (không có màn riêng)
  → (must_change_password = true, tài khoản mới tạo hoặc vừa được Admin đặt lại mật khẩu) → buộc màn "Đổi mật khẩu lần đầu", không vào được chức năng khác tới khi đổi xong
  → Vào /quan-tri/khoa-hoc (trang mặc định sau đăng nhập)

Phần B — Quản lý tài khoản staff (chỉ Admin)
/quan-tri/tai-khoan → danh sách staff (lọc vai trò/trạng thái)
  → "+ Tạo tài khoản" → điền họ tên, email, vai trò → tạo → hiện mật khẩu khởi tạo 1 lần (sao chép, gửi thủ công)
  → Bấm "Khóa" 1 tài khoản → modal xác nhận → khóa → tài khoản đó bị từ chối truy cập ở request tiếp theo
  → Bấm "Mở khóa" → mở lại
  → Bấm "Đặt lại mật khẩu" → modal xác nhận → sinh mật khẩu mới, hiện 1 lần, huỷ phiên hiện tại của staff đó
/quan-tri/nhat-ky → xem nhật ký thao tác (audit log), lọc theo hành động/thời gian/người thực hiện — chỉ đọc
```

## 2. Màn hình

### 2.1 `admin.vitaminvui.vn/dang-nhap` — Đăng nhập quản trị
**Bố cục:** card căn giữa max-width 420px, tương tự `/dang-nhap` của web học sinh nhưng branding "Quản trị VitaminVui" để phân biệt rõ đang ở đúng cổng.
- Tiêu đề "Đăng nhập quản trị"
- Email * (`<TextInput>`)
- Mật khẩu * (`<PasswordInput>`)
- Nút "Đăng nhập" (`<Button loading>` khi submit)
- **Không có** liên kết "Đăng ký" (staff không tự đăng ký); có liên kết nhỏ "Quên mật khẩu? Liên hệ Admin" (US-016 chưa có tự phục vụ quên mật khẩu cho staff — ngoài phạm vi, xem US-015 mục "Ngoài phạm vi")

### 2.2 Màn MFA (`<MfaOtpForm>`) — chỉ Admin/Quản lý trang
**Bố cục:** card căn giữa, giống `/xac-thuc-otp` bên web học sinh.
- Icon khiên bảo mật, tiêu đề "Xác thực 2 lớp"
- Mô tả: "Vì tài khoản của bạn có quyền truy cập dữ liệu học sinh, chúng tôi đã gửi mã xác nhận tới email {email đã che}."
- `<OtpInput length="6">`
- `<Countdown>` hiệu lực + nút "Gửi lại mã" (cooldown)
- Nút "Xác nhận"
- Link nhỏ "Đăng nhập bằng tài khoản khác" (huỷ phiên chờ MFA, quay lại 2.1)

### 2.3 Màn buộc đổi mật khẩu lần đầu (`<ForcePasswordChangeForm>`)
**Bố cục:** card căn giữa, không có menu/sidebar xung quanh (chặn toàn bộ tới khi đổi xong).
- Tiêu đề "Đổi mật khẩu để tiếp tục"
- Mô tả: "Đây là lần đăng nhập đầu tiên (hoặc mật khẩu của bạn vừa được Admin đặt lại). Vui lòng đặt mật khẩu mới trước khi sử dụng hệ thống."
- Mật khẩu mới * / Xác nhận mật khẩu mới * (`<PasswordInput>`)
- Nút "Đặt mật khẩu mới và tiếp tục"
- Không có nút "Bỏ qua"/"Để sau"

### 2.4 `/quan-tri/tai-khoan` — Danh sách tài khoản staff (chỉ Admin)
**Bố cục:** `<AdminSidebar>` + nội dung chính. Thanh filter: dropdown Vai trò (Tất cả/Admin/Quản lý trang/Giáo viên), dropdown Trạng thái (Tất cả/Đang hoạt động/Đã khóa), ô tìm theo tên/email. Nút "+ Tạo tài khoản" góc phải.

`<DataTable>` cột: Họ tên | Email | Vai trò (`<Badge>`) | Trạng thái (`<StatusPill>`: Đang hoạt động/Đã khóa) | Lần đăng nhập gần nhất | Thao tác (Khóa/Mở khóa · Đặt lại mật khẩu).
- Với chính tài khoản đang đăng nhập: nút "Khóa" bị ẩn/disabled kèm tooltip "Không thể tự khóa tài khoản của chính mình" (BR5, AC4).
- Với Admin `active` cuối cùng: nút "Khóa" disabled kèm tooltip "Cần giữ lại ít nhất 1 tài khoản Admin đang hoạt động" (BR6, AC5).

### 2.5 Modal "Tạo tài khoản staff mới"
- Họ và tên * (`<TextInput>`)
- Email * (`<TextInput>`) — lỗi "Email đã được sử dụng" nếu trùng (AC2)
- Vai trò * (`<Select>`: Giáo viên / Quản lý trang / Admin — **không có** lựa chọn "Học sinh")
- Nút "Huỷ" / "Tạo tài khoản"
- Sau khi tạo thành công → chuyển sang **modal "Mật khẩu khởi tạo"**: hiển thị mật khẩu ngẫu nhiên dạng `monospace`, nút "Sao chép", cảnh báo `<Alert variant="warning">`: "Mật khẩu này chỉ hiển thị 1 lần. Vui lòng gửi cho {tên} qua kênh an toàn và yêu cầu họ đổi mật khẩu ngay khi đăng nhập lần đầu." Nút "Đã sao chép, đóng".

### 2.6 Modal xác nhận Khóa / Mở khóa / Đặt lại mật khẩu (`<ConfirmModal>`)
| Hành động | Tiêu đề | Mô tả hậu quả | Nút xác nhận |
|---|---|---|---|
| Khóa tài khoản | "Khóa tài khoản {tên}?" | "Tài khoản này sẽ không đăng nhập được và bị từ chối truy cập ngay ở lượt thao tác tiếp theo (nếu đang có phiên hoạt động), cho tới khi được mở khóa lại." | "Khóa tài khoản" (danger) |
| Mở khóa tài khoản | "Mở khóa tài khoản {tên}?" | "Tài khoản này sẽ đăng nhập lại được bình thường." | "Mở khóa" (primary) |
| Đặt lại mật khẩu | "Đặt lại mật khẩu cho {tên}?" | "Hệ thống sẽ sinh mật khẩu mới, huỷ phiên đăng nhập hiện tại của {tên} (nếu có) và buộc đổi mật khẩu ở lần đăng nhập kế tiếp." | "Đặt lại mật khẩu" (danger) — sau khi xác nhận, hiện lại modal "Mật khẩu khởi tạo" như mục 2.5 |

### 2.7 `/quan-tri/nhat-ky` — Nhật ký thao tác (Audit log, chỉ Admin, chỉ đọc)
**Bố cục:** thanh filter: khoảng thời gian (`<DateRangePicker>`), dropdown "Hành động" (đăng nhập, khóa/mở khóa, tạo tài khoản, đặt lại mật khẩu, xuất bản khóa học, hoàn tiền, xuất file...), ô tìm theo người thực hiện.

`<AuditLogTable>` cột: Thời điểm | Người thực hiện (tên + vai trò) | Hành động | Đối tượng (loại + mã/tên) | IP | Ghi chú (rút gọn `changes`, có thể mở rộng xem chi tiết dạng JSON đọc được). **Không có cột/nút thao tác nào (không sửa, không xoá)** — đúng BR8.

## 3. Bảng trạng thái UI & thông điệp

| Màn hình | Trạng thái | Thông điệp |
|---|---|---|
| Đăng nhập quản trị | Sai email/mật khẩu | "Thông tin đăng nhập hoặc mật khẩu không đúng" |
| Đăng nhập quản trị | Sai cổng (học sinh) | "Vui lòng đăng nhập tại trang dành cho bạn." |
| Đăng nhập quản trị | Origin không hợp lệ (gọi thẳng API từ nơi khác) | Không có màn UI (bị chặn ở tầng mạng/CORS trước khi tới form) |
| Đăng nhập quản trị | Tài khoản bị khóa | "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin." |
| MFA | Sai OTP | "Mã xác nhận không đúng, vui lòng thử lại." (ghi `audit_logs` `staff.mfa_failed`) |
| MFA | Hết hạn | "Mã xác nhận đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới." |
| MFA | Vượt giới hạn sai | "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau." |
| Đổi mật khẩu lần đầu | Thành công | Chuyển thẳng vào `/quan-tri/khoa-hoc`, `<Toast variant="success">` "Đổi mật khẩu thành công" |
| Danh sách staff | Trùng email khi tạo (AC2) | "Email đã được sử dụng" dưới field email |
| Danh sách staff | Tạo vai trò `hoc_sinh` | Không thể xảy ra — `<Select>` chỉ có 3 lựa chọn staff |
| Danh sách staff | Tự khóa chính mình (AC4) | Nút disabled + tooltip, không có API nào gọi được từ UI |
| Danh sách staff | Khóa Admin `active` cuối cùng (AC5) | `<Alert variant="danger">` trong modal: "Không thể khóa vì đây là tài khoản Admin đang hoạt động duy nhất." |
| Danh sách staff | Khóa/mở khóa/đặt lại mật khẩu thành công | `<Toast variant="success">` tương ứng + `<StatusPill>` cập nhật ngay |
| Danh sách staff | Double-submit khóa/đặt lại mật khẩu | Nút trong modal chuyển `loading` ngay khi bấm, disabled tới khi có phản hồi — chặn double click |
| Danh sách staff | Đang tải | `<Skeleton variant="table-row">` |
| Nhật ký thao tác | Không có bản ghi khớp filter | `<EmptyState>` "Không tìm thấy nhật ký phù hợp với bộ lọc" |
| Nhật ký thao tác | Đang tải | `<Skeleton variant="table-row">` |
| Không có quyền (QLT/GV vào `/quan-tri/tai-khoan` hoặc `/quan-tri/nhat-ky`) | Trang 403 chuẩn: "Bạn không có quyền truy cập trang này." | |
| Staff bị khóa giữa phiên đang thao tác | Toàn trang chuyển overlay 403: "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin." (không phải lỗi hệ thống chung chung) | |
| Idle quá 120 phút / quá 12 giờ | `401 STAFF_IDLE_TIMEOUT` → chuyển `admin.vitaminvui.vn/dang-nhap` kèm thông báo "Phiên làm việc đã hết hạn do không hoạt động. Vui lòng đăng nhập lại." | |
| Giáo viên đăng nhập thiết bị mới (AC10) | Không có màn UI riêng — chỉ gửi email cảnh báo nền, đăng nhập vẫn tiếp tục bình thường | |

## 4. Component React dùng/tạo mới
- `<TextInput>`, `<PasswordInput>`, `<OtpInput>`, `<Countdown>`, `<Button>`, `<Alert>`, `<Toast>`, `<DataTable>`, `<StatusPill>`, `<Badge>`, `<ConfirmModal>`, `<EmptyState>`, `<Skeleton>`, `<DateRangePicker>` (dùng lại — `packages/ui`)
- `<MfaOtpForm>` (mới — `apps/admin`, mục 2.2)
- `<ForcePasswordChangeForm>` (mới — `apps/admin`, mục 2.3, dùng lại cho cả "đổi mật khẩu lần đầu" và sau khi Admin đặt lại mật khẩu cho staff khác đăng nhập lần tới)
- `<AuditLogTable>` (mới — `packages/ui`, vì có thể tái dùng nếu sau này thêm màn audit khác)

## 5. Điểm cần PO duyệt
- MFA bắt buộc cho Admin/Quản lý trang ngay từ go-live hay tắt tạm bằng `FEATURE_STAFF_MFA` rồi bật sau (câu hỏi mở của US-016) — mockup hiện thiết kế MFA luôn bật.
- Kênh gửi mật khẩu khởi tạo cho staff mới: mockup chọn phương án "hiển thị 1 lần cho Admin tự gửi" (an toàn hơn, tránh phụ thuộc email đến đúng người) — cần PO xác nhận có đổi sang gửi email tự động không.
- Có cần quy trình offboarding checklist khi khóa tài khoản không (câu hỏi mở của US-016) — hiện chưa thiết kế, chỉ có khóa đơn thuần.
- Quản lý trang có được xem (không sửa) danh sách staff/audit log không, hay hoàn toàn ẩn như mặc định đang áp dụng (BR1, câu hỏi mở của US-016) — mockup hiện ẩn hoàn toàn (403).
