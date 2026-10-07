# QA: FA6 — Duyệt đăng ký khóa miễn phí (admin, US-012, backend T14)

**Kết quả:** PASS (0 Critical / 0 High / 0 Major; 3 Minor/quan sát, không chặn)
**Phạm vi:** code chưa commit trong `frontend/apps/admin` (đã gồm R1/R2/R4 Dev sửa). Bỏ qua `apps/web` (FW8). Đã chốt không tính bug: không có thu hồi / `q` / đếm theo tab, Duyệt không có hộp xác nhận.

## 1. Kiểm tra tự động (qua `frontend/scripts/pnpm.sh`, NODE_OPTIONS=--max-old-space-size=1536)
| Hạng mục | Kết quả |
|---|---|
| `tsc --noEmit` | sạch |
| `eslint .` | sạch |
| vitest toàn bộ admin | 24 file / 302 test pass |
| `next build` (`NEXT_DIST_DIR=.next-qa`, đã xoá) | thành công, có route `/quan-tri/duyet-dang-ky` |
| e2e thật `duyet-dang-ky-real.spec.ts` (`--workers=1 --retries=0`, seed `--reset` trước) | 7/7 pass |
| e2e thật QA `duyet-dang-ky-qa-real.spec.ts` (10 test, chạy trọn một lượt trên dữ liệu mới) | 10/10 pass |

## 2. Độ phủ theo yêu cầu QA
| Mục | Test | Kết quả |
|---|---|---|
| AC2 duyệt / AC3 từ chối + lý do / AC6 giáo viên chỉ khóa mình / AC7 danh sách + phân trang | spec của Dev (7 test) | PASS |
| Hai người cùng xử lý một yêu cầu | QA1: danh sách cũ, QLT duyệt - GV từ chối và ngược lại: người sau nhận toast "đã được người khác xử lý", dòng biến mất, hộp thoại đóng; đồng thời thật ở API (duyệt/duyệt, duyệt/từ chối): luôn đúng một 200 + một 409 `ALREADY_PROCESSED` | PASS |
| Giáo viên bị gỡ khỏi khóa khi đang mở trang | QA2: bấm Duyệt - toast "không có quyền xử lý yêu cầu này", cả hai dòng của khóa biến mất sau tải lại; POST reject trực tiếp 403; mở thẳng `course_id` - màn "không có quyền"; GV còn lại vẫn thấy yêu cầu (vẫn chờ) | PASS |
| Khóa đổi sang có phí khi còn chờ | QA3: 422 `COURSE_NOT_FREE` hiện ngay tại dòng, nút vẫn bấm được, từ chối kèm lý do vẫn thành công | PASS |
| Khóa bị xoá mềm khi còn chờ | QA4: API xoá chặn 409 `COURSE_HAS_ENROLLMENTS` nên xoá mềm bằng DB (watcher host); Duyệt - 409 `COURSE_UNAVAILABLE` ngay tại dòng "Khóa học đã bị xoá…"; reject qua API 200; sau tải lại dòng biến mất | PASS (xem M-1) |
| Email kết quả (Mailpit) | QA5: duyệt - "Bạn đã được duyệt học khóa …"; từ chối - "… chưa được chấp nhận" kèm "Lý do: …"; lý do có dấu, `"`, `&`, `'`, `\`, `%`, emoji, xuống dòng hiển thị đúng dạng chữ, HTML mail có `&amp;`, không có `<script` | PASS |
| Học sinh được duyệt vào học | QA5: trước duyệt `GET /learn/courses/{id}` 403, sau duyệt 200; học sinh bị từ chối vẫn 403 | PASS |
| Lý do 1000 / 1001 / đặc biệt / `<script>` | QA6: 1000 ký tự gửi được, gõ thêm bị chặn (bộ đếm 1000/1000); 1001: ô nhập tự cắt còn 1000 nên UI không gửi được, API gửi thẳng 422 "tối đa 1.000 ký tự"; emoji + xuống dòng + ký tự đặc biệt lưu và hiển thị đủ; `<script>…` và `a > b` 422 dưới ô, hộp thoại giữ nguyên, không `alert`, không chạy script; chỉ khoảng trắng = không có lý do; ký tự điều khiển / lý do dạng mảng 422; 1000 emoji (2000 đơn vị UTF-16) API chấp nhận 200 | PASS |
| URL rác | QA7: 24 URL (status/course_id/page/per_page rác, trùng, mảng, chuỗi XSS, số quá lớn): không lỗi trang, không `pageerror`, không 5xx, không phản chiếu HTML; `?page=99999` tự lùi về trang cuối hợp lệ | PASS |
| Bàn phím hộp thoại | QA8: focus vào hộp thoại khi mở; Tab / Shift+Tab 8 lần không ra nội dung nền (nền inert, `<dialog>` modal gốc); Esc đóng và trả focus về nút Từ chối; khi đang gửi (chậm 2.5 giây) bấm đôi Xác nhận chỉ 1 request, Esc không đóng hộp thoại | PASS |
| R1 và chặn bấm kép | QA9: đổi tab khi tải chậm (3 giây): dòng cũ không có nút Duyệt/Từ chối; bấm đôi Duyệt chỉ 1 request | PASS |
| 375px | QA10: không tràn ngang (danh sách, hộp thoại, tab Đã từ chối với lý do 1000 ký tự); mọi nút dòng, tab, select, liên kết phân trang, nút hộp thoại đều ≥ 44x44 px; hộp thoại nằm trong khung 375px | PASS |

Dữ liệu dùng tài khoản `e2e-fa6-*`; phiên QLT dùng lại qua storageState (OTP 1/phút). Đã `seed-e2e-requests.sh --clean` (63 tài khoản, 8 khóa), xoá watcher và `test-results/`, không còn container Playwright; không đụng `.next`, dev server, `docker compose up/run`.

## 3. Bug phát hiện
Không có bug Critical / High / Major.

### M-1 (Minor): yêu cầu chờ của khóa đã xoá mềm biến mất khỏi danh sách nhưng vẫn ở trạng thái chờ
- Bước tái hiện: khóa có yêu cầu chờ bị xoá mềm (qua DB; API xoá hiện chặn 409 nên chỉ xảy ra khi DB bị sửa tay) - tải lại `/quan-tri/duyet-dang-ky`.
- Mong đợi: người duyệt có cách dọn các yêu cầu này (từ chối), hoặc chúng không tồn tại ở trạng thái chờ.
- Thực tế: `EnrollmentRequestController::index` có `whereHas('course')` nên dòng ẩn hẳn; thông báo "vẫn có thể từ chối" chỉ đúng khi trang cũ còn mở. API reject của yêu cầu loại này trả 200 với `course: null`.
- Vị trí: `backend/app/Http/Controllers/Api/V1/Admin/EnrollmentRequestController.php` (index). Không chặn FA6; ghi backlog backend nếu muốn xử lý tận gốc (từ chối hàng loạt khi xoá khóa).

### M-2 (Minor, quan sát backend): học sinh chưa xác thực email không nhận email kết quả
- `EnrollmentService::notifyDecision` bỏ qua khi `email_verified_at` null (cố ý). Học sinh vẫn học được (US-001 AC9) nhưng không được báo kết quả từ chối + lý do qua email. Cần PO xác nhận đây là mong muốn; hiện giao diện admin luôn ghi "Học sinh nhận email kết quả" (không phân biệt).
- Ghi chú môi trường: seed thử nghiệm phải đặt `email_verified_at` cho học sinh mới thấy email (đã làm trong `seed-qa-fa6.sh`).

### M-3 (Minor, thông tin): bộ đếm lý do tính theo UTF-16, server theo ký tự
- Emoji chiếm 2 đơn vị nên ô nhập (maxLength 1000) chỉ cho tối đa 500 emoji, trong khi server nhận 1000. Chặt hơn server, không gây lỗi; chỉ ghi nhận.

## 4. Rủi ro và đề xuất
- Dev nên cân nhắc ghi chú dưới ô lý do rằng 1000 ký tự tính theo UTF-16 (M-3) - không bắt buộc.
- Khi có endpoint thu hồi hoặc xoá khóa có yêu cầu chờ, thêm e2e M-1.
- Máy local tải cao (load 20-27) làm e2e đôi lúc chậm; spec QA đã nới timeout (QA7 400 giây). Mỗi lần chạy lại phải `seed --reset` + `seed-qa-fa6.sh` (seed factory thỉnh thoảng đụng email Faker ngẫu nhiên - chạy lại là được).

## 5. File test thêm (chỉ trong `frontend/apps/admin/e2e/`)
- `duyet-dang-ky-qa-real.spec.ts` (10 test QA1-QA10)
- `seed-qa-fa6.sh` (dữ liệu thêm: khóa "E2E FA6 QA Race/Price/Deleted/Removed/Reasons", HS10-19, HS50-63; chạy sau `seed-e2e-requests.sh --reset`, `--clean` của script đó dọn luôn)
- `qa-fa6-watcher.sh` (watcher host xoá mềm khóa cho QA4; chạy nền khi test, kill sau)

Cách chạy lại: `e2e/seed-e2e-requests.sh --reset && e2e/seed-qa-fa6.sh && (e2e/qa-fa6-watcher.sh &)` - `e2e/run-real.sh e2e/duyet-dang-ky-qa-real.spec.ts --workers=1 --retries=0` - `pkill -f qa-fa6-watcher.sh; e2e/seed-e2e-requests.sh --clean`.
