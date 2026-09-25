# Đặc tả UX — US-011: Quản lý chuyên đề khóa học (CRUD)

## 1. Luồng người dùng
```
/quan-tri/chuyen-de → danh sách → "+ Tạo chuyên đề" → modal form → lưu
→ bấm "Sửa" 1 dòng → modal form (điền sẵn) → lưu
→ bấm toggle Ẩn/Hiện → đổi trạng thái ngay
→ bấm "Xoá" → nếu đang gán khóa học: bị chặn + gợi ý Ẩn; nếu chưa gán: modal xác nhận → xoá
```

## 2. Màn hình

### 2.1 `/quan-tri/chuyen-de` — Danh sách chuyên đề
**Bố cục:** `<DataTable>` đơn giản — cột: Tên chuyên đề | Số khóa học đang gán | Trạng thái (toggle Active/Ẩn) | Thao tác (Sửa · Xoá). Nút "+ Tạo chuyên đề" góc phải trên. Không cần filter phức tạp (danh sách thường ngắn), có ô tìm nhanh theo tên nếu danh sách dài.

### 2.2 Form tạo/sửa chuyên đề (modal, dùng chung tạo & sửa)
- Tên chuyên đề * (input, hiển thị preview slug tự sinh bên dưới, ví dụ "Slug: hinh-hoc")
- Trạng thái: toggle Active/Ẩn (mặc định Active khi tạo mới)
- Nút "Lưu" / "Huỷ"

### 2.3 Xử lý xoá (AC3/AC4)
- Đang gán ≥1 khóa học: bấm "Xoá" → `<Modal>` (không cho xác nhận xoá, chỉ có nút "Đã hiểu" + gợi ý): "Chuyên đề này đang được gán cho {n} khóa học nên không thể xoá. Bạn có thể chuyển sang trạng thái Ẩn thay thế." + nút tắt nhanh "Ẩn chuyên đề này"
- Chưa gán khóa học nào: `<ConfirmModal variant="danger">` bình thường "Xoá chuyên đề 'Số học'? Hành động này không thể hoàn tác." → Huỷ/Xoá

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Thông điệp |
|---|---|
| Tên trùng (không phân biệt hoa/thường) | "Chuyên đề đã tồn tại" (dưới field tên) |
| Tên rỗng | "Vui lòng nhập tên chuyên đề" |
| Tạo/sửa thành công | Toast "Đã lưu chuyên đề" |
| Ẩn thành công | Toast "Đã ẩn chuyên đề khỏi bộ lọc công khai" |
| Hiện lại thành công | Toast "Đã hiển thị lại chuyên đề" |
| Danh sách rỗng (hệ thống mới, chưa seed) | `<EmptyState>` "Chưa có chuyên đề nào", nút "+ Tạo chuyên đề đầu tiên" |
| Giáo viên/Học sinh truy cập route này | Trang 403 |

## 4. Component React dùng/tạo mới
- `<DataTable>`, `<Modal>`, `<ConfirmModal>`, `<Toast>` (dùng lại — `packages/ui`)
- `<MultiSelect>` chuyên đề — component dùng chung đã định nghĩa ở design-system.md, dữ liệu chuyên đề active từ đây feed vào US-002 (bộ lọc) và US-009 (form khóa học)

## 5. Điểm cần PO duyệt
- Không phát sinh điểm mới; lưu ý 2 câu hỏi mở của story (thứ tự hiển thị tuỳ chỉnh, danh sách chuyên đề khởi tạo/seed) không ảnh hưởng cấu trúc màn hình, chỉ ảnh hưởng dữ liệu mẫu ban đầu.
