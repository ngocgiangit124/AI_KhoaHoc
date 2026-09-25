# Đặc tả UX — US-018: Quyền dữ liệu cá nhân (xuất dữ liệu, xóa/ẩn danh tài khoản)

Xem thêm quy ước chung tại `docs/design/design-system.md`. Chỉ áp dụng vai trò **Học Sinh**, app `web`. Chỉ mô tả thao tác của **chính học sinh** với dữ liệu của mình — phụ huynh không có tài khoản, không thao tác qua giao diện này (BR8, ngoài phạm vi).

## 1. Luồng người dùng

```
Học sinh đã đăng nhập → /tai-khoan/quyen-du-lieu-ca-nhan
  → Xem trạng thái đồng ý hiện tại (liên kết US-017: điều khoản, chính sách dữ liệu, đồng ý phụ huynh nếu có)
  → Bấm "Tải dữ liệu của tôi" → xử lý → tải file JSON chứa dữ liệu cá nhân của chính mình
  → Bấm "Xóa tài khoản" → modal cảnh báo hậu quả → xác nhận muốn tiếp tục
      → hệ thống gửi OTP tới email/SĐT đã xác thực → nhập đúng OTP trong thời hạn
      → hoàn tất: tài khoản bị ẩn danh hoá, đăng xuất ngay, không đăng nhập lại được bằng thông tin cũ
      → sai/hết hạn OTP → không đổi gì, tài khoản vẫn hoạt động bình thường
```

## 2. Màn hình

### 2.1 `/tai-khoan/quyen-du-lieu-ca-nhan` — Quyền dữ liệu cá nhân
**Bố cục:** nằm trong layout trang tài khoản (cùng nhóm tab với "Đổi mật khẩu" — US-015), chia 3 khối:

**Khối 1 — Trạng thái đồng ý (liên kết US-017):**
- "Điều khoản sử dụng" — Đã đồng ý (phiên bản {policy_version}, ngày {granted_at})
- "Chính sách xử lý dữ liệu cá nhân" — Đã đồng ý (phiên bản {policy_version}, ngày {granted_at})
- Nếu dưới 18 tuổi: dòng "Xác nhận của phụ huynh" — `<StatusPill>` theo `parent_consent_status` (Đang chờ/Đã xác nhận/Đã rút lại) — chỉ hiển thị, không có hành động sửa ở đây (hành động "gửi lại email" đã có ở màn chặn checkout, US-001 mục 2.5)

**Khối 2 — Tải dữ liệu của tôi:**
- Mô tả: "Bạn có thể tải toàn bộ dữ liệu cá nhân mà VitaminVui đang lưu về bạn, bao gồm hồ sơ, lịch sử đơn hàng, khóa học đã ghi danh, tiến độ học tập và lịch sử đồng ý."
- Nút "Tải dữ liệu của tôi" (`variant="secondary"`)
- Khi bấm: nút chuyển `loading` "Đang chuẩn bị dữ liệu..."; nếu xử lý nhanh → tự tải file `vitaminvui-du-lieu-ca-nhan-{ngày}.json`; nếu dữ liệu lớn/cần chạy nền (quyết định kỹ thuật của Dev — xem mục 5), đổi sang thông báo "Chúng tôi đang chuẩn bị file, hệ thống sẽ tự tải xuống khi xong. Bạn có thể tiếp tục dùng các trang khác."

**Khối 3 — Xóa tài khoản (vùng nguy hiểm, tách biệt rõ):**
- Khung viền `rose-200`, tiêu đề "Xóa tài khoản" màu `rose-700`
- Mô tả: "Xóa tài khoản sẽ ẩn danh hóa toàn bộ thông tin cá nhân của bạn (họ tên, email, số điện thoại, ngày sinh, liên hệ phụ huynh). Bạn sẽ không thể đăng nhập lại bằng thông tin hiện tại. Lịch sử đơn hàng vẫn được lưu lại theo quy định kế toán nhưng không còn gắn với thông tin cá nhân của bạn. Hành động này không thể hoàn tác."
- Nút "Xóa tài khoản" (`variant="danger"`)

### 2.2 Modal cảnh báo hậu quả (bước 1, trước khi gửi OTP)
`<ConfirmModal variant="danger">`:
- Tiêu đề: "Bạn chắc chắn muốn xóa tài khoản?"
- Danh sách gạch đầu dòng hậu quả (nhắc lại rõ ràng, không chỉ 1 câu):
  - "Bạn sẽ bị đăng xuất ngay lập tức và không thể đăng nhập lại bằng email/số điện thoại hiện tại."
  - "Họ tên, email, số điện thoại, ngày sinh và thông tin liên hệ phụ huynh sẽ bị ẩn danh hóa."
  - "Không thể hoàn tác sau khi hoàn tất."
- Nút "Huỷ" / "Tiếp tục xóa tài khoản" (danger) → gửi OTP, chuyển sang màn 2.3

### 2.3 Màn xác nhận OTP xóa tài khoản (bước 2)
**Bố cục:** giống `/xac-thuc-otp` của US-001 nhưng trong ngữ cảnh nguy hiểm hơn (viền `rose`).
- Icon cảnh báo, tiêu đề "Xác nhận xóa tài khoản"
- Mô tả: "Nhập mã gồm 6 chữ số vừa được gửi tới {email đã che} để hoàn tất xóa tài khoản."
- `<OtpInput length="6">`
- `<Countdown>` hiệu lực + nút "Gửi lại mã" (cooldown)
- Nút chính "Xác nhận xóa tài khoản" (`variant="danger"`)
- Nút phụ "Huỷ, giữ lại tài khoản của tôi" (quay lại 2.1, không đổi gì)

### 2.4 Màn hoàn tất (sau khi ẩn danh hoá thành công)
- Không còn ở trong app đã đăng nhập (đã bị đăng xuất) — chuyển về trang chủ `/` kèm `<Alert variant="info">` toàn trang hoặc trang riêng: "Tài khoản của bạn đã được xóa. Cảm ơn bạn đã sử dụng VitaminVui." Không có nút "Hoàn tác".

## 3. Bảng trạng thái UI & thông điệp

| Màn hình | Trạng thái | Thông điệp |
|---|---|---|
| 2.1 | Đang tải dữ liệu | Nút "Tải dữ liệu của tôi" chuyển `loading` "Đang chuẩn bị dữ liệu..." |
| 2.1 | Tải dữ liệu thành công | `<Toast variant="success">` "Đã tải xong dữ liệu cá nhân của bạn" |
| 2.1 | Tải dữ liệu lỗi | `<Alert variant="danger">` "Không thể tạo file dữ liệu lúc này, vui lòng thử lại sau." |
| 2.3 | Sai OTP (AC5) | "Mã xác nhận không đúng, vui lòng thử lại." |
| 2.3 | OTP hết hạn (AC5) | "Mã xác nhận đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới." |
| 2.3 | Double-submit xác nhận xóa | Nút "Xác nhận xóa tài khoản" chuyển `loading` + disabled ngay khi bấm, chặn double click (không ẩn danh hoá 2 lần) |
| 2.4 | Hoàn tất (AC3) | Xem mục 2.4 |

## 4. Component React dùng/tạo mới
- `<StatusPill>`, `<Button>`, `<Alert>`, `<Toast>`, `<ConfirmModal>`, `<OtpInput>`, `<Countdown>` (dùng lại — `packages/ui`, đã có từ các story trước)
- Không cần component mới riêng; trang ghép từ các khối/component sẵn có.

## 5. Điểm cần PO duyệt
- Xuất dữ liệu xử lý đồng bộ (tải ngay) hay chạy nền giống US-010 khi dữ liệu lớn — quyết định kỹ thuật của Dev/Architect, ảnh hưởng trực tiếp tới copy ở khối 2 (mục 2.1); mockup thiết kế sẵn cả 2 trạng thái.
- Danh sách trường chính xác trong file xuất (có gồm log truy cập/audit liên quan tới chính học sinh không) — câu hỏi mở của US-018, không ảnh hưởng UI nhưng Dev cần biết trước khi code.
- Học sinh dưới 18 tuổi tự xóa tài khoản có cần thêm xác nhận của phụ huynh không (liên quan US-017) — câu hỏi mở của US-018; mockup hiện **không** thêm bước này (chỉ OTP của chính học sinh), cần PO/pháp chế xác nhận trước go-live.
- Giới hạn số lần yêu cầu xuất dữ liệu/ngày (BR7, câu hỏi mở) — chưa thiết kế UI riêng cho trạng thái "đã đạt giới hạn", bổ sung sau khi PO chốt ngưỡng.
