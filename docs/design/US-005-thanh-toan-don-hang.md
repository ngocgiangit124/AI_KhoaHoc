# Đặc tả UX — US-005: Thanh toán đơn hàng (Checkout qua MoMo)

## 1. Luồng người dùng
```
/gio-hang → "Tiến hành thanh toán" → /checkout (nếu chưa xác thực OTP → chặn, xem US-001 mục 2.4;
             nếu đang chờ phụ huynh xác nhận → chặn, xem US-001 mục 2.5 / US-017)
/checkout → xem lại đơn hàng
   → Tổng sau giảm = 0đ (mã giảm 100%): "Hoàn tất đăng ký" → order paid NGAY trong 1 bước, KHÔNG chuyển sang MoMo
     → thẳng tới biến thể "Thanh toán thành công" của /checkout/ket-qua
   → Tổng sau giảm 1–999đ: chặn ngay tại /checkout (MoMo không nhận số tiền này) — xem mục 2.1b
   → Tổng ≥ 1.000đ: "Thanh toán qua MoMo" → tạo order (pending) → chuyển hướng sang MoMo
   → Giỏ/giá/mã đã đổi so với lúc vào trang (409 CHECKOUT_CHANGED) → không tạo được đơn → xem mục 2.1c
[Học sinh thao tác trên app/web MoMo — ngoài hệ thống]
MoMo redirect trình duyệt về → /checkout/ket-qua?order={code}
   → Hiển thị "Đang xác nhận thanh toán..." (trạng thái tạm, chờ IPN/đối soát — KHÔNG đọc tham số MoMo trên URL)
   → (nền) IPN hoặc đối soát chủ động xử lý → trang tự poll `GET /orders/{code}` mỗi 3 giây (~1 phút)
   → Thành công → "Thanh toán thành công" + nút "Vào học ngay" / "Xem đơn hàng"
   → Thất bại/huỷ trên MoMo (attempt mới nhất `failed`) → "Thanh toán không thành công" + nút "Thử lại"
   → Link thanh toán MoMo hết hạn kỹ thuật NHƯNG đơn vẫn còn hạn 12h (`payment.link_expired = true`, đơn `pending`)
     → biến thể riêng "Link thanh toán đã hết hạn" + nút "Tạo lại giao dịch" (KHÔNG tạo đơn mới — xem mục 2.2)
   → Quá 12 giờ chưa xác nhận thanh toán (đơn tự huỷ, kể cả sau thời gian ân hạn đối soát) → "Đơn hàng đã hết hạn" + nút "Tạo đơn hàng mới"
   → Học sinh chủ động bấm "Kiểm tra lại thanh toán" (giới hạn 1 lần/30 giây) → đối soát ngay attempt mới nhất
/tai-khoan/don-hang → danh sách đơn hàng của học sinh, mỗi đơn `pending` có link "Xem trạng thái" → /checkout/ket-qua?order={code}
```

## 2. Màn hình

### 2.1 `/checkout` — Xem lại & thanh toán
**Bố cục:** giống trang giỏ hàng nhưng chỉ đọc (không xoá/sửa mã ở đây, quay lại giỏ hàng nếu cần đổi) + rõ ràng:
- Danh sách khóa học sẽ mua (tên, giá)
- `<OrderSummary>` (tạm tính/giảm giá/tổng cộng — dùng lại từ US-004)
- Khối "Phương thức thanh toán": hiện tại chỉ 1 lựa chọn — thẻ MoMo (logo + radio đã chọn sẵn, disabled vì MVP chỉ có 1 cổng)
- Nút chính full-width "Thanh toán qua MoMo" (primary, size lg), khi bấm → `loading` (đang tạo giao dịch...)
- Dòng ghi chú nhỏ: "Bạn sẽ được chuyển sang ứng dụng/web MoMo để hoàn tất thanh toán."

**Trường hợp 1 khóa học trong giỏ bị unpublish (AC4):** `<Alert variant="warning">` phía trên: "Khóa học 'Hình học nâng cao lớp 9' đã ngừng bán và được loại khỏi đơn hàng." — danh sách/tổng tiền tự động loại bỏ khóa học đó; nếu mã giảm giá không còn hợp lệ, hiển thị thêm cảnh báo gỡ mã (đồng bộ US-004 AC10).

### 2.1a Tổng sau giảm = 0đ — hoàn tất ngay, không qua MoMo (`FEATURE_ZERO_TOTAL_CHECKOUT`, mặc định bật)
- Khối "Phương thức thanh toán" **ẩn hẳn** (không có gì để chọn cổng); thay bằng dòng `<Alert variant="info">`: "Đơn hàng của bạn được miễn phí hoàn toàn nhờ mã giảm giá. Bấm bên dưới để hoàn tất ngay, không cần thanh toán."
- Nút chính đổi copy thành "Hoàn tất đăng ký" (thay vì "Thanh toán qua MoMo"); bấm → gọi thẳng `POST /checkout`, server `markPaid` trong cùng transaction → điều hướng thẳng tới biến thể "Thanh toán thành công" ở mục 2.2 (bỏ qua bước "Đang xác nhận").

### 2.1b Tổng sau giảm 1–999đ — chặn tại `/checkout` (`AMOUNT_BELOW_GATEWAY_MIN`, chờ PO — README §8 mục 8)
- Ngay khi vào `/checkout` (hoặc ngay sau khi tính lại giá do áp/gỡ mã), nếu tổng nằm trong khoảng 1–999đ: khối "Phương thức thanh toán" và nút thanh toán **disabled**, thay bằng `<Alert variant="danger">`: "Số tiền {n}đ quá nhỏ để thanh toán qua MoMo. Vui lòng quay lại giỏ hàng để điều chỉnh mã giảm giá hoặc thêm khóa học." + nút "Quay lại giỏ hàng".
- Badge nhỏ `⚠ Chờ PO xác nhận` cạnh alert (mức tối thiểu/tối đa của cổng do PO/Dev xác nhận lại với MoMo).

### 2.1c Giỏ/giá/mã thay đổi khi bấm thanh toán (409 `CHECKOUT_CHANGED`)
- Khi `POST /checkout` trả 409 kèm `preview` mới: hiện `<Alert variant="warning">` ngay trên đầu trang (không rời trang): "Giỏ hàng của bạn đã thay đổi (giá, mã giảm giá hoặc khóa học không còn khả dụng). Vui lòng kiểm tra lại trước khi thanh toán tiếp."
- Toàn bộ danh sách khóa học + `<OrderSummary>` **tự cập nhật theo `preview` mới** trả về từ server (không cần tải lại trang); nút thanh toán trở lại trạng thái bình thường để học sinh bấm lại với giá đã cập nhật.
- Nếu nguyên nhân là hết chỗ mã giảm giá (409 `COUPON_EXHAUSTED` khi tạo link mới cho đơn đã có — mục 2.2): xử lý riêng ở biến thể "Hết chỗ mã giảm giá", không dùng chung banner này.

### 2.2 `/checkout/ket-qua` — Màn hình chờ/kết quả sau khi MoMo redirect
6 biến thể trạng thái trên cùng 1 layout (icon lớn + tiêu đề + mô tả + hành động):

| Trạng thái | Icon/màu | Tiêu đề | Mô tả | Hành động |
|---|---|---|---|---|
| Đang xác nhận (`pending`, chưa có IPN/đối soát) | Vòng xoay `info` (sky) | "Đang xác nhận thanh toán..." | "Hệ thống đang xác nhận giao dịch với MoMo, vui lòng đợi trong giây lát. Trang sẽ tự cập nhật." | Tự động poll `GET /orders/{code}` mỗi 3 giây (~1 phút); nút phụ `<CheckPaymentButton>` "Kiểm tra lại thanh toán" — gọi `POST /orders/{code}/check-payment`, **tự khoá 30 giây sau mỗi lần bấm** (đếm ngược hiển thị trên nút, ví dụ "Kiểm tra lại (28s)") |
| Thành công (`order.status = paid`) | ✔ lớn `success` (emerald) | "Thanh toán thành công!" | "Bạn đã sở hữu {n} khóa học. Chúc bạn học tốt!" (đơn 0đ dùng cùng biến thể này, tới thẳng không qua "Đang xác nhận") | Nút chính "Vào học ngay", nút phụ "Xem đơn hàng" |
| Thất bại/huỷ (attempt mới nhất `failed` do MoMo) | ✕ `danger` (rose) | "Thanh toán không thành công" | "Giao dịch đã bị huỷ hoặc không hoàn tất trên MoMo. Giỏ hàng của bạn vẫn được giữ nguyên." | Nút chính "Thử thanh toán lại" → quay lại `/checkout` tạo đơn mới, nút phụ "Về giỏ hàng" |
| **Link thanh toán đã hết hạn** (`payment.link_expired = true`, đơn vẫn `pending`, còn trong hạn 12h) | ⏱ `warning` (amber) | "Link thanh toán đã hết hạn" | "Liên kết thanh toán MoMo trước đó đã hết hạn nhưng đơn hàng của bạn vẫn còn hiệu lực. Bấm bên dưới để tạo liên kết thanh toán mới." | Nút chính **"Tạo lại giao dịch"** → `POST /orders/{code}/pay` (tạo `payment_attempt` mới, **không** tạo `order` mới) → nhận `pay_url` mới → chuyển sang MoMo |
| Hết chỗ mã giảm giá khi tạo lại giao dịch (409 `COUPON_EXHAUSTED` từ `/orders/{code}/pay`) | ⚠ `warning` (amber) | "Mã giảm giá đã hết lượt sử dụng" | "Mã giảm giá áp dụng cho đơn này đã hết lượt trong lúc chờ thanh toán. Đơn hàng đã được huỷ, vui lòng tạo đơn mới." | Nút chính "Tạo đơn hàng mới" → `/gio-hang` (mã đã tự gỡ) |
| Đơn hàng đã hết hạn (`cancelled`, quá 12 giờ kể cả sau thời gian ân hạn đối soát) | ⏱ `warning` (amber) | "Đơn hàng đã hết hạn" | "Đơn hàng chưa được thanh toán trong 12 giờ nên đã tự động huỷ. Giỏ hàng của bạn không bị ảnh hưởng." | Nút chính "Tạo đơn hàng mới" → `/gio-hang` hoặc `/checkout` |

Ghi chú thiết kế quan trọng:
- Màn "Đang xác nhận" **không được tự ý coi là thành công** dù tham số redirect từ MoMo báo ok — phải chờ trạng thái order thực tế lấy từ `GET /orders/{code}` (BR11), **tuyệt đối không đọc tham số gắn vào URL redirect để quyết định trạng thái** (ADR-001 §2).
- Nếu quá thời gian chờ hợp lý (~1 phút polling) mà vẫn `pending`, đổi mô tả thành "Việc xác nhận đang mất nhiều thời gian hơn dự kiến, bạn có thể kiểm tra lại sau ở mục 'Đơn hàng của tôi'." và dừng tự poll (tránh gọi API vô hạn); nút "Kiểm tra lại thanh toán" vẫn dùng được (theo giới hạn 1 lần/30 giây).
- `<CheckPaymentButton>`: khi đang trong 30 giây khoá, disabled + hiện số giây còn lại; khi hết khoá, nhãn trở lại "Kiểm tra lại thanh toán". Nếu bấm khi đang khoá (double-click nhanh) → không gửi request, chỉ hiệu ứng rung nhẹ nút (client-side guard, server cũng có `throttle:check-payment` 1 lần/30s làm lưới an toàn cuối).
- Biến thể "Link thanh toán đã hết hạn" khác hẳn "Thất bại": "Thất bại" nghĩa là MoMo đã xác nhận giao dịch không thành công (huỷ trên app MoMo...) → phải tạo **đơn mới**; "Link hết hạn" nghĩa là chưa có xác nhận nào, chỉ link cũ quá hạn kỹ thuật (ngắn hơn 12h) → tạo **giao dịch mới trên cùng đơn cũ**, giữ nguyên mã đơn và giá đã chốt.

### 2.1d Trạng thái "Đơn hàng của tôi" cho đơn `pending`
Mỗi dòng đơn `pending` trong `/tai-khoan/don-hang` có link phụ "Xem trạng thái" dẫn thẳng tới `/checkout/ket-qua?order={code}` (không tạo lại giao dịch tự động khi vào trang, chỉ hiển thị đúng biến thể theo trạng thái hiện tại của đơn/attempt).

### 2.3 `/tai-khoan/don-hang` — Đơn hàng của tôi
**Bố cục:** danh sách dạng thẻ/dòng (mobile) hoặc bảng (desktop): Mã đơn | Ngày mua | Khóa học (rút gọn "+2 khóa học khác") | Số tiền (kèm số tiền đã giảm nếu có) | Phương thức (MoMo, hoặc "Miễn phí" cho đơn 0đ) | Trạng thái (`<StatusPill>`).
- Bấm vào 1 dòng → xem chi tiết (danh sách khóa học đầy đủ, mã giảm giá đã dùng nếu có)
- Đơn `pending` có thêm link phụ "Xem trạng thái" (xem mục 2.1d)

## 3. Bảng trạng thái UI & thông điệp bổ sung

| Trạng thái | Thông điệp |
|---|---|
| Giỏ hàng trống cố vào `/checkout` (AC3) | Redirect `/gio-hang` kèm toast "Giỏ hàng của bạn đang trống" |
| Lỗi kết nối MoMo khi khởi tạo (AC6) | `<Alert variant="danger">` tại `/checkout`: "Không thể kết nối tới cổng thanh toán MoMo, vui lòng thử lại sau." — nút vẫn ở trạng thái bình thường để thử lại (502 `PAYMENT_GATEWAY_UNAVAILABLE`) |
| Tổng sau giảm 1–999đ | Chặn tại `/checkout`, xem mục 2.1b (422 `AMOUNT_BELOW_GATEWAY_MIN`) |
| Giỏ/giá/mã thay đổi lúc bấm thanh toán | Xem mục 2.1c (409 `CHECKOUT_CHANGED`) |
| Chưa xác thực OTP | Xem US-001 mục 2.4 |
| Đang chờ phụ huynh xác nhận (<18 tuổi) | Xem US-001 mục 2.5 / US-017 (403 `PARENT_CONSENT_REQUIRED`) |
| Bấm "Kiểm tra lại thanh toán" khi đang trong 30 giây khoá | Nút disabled + đếm ngược, không gửi request (429 `TOO_MANY_ATTEMPTS` là lưới an toàn phía server) |
| `<StatusPill>` giá trị | `pending` (amber, "Chờ thanh toán"), `paid` (emerald, "Đã thanh toán"), `failed` (rose, "Thất bại"), `cancelled` (gray, "Đã huỷ"), `refunded` (rose, "Đã hoàn tiền") |

## 4. Component React dùng/tạo mới
- `<OrderSummary>` (dùng lại từ US-004 — `apps/web`)
- `<StatusPill>` (dùng lại chung — `packages/ui`, mở rộng thêm giá trị cho đơn hàng)
- `<Countdown>` — không hiển thị đếm ngược cho học sinh ở màn checkout (12 giờ là hạn nền, không cần đồng hồ hiển thị theo AC), nhưng nếu PO muốn minh bạch có thể thêm dòng nhỏ "Đơn hàng cần thanh toán trước {giờ}" ở trang "Đơn hàng của tôi" cho đơn `pending` — **đề xuất thêm, PO xác nhận**; dùng lại cho `<CheckPaymentButton>` (đếm ngược 30 giây)
- `<PaymentResultState>` (mới — `apps/web`) — nhận prop `status` ∈ `confirming | paid | failed | link_expired | coupon_exhausted | expired`, render đúng 1 trong 6 biến thể mục 2.2
- `<CheckPaymentButton>` (mới — `apps/web`, dùng chung `<Countdown>`)

## 5. Điểm cần PO duyệt
- Có cần hiển thị cho học sinh thời điểm đơn `pending` sẽ tự huỷ (đếm ngược 12 giờ) trên trang "Đơn hàng của tôi" không, hay chỉ xử lý ngầm?
- Mức tối thiểu/tối đa chính xác của cổng MoMo cho biến thể 2.1b (hiện giả định 1.000đ trở lên hợp lệ, 1–999đ bị chặn theo ADR-001 §8) — Dev xác nhận lại với tài liệu MoMo hiện hành.
- Có cấm tạo mã giảm giá cố định khiến tổng rơi vào khoảng 1–999đ ngay từ lúc tạo mã (US-013) thay vì chặn ở checkout không?
