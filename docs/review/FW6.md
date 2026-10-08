# REVIEW: FW6 — Khóa học của tôi + tiến độ (web học sinh)

## Dev
**Trạng thái:** xong; kết quả ở mục "Cách test". Phụ thuộc API T23 (`GET /me/courses`, `GET /me/courses/{course}/progress`) đã có, không sửa backend/admin/`packages/ui`, không thêm package, `pnpm-lock.yaml` không đổi.

### Phạm vi
- Route thật trong nhóm `(site)` (khung header + footer + `AuthProvider`, `noindex`, `force-dynamic`):
  - `/tai-khoan/khoa-hoc-cua-toi?trang=N`: "Học tiếp" (khóa đầu tiên đang học dở, chỉ ở trang 1) + 3 tab Đang học / Chờ duyệt / Không được duyệt (lý do từ chối + "Xem khóa và đăng ký lại"). Mỗi thẻ: bìa (`CourseImage` nếu có `thumbnail_url`), trạng thái bằng chữ (Đã hoàn thành / Đang học / Chưa bắt đầu), thanh + chữ "6/20 bài · 30%", "Chưa có nội dung" khi `has_content=false`, điểm trắc nghiệm cao nhất `x/10`, ghi chú "Khóa đã ngừng bán" khi `is_published=false`, nút Tiếp tục học / Bắt đầu học / Xem lại tới `resume_lesson_id` (trang học FW4) và "Xem tiến độ". Phân trang 12/trang bằng `Pagination` (URL `?trang=`, F5 giữ trang). Chỉ có yêu cầu chờ duyệt thì mở sẵn tab "Chờ duyệt".
  - `/tai-khoan/khoa-hoc-cua-toi/{course}`: breadcrumb, tên + huy hiệu trạng thái, thanh tiến độ lớn + "Tiếp tục học" / "Xem lại bài học", bảng "Bài kiểm tra" (số câu, lượt đã làm, điểm cao nhất `x/10`, "Chưa làm", liên kết "Làm bài/Làm lại" tới quiz FW5 và "Xem kết quả" tới `/hoc/{course}/quiz/{quiz}/ket-qua` (lượt đã nộp gần nhất)), mục lục chương/bài (trạng thái bằng chữ, bài tiếp theo được đánh dấu, mở sẵn chương chứa bài đó, bấm bài sang trang học). Dưới `md` bảng đổi thành danh sách thẻ (bảng nhiều cột + nút không vừa 375px; xem "Phát hiện").
  - `id` khóa không phải số nguyên dương -> 404 của site (`notFound()`); id số mà API trả 404 -> thông báo "Không tìm thấy khóa học".
- Nối link: header (mục "Khóa học của tôi" chỉ hiện khi đã đăng nhập, sáng ở cả trang con), trang `/tai-khoan` (nút "Khóa học của tôi"). `lib/routes.ts`: `myCourses`, `myCourse(id)`.
- Gọi API 100% từ trình duyệt (`authFetch`, cookie phiên) như FW4/FW5; `lib/my/schemas.ts` (zod; điểm DECIMAL có thể là chuỗi `"8.33"` nên chấp nhận cả số lẫn chuỗi), `lib/my/api.ts`, `errors.ts` (phân loại lỗi + parse `?trang=`/id), `format.ts` (trạng thái, nhãn nút, chọn khóa "Học tiếp").
- Trạng thái: đang tải (skeleton, `LoadingRegion`), rỗng ("Bạn chưa có khóa học nào" + "Khám phá khóa học" -> `/khoa-hoc`), trang vượt quá `last_page` ("Trang này không có khóa học" + về trang đầu), 401 (`SessionEndedGate` mở hộp thoại/chuyển đăng nhập; màn chỉ báo ngắn "Phiên đăng nhập đã kết thúc"), 403 `COURSE_NOT_OWNED` (kèm liên kết tới trang khóa khi API trả `errors.course.slug` hợp lệ; khóa đang chờ duyệt/bị thu hồi đều rơi vào đây), 404, 429 ("Bạn thao tác hơi nhanh" + Thử lại), lỗi mạng/5xx/sai contract (Thử lại). Khách: `RequireUser` chuyển tới `/dang-nhap?next=...` và KHÔNG gọi API; `/auth/me` lỗi tạm thời -> Thử lại.
- Tuân quy ước: không `localStorage`, không `any`, không đổi màu/font/khoảng cách (dùng lại component `@vitaminvui/ui/v2` và bố cục của trang mẫu `(v2-preview)`), nút/liên kết chính cao 44px (không dùng size `sm`), chữ nội dung ≥ 16px (chữ phụ 14px), ngày dùng `formatDate` (múi giờ Asia/Ho_Chi_Minh cố định).

### File
- Mới: `app/(site)/tai-khoan/khoa-hoc-cua-toi/page.tsx`, `.../[course]/page.tsx`; `components/my/{MyCoursesScreen,MyCourseCard,CourseProgressScreen,ProgressOutline,QuizScoreTable,MyNotice,RequireUser,useMyLoad}` ; `lib/my/{schemas,api,errors,format}.ts`.
- Test mới: `lib/my/format.test.ts` (8), `components/my/MyCoursesScreen.test.tsx` (8), `components/my/CourseProgressScreen.test.tsx` (5); e2e `e2e/khoa-hoc-cua-toi-real.spec.ts`, `e2e/seed-e2e-progress.sh`, `e2e/run-progress-real.sh`, `playwright.fw6.config.ts`.
- Sửa: `lib/routes.ts`, `components/shell/ShellHeader.tsx`, `components/account/AccountView.tsx`.
- Biến môi trường mới: không.

### Cách test
- `frontend/scripts/pnpm.sh --filter @vitaminvui/web exec env NEXT_DIST_DIR=.next-fw6 sh -c "./node_modules/.bin/tsc --noEmit && ./node_modules/.bin/eslint . && ./node_modules/.bin/vitest run"`: tsc sạch, eslint sạch, vitest 409/409 (50 file; 21 test mới).
- Build: `next build` (Turbopack, `NEXT_DIST_DIR=.next-e2e-fw6`) thành công khi dựng bản chạy e2e, có hai route mới (động). Thư mục build đã xoá; không đụng `.next` dùng chung.
- E2E thật (backend + Next production build trong ảnh Playwright, `--workers=1`, 7/7 pass, ~1,5 phút): `e2e/seed-e2e-progress.sh --reset` (tiền tố `e2e-fw6-`, học sinh `fw6-hs-own|none|many@example.com`, mật khẩu `matkhau-123`; chỉ local/testing; chỉ tinker, không migrate/seed) rồi `E2E_FW6="course=.. l1=.. l2=.. l3=.. l4=.. qa=.. qb=.. pend=.. rej=.." frontend/apps/web/e2e/run-progress-real.sh` (`E2E_REUSE_BUILD=1` dùng lại build khi không sửa code), xong `seed-e2e-progress.sh --clean` (đã dọn). Mỗi lần chạy lại nên seed lại (id đổi). Bao phủ: khách -> đăng nhập; 2/4 bài = 50% (AC1), Học tiếp đúng bài 3, chờ duyệt/bị từ chối, menu header; chi tiết: điểm cao nhất 8,33/10 trong 2 lượt (AC5), link quiz/kết quả, mục lục; rỗng (AC3), 403 chưa sở hữu, 404; mạng đứt + 429 + Thử lại; 13 khóa phân trang 12/1, sắp học gần nhất trước (AC4), F5 giữ trang; 375px không tràn ngang, nút ≥ 44px, h1 ≥ 16px.
- Log "WebServer: The destination stream closed early" của `next start` là nhiễu khi trình duyệt huỷ request (prefetch/điều hướng), không ảnh hưởng kết quả.

### Phát hiện / điểm cần lưu ý
- Lỗi thật bắt được nhờ e2e 375px: `DataTable` trong khung `overflow-x-auto` vẫn làm trang tràn ngang khi có phần tử `sr-only` (position absolute) nằm trong ô bị cuộn. Cách xử lý ở FW6: dưới `md` dùng danh sách thẻ, từ `md` dùng bảng, và nút trong bảng dùng `aria-label` thay vì `<span class="sr-only">`. Các màn khác dùng `DataTable` với `sr-only` trong ô (hoặc `<th>`) có thể gặp lỗi tương tự ở màn hẹp — cân nhắc sửa ở `packages/ui` (cho khung bảng `relative`) do `nextjs-designer`/PO quyết.
- Màn kết quả quiz (FW5) mở bằng liên kết "Xem kết quả" không kèm `lan` nên hiện lượt đã nộp GẦN NHẤT, không nhất thiết lượt điểm cao nhất; design US-008 §2.2 nói "lần làm tốt nhất". `GET /me/courses/{course}/progress` không trả id lượt tốt nhất — nếu PO muốn đúng lượt tốt nhất cần thêm `best_attempt_id` vào API (chuyển `laravel-architect`/`laravel-dev`). Hiện giữ hành vi "lượt gần nhất".
- API không trả enrollment `revoked` trong `/me/courses` (đúng BR/edge case US-008: không còn xuất hiện); do vậy không có nhóm "bị thu hồi" trên danh sách. Vào thẳng URL tiến độ của khóa bị thu hồi thấy "Bạn chưa sở hữu khóa học này" (403).
- Seed tạo lượt quiz bằng factory (`result` rỗng) nên màn kết quả FW5 của các lượt đó không có chi tiết từng câu: e2e FW6 chỉ kiểm điều hướng tới `/ket-qua`.
- Bìa khóa: `CourseImage` dùng `unoptimized` như danh mục; e2e không có khóa có `thumbnail_url` nên nhánh ảnh thật chưa được e2e phủ (có ở FeaturedCourses/CatalogView đã chạy).

---

## Review (laravel-reviewer, 2026-10-08)
**Kết luận:** APPROVE (0 BLOCKER · 2 SHOULD · 2 NIT)
**Phạm vi:** diff chưa commit của `frontend/apps/web` (route `khoa-hoc-cua-toi` x2, `components/my/*`, `lib/my/*`, routes, ShellHeader, AccountView, e2e/seed/run script, `playwright.fw6.config.ts`). Đã chạy lại vitest `components/my lib/my`: 21/21 pass. Chưa chạy lại e2e (cần backend Docker).

### Tổng quan
Bám sát contract T23: zod khớp từng trường (điểm DECIMAL nhận số lẫn chuỗi), gọi 100% bằng `authFetch` từ trình duyệt, `RequireUser` không gọi API khi là khách. Có đủ tải/rỗng/quá trang/401/403/404/429/mạng. Không `dangerouslySetInnerHTML`, `rejection_reason` render dạng text, link nội bộ đều qua `routes.*` với id số, id đường dẫn được kiểm bằng regex. Trạng thái luôn có chữ, không chỉ màu. Seed e2e có guard `local/testing`, chỉ dùng tinker, tiền tố `e2e-fw6-`/`fw6-*`.

### Phát hiện
#### R1 [SHOULD] "Xem kết quả" mở lượt gần nhất trong khi cột bên cạnh ghi "Điểm cao nhất"
- Vị trí: `components/my/QuizScoreTable.tsx` (cả bảng và thẻ).
- Vấn đề: học sinh thấy "8,33/10" rồi bấm "Xem kết quả" ra lượt 5/10 -> khó hiểu, lệch design US-008 §2.2. Không chặn: dữ liệu vẫn đúng, contract hiện tại không có `best_attempt_id`.
- Đề xuất: (a) tạm thời đổi nhãn thành "Xem kết quả lượt gần nhất" (aria-label tương ứng); (b) ghi backlog-v2 cho `laravel-architect`: thêm `best_attempt_id` vào `quizzes[]` của `/me/courses/{course}/progress` rồi truyền `routes.quizResult(courseId, quizId, { attemptId })`.

#### R2 [SHOULD] `DataTable` + `sr-only` gây tràn ngang ở 375px: ghi nợ ở packages/ui
- Vị trí: `packages/ui/src/v2/DataTable.tsx` (khung `overflow-x-auto` không `relative` nên phần tử `sr-only` absolute thoát khỏi vùng cuộn).
- Vấn đề: cách xử lý của FW6 (thẻ dưới `md`, `aria-label` trong bảng) đúng và đủ cho trang này; không chặn. Nhưng các màn khác dùng `DataTable` có `sr-only` trong ô/`<th>` (có cả `caption sr-only`) có thể dính cùng lỗi.
- Đề xuất: thêm `relative` vào khung cuộn của `DataTable` (1 dòng) do `nextjs-designer`/PO quyết; ghi backlog-v2, kèm test 375px ở màn dùng DataTable. Khi sửa xong có thể giữ nguyên thẻ dưới `md` (UX tốt hơn cuộn ngang).

#### R3 [NIT] Thông điệp 403 trong `MyNotice` nói chung cho cả list và course
- `MyNotice.tsx` nhánh `not_owned` chỉ xảy ra ở trang chi tiết; nên bỏ nhánh khỏi `what="list"` hoặc chấp nhận (vô hại).

#### R4 [NIT] `useMyLoad` có tham số `enabled` chưa ai dùng; `ResumeBanner` lặp lại khóa cũng có thẻ trong danh sách (đúng design, chỉ lưu ý khi QA đếm thẻ).

### Đánh giá 3 điểm Dev nêu
1. `sr-only` trong DataTable: KHÔNG chặn. Workaround FW6 đủ; sửa gốc ở `packages/ui` là việc riêng (R2).
2. Thiếu `best_attempt_id`: KHÔNG chặn. AC5 của US-008 là hiển thị điểm cao nhất/số lượt (đã đúng); chỉ điều hướng lệch design (R1), đổi nhãn tạm + backlog API.
3. `/me/courses` không trả enrollment bị thu hồi: KHÔNG chặn. Khớp contract T23 và BR US-008 (revoked không hiện); vào thẳng URL ra 403 "chưa sở hữu" là hành vi mong muốn, không lộ dữ liệu.

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| Danh sách khóa + % tiến độ (AC1) | `MyCourseCard`, `progressText`, ProgressBar | số + chữ + thanh; e2e 2/4 = 50% |
| Học tiếp tới bài đang dở | `pickResume`, `resume_lesson_id` | chỉ trang 1 |
| Trạng thái rỗng (AC3) | `Loaded` EmptyState -> `/khoa-hoc` | |
| Sắp theo học gần nhất + phân trang (AC4) | thứ tự do API, `Pagination ?trang=` | 12/trang, F5 giữ trang |
| Điểm cao nhất + lượt quiz (AC5) | `QuizScoreTable` | điều hướng kết quả: xem R1 |
| Chờ duyệt / từ chối kèm lý do | tab + `RejectedList` | |
| Khách / lỗi mạng / 401/403/404/429 | `RequireUser`, `MyNotice`, `useMyLoad` | |

### Gợi ý cho QA
- Cắt phiên (xoá cookie) giữa lúc đang xem: hộp thoại phiên + màn "Phiên đăng nhập đã kết thúc".
- Học sinh có >12 khóa và pending cùng lúc: tab mặc định ở trang 2, pending/rejected lặp ở mọi trang.
- Khóa `is_published=false`, khóa `has_content=false` (0 bài), khóa 100% (nút "Xem lại"), tiêu đề rất dài ở 375px.
- Chuyển tab bàn phím (mũi tên) và `<details>` mục lục bằng Enter/Space; bài tiếp theo được mở sẵn.
- Hai học sinh khác nhau: không thấy khóa/URL tiến độ của nhau (403 `COURSE_NOT_OWNED`).
