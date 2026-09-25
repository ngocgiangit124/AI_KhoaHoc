# Đặc tả UX — US-009: Quản trị khóa học (tạo, sửa, xoá, xuất bản)

## 1. Luồng người dùng
```
/quan-tri/khoa-hoc (danh sách — lọc theo quyền: Admin/QLT thấy tất cả, Giáo viên chỉ thấy khóa học mình phụ trách)
  → "Tạo khóa học mới" → /quan-tri/khoa-hoc/tao → điền form → lưu (status=draft) → chuyển sang trang sửa
  → bấm 1 khóa học → /quan-tri/khoa-hoc/{id}/sua
      → Tab "Thông tin chung" (sửa tên, giá, chuyên đề, giáo viên phụ trách, ảnh)
      → Tab "Chương & bài học" (cây kéo-thả, thêm/sửa/xoá chương/bài, toggle nguồn video)
      → nút "Xuất bản" (chỉ Admin/QLT thấy) / "Ngừng bán" / "Xoá"
```

## 2. Màn hình

### 2.1 `/quan-tri/khoa-hoc` — Danh sách khóa học (quản trị)
**Bố cục:** `<DataTable>` chuẩn quản trị — cột: Ảnh nhỏ | Tên khóa học | Lớp | Chuyên đề | Giáo viên phụ trách (avatar nhóm) | Giá | Trạng thái (`<StatusPill>`: Nháp/Đã xuất bản/Ngừng bán) | Số học sinh | Thao tác (Sửa · Xuất bản/Ngừng bán · Xoá).
Trên cùng: ô tìm kiếm theo tên, filter theo Lớp/Trạng thái/Chuyên đề, nút "+ Tạo khóa học mới" (góc phải).
Giáo viên đăng nhập: bảng chỉ hiển thị khóa học mình phụ trách (AC6), ẩn cột/nút Xuất bản-Ngừng bán (không có quyền — BR2).

### 2.2 `/quan-tri/khoa-hoc/tao` và `/quan-tri/khoa-hoc/{id}/sua` — Tab "Thông tin chung"
Form 1 cột (desktop 2 cột: trái nội dung chính, phải ảnh đại diện + trạng thái):
- Tên khóa học * → tự sinh slug (hiển thị dưới dạng preview "URL: /khoa-hoc/hinh-hoc-lop-9", cho sửa tay nếu trùng)
- Lớp học * (select 6–12)
- Chuyên đề * (đa lựa chọn — dùng chung `<MultiSelect>` với bộ lọc US-002 và CRUD US-011)
- Mô tả ngắn * / Mô tả chi tiết (rich text đơn giản hoặc textarea)
- Học phí * (số, hiện "Miễn phí" nếu = 0, có toggle nhanh "Khóa học miễn phí")
- Ảnh đại diện * (`<FileUpload accept="image/jpeg,image/png,image/webp">`, preview, validate định dạng/kích thước — **không nhận SVG/GIF/HTML**, kể cả file đổi đuôi; helper text "JPG/PNG/WebP, tối đa 2MB. Không nhận SVG." Lỗi khi tải sai định dạng: "Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF." Server đọc lại và mã hoá lại thành WebP, bỏ EXIF — ảnh preview trên UI có thể khác nhẹ ảnh gốc sau khi lưu)
- Giáo viên phụ trách * (đa lựa chọn `<MultiSelect>`, chỉ hiện tài khoản role = giáo viên; validate luôn còn ≥1 người khi gỡ bớt)
- Nút "Lưu nháp" (secondary) — luôn khả dụng
- Nút "Xuất bản" (primary, chỉ Admin/QLT) — disabled kèm tooltip nếu chưa đủ điều kiện (chưa có chương/bài, xem 2.4)
- Nếu là giáo viên tự tạo: không có nút Xuất bản, thay bằng ghi chú "Khóa học sẽ được Admin xem xét và xuất bản"

### 2.3 Tab "Chương & bài học"
**Bố cục:** cây danh sách — mỗi Chương là 1 khối gập/mở, chứa danh sách Bài học bên trong, có tay cầm kéo-thả (⠿) để sắp xếp thứ tự cả chương lẫn bài (BR7). Nút "+ Thêm chương" cuối danh sách; mỗi chương có nút "+ Thêm bài học".

**Form thêm/sửa 1 bài học (modal hoặc panel trượt):**
- Tên bài học *
- Toggle "Cho xem thử (preview)" * — off theo mặc định, đặt **trước** khối chọn nguồn video vì quyết định nguồn nào được phép dùng
- `<VideoSourceToggle>` "Tải video lên hệ thống" / "Dán link video ngoài" — **tuỳ chọn "Dán link video ngoài" chỉ bật (không disabled) khi toggle "Cho xem thử" đang BẬT**; nếu bài chưa bật preview, tuỳ chọn "Dán link ngoài" bị mờ/disabled kèm tooltip "Chỉ bài cho xem thử mới được dùng link video ngoài. Bài trả phí phải tải video lên hệ thống." (US-009 BR10, ADR-002 §S13 — bảo vệ nội dung trả phí)
  - Nếu "Tải lên": vùng kéo-thả file (`<TusVideoUpload>`) + thanh tiến trình upload + `<VideoStatusBadge>` (xem dưới)
  - Nếu "Dán link" (chỉ khi preview bật): input URL, chỉ nhận YouTube/Vimeo (hiển thị hint "Dán link YouTube hoặc Vimeo công khai")
- Thời lượng (phút\:giây) — tự nhận diện sau khi video xử lý xong (nguồn tải lên) hoặc để trống (nguồn link ngoài)
- Nút "Lưu bài học"

**Trạng thái xử lý video sau khi tải lên (`<VideoStatusBadge>`, chỉ áp dụng nguồn "Tải video lên hệ thống"):**

| Trạng thái | Badge | Ý nghĩa | Hành động khả dụng |
|---|---|---|---|
| Đang tải lên | `info` (sky) "Đang tải lên… {%}" | File đang truyền qua TUS, chưa xong | Có thể huỷ tải; không lưu được bài học tới khi xong hoặc chọn nguồn khác |
| Đang xử lý | `warning` (amber) "Đang xử lý video" | Đã tải xong, server đang chuyển mã (transcode) sang HLS | Có thể lưu các trường khác của bài học, nhưng học sinh chưa xem được; hiện dòng nhỏ "Video đang được xử lý, có thể mất vài phút" |
| Sẵn sàng | `success` (emerald) "Sẵn sàng phát" | Video đã có bản HLS, phát được | Có thể xuất bản bài học bình thường |
| Lỗi | `danger` (rose) "Xử lý thất bại" | File hỏng/không đúng định dạng hoặc quá thời gian xử lý | `<Alert variant="danger">` "Video xử lý thất bại, vui lòng tải lại file khác." + nút "Tải lại" |

Danh sách bài học trong cây chương/bài (mục 2.3) cũng hiện `<VideoStatusBadge>` thu nhỏ cạnh mỗi bài dùng nguồn "Tải lên" đang ở trạng thái khác "Sẵn sàng", để Admin/GV biết bài nào chưa xem/xuất bản được ngay.

### 2.4 Trạng thái chặn xuất bản (AC3)
Khi bấm "Xuất bản" mà chưa đủ điều kiện: `<Modal>`/`<Alert variant="danger">` ngay tại chỗ: "Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản." Nút bị disable kèm tooltip cùng nội dung để người dùng hiểu trước khi bấm.

### 2.5 Xoá khóa học
- Chưa có enrollment (AC5): `<ConfirmModal variant="danger">` "Xoá khóa học 'Đại số lớp 7'? Hành động này không thể hoàn tác." → Huỷ / Xoá
- Đã có enrollment (AC4): nút "Xoá" đổi thành disabled kèm tooltip "Không thể xoá vì đã có học sinh mua. Hãy chuyển sang Ngừng bán." → chỉ hiện nút "Ngừng bán" với modal cảnh báo riêng: "Ngừng bán sẽ ẩn khóa học khỏi danh mục công khai, nhưng học sinh đã mua vẫn giữ quyền truy cập."

## 3. Bảng trạng thái UI & thông điệp bổ sung

| Trạng thái | Thông điệp |
|---|---|
| Giáo viên truy cập khóa học không phụ trách (AC7) | Trang 403: "Bạn không có quyền truy cập khóa học này." |
| Gán giáo viên sai role | Lỗi validate: "Chỉ có thể chọn tài khoản có vai trò Giáo viên" |
| Gỡ giáo viên phụ trách cuối cùng | Chặn với thông báo: "Khóa học cần có ít nhất 1 giáo viên phụ trách" |
| Ảnh sai định dạng (SVG/GIF/HTML) hoặc quá lớn | "Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF, dung lượng tối đa 2MB" |
| Dán link ngoài khi bài chưa bật "Cho xem thử" | Tuỳ chọn "Dán link ngoài" disabled + tooltip "Chỉ bài cho xem thử mới được dùng link video ngoài" |
| Video đang xử lý mà bấm "Xuất bản khóa học" | Không chặn xuất bản khóa học (chỉ chặn nếu chưa có bài nào), nhưng bài đó hiện badge "Đang xử lý video" cho tới khi xong — học sinh tạm thời chưa xem được bài này |
| Video xử lý thất bại | Xem bảng trạng thái video ở mục 2.3 |
| Đang tải danh sách | `<Skeleton variant="table-row">` 5 dòng |
| Danh sách rỗng (giáo viên chưa có khóa học nào) | `<EmptyState>` "Bạn chưa phụ trách khóa học nào", nút "Tạo khóa học mới" |
| Lưu thành công | Toast "Đã lưu thay đổi" |
| Xuất bản thành công | Toast "Đã xuất bản khóa học" + status pill đổi ngay |

## 4. Component React dùng/tạo mới
- `<DataTable>`, `<StatusPill>`, `<MultiSelect>`, `<Modal>`, `<Toast>` (dùng lại — `packages/ui`)
- `<VideoSourceToggle>` (mới — `apps/admin`, có prop `isPreviewOnly` điều khiển disable "Dán link ngoài")
- `<VideoStatusBadge>` (mới — `apps/admin`)
- `<ChapterLessonTree>` (mới — `apps/admin`, kéo-thả bằng `@dnd-kit`)
- `<FileUpload>` (mới — `apps/admin`, dùng cho ảnh đại diện) và `<TusVideoUpload>` (mới — `apps/admin`, dùng cho video tải lên qua TUS)

## 5. Điểm cần PO duyệt
- Không phát sinh điểm "chờ PO xác nhận" mới trong 3 điểm chính đã nêu; lưu ý 2 câu hỏi mở của US-009 (quy trình duyệt tường minh riêng biệt, giới hạn số khóa học giáo viên tự tạo) không ảnh hưởng trực tiếp tới UI hiện tại nhưng nên xác nhận sớm vì có thể cần thêm trạng thái "Chờ duyệt" khác biệt với "Nháp" trong `<StatusPill>`.
- Ngưỡng thời gian coi là "video xử lý thất bại/quá lâu" (dùng cho badge lỗi) — Dev/Architect xác nhận (liên quan `videos:check-stuck`, ADR-002).
