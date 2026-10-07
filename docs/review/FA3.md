# REVIEW: FA3 — Khóa học quản trị trên design v2
**Kết luận:** APPROVE (không BLOCKER; 2 SHOULD nên sửa trước khi chuyển QA hoặc ghi nợ rõ, 5 NIT)
**Phạm vi:** thay đổi chưa commit trong `frontend/apps/admin` (khoa-hoc, components/courses, lib/courses, shell, nav, SubjectsScreen, e2e) và `packages/ui/src/v2` (AdminFrame, OtpInput + test) · ~45 file. Không review `apps/web`, `TurnstileWidget`, `backend/`.
**Đã chạy:** `admin lint` sạch · `admin typecheck` sạch · `admin test` 169/169 pass (15 file) · `ui test` 27/27 pass. Không build/e2e.

## Tổng quan
Làm chắc tay. Quyền dựa vào `abilities` của API (không tự suy từ vai trò), trường bị khoá không bao giờ gửi, form tạo/sửa đúng hợp đồng T08 (multipart + `_method=PUT` khi có ảnh, JSON khi không; `teacher_ids` chỉ staff gửi khi tạo, sửa qua `PUT .../teachers`). Xử lý lỗi đủ: 422 xuống đúng field (kể cả `subject_ids.N`, `teacher_ids`), 413 (cả dạng NetworkError do Nginx thiếu CORS), 403, 404 → "không còn tồn tại", 409 `COURSE_HAS_ENROLLMENTS`/`ALREADY_PROCESSED`/`INVALID_COURSE_STATE`, 422 `COURSE_NOT_PUBLISHABLE`. Không còn import v1 trong `components/courses`, `lib/courses`, app admin (LegacyToastProvider đã gỡ; `grep` không còn `CheckboxGroup`/`ManualOrderModal`). Không `dangerouslySetInnerHTML`, `localStorage`, `any`. Xem trước ảnh dùng `data:` URL (đúng CSP admin). 3 Minor của QA FA-V2 đều đã sửa và có test (BUG-3 có `OtpInput.test.tsx`).

## Quyết định dev đã được hỏi
- Thao tác xuất bản/ngừng bán/xoá/thứ tự nổi bật/gán GV chỉ ở trang sửa, danh sách chỉ "Sửa": **chấp nhận**. Các AC2–AC5, AC10 vẫn đạt (đủ ở trang sửa), tránh nhầm thao tác nguy hiểm trên bảng, 375px gọn hơn. Hệ quả cần ghi nhận: staff không thấy thứ tự nổi bật ở danh sách (R5).
- Bỏ modal + cột "Thứ tự": **chấp nhận** (ô nằm trong thẻ "Hiển thị", lưu riêng, Enter không gửi form cha, có test).
- `DEFAULT_LANDING` giữ `/quan-tri`: **chấp nhận** (mọi vai trò đều có trang Tổng quan; không có lý do buộc đổi).
- Giá: GV nhập được khi TẠO (đúng backend: `price` required ở `StoreCourseRequest` cho cả GV), khi sửa chỉ staff (`abilities.edit_price`). Khớp T08.

## Phát hiện
### R1 [SHOULD] Lưu form thông tin làm mất bản nháp chưa lưu của thẻ Giáo viên và ô Thứ tự nổi bật
- Vị trí: `components/courses/CourseEditScreen.tsx:192` (`key={`${course.id}-${formVersion}`}`) và `:206-216` (TeachersCard/ManualOrderField nằm trong prop `aside` của `CourseForm`).
- Vấn đề: `onSaved` tăng `formVersion` để dựng lại form, nhưng `aside` là con của `CourseForm` nên TeachersCard và ManualOrderField cũng bị unmount/mount lại. Staff chỉnh danh sách giáo viên (chưa bấm "Lưu giáo viên") rồi bấm "Lưu thay đổi" của form → danh sách GV quay về như cũ, alert "Có thay đổi chưa lưu" biến mất, không có cảnh báo. Ô thứ tự đang gõ dở cũng mất. Mất dữ liệu nhập, không mất dữ liệu server.
- Đề xuất: không remount toàn bộ `CourseForm`; hoặc đặt `key` riêng cho form và render `aside` bằng cùng cách nhưng giữ state ở ngoài (đưa TeachersCard/ManualOrderField ra khỏi `<form>` bằng portal/cột riêng), hoặc thay việc remount bằng hàm `reset(valuesFromCourse(saved))` nội bộ của `CourseForm` khi `course` đổi. Thêm test: đổi GV, lưu form, GV vẫn ở trạng thái đã chọn.

### R2 [SHOULD] TeacherPicker: nút bỏ chip 28px, mất focus sau khi bỏ, không báo thay đổi
- Vị trí: `components/courses/TeacherPicker.tsx:48-61` (`className="size-7"`), `:37-39`.
- Vấn đề: (a) nút "Bỏ …" cao/rộng 28px, thấp hơn quy ước chạm 44px mà task này vừa sửa cho menu (BUG-1) — khó bấm ở 375px, dễ bấm nhầm người liền kề. (b) Bấm bỏ → nút bị unmount, focus rơi về `<body>`; người dùng bàn phím/đọc màn hình phải Tab lại từ đầu trang. (c) Thêm/bỏ GV không có vùng thông báo (`aria-live`), người đọc màn hình không biết đã thêm. (d) Ô chọn dùng `<select>` gốc: tốt cho bàn phím và mobile, giữ.
- Đề xuất:
  ~~~tsx
  // đếm: báo thay đổi
  <p className="text-sm text-ink-soft" role="status" aria-live="polite">…</p>
  // chip: vùng chạm >= 44px dưới sm (padding tàng hình), vd. className="size-7 max-sm:size-11"
  // sau khi bỏ: đưa focus vào <select> "Thêm giáo viên" (ref) hoặc nút bỏ của chip kế bên
  ~~~
  Thêm test bàn phím: Tab tới nút bỏ, Enter, focus còn nằm trong nhóm.

### R3 [NIT] Xuất bản / Ngừng bán không cảnh báo khi form có thay đổi chưa lưu
- Vị trí: `CourseEditScreen.tsx:150-163`. Staff sửa tiêu đề rồi bấm "Xuất bản": khóa xuất bản với dữ liệu cũ, form vẫn hiện bản nháp chưa lưu. Gợi ý: nếu form dirty, báo "Hãy lưu thay đổi trước" hoặc chặn. Tương tự `beforeunload` chỉ theo dõi form chính, không theo dõi TeachersCard/ManualOrderField.

### R4 [NIT] Chạm 44px và nhãn "Sửa" ở mobile
- `CoursesScreen.tsx:171` ẩn chữ "Sửa" dưới `sm` (còn icon + `aria-label`), trong khi `design-system-v2.md` §14 ghi "Sửa có chữ". Bảng đã gộp cột nên có chỗ cho chữ; cân nhắc giữ chữ.
- `CourseForm.tsx:305` checkbox chuyên đề `min-h-10` (40px) ở mobile; các ô khác của form đã `max-sm:h-11`.

### R5 [NIT] Staff không thấy "Thứ tự nổi bật" ở danh sách
- Hệ quả của việc bỏ cột. Muốn rà thứ tự phải mở từng khóa. Có thể thêm "Nổi bật #n" vào dòng phụ cho staff khi `manual_order != null` (không tốn cột).

### R6 [NIT] Nội dung hướng dẫn sau khi tạo chưa khớp thực tế
- `CourseCreateScreen.tsx:19` toast "Tiếp theo: thêm chương và bài học" và `CourseForm.tsx:415`, nhưng tab "Chương & bài" đang "Sắp có" (FA4). Staff không xuất bản được khóa mới tới khi FA4 xong (AC3 chặn đúng). Đổi lời hoặc ghi vào board là phụ thuộc FA4 để QA không báo bug.

### R7 [NIT] Vặt
- `packages/ui/src/v2/layout/AdminFrame.tsx:52,65`: thứ tự class `lg:min-h-10` chen giữa (`...text-sm`, `...transition-colors lg:min-h-10 duration-150`); xếp lại cho gọn. BUG-1/BUG-2 chưa có test hồi quy (chấp nhận được vì là CSS).
- `e2e/seed-e2e-courses.sh --clean` `forceDelete` theo `LIKE 'E2E FA3 %'` chạy qua `docker compose exec`: nên thêm kiểm `APP_ENV=local` trước khi xoá. Mật khẩu `Password123!` chỉ dùng cho tài khoản seed local, chấp nhận.

## Đối chiếu acceptance criteria / yêu cầu
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 tạo (staff, đủ trường, ≥1 GV, ảnh bắt buộc) | `CourseForm` mode create + `buildCreateFormData` | Đạt; validate client đúng + 422 xuống field |
| AC2 xuất bản | `useCourseActions.publish` (trang sửa) | Đạt; 409 trạng thái cũ → tải lại |
| AC3 chặn khi chưa có chương/bài | 422 `COURSE_NOT_PUBLISHABLE` → hộp thoại với đúng câu | Đạt; có test |
| AC4 có học sinh: chặn xoá, gợi ý Ngừng bán | nút Xoá khoá + lời giải thích; 409 `COURSE_HAS_ENROLLMENTS` → hộp thoại + nút Ngừng bán | Đạt; xử lý cả số liệu cũ/pending |
| AC5 xoá khi chưa có học sinh | `ConfirmDialog` → `DELETE` → về danh sách | Đạt |
| AC6 GV chỉ thấy khóa mình | server scope `visibleTo`; FE không gửi `teacher_id` cho GV, ẩn cột/lọc GV | Đạt |
| AC7 GV vào URL khóa lạ → 403 | `isForbidden` → màn "Bạn không có quyền…" | Đạt; có test; danh sách 403 → `ForbiddenView` |
| AC8 sắp xếp chương/bài | — | Thuộc FA4 |
| AC9 GV tạo, tự là GV phụ trách | không gửi `teacher_ids`, thẻ "Bạn (tự động…)" | Đạt |
| AC10 nhiều GV | `TeacherPicker` + `TeachersCard` (`PUT .../teachers`), chặn bỏ người cuối, GV đã bị khoá vẫn giữ được | Đạt, chỉ với staff; không gọi `/admin/teachers` với GV (có test). Xem R1/R2 |
| AC11 video | — | Thuộc FA4 |
| FA3: upload ảnh | `ThumbnailField` + `lib/courses/image.ts` | Sniff byte đầu, 2 MB, 4000px, xem trước `data:`; 413/422 đúng; EXIF/WebP do server |
| FA-V2 BUG-1/2/3 | AdminFrame `min-h-11` dưới `lg`; SubjectsScreen thu tiêu đề + cột; OtpInput `onPaste` | Đạt; OtpInput có 4 test |
| Không còn v1 | grep import `@vitaminvui/ui"` trong admin | Sạch; `chuyen-de/page.tsx` dùng Skeleton v2 |

## Gợi ý cho QA
- 375px: danh sách (không cuộn ngang, nút Sửa 44px), form tạo/sửa (thanh lưu dính đáy không che ô cuối), TeacherPicker (R2), checkbox chuyên đề.
- R1: staff đổi GV, bấm "Lưu thay đổi" của form, kiểm GV; gõ dở Thứ tự nổi bật rồi lưu form.
- GV: URL khóa lạ (403), tạo khóa (giá nhập được khi tạo, bị khoá khi sửa), khóa đã xuất bản (lớp khoá + câu giải thích), không thấy nút xuất bản/xoá/GV/thứ tự.
- Ảnh: SVG đổi đuôi `.jpg`, > 2 MB, > 4000px, 413 thật qua Nginx (lỗi mạng/CORS), sửa chỉ ảnh (multipart `_method=PUT`), sửa không đổi gì ("Chưa có thay đổi").
- Race: xoá khóa ở tab khác rồi lưu/xuất bản ở tab này (404 → "không còn tồn tại"); xuất bản 2 tab (409 → tải lại); GV bị khoá sau khi đã gán rồi gán lại danh sách; chuyên đề bị ẩn sau khi đã gán.
- Dán OTP "633 723" ở màn MFA (BUG-3) và menu ngăn kéo 375px (BUG-1).

## Dev đã sửa (sau review)
- **R1:** `CourseForm` không còn bị remount sau khi lưu (`key={course.id}`); nhận prop `version` và tự nạp lại giá trị từ `course` ngay lúc render, nên `aside` (TeachersCard, ManualOrderField) giữ nguyên bản nháp. Test mới ở `CourseEditScreen.test`.
- **R2:** `TeacherPicker`: nút bỏ chip `max-sm:size-11`; sau khi bỏ, focus sang chip kế bên (hết chip thì về ô "Thêm giáo viên"); vùng `role="status" aria-live="polite"` báo "Đã thêm/bỏ …". Test mới `TeacherPicker.test.tsx` (3 test).
- **R3:** xuất bản/ngừng bán khi form còn thay đổi chưa lưu → hộp xác nhận "Còn thay đổi chưa lưu" (Tiếp tục / Quay lại để lưu). `CourseForm` có `onDirtyChange`. Có test. (`beforeunload` vẫn chỉ theo dõi form chính.)
- **R4:** nút "Sửa" giữ chữ ở mobile (`max-sm:h-11`, ô tên `min-w-48` dưới `sm` để bảng không tràn); checkbox chuyên đề `min-h-11` dưới `sm`.
- **R6:** bỏ lời nhắc "thêm chương và bài" ở toast và thẻ ghi chú form tạo; thêm `TODO(FA4)`.
- **R7:** sắp lại class Tailwind ở `AdminFrame`; `seed-e2e-courses.sh --clean` từ chối nếu `APP_ENV` của container php không phải `local`/`testing`.
- **R5:** ghi nợ, không làm (Nổi bật #n ở dòng phụ danh sách).
- Chạy lại: tsc, eslint sạch; unit admin 174/174, ui 27/27; e2e thật `khoa-hoc-real` `--workers=1` 2/2.
