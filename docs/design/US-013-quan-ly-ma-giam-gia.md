# Đặc tả UX — US-013: Quản lý mã giảm giá (Coupon)

## 1. Luồng người dùng
```
/quan-tri/ma-giam-gia → danh sách (lọc theo trạng thái) → "+ Tạo mã giảm giá" → /quan-tri/ma-giam-gia/tao
  → điền form (loại giảm giá, phạm vi áp dụng, giới hạn lượt/ngày hiệu lực) → lưu
→ bấm 1 mã → xem chi tiết (tiến độ lượt dùng) → "Vô hiệu hoá" nếu cần
```

## 2. Màn hình

### 2.1 `/quan-tri/ma-giam-gia` — Danh sách mã giảm giá
**Bố cục:** `<DataTable>` — cột: Mã | Loại giảm (Phần trăm/Số tiền cố định) + giá trị | Phạm vi (Toàn bộ khóa học / Theo chuyên đề / Theo khóa học cụ thể — badge nhỏ) | Hiệu lực (từ – đến) | Lượt dùng (`<ProgressBar>` dạng "45/100") | Trạng thái (`<StatusPill>`: Active/Inactive/Hết hạn/Hết lượt) | Thao tác (Sửa · Vô hiệu hoá · Xoá). Filter theo trạng thái ở trên (tabs hoặc dropdown, theo AC6). Nút "+ Tạo mã giảm giá" góc phải trên.

Lưu ý hiển thị trạng thái suy diễn: dù `status` DB chỉ có active/inactive, UI cần tự tính hiển thị "Hết hạn" (khi `valid_until` < hôm nay) và "Hết lượt" (khi `used_count >= max_uses`) để admin dễ nhận biết mà không cần đọc số liệu chi tiết (đáp ứng AC4/AC6).

### 2.2 `/quan-tri/ma-giam-gia/tao` (dùng chung tạo & sửa) — Form mã giảm giá
- Mã giảm giá * (input, tự uppercase khi hiển thị/lưu, helper "Không phân biệt hoa/thường khi học sinh nhập")
- Loại giảm giá * — radio 2 lựa chọn: "Phần trăm (%)" / "Số tiền cố định (đ)" → hiện field giá trị tương ứng ngay dưới (validate % ≤ 100 — AC7)
- Ngày bắt đầu hiệu lực * / Ngày kết thúc hiệu lực * (date range, validate kết thúc phải sau bắt đầu — AC5)
- Tổng số lượt sử dụng tối đa (số, để trống = không giới hạn) — **bắt buộc nhập (không để trống)** và **bắt buộc có Ngày kết thúc hiệu lực** khi mã là giảm 100% hoặc giảm cố định ≥ giá khóa rẻ nhất đang bán trong phạm vi áp dụng (ADR-001 §8, S18): validate phía client + lỗi rõ "Mã giảm 100% (hoặc giảm hết giá trị đơn hàng thấp nhất) bắt buộc có giới hạn lượt dùng và ngày hết hạn để tránh bị lộ mã và giữ chỗ ảo."
- Ghi chú cố định (không cho sửa, hiển thị dạng text tĩnh): "Mỗi học sinh chỉ được sử dụng mã này 1 lần"
- Phạm vi áp dụng * — radio 3 lựa chọn: "Toàn bộ khóa học" (mặc định) / "Theo chuyên đề cụ thể" (hiện `<MultiSelect>` chuyên đề) / "Theo khóa học cụ thể" (hiện `<MultiSelect>` khóa học, có tìm kiếm vì danh sách có thể dài)
- Nút "Lưu" / "Huỷ"
- Mã đã có lượt dùng: các field mã/loại giảm/giá trị chuyển chỉ đọc (không cho đổi `code`/`discount_type`/`discount_value` sau khi đã có `coupon_usages` — ADR-001 §6), kèm ghi chú nhỏ "Mã đã được sử dụng nên không thể đổi mã, loại hoặc giá trị giảm."

### 2.3 Trang chi tiết mã (hoặc mở rộng ngay trong form sửa)
- Thanh tiến độ lượt dùng lớn: "45/100 lượt đã dùng"
- Danh sách chuyên đề/khóa học thuộc phạm vi (nếu không phải "Toàn bộ")
- Nút "Vô hiệu hoá" (nếu đang active) → modal xác nhận nhẹ (không phải xoá, có thể bật lại được — thực ra bật lại có nằm trong AC không? Story chỉ có "Vô hiệu hoá", không có "Kích hoạt lại" tường minh — **để nút "Kích hoạt lại" ở trạng thái ẩn/disabled và ghi chú "chờ PO xác nhận" nếu nghiệp vụ cần bật lại mã đã tắt**)

### 2.4 Xoá mã (trường hợp biên)
- Đã từng được dùng trong ≥1 đơn hàng: nút "Xoá" disabled kèm tooltip "Không thể xoá vì mã đã được sử dụng. Hãy vô hiệu hoá thay thế."
- Chưa từng dùng: modal xác nhận xoá bình thường

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Thông điệp |
|---|---|
| Mã trùng (AC2) | "Mã giảm giá đã tồn tại" (dưới field mã) |
| % giảm giá > 100 (AC7) | "Giá trị giảm không được vượt quá 100%" |
| Ngày kết thúc trước ngày bắt đầu (AC5) | "Ngày kết thúc phải sau ngày bắt đầu" |
| Mã 100%/fixed lớn thiếu `max_uses` hoặc `valid_until` | "Mã giảm 100% (hoặc giảm hết giá trị đơn hàng thấp nhất) bắt buộc có giới hạn lượt dùng và ngày hết hạn để tránh bị lộ mã và giữ chỗ ảo." |
| Sửa mã đã có lượt dùng, đổi mã/loại/giá trị | Field chỉ đọc, không cho sửa (xem mục 2.2) |
| Tạo/sửa thành công | Toast "Đã lưu mã giảm giá" |
| Vô hiệu hoá thành công (AC3) | Toast "Đã vô hiệu hoá mã giảm giá" + status pill đổi ngay |
| Danh sách rỗng | `<EmptyState>` "Chưa có mã giảm giá nào", nút "+ Tạo mã đầu tiên" |
| Không khớp filter | `<EmptyState>` "Không có mã nào khớp bộ lọc" |
| Học sinh/Giáo viên truy cập route này | Trang 403 |

## 4. Component React dùng/tạo mới
- `<DataTable>`, `<StatusPill>`, `<ProgressBar>`, `<MultiSelect>`, `<Modal>`, `<ConfirmModal>`, `<Toast>`, `<EmptyState>` (dùng lại — `packages/ui`)
- `<CouponScopeSelector>` (mới — `apps/admin`, gói 3 radio + multiselect tương ứng ở mục 2.2)

## 5. Điểm cần PO duyệt
- **Cách tính lượt dùng mã giảm giá (BR7)** — `used_count`/`coupon_usages` chỉ ghi khi đơn `paid` (khớp diễn giải của BA), nhưng **có cơ chế giữ chỗ có thời hạn tối đa 30 phút** cho đơn `pending` mang mã (`orders.coupon_hold_until`, ADR-001 §6) để tránh quá bán khi nhiều học sinh thanh toán đồng thời — **cần PO xác nhận chính thức** cả 2 phần (thời điểm trừ lượt và cơ chế giữ chỗ 30 phút); nếu PO muốn đổi hướng "trừ lượt ngay khi tạo đơn, hoàn khi huỷ", chi phí thay đổi thấp (ADR-001 §6). Phía học sinh (US-004/US-005), khi hết chỗ mã do giữ chỗ, đã thiết kế thông báo "Mã giảm giá đã hết lượt sử dụng" (xem US-005 mục 2.2 biến thể `COUPON_EXHAUSTED`).
- Mã giảm 100% (hoặc fixed ≥ giá khóa rẻ nhất) bắt buộc `max_uses` + `valid_until` (S18) — mặc định an toàn đang áp dụng, đánh dấu `⚠ Chờ PO xác nhận`.
- Có cần chức năng "Kích hoạt lại" mã đã vô hiệu hoá không (không có trong AC hiện tại, Designer để trống UI, cần PO xác nhận).
- Phân biệt "mã giảm giá" (US-013) và "mã giới thiệu" (US-001) có hợp nhất không — nếu PO xác nhận hợp nhất, cần thiết kế lại đáng kể cả 2 luồng.
