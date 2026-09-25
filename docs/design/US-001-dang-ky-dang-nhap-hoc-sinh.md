# Đặc tả UX — US-001: Đăng ký & đăng nhập tài khoản học sinh

Xem thêm quy ước chung tại `docs/design/design-system.md`.

## 1. Luồng người dùng

```
Khách → /dang-ky (điền form, tick 2 checkbox đồng ý riêng biệt, vượt captcha Turnstile,
                   nếu <18 tuổi hiện thêm 2 field phụ huynh)
      → Submit hợp lệ → tự động đăng nhập → chuyển /  (trang chủ) kèm banner nhắc xác thực
      → (song song) hệ thống gửi OTP qua email
      → (nếu <18 tuổi) hệ thống gửi email xác nhận cho phụ huynh, parent_consent_status = pending
      → Khách bấm "Xác thực ngay" trên banner → /xac-thuc-otp
      → Nhập đúng OTP → xác thực tài khoản thành công

      → Nếu ĐỦ tuổi (parent_consent_status = not_required): có thể mua khóa học ngay
      → Nếu DƯỚI tuổi và phụ huynh CHƯA xác nhận (pending): vào /checkout hoặc bấm "Đăng ký học miễn phí"
        bị chặn bởi màn "Đang chờ phụ huynh xác nhận" (US-017) — có nút gửi lại email cho phụ huynh
      → Phụ huynh mở email → bấm link → /xac-nhan-phu-huynh/{token} (trang công khai, không cần tài khoản)
        → bấm "Đồng ý" → parent_consent_status = granted → học sinh checkout được ngay lần vào tiếp theo

Khách đã có tài khoản → /dang-nhap → nhập email/SĐT + mật khẩu → vào hệ thống
Khách quên mật khẩu → bấm "Quên mật khẩu?" → /quen-mat-khau (US-015, xem file riêng)
Học sinh chưa xác thực cố vào /checkout → chặn, hiện màn "Cần xác thực tài khoản" kèm nút gửi lại OTP → /xac-thuc-otp
```

Màn "Đang chờ phụ huynh xác nhận", trang công khai `/xac-nhan-phu-huynh/{token}` và luồng rút lại đồng ý được đặc tả đầy đủ ở `docs/design/US-017-dong-y-xu-ly-du-lieu-ca-nhan-xac-nhan-phu-huynh.md` — tài liệu này chỉ mô tả phần chạm tới màn đăng ký (2 checkbox, captcha) và điểm nối sang US-017.

## 2. Màn hình

### 2.1 `/dang-ky` — Đăng ký tài khoản
**Bố cục:** form 1 cột trên mobile, card căn giữa max-width 480px trên desktop. Logo trên cùng, tiêu đề "Tạo tài khoản học sinh".

**Trường dữ liệu (theo thứ tự):**
- Họ và tên * (text)
- Ngày sinh * (date picker) — khi chọn ngày khiến tuổi < 18 tại thời điểm hiện tại, tự động hiện thêm 2 field bên dưới (animate mở rộng, không load lại trang):
  - Số điện thoại phụ huynh (tối thiểu 1 trong 2 với email phụ huynh) *
  - Email phụ huynh (tối thiểu 1 trong 2) *
  - Helper text: "Bắt buộc nhập ít nhất 1 trong 2 thông tin liên hệ phụ huynh vì bạn dưới 18 tuổi."
- Email * (email)
- Số điện thoại * (tel, định dạng VN)
- Lớp đang học * (select 6–12)
- Mật khẩu * (password, hiện/ẩn), helper "Tối thiểu 8 ký tự"
- Xác nhận mật khẩu *
- Mã giới thiệu (không bắt buộc) — helper "Nếu có, nhập mã của người giới thiệu bạn" — badge nhỏ `⚠ Chờ PO xác nhận mục đích sử dụng`
- **2 checkbox đồng ý tách riêng, KHÔNG tick sẵn (bắt buộc cả 2 — US-017 BR1):**
  - ☐ "Tôi đã đọc và đồng ý với [Điều khoản sử dụng]" (link mở tab mới)
  - ☐ "Tôi đã đọc và đồng ý với [Chính sách xử lý dữ liệu cá nhân]" (link mở tab mới)
  - Thiếu 1 trong 2 → lỗi đỏ ngay dưới nhóm checkbox: "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân"
  - Component `<ConsentCheckboxGroup>` — ghi rõ `policy_version` đang hiển thị (nhỏ, xám, dưới 2 checkbox) để khớp với bản ghi `consents` sẽ tạo ở server
- **Captcha Cloudflare Turnstile** (`<TurnstileWidget>`) — hiện ngay phía trên nút submit; nút "Tạo tài khoản" disabled tới khi captcha xác minh xong; lỗi captcha (hết hạn/thất bại) → `<Alert variant="danger">` "Xác minh chống spam thất bại, vui lòng thử lại" + tự load lại widget
- Nút chính `<Button variant="primary" size="lg">` "Tạo tài khoản" (full width mobile)
- Link phụ "Đã có tài khoản? Đăng nhập"

**Hành động:** submit → validate client trước, sau đó server; giữ nguyên dữ liệu khi lỗi (trừ mật khẩu và trạng thái captcha — Turnstile token dùng 1 lần, phải xác minh lại nếu submit lỗi).

### 2.2 `/xac-thuc-otp` — Xác thực OTP
**Bố cục:** card căn giữa, icon phong bì/điện thoại, tiêu đề "Xác thực tài khoản", mô tả "Chúng tôi đã gửi mã gồm 6 chữ số tới {email/SĐT đã che một phần}".
- `<OtpInput length={6}>` tự nhảy ô, chỉ nhập số
- Đồng hồ đếm ngược hiệu lực OTP + nút "Gửi lại mã" (disabled trong lúc đếm ngược, ví dụ 60 giây — cụ thể chờ PO chốt thời hạn OTP)
- Nút "Xác nhận"
- Link "Đổi email/SĐT" quay lại chỉnh thông tin (tuỳ chọn)

### 2.3 `/dang-nhap` — Đăng nhập
**Bố cục:** tương tự đăng ký, card căn giữa.
- Toggle/tab "Email" | "Số điện thoại" hoặc 1 input dùng chung placeholder "Email hoặc số điện thoại"
- Mật khẩu * (hiện/ẩn)
- Link "Quên mật khẩu?" → **hoạt động**, dẫn tới `/quen-mat-khau` (US-015 — xem `docs/design/US-015-quen-mat-khau-doi-mat-khau.md`)
- Nút "Đăng nhập"
- Link "Chưa có tài khoản? Đăng ký ngay"

### 2.4 Màn "Cần xác thực tài khoản" (chặn checkout khi chưa xác thực — AC9)
**Bố cục:** `<Alert variant="warning">` toàn trang hoặc modal chặn trước khi vào `/checkout`.
- Nội dung: "Bạn cần xác thực tài khoản trước khi thanh toán."
- Nút "Xác thực ngay" → `/xac-thuc-otp`, nút phụ "Gửi lại mã OTP"

### 2.5 Màn "Đang chờ phụ huynh xác nhận" (chặn checkout/đăng ký miễn phí khi `parent_consent_status ∈ {pending, revoked}` — US-017 BR6, AC6)
**Bố cục:** cùng vị trí chặn với 2.4 (trước `/checkout` hoặc trước nút "Đăng ký học miễn phí"), `<Alert variant="info">` (sky) toàn trang hoặc modal, không dùng `danger` vì đây không phải lỗi của học sinh.
- Icon đồng hồ cát, tiêu đề "Đang chờ phụ huynh xác nhận"
- Mô tả: "Vì bạn dưới 18 tuổi, phụ huynh cần xác nhận đồng ý trước khi bạn có thể mua khóa học. Chúng tôi đã gửi email xác nhận tới {email phụ huynh đã che một phần}."
- Nút "Gửi lại email cho phụ huynh" (`resend`, tối đa 3 lần/ngày — disabled + đếm ngược sau khi bấm, hết lượt trong ngày thì disabled kèm chú thích "Đã đạt giới hạn gửi lại hôm nay, vui lòng thử lại vào ngày mai")
- Nếu `parent_consent_status = revoked` (phụ huynh đã rút lại đồng ý): đổi tiêu đề thành "Phụ huynh đã rút lại đồng ý", mô tả "Phụ huynh của bạn đã rút lại sự đồng ý trước đó. Vui lòng liên hệ phụ huynh hoặc bộ phận hỗ trợ." — vẫn giữ nút gửi lại email để xin xác nhận lại.
- Chi tiết đầy đủ (trang công khai phụ huynh bấm link, các trạng thái link hết hạn/đã dùng...) xem `docs/design/US-017-dong-y-xu-ly-du-lieu-ca-nhan-xac-nhan-phu-huynh.md`.

## 3. Bảng trạng thái UI & thông điệp

| Màn hình | Trạng thái | Thông điệp |
|---|---|---|
| Đăng ký | Lỗi email trùng | "Email đã được sử dụng" (dưới field email) |
| Đăng ký | Lỗi SĐT trùng | "Số điện thoại đã được sử dụng" |
| Đăng ký | Lỗi mật khẩu không khớp | "Xác nhận mật khẩu không khớp" (dưới field xác nhận) |
| Đăng ký | Thiếu liên hệ phụ huynh (<18 tuổi) | "Vui lòng nhập ít nhất số điện thoại hoặc email phụ huynh" |
| Đăng ký | Thiếu 1/2 checkbox đồng ý | "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân" (US-017 AC1) |
| Đăng ký | Captcha chưa xác minh | Nút "Tạo tài khoản" disabled; nếu cố submit → "Vui lòng hoàn tất xác minh chống spam" |
| Đăng ký | Đang submit | Nút chuyển `loading`, disable toàn form |
| Đăng ký | Thành công (đủ tuổi) | Chuyển trang chủ + `<Alert variant="info">` "Vui lòng xác thực tài khoản để có thể mua khóa học" |
| Đăng ký | Thành công (<18 tuổi) | Chuyển trang chủ + `<Alert variant="info">` "Vui lòng xác thực tài khoản. Chúng tôi cũng đã gửi email xác nhận tới phụ huynh của bạn — bạn cần cả 2 bước hoàn tất mới mua được khóa học." |
| Đăng nhập | Sai thông tin | "Thông tin đăng nhập hoặc mật khẩu không đúng" (banner chung, không chỉ rõ field nào sai — BR5) |
| Đăng nhập | Khoá tạm thời (AC6) | "Tài khoản tạm khoá do đăng nhập sai nhiều lần. Vui lòng thử lại sau {x} phút." |
| Đăng nhập | Tài khoản bị khoá bởi admin | "Tài khoản của bạn đã bị khoá. Vui lòng liên hệ hỗ trợ." |
| OTP | Sai mã | "Mã OTP không đúng, vui lòng thử lại." |
| OTP | Hết hạn | "Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới." |
| OTP | Thành công | Chuyển trang chủ, `<Toast variant="success">` "Xác thực tài khoản thành công" |
| Checkout khi chưa xác thực | Chặn | Xem mục 2.4 |
| Checkout/đăng ký miễn phí khi `parent_consent_status=pending` | Chặn | Xem mục 2.5 |
| Checkout/đăng ký miễn phí khi `parent_consent_status=revoked` | Chặn | Xem mục 2.5 (biến thể "đã rút lại đồng ý") |

## 4. Component React dùng/tạo mới
- `<TextInput>`, `<Select>`, `<PasswordInput>` (dùng lại toàn dự án — `packages/ui`)
- `<OtpInput>` (dùng lại — `packages/ui`)
- `<Alert>`, `<Toast>` (dùng lại)
- `<Button>` với trạng thái `loading` (dùng lại)
- `<ConsentCheckboxGroup>` (mới — `apps/web`, US-017)
- `<TurnstileWidget>` (mới — `packages/ui`, dùng lại ở US-015 "Quên mật khẩu")
- `<ParentConsentPendingBanner>` (mới — `apps/web`, US-017, dùng cho màn 2.5)
- Khối "hiện/ẩn field phụ huynh theo tuổi" — state React cục bộ theo `date_of_birth`, không cần thư viện ngoài.

## 5. Điểm cần PO duyệt
- Trường "Mã giới thiệu" — xem mục 8 của design-system.md.
- Thời hạn/giao diện đếm ngược gửi lại OTP (số giây cụ thể).
- Ngưỡng tuổi cần phụ huynh xác nhận (mặc định 18) — xem US-017, cần pháp chế xác nhận.
