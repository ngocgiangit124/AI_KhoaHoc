# Đặc tả UX — US-010: Quản lý đơn hàng

## 1. Luồng người dùng
```
/quan-tri/don-hang → bắt buộc chọn khoảng ngày (≤ 366 ngày) + lọc trạng thái → xem danh sách (email/SĐT đã che)
  → chuyển trang bằng "Trước"/"Tiếp" (cursor, KHÔNG nhảy tới trang N)
  → "Xuất file" → mở modal chọn định dạng + (chỉ Admin) tuỳ chọn kèm liên hệ + lý do → luôn chạy nền → poll trạng thái → tải khi xong
  → bấm 1 đơn → /quan-tri/don-hang/{id} → xem chi tiết (email/SĐT hiện đầy đủ, có ghi audit `order.view_pii`) + lịch sử trạng thái
      → nếu đã paid: nút "Đánh dấu hoàn tiền" → modal xác nhận → hoàn tiền → enrollment bị thu hồi
```

## 2. Màn hình

### 2.1 `/quan-tri/don-hang` — Danh sách đơn hàng
**Bố cục:** thanh filter trên cùng — tabs nhanh theo trạng thái (Tất cả · Chờ thanh toán · Đã thanh toán · Thất bại/Đã huỷ · Đã hoàn tiền) + `<DateRangePicker>` **bắt buộc chọn** (mặc định 30 ngày gần nhất, tối đa 366 ngày — nếu chọn quá 366 ngày thì tự kẹp lại và báo `<Alert variant="warning">` nhỏ) + ô tìm theo mã đơn/tên học sinh/email chính xác/SĐT. Nút "Xuất file" đặt cố định cạnh bộ lọc.

`<DataTable>` cột: Mã đơn | Học sinh (tên đầy đủ + `<PiiMaskedText>` email/SĐT dạng `ng***@gmail.com` / `09****123`) | Khóa học (rút gọn) | Tổng tiền (kèm giảm giá nếu có) | Phương thức | Trạng thái (`<StatusPill>`) | Ngày tạo | Thao tác (Xem chi tiết).

Vì bảng có thể lên tới ~1 triệu dòng (DBA #9): **phân trang kiểu cursor** — `<CursorPagination>` chỉ có 2 nút **"‹ Trước" / "Tiếp ›"** (không có danh sách số trang, không nhảy tới trang N), kèm dòng "Tìm thấy khoảng {total} đơn hàng khớp bộ lọc" phía trên bảng (đếm bằng `COUNT` riêng theo cùng bộ lọc). Nút "Trước" disabled ở trang đầu tiên; nút "Tiếp" disabled khi hết dữ liệu.

**Xuất file — luôn chạy nền (không có nhánh "đồng bộ"):** bấm "Xuất file" → mở `<ExportOptionsModal>` (mục 2.1b) → sau khi xác nhận, nút chuyển trạng thái "Đang chuẩn bị file..." (disabled, icon xoay) + toast "Đã tạo yêu cầu xuất file, bạn có thể theo dõi tiến độ bên dưới". Khu vực "Lịch sử xuất file gần đây" (danh sách nhỏ dưới bảng hoặc panel trượt) hiển thị từng lần xuất: thời điểm tạo, bộ lọc rút gọn, trạng thái (`Đang xử lý` → `Sẵn sàng tải` → hết hạn sau 24h), nút "Tải xuống" khi sẵn sàng (mở `download_url` — signed URL 10 phút).

### 2.1b Modal tuỳ chọn xuất file (`<ExportOptionsModal>`)
- Chọn định dạng: CSV / XLSX (radio)
- **Mặc định KHÔNG kèm thông tin liên hệ** (checkbox "Kèm email/SĐT học sinh" để tắt, không tick sẵn)
- Nếu vai trò là **Admin**: checkbox trên hiện đầy đủ, khi tick → bắt buộc hiện thêm ô "Lý do" (textarea, bắt buộc, ≤ 1000 ký tự) kèm `<Alert variant="warning">` nhỏ: "Tệp xuất sẽ chứa email/số điện thoại học sinh. Hành động này được ghi vào nhật ký hệ thống."
- Nếu vai trò là **Quản lý trang**: checkbox "Kèm email/SĐT học sinh" **ẩn hẳn** (QLT không có quyền này — khác US-009 nơi QLT ngang Admin); chỉ thấy dòng ghi chú nhỏ "Tệp xuất không bao gồm email/số điện thoại học sinh."
- Luôn có dòng ghi chú: "Tệp xuất không bao giờ chứa thông tin liên hệ phụ huynh."
- Nút "Huỷ" / "Tạo yêu cầu xuất"
- Cảnh báo khi bộ lọc hiện tại ước tính > 10.000 dòng: `<Alert variant="warning">` "Yêu cầu này có thể mất vài phút để xử lý do số lượng dòng lớn."
- Nếu tài khoản đã đạt giới hạn 10 lần xuất/ngày: modal hiện `<Alert variant="danger">` "Bạn đã đạt giới hạn 10 lần xuất file trong hôm nay. Vui lòng thử lại vào ngày mai." và nút "Tạo yêu cầu xuất" disabled.

### 2.2 `/quan-tri/don-hang/{id}` — Chi tiết đơn hàng
**Bố cục:** 2 khối —
1. Thông tin đơn: mã đơn, học sinh (tên đầy đủ + email/SĐT **hiện đầy đủ, không che** — vì mở chi tiết đã ghi `audit_logs` `order.view_pii`), danh sách khóa học kèm giá chốt từng khóa, mã giảm giá đã dùng (nếu có) + số tiền giảm, tổng tiền, phương thức (MoMo), mã giao dịch MoMo (`payment_reference`), trạng thái hiện tại (`<StatusPill>` to).
2. "Lịch sử trạng thái": timeline dọc — Tạo đơn (pending) → Thanh toán thành công (paid, kèm thời điểm IPN/đối soát) hoặc Thất bại/Đã huỷ (kèm lý do, ví dụ "Tự động huỷ do quá 12 giờ chưa thanh toán") → Đã hoàn tiền (nếu có, kèm người thực hiện + thời điểm).

Không bao giờ hiển thị thông tin liên hệ phụ huynh ở màn này (kể cả khi đơn thuộc học sinh dưới 18 tuổi).

Nút hành động (chỉ hiện khi `status = paid`): "Đánh dấu hoàn tiền" (variant danger).

### 2.3 Modal xác nhận hoàn tiền (AC4)
`<ConfirmModal variant="danger">`: tiêu đề "Xác nhận hoàn tiền đơn hàng #{mã}", mô tả rõ hậu quả: "Toàn bộ {n} khóa học trong đơn sẽ bị thu hồi quyền truy cập của học sinh ngay lập tức. Hành động này không thể hoàn tác. Hệ thống KHÔNG tự động hoàn tiền qua MoMo — bạn cần xử lý hoàn tiền thực tế ngoài hệ thống trước khi xác nhận." Ô nhập ghi chú (tuỳ chọn) lý do hoàn tiền. Nút "Huỷ" / "Xác nhận hoàn tiền".

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Thông điệp |
|---|---|
| Chưa chọn khoảng ngày | Nút "Áp dụng bộ lọc" disabled + hint đỏ nhỏ "Vui lòng chọn khoảng ngày (tối đa 366 ngày)" |
| Không có đơn khớp filter (AC5) | `<EmptyState>` "Không tìm thấy đơn hàng phù hợp với bộ lọc" |
| Đang tải danh sách | `<Skeleton variant="table-row">` |
| Hoàn tiền 2 lần liên tiếp | Nút đã ẩn/disabled sau lần đầu; nếu vẫn cố (double click nhanh) → toast lỗi "Đơn hàng này đã được xử lý hoàn tiền trước đó" (409 `ALREADY_PROCESSED`) |
| Hoàn tiền thành công | Toast "Đã đánh dấu hoàn tiền, quyền truy cập của học sinh đã được thu hồi" + status pill đổi ngay + timeline cập nhật |
| Đơn pending lâu chưa tới 12h | Badge phụ nhỏ trong bảng "Chờ thanh toán · còn {x} giờ" cạnh status pill để admin dễ nhận biết |
| Xuất file — đang xử lý | Panel "Lịch sử xuất file" hiện dòng `<StatusPill status="processing">` "Đang xử lý" |
| Xuất file — sẵn sàng | `<StatusPill status="ready">` "Sẵn sàng tải" + nút "Tải xuống" |
| Xuất file — lỗi | `<StatusPill status="error">` "Thất bại" + `<Alert variant="danger">` "Xuất báo cáo thất bại, vui lòng thử lại" |
| Xuất file — đã hết hạn tải (>24h) | `<StatusPill status="expired">` "Đã hết hạn" — không còn nút tải |
| Đã đạt giới hạn 10 lần xuất/ngày | Xem mục 2.1b |
| Kèm liên hệ nhưng chưa nhập lý do (Admin) | Lỗi đỏ dưới ô lý do: "Vui lòng nhập lý do khi xuất kèm thông tin liên hệ" |
| Mở chi tiết đơn (xem PII đầy đủ) | Ghi ngầm `audit_logs` `order.view_pii`, không cần thông báo riêng trên UI |
| Không có quyền (Giáo viên/Học sinh truy cập route quản trị) | Trang 403 chuẩn |
| QLT cố xuất kèm liên hệ qua API trực tiếp (bỏ qua UI) | 403 `FORBIDDEN` — UI đã ẩn tuỳ chọn này nên chỉ cần Dev test API, không cần thông điệp UI riêng |

## 4. Component React dùng/tạo mới
- `<DataTable>`, `<StatusPill>`, `<ConfirmModal>`, `<EmptyState>`, `<Toast>`, `<CursorPagination>` (dùng lại — `packages/ui`)
- `<DateRangePicker>` (dùng lại — `packages/ui`, bắt buộc chọn khoảng ngày cho danh sách đơn quản trị)
- `<PiiMaskedText>` (dùng lại — `packages/ui`)
- `<ExportOptionsModal>` (mới — `apps/admin`, mục 2.1b)
- `<OrderTimeline>` (mới — `apps/admin`, riêng cho chi tiết đơn hàng)

## 5. Điểm cần PO duyệt
- Phân trang cursor Trước/Tiếp + tổng số bản ghi (DBA #9) — mặc định an toàn đang áp dụng, xem README §8 mục 12; đánh dấu `⚠ Chờ PO xác nhận`.
- Xuất file kèm liên hệ chỉ Admin + bắt buộc lý do (S14) — mặc định an toàn đang áp dụng, xem README §8 mục 3; đánh dấu `⚠ Chờ PO xác nhận`.
- Định dạng che PII cụ thể (`09****123`, `ng***@gmail.com`) — Dev xác nhận lại quy tắc che chính xác (số ký tự giữ lại đầu/cuối) khi hiện thực `PiiMasker`.
