# Đặc tả UX — US-012: Đăng ký khóa học miễn phí và phê duyệt

## 1. Luồng người dùng
```
Học sinh: /khoa-hoc/{slug} (miễn phí) → "Đăng ký" → trạng thái đổi "Đang chờ duyệt" ngay
Giáo viên phụ trách/Admin/QLT: /quan-tri/khoa-hoc/{id}/duyet-dang-ky → danh sách yêu cầu chờ duyệt
   → "Duyệt" → học sinh có quyền học ngay (US-006)
   → "Từ chối" (nhập lý do tuỳ chọn) → học sinh nhận thông báo, có thể đăng ký lại
```

## 2. Màn hình

### 2.1 Phía học sinh — trạng thái trên trang chi tiết khóa học (US-003)
Đã mô tả ở `docs/design/US-003-trang-chi-tiet-khoa-hoc.md` mục 3 (nút "Đăng ký" / "Đang chờ duyệt" / "Đăng ký lại"). Bổ sung ở đây:
- Sau khi bấm "Đăng ký" thành công: toast "Đã gửi yêu cầu đăng ký, vui lòng chờ duyệt"
- Sau khi được duyệt: nếu học sinh đang ở trang đó, badge cập nhật thành "Vào học" (qua thông báo trong ứng dụng/refresh); đồng thời nên có mục "Thông báo" nhỏ ở header (chuông) hiển thị "Yêu cầu đăng ký khóa học 'Đại số cơ bản lớp 6' đã được duyệt" — nếu chưa có hệ thống thông báo trong app ở MVP, tối thiểu cần email/thông báo khi quay lại trang (câu hỏi mở US-012 về kênh thông báo, xem mục 5)
- Sau khi bị từ chối: trang chi tiết hiện `<Badge variant="danger">` "Yêu cầu đã bị từ chối" + lý do (nếu có) + nút "Đăng ký lại"

### 2.2 `/quan-tri/khoa-hoc/{id}/duyet-dang-ky` — Danh sách yêu cầu chờ duyệt
**Bố cục:** `<DataTable>` — cột: Học sinh (tên + lớp, **email/SĐT đã che** — `<PiiMaskedText>`, dùng chung quy tắc che PII với US-010) | Thời điểm gửi yêu cầu | Thao tác (nút "Duyệt" variant success, "Từ chối" variant danger), sắp xếp theo thời gian gửi sớm nhất trước (AC7). Có phân trang nếu nhiều (câu hỏi mở về giới hạn quy mô đã lưu ý số lượng lớn cần phân trang).

Trang này chỉ hiện với người có quyền duyệt khóa học cụ thể đó (giáo viên phụ trách/Admin/QLT — BR4/AC6); có thể truy cập từ menu quản trị khóa học (tab "Yêu cầu chờ duyệt" trong trang sửa khóa học US-009, badge số lượng đang chờ) hoặc trang tổng hợp riêng nếu 1 giáo viên phụ trách nhiều khóa học miễn phí.

**Ghi chú kỹ thuật:** nếu Admin/GV đang thao tác chuyển khóa học miễn phí này sang có phí ở US-009 trong lúc còn yêu cầu `pending`, hệ thống chặn thao tác đó (README §3.3) — form sửa khóa học (US-009) cần hiện lý do chặn kèm link quay lại màn duyệt đăng ký này. Mail thông báo kết quả duyệt/từ chối phụ thuộc feature flag `enrollment_decision_mail` (mặc định **tắt** — chờ PO, xem mục 5).

### 2.3 Modal "Từ chối" (AC3)
`<ConfirmModal variant="danger">`: tiêu đề "Từ chối yêu cầu của {tên học sinh}", textarea "Lý do (không bắt buộc)", nút "Huỷ"/"Xác nhận từ chối".

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Thông điệp |
|---|---|
| Học sinh cố đăng ký lại khi đang pending (AC4) | Toast lỗi: "Bạn đã gửi yêu cầu, vui lòng chờ duyệt" |
| Danh sách chờ duyệt rỗng | `<EmptyState>` "Hiện không có yêu cầu nào đang chờ duyệt" |
| Duyệt thành công | Toast "Đã duyệt yêu cầu của {tên học sinh}" + dòng biến mất khỏi danh sách chờ |
| Từ chối thành công | Toast "Đã từ chối yêu cầu của {tên học sinh}" |
| Duyệt/từ chối 2 lần liên tiếp (double click) | Nút disable ngay sau click đầu tiên, tránh double submit |
| Giáo viên không phụ trách cố truy cập (AC6) | Trang 403 |
| Đang tải danh sách | `<Skeleton variant="table-row">` × 5 |

## 4. Component React dùng/tạo mới
- `<DataTable>`, `<ConfirmModal>`, `<Badge>`, `<Toast>`, `<EmptyState>`, `<PiiMaskedText>` (dùng lại — `packages/ui`)
- Badge số lượng "chờ duyệt" cạnh menu quản trị khóa học — dùng `<Badge variant="warning">` dạng số nhỏ (giống notification dot)

## 5. Điểm cần PO duyệt
- Kênh thông báo khi yêu cầu được duyệt/từ chối (email? chỉ hiển thị trong app khi quay lại trang?) — câu hỏi mở của US-012, ảnh hưởng có cần thiết kế thêm màn "Trung tâm thông báo" hay email template riêng không. Mặc định kỹ thuật hiện tại: mail qua flag `enrollment_decision_mail` **tắt** (README §8) — đánh dấu `⚠ Chờ PO xác nhận`.
- Xử lý yêu cầu pending khi khóa học đổi từ miễn phí sang có phí (câu hỏi mở US-012) — mặc định kỹ thuật hiện tại: **chặn** đổi giá khi còn yêu cầu pending (README §8), ảnh hưởng có cần thêm trạng thái/thông báo đặc biệt trên trang chi tiết khóa học hay không.
