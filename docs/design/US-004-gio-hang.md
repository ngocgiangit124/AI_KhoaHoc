# Đặc tả UX — US-004: Giỏ hàng khóa học

## 1. Luồng người dùng
```
/khoa-hoc/{slug} → "Mua ngay" → thêm vào giỏ → /gio-hang
/gio-hang → xoá bớt khóa học / nhập mã giảm giá → "Áp dụng" → tổng tiền cập nhật
/gio-hang → "Tiến hành thanh toán" → /checkout (US-005)
```
Yêu cầu đăng nhập; khách chưa đăng nhập bấm "Thêm vào giỏ hàng" → chuyển `/dang-nhap` (AC6).

## 2. Màn hình

### 2.1 `/gio-hang` — Giỏ hàng
**Bố cục mobile:** danh sách dòng khóa học (ảnh nhỏ, tên, giá, nút xoá icon thùng rác) → khối "Mã giảm giá" (input + nút Áp dụng) → khối tổng tiền (tạm tính, giảm giá, tổng cộng) → nút "Tiến hành thanh toán" sticky đáy.

**Bố cục desktop:** 2 cột — trái danh sách item (bảng đơn giản: Khóa học | Giá | Xoá), phải card tóm tắt đơn hàng sticky (mã giảm giá + tổng tiền + nút thanh toán).

**Mỗi dòng khóa học hiển thị:** ảnh, tên khóa học, lớp, giá; nếu mã giảm giá áp dụng nhưng khóa này *ngoài phạm vi*, hiển thị nhãn nhỏ "Không áp dụng mã" cạnh giá (giữ giá gốc, theo AC11).

**Khối mã giảm giá:**
- Input text (uppercase tự động khi hiển thị) + nút "Áp dụng"
- Khi thành công: đổi thành dòng `Mã "TOAN2026" đã áp dụng ✓` (màu emerald) + nút nhỏ "Gỡ mã" (x), dòng "Giảm giá: -50.000đ" tách biệt trong bảng tổng tiền
- Khi lỗi: text đỏ ngay dưới input theo loại lỗi (bảng mục 3)
- `⚠ Chờ PO xác nhận cách tính lượt dùng` — ghi chú nhỏ (không hiển thị cho học sinh, chỉ ghi trong tài liệu này) rằng UI giả định lượt dùng chỉ trừ khi đơn thanh toán thành công, không "giữ chỗ" ngay khi áp dụng ở bước này.

**Khối tổng tiền:**
```
Tạm tính:            1.200.000đ
Giảm giá (TOAN2026):   -50.000đ
──────────────────────────────
Tổng cộng:            1.150.000đ
```

### 2.2 Giỏ hàng trống
`<EmptyState>`: icon giỏ hàng, "Giỏ hàng của bạn đang trống", nút "Khám phá khóa học" → `/khoa-hoc`.

## 3. Bảng trạng thái UI & thông điệp

| Tình huống | Thông điệp |
|---|---|
| Thêm khóa học đã có trong giỏ | Toast: "Khóa học đã có trong giỏ hàng" |
| Thêm khóa học đã sở hữu | Toast lỗi: "Bạn đã sở hữu khóa học này" |
| Xoá item thành công | Toast: "Đã xoá khỏi giỏ hàng" (tổng tiền tự cập nhật) |
| Mã không tồn tại | "Mã giảm giá không tồn tại" |
| Mã hết hạn | "Mã giảm giá đã hết hạn" |
| Mã hết lượt dùng | "Mã giảm giá đã hết lượt sử dụng" |
| Mã đã dùng trước đó | "Bạn đã sử dụng mã này rồi" |
| Mã không thuộc phạm vi giỏ hàng hiện tại | "Mã không áp dụng được cho giỏ hàng hiện tại" |
| Áp mã thành công | Toast: "Áp dụng mã giảm giá thành công" |
| Đổi mã (AC9) | Toast: "Đã thay thế bằng mã mới" |
| Mã tự động bị gỡ do xoá khóa học liên quan (AC10) | `<Alert variant="warning">` "Mã giảm giá đã được gỡ vì không còn khóa học phù hợp trong giỏ hàng" |
| Khóa học trong giỏ bị gỡ bán (unpublish) | Dòng item có `<Badge variant="warning">` "Ngừng bán" + nút "Xoá khỏi giỏ" nổi bật, chặn thanh toán tới khi xoá |
| Đang tải giỏ hàng | `<Skeleton variant="text">` 2–3 dòng item |
| Lỗi tải giỏ hàng | `<Alert variant="danger">` "Không tải được giỏ hàng, vui lòng thử lại" |

## 4. Component React dùng/tạo mới
- `<EmptyState>`, `<Alert>`, `<Toast>`, `<Badge>`, `<Skeleton>` (dùng lại — `packages/ui`)
- `<CouponInput>` (mới — `apps/web`) — gói input + trạng thái đã áp dụng/lỗi, tái sử dụng ở `/checkout` nếu cần hiển thị lại
- `<OrderSummary>` (mới — `apps/web`) — khối tổng tiền tái sử dụng ở US-005 checkout

## 5. Điểm cần PO duyệt
- Cách tính lượt dùng mã giảm giá (US-013 BR7) — xem mục 8 design-system.md.
