# Đặc tả UX — US-015: Quên mật khẩu / Đổi mật khẩu (Học sinh)

Xem thêm quy ước chung tại `docs/design/design-system.md`. Chỉ áp dụng vai trò **Học Sinh**, app `web` (`vitaminvui.vn`). Đổi/đặt lại mật khẩu cho staff thuộc US-016.

## 1. Luồng người dùng

```
/dang-nhap → "Quên mật khẩu?" → /quen-mat-khau
/quen-mat-khau → nhập email hoặc SĐT + captcha Turnstile → submit
             → LUÔN hiện cùng 1 thông điệp chung (không lộ tài khoản có tồn tại hay không — BR2)
             → (nếu tài khoản thực sự tồn tại + đang hoạt động) gửi OTP purpose=reset_password qua email
             → chuyển sang /quen-mat-khau/dat-lai (dù tài khoản có tồn tại hay không — để không lộ thông tin qua việc điều hướng khác nhau)
/quen-mat-khau/dat-lai → nhập OTP (6 số) + mật khẩu mới + xác nhận → submit
             → đúng OTP → mật khẩu đổi, MỌI phiên khác của tài khoản bị huỷ (tombstone password_changed)
             → chuyển /dang-nhap kèm thông báo thành công, yêu cầu đăng nhập lại bằng mật khẩu mới
             → sai/hết hạn OTP → báo lỗi tại chỗ, không rời trang

Học sinh ĐANG đăng nhập → /tai-khoan/doi-mat-khau → nhập mật khẩu hiện tại + mật khẩu mới + xác nhận → submit
             → đúng mật khẩu hiện tại → đổi ngay, phiên hiện tại được GIỮ LẠI (bind lại), các phiên khác (nếu có) bị huỷ
             → sai mật khẩu hiện tại → báo lỗi tại chỗ "Mật khẩu hiện tại không đúng"
```

Việc phiên khác bị huỷ sau khi đổi/đặt lại mật khẩu hiển thị cho phiên bị huỷ đó bằng `<ForcedLogoutOverlay code="SESSION_REVOKED">` — xem `docs/design/US-014-gioi-han-mot-thiet-bi-mot-phien.md` mục 2.1.

## 2. Màn hình

### 2.1 `/quen-mat-khau` — Quên mật khẩu
**Bố cục:** card căn giữa max-width 420px, giống phong cách `/dang-nhap`.
- Tiêu đề "Quên mật khẩu?"
- Mô tả: "Nhập email hoặc số điện thoại đã đăng ký, chúng tôi sẽ gửi mã xác nhận qua email để bạn đặt lại mật khẩu."
- Trường "Email hoặc số điện thoại" * (`<TextInput>`)
- `<TurnstileWidget>` — nút submit disabled tới khi xác minh xong (BR6, giống US-001)
- Nút chính "Gửi mã xác nhận" (`<Button variant="primary" loading>` khi đang submit)
- Link phụ "Quay lại đăng nhập"

### 2.2 `/quen-mat-khau/dat-lai` — Nhập OTP + mật khẩu mới
**Bố cục:** card căn giữa, tương tự `/xac-thuc-otp` của US-001 nhưng có thêm 2 field mật khẩu.
- Mô tả: "Nếu thông tin tồn tại, chúng tôi đã gửi mã gồm 6 chữ số tới email của bạn."
- `<OtpInput length="6">`
- `<Countdown>` hiệu lực OTP + nút "Gửi lại mã" (disabled trong lúc đếm ngược — cooldown 60 giây theo BR7, tối đa 5 lần/giờ và 10 lần/ngày)
- Mật khẩu mới * (`<PasswordInput>`, hiện/ẩn), helper "Tối thiểu 8 ký tự"
- Xác nhận mật khẩu mới *
- Nút chính "Đặt lại mật khẩu"
- Ghi chú nhỏ dưới form: "Sau khi đặt lại, bạn sẽ được đăng xuất khỏi mọi thiết bị khác đang đăng nhập."

### 2.3 `/tai-khoan/doi-mat-khau` — Đổi mật khẩu (đang đăng nhập)
**Bố cục:** nằm trong layout trang tài khoản (sidebar/tab "Đổi mật khẩu" cạnh "Quyền dữ liệu cá nhân" — US-018), form 1 cột max-width 420px.
- Mật khẩu hiện tại * (`<PasswordInput>`)
- Mật khẩu mới * (`<PasswordInput>`), helper "Tối thiểu 8 ký tự"
- Xác nhận mật khẩu mới *
- Nút chính "Đổi mật khẩu"
- Ghi chú nhỏ: "Phiên đăng nhập hiện tại của bạn sẽ được giữ nguyên. Các thiết bị khác (nếu có) sẽ bị đăng xuất."

## 3. Bảng trạng thái UI & thông điệp

| Màn hình | Trạng thái | Thông điệp |
|---|---|---|
| `/quen-mat-khau` | Submit thành công (LUÔN cùng 1 câu dù tài khoản tồn tại hay không — BR2, AC1) | `<Alert variant="info">` (chuyển sang `/quen-mat-khau/dat-lai`): "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn." |
| `/quen-mat-khau` | Vượt giới hạn gửi (AC6) | "Bạn đã yêu cầu quá nhiều lần, vui lòng thử lại sau {x} phút." |
| `/quen-mat-khau` | Captcha chưa xác minh | Nút submit disabled |
| `/quen-mat-khau/dat-lai` | Sai OTP (AC3) | "Mã xác nhận không đúng, vui lòng thử lại." |
| `/quen-mat-khau/dat-lai` | OTP hết hạn (AC3) | "Mã xác nhận đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới." |
| `/quen-mat-khau/dat-lai` | Mã đã được dùng (race — AC7) | "Mã này đã được sử dụng. Vui lòng yêu cầu mã mới." |
| `/quen-mat-khau/dat-lai` | Mật khẩu mới không khớp xác nhận | "Xác nhận mật khẩu không khớp" (dưới field xác nhận) |
| `/quen-mat-khau/dat-lai` | Thành công (AC2) | Chuyển `/dang-nhap` + `<Toast variant="success">` "Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại." |
| `/tai-khoan/doi-mat-khau` | Sai mật khẩu hiện tại (AC5) | "Mật khẩu hiện tại không đúng" (dưới field mật khẩu hiện tại) |
| `/tai-khoan/doi-mat-khau` | Mật khẩu mới không khớp xác nhận | "Xác nhận mật khẩu không khớp" |
| `/tai-khoan/doi-mat-khau` | Thành công (AC4) | `<Toast variant="success">` "Đã đổi mật khẩu. Các thiết bị khác (nếu có) đã được đăng xuất." — không rời trang, phiên hiện tại vẫn còn |

## 4. Component React dùng/tạo mới
- `<TextInput>`, `<PasswordInput>`, `<OtpInput>`, `<Countdown>`, `<TurnstileWidget>`, `<Alert>`, `<Toast>`, `<Button>` (dùng lại — `packages/ui`, đã có từ US-001)
- Không cần component mới; 3 màn hình chỉ là bố cục mới ghép từ component sẵn có.

## 5. Điểm cần PO duyệt
- Thời hạn hiệu lực OTP `reset_password` — dùng chung 10 phút với OTP xác thực tài khoản hay ngắn hơn (câu hỏi mở của US-015)? Mockup hiện dùng chung `<Countdown>` như US-001.
- Mật khẩu mới có được trùng mật khẩu cũ không (câu hỏi mở của US-015) — hiện chưa thiết kế validate riêng, chờ xác nhận trước khi Dev code.
- Có gửi thêm email "Mật khẩu của bạn vừa được thay đổi" tới hộp thư sau khi đổi/đặt lại mật khẩu không, hay chỉ thông báo trên UI như thiết kế hiện tại (câu hỏi mở của US-015)?
