# FA6 — Duyệt đăng ký khóa miễn phí (US-012)

## Dev

**Ngày:** 2026-10-07 · **App:** `frontend/apps/admin` · **Phụ thuộc:** T14 (đã xong)

### Đã làm
- Route `/quan-tri/duyet-dang-ky` (cả admin, Quản lý trang, giáo viên; menu "Duyệt đăng ký" nhóm Nội dung). Bộ lọc trên URL: `status` (mặc định chờ duyệt), `course_id`, `page`, `per_page` (25/50). Tab Chờ duyệt / Đã duyệt / Đã từ chối; tab hiện tại có số tổng (`meta.total`).
- Bảng theo `RequestsManager` của bản xem trước: học sinh (tên, lớp, email/SĐT đã che đúng như API), khóa, giờ gửi (cũ nhất trước do API sắp), Duyệt / Từ chối.
- Duyệt: nút khoá + "Đang duyệt…", chặn bấm kép bằng ref. Từ chối: hộp thoại lý do tuỳ chọn (đếm /1000, cắt khoảng trắng, rỗng thì không gửi); hộp thoại không đóng khi đang gửi.
- Lỗi: 409 `ALREADY_PROCESSED`, 403, 404 → toast "đã được người khác xử lý/không còn quyền…" và tải lại; 422 `COURSE_NOT_FREE`, 409 `COURSE_UNAVAILABLE` → báo ngay tại dòng (vẫn từ chối được); 422 `reason` → dưới ô lý do + focus; 403 khi mở thẳng khóa không phụ trách → trang "không có quyền".
- Bộ lọc khóa lấy từ `GET /admin/courses` (giáo viên chỉ nhận khóa mình), lọc giá 0 ở client, tối đa 4 trang × 50.
- 375px: nút 44px, không tràn ngang; khóa và giờ gửi chuyển xuống dưới tên học sinh.
- Không hiển thị gì ngoài field API trả (không ghép thêm thông tin học sinh).

### File
- Mới: `lib/enrollment-requests/{types,query,api,errors}.ts` + test, `components/enrollment-requests/EnrollmentRequestsScreen.tsx` + test, `app/quan-tri/duyet-dang-ky/page.tsx`, `e2e/duyet-dang-ky-real.spec.ts`, `e2e/seed-e2e-requests.sh`.
- Sửa: `lib/nav.ts`, `components/shell/AdminShell.tsx` (icon), hai test menu (`shell.test.tsx`, `lib/auth/logic.test.ts`: giáo viên thêm mục Duyệt đăng ký).
- Không đụng `packages/ui`, `apps/web`, backend, lockfile. Không có biến môi trường mới.

### Chênh lệch contract / thiếu API (chuyển laravel-dev / PO)
1. **Thu hồi (revoke) từ admin: không có endpoint** (`routes/admin.php` chỉ index/approve/reject). Story BR nhắc "thu hồi" nhưng AC không bắt buộc; FE không dựng nút giả.
2. **Duyệt hàng loạt: contract không có** (story: ngoài phạm vi) → không làm.
3. **Tìm theo tên học sinh: API không có tham số `q`** → không làm (không lọc client trên một trang vì sẽ sai khi phân trang). Nếu PO cần, thêm `q` vào `EnrollmentRequestIndexRequest`.
4. Số lượng 3 tab và badge cam ở menu cần số chờ duyệt: API chỉ trả `meta.total` của một trạng thái mỗi lần → tab chỉ hiện số của tab đang mở; chưa có badge ở sidebar (cần endpoint đếm hoặc gọi thêm).
5. Ô lọc khóa dựa vào `GET /admin/courses` (≤ 200 khóa gần nhất); nếu có nhiều hơn, khóa cũ không có trong ô chọn nhưng vẫn lọc được qua `course_id` trên URL.
6. "Duyệt" không có hộp xác nhận (theo thiết kế v2, thao tác nhanh); vì chưa có thu hồi nên đây là thao tác không hoàn tác bằng giao diện. Nếu PO muốn xác nhận trước khi duyệt, nói để thêm.

### Kiểm tra
- `tsc --noEmit` sạch; `eslint .` sạch; vitest admin 24 file / 296 test pass (mới: 8 test lib + 10 test màn hình).
- `next build` (`NEXT_DIST_DIR=.next-check`, đã xoá) thành công, có route `/quan-tri/duyet-dang-ky`.
- E2E thật `e2e/duyet-dang-ky-real.spec.ts` (`--workers=1`): 7/7 pass. Trước mỗi lần chạy: `e2e/seed-e2e-requests.sh --reset`; sau cùng `--clean` (đã chạy, seed đã dọn). Dùng `e2e/run-real.sh`.

### Đối chiếu AC
- AC2 duyệt (ghi `approved_by/at` do backend; FE gọi approve, học sinh nhận email do backend): đạt, có e2e (QLT và giáo viên).
- AC3 từ chối kèm lý do, hiển thị lý do ở tab Đã từ chối: đạt, có e2e (+ 422 HTML).
- AC6 giáo viên không phụ trách: danh sách không có khóa khác, mở thẳng `course_id` khóa khác → 403 view, POST trực tiếp → 403: đạt (e2e).
- AC7 danh sách đầy đủ, thời điểm gửi, sớm nhất trước, phân trang: đạt (e2e thứ tự + 25/trang, F5 giữ trang).
- Double click: ref chặn + unit test + 409 có thông báo (e2e).
- AC1/AC4/AC5 thuộc web học sinh (ngoài FA6).

### Nên kiểm kỹ (laravel-qa)
- Hai người cùng duyệt một yêu cầu; giáo viên bị gỡ khỏi khóa trong lúc đang mở trang; khóa đổi sang có phí khi yêu cầu còn chờ (422 `COURSE_NOT_FREE` hiện tại dòng); khóa bị xoá mềm; email kết quả gửi cho học sinh (Mailpit); lý do dài 1000 ký tự và ký tự đặc biệt; 375px trên máy thật.

---

## Review

**Kết luận:** APPROVE (0 BLOCKER · 2 SHOULD · 3 NIT)
**Phạm vi:** code chưa commit của FA6 trong `frontend/apps/admin` (lib/enrollment-requests, EnrollmentRequestsScreen, page, e2e + seed, nav/shell/test menu) · đối chiếu US-012, backend T14 (`EnrollmentRequestController`, `DecideEnrollmentRequest`, `EnrollmentRequestIndexRequest`, `EnrollmentRequestResource`, `EnrollmentService`). tsc sạch, eslint sạch, vitest 2 file FA6 pass 18/18 (không chạy e2e/build).

### Tổng quan
Contract khớp backend thật: query `status/course_id/per_page/page`, per_page 25/50 nằm trong 1..100, mã lỗi `ALREADY_PROCESSED`/`COURSE_NOT_FREE`/`COURSE_UNAVAILABLE`, 422 `reason` và 403 đều được xử lý đúng. Quyền do API quyết định (giáo viên + `course_id` khóa khác → 403 → ForbiddenView). FE không tự ghép PII, lý do từ chối render dạng text React (không `dangerouslySetInnerHTML`). Bộ lọc trên URL parse chặt, giá trị rác rơi về mặc định, trang vượt cuối tự lùi. Seed kiểm `APP_ENV` local/testing trước khi chạy. Chặn bấm kép bằng ref cộng với `disabled` toàn bảng là hợp lý.

### Phát hiện
### R1 [SHOULD] Cột thao tác dùng `query.status`, không dùng `r.status` → nút Duyệt/Từ chối xuất hiện trên dòng cũ khi đổi tab
- Vị trí: `components/enrollment-requests/EnrollmentRequestsScreen.tsx:116` (`pending`), `:175-180` (cột `act`), `:75` (`rows` giữ dữ liệu trang trước trong lúc tải).
- Vấn đề: khi bấm từ tab "Đã duyệt/Đã từ chối" sang "Chờ duyệt", `result.page` vẫn là trang cũ cho đến khi tải xong, nhưng cột đã đổi sang Duyệt/Từ chối (và `busyId===null` nên nút bấm được). Bấm vào dòng đã xử lý sẽ gọi approve/reject → 409 `ALREADY_PROCESSED` (vô hại nhưng gây nhầm, và nếu backend đổi hành vi sẽ nguy hiểm). Chiều ngược lại (chờ duyệt → đã duyệt) thì nhánh kết quả dựa vào `r.status` nên đúng.
- Đề xuất:
  ~~~tsx
  // chỉ hiện nút khi dòng thật sự đang chờ và danh sách không đang tải lại theo bộ lọc mới
  cell: (r) => r.status === "pending_approval" ? (<div>…</div>) : null,
  // và/hoặc: const rows = loading && page && result?.key.split("#")[0] !== apiKey ? [] : page?.data ?? [];
  ~~~
  Tốt hơn nữa: `disabled={busyId !== null || loading}` cho hai nút.

### R2 [SHOULD] Thiếu test cho chuyển tab khi đang tải và cho 403/404 khi quyết định
- Vị trí: `EnrollmentRequestsScreen.test.tsx` (10 case; không có case 403/404 của approve/reject, `COURSE_UNAVAILABLE`, 429, hay đổi tab trong lúc loading).
- Đề xuất: thêm 3 case ngắn (403 approve → toast cảnh báo + reload; 409 `COURSE_UNAVAILABLE` hiện ở dòng; đổi tab không cho bấm nút trên dòng cũ — sau khi sửa R1).

### R3 [NIT] Lọc trên URL không "chống" chính nó khi `status` hợp lệ nhưng `course_id` không tồn tại
- Vị trí: `EnrollmentRequestsScreen.tsx:79,216`. Khóa không có trong ô chọn hiển thị "Khóa #id"; chấp nhận được. Giáo viên nhập id khóa người khác → 403 → trang "không có quyền" (đúng AC6). Không cần sửa; chỉ ghi nhận.

### R4 [NIT] Lý do chứa `<`/`>` bị backend từ chối (422) nhưng FE không báo trước
- Vị trí: `EnrollmentRequestsScreen.tsx:345` (Field lý do), backend `DecideEnrollmentRequest::rules`. Thông báo 422 hiện đúng dưới ô và focus, nên chấp nhận. Có thể thêm gợi ý "Văn bản thuần, không dùng < >" ở `description` của Field.

### R5 [NIT] `listFreeCourses` gọi tuần tự tối đa 4 trang
- Vị trí: `lib/enrollment-requests/api.ts:41-51`. Chậm nhẹ với nhiều khóa; chỉ ảnh hưởng ô lọc, lỗi đã có thông báo mềm. Tốt hơn là xin backend `GET /admin/courses?price=0` hoặc endpoint ô chọn (ghi backlog).

### Đánh giá các mục dev nêu thiếu API / câu hỏi
1. Thu hồi (revoke): đồng ý không dựng nút giả. `EnrollmentService::revoke` đã có, chỉ thiếu route admin. AC không bắt buộc → backlog (task backend nhỏ: `POST .../{enrollment}/revoke`, chỉ Admin/QLT, reason mã ngắn). Không chặn FA6.
2. `q` tìm theo tên: đồng ý không lọc client trên một trang. Backlog backend (`q` trong `EnrollmentRequestIndexRequest`, dùng LIKE theo tên có index/escape). Không chặn.
3. Đếm theo tab/badge sidebar: đồng ý hoãn. Cần endpoint đếm (một query `GROUP BY status`, scope theo giáo viên) — gom cùng task backend; không chặn.
4. Có nên xác nhận trước khi Duyệt: nên GIỮ không xác nhận theo design v2 §14.1 (thao tác lặp nhiều, có chặn kép, có toast). Rủi ro bấm nhầm trên 375px đã giảm vì nút xếp dọc, cao 44px, cách 8px. Điều kiện: khi có endpoint thu hồi thì nên bổ sung "Hoàn tác" hoặc thu hồi; nếu PO muốn chắc tay trước thì thêm xác nhận chỉ cho Duyệt. Quyết định cuối thuộc PO.
5. Gợi ý nhỏ cho backend (không thuộc FA6): tab Đã duyệt/Đã từ chối nên sắp theo thời điểm xử lý mới nhất trước (hiện `requested_at` tăng dần cho mọi tab).

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC2 duyệt | `approveRequest` + toast + reload | Backend ghi `approved_by/at`, gửi email |
| AC3 từ chối + lý do | Dialog lý do, `rejectRequest`, tab Đã từ chối hiện "Lý do:" dạng text | OK; backend chặn HTML |
| AC6 giáo viên chỉ khóa mình | API lọc theo `course_teacher`; `course_id` khóa khác → 403 → ForbiddenView | Có e2e; không có chặn ở client (đúng, tin API) |
| AC7 danh sách, thời điểm gửi, cũ nhất trước, phân trang | Cột "Gửi lúc", URL `page/per_page`, Pagination | Đạt |
| Double click / xử lý đồng thời | `busyRef` + disable + 409 → tải lại | Đạt |
| AC1/4/5 | Thuộc web học sinh | Ngoài FA6 |

### Gợi ý cho QA
- Đổi tab Đã duyệt → Chờ duyệt trên mạng chậm (throttle) và bấm nhanh vào dòng cũ (R1).
- Hai người cùng duyệt một dòng; giáo viên bị gỡ khỏi khóa lúc đang mở trang (403 khi bấm → toast + tải lại).
- Khóa chuyển có phí/xoá mềm khi còn yêu cầu chờ (thông báo ở dòng, vẫn từ chối được).
- Lý do 1000 ký tự, có emoji, có `<`/`>`; lý do chỉ toàn khoảng trắng (không gửi).
- `?page=99999`, `?status=abc`, `?per_page=7`, `?course_id=-1`/`abc` trên URL; xử lý dòng cuối của trang 2 (tự lùi trang).
- 375px: không tràn ngang, nút 44px, Tab/Escape/focus trong hộp thoại.

## Dev đã sửa (sau review)

- **R1:** cột thao tác quyết định theo `r.status` của từng dòng (chờ duyệt → nút; đã duyệt/từ chối → badge + lý do), không theo tab; hai nút Duyệt/Từ chối thêm `disabled` khi đang tải (`loading`). Dòng cũ còn trên màn lúc đổi tab không bao giờ có nút.
- **R2:** thêm 6 test màn hình (tổng 16): đổi tab khi đang tải (không có nút trên dòng cũ), nút khoá khi đang tải lại, 403 duyệt (toast + tải lại), 404 từ chối (đóng hộp thoại, tải lại), 409 `COURSE_UNAVAILABLE` và 429 hiện ở dòng, gợi ý văn bản thuần.
- **R4:** ô lý do có gợi ý "Chỉ nhập văn bản thuần (không dùng ký tự < >)."
- R3, R5 giữ nguyên (NIT; R5 vào backlog backend: `GET /admin/courses?price=0`). "Duyệt" vẫn không có hộp xác nhận (theo design, chờ PO).
- Kiểm tra lại: tsc sạch, eslint sạch, vitest `components/enrollment-requests` 16/16, e2e `duyet-dang-ky-real` 7/7 (seed `--reset` trước, `--clean` sau).
