# QA: FA2 (màn Chuyên đề, apps/admin)
**Kết quả:** PASS (0 Critical, 0 Major; 2 Minor, 3 ghi nhận). Security tạm dừng theo PO.
**Phạm vi:** cây làm việc trên HEAD 3c37f29, chỉ phần FA2 (page, components/subjects, lib/subjects, nav.ts, auth/errors.ts N1, e2e). Review `docs/review/FA2.md` (R1, R4, R5 đã được Dev sửa trong code; kiểm lại bên dưới).

## Bước 1: frontend admin (Docker vitaminvui-frontend-dev, từng lệnh riêng)
lint sạch; typecheck (next typegen + tsc) sạch; vitest 9 file / 94 test pass; next build pass (có route `/quan-tri/chuyen-de`).

## Bước 2: e2e thật `chuyen-de-real.spec.ts` (sau `seed-e2e-subjects.sh`)
4/4 pass (45s): QLT tạo/trùng/rỗng/HTML/sửa/ẩn-hiện/tìm-lọc URL/xoá/chặn xoá đang gán/375px; GV chỉ đọc; overlay khoá (giả lập); mất phiên.

## Bước 3: kiểm bổ sung (spec mới `e2e/chuyen-de-qa-real.spec.ts`, backend/Redis/Mailpit thật)
| Kịch bản | Kết quả |
|---|---|
| Admin thấy mục Chuyên đề và nút ghi | PASS |
| Tên đúng 100 ký tự Unicode có dấu: tạo được, tìm lại được, không tràn ngang; 101 ký tự bị chặn "tối đa 100 ký tự" | PASS |
| Tên có `&`, `"`, emoji hiển thị nguyên văn | PASS |
| Tìm `%`, `_`, `\`: khớp ký tự thật, không phải wildcard (`a_b` không ra `axb`, `%` không ra mọi dòng), không lỗi | PASS |
| Phân trang thật (31 chuyên đề): trang 1 đúng 25 dòng, trang 2 phần còn lại, nút trước/sau khoá đúng, per_page=50 về trang 1 | PASS |
| URL lạ `per_page=7&status=abc&page=-3` về mặc định; `page=999` tự lùi trang cuối, không vòng lặp; đổi từ khoá khi đang ở trang 2 về trang 1 | PASS |
| R1: bấm lại link sidebar khi đang có `?q=`: URL sạch, ô tìm rỗng, sau 1,2s không bị đẩy lại | PASS |
| R1: back về `?q=...` ô tìm theo URL, URL không bị giằng co; forward về URL sạch | PASS |
| Gõ dở rồi bấm sidebar trong cửa sổ debounce: URL và ô tìm nhất quán (ra `?q=E2E+CD+P3`, ô giữ "E2E CD P3") | PASS (ghi nhận N1) |
| Hai tab cùng phiên, A xoá, B sửa dòng cũ: banner "không còn tồn tại" trong hộp thoại; B bấm Xoá: toast "Chuyên đề không còn tồn tại", hộp thoại đóng, dòng biến mất | PASS |
| Hai tab, A đổi tên, B (tên cũ) đổi sang tên khác chỉ hoa/thường: thành công theo id, không tự báo trùng chính nó | PASS |
| Hai tab, A xoá, B bấm ẩn/hiện dòng cũ: toast báo lỗi nhưng dòng chết còn trên bảng | Xem BUG-1 |
| 409 giữa chừng: mở hộp xoá (courses_count 0), khóa học được gán từ ngoài, xác nhận Xoá: chuyển sang hộp chặn "đang được gán", không còn nút Xoá; bấm Ẩn thành công, hộp đóng, switch tắt (R4) | PASS |
| R5: trễ DELETE 2,5s, bấm Esc khi đang xoá: hộp thoại không đóng, xong thì toast "Đã xoá chuyên đề" và đóng | PASS |
| Khoá THẬT giữa phiên: QLT mở trang, QA `staff:lock` từ ngoài, gõ tìm (không tải lại): server trả `ACCOUNT_LOCKED` thật, overlay "tài khoản bị khóa" bật; F5 vẫn ở màn khoá, không còn nút Tạo | PASS (đóng N2 của T28) |
| Giáo viên: GET chỉ trả active dù `?status=hidden` (UI không có cột/ô trạng thái); POST/DELETE/PATCH trực tiếp đều 403 | PASS |
| 375px (GV chỉ đọc): không tràn ngang, ô tìm và số dòng/trang cao >= 44px | PASS |
| MFA N1 (đã chạy trong các lần đăng nhập MFA): sai mã không kiểm lại trong lượt này (hạn mức OTP); vitest pass | Không kiểm HTTP |

## Bug phát hiện
### BUG-1: Dòng chết còn trên bảng sau 404 khi ẩn/hiện (Minor)
- Bước: mở danh sách ở tab B; tab A xoá chuyên đề; tab B bấm switch ẩn/hiện dòng đó.
- Mong đợi: toast lỗi và danh sách tải lại, dòng biến mất (như nhánh Xoá, nơi `catch` có `reload()`).
- Thực tế: toast "Chuyên đề không còn tồn tại." nhưng dòng vẫn còn; bấm lại lặp lại lỗi. Nhánh Sửa 404 cũng chỉ báo "đóng và tải lại danh sách", dòng còn nguyên sau Huỷ.
- Vị trí: `components/subjects/SubjectsScreen.tsx` `changeStatus` (catch chỉ toast, không `reload()`); `SubjectFormModal` 404.
- Đề xuất: gọi `reload()` khi lỗi 404 (và có thể mọi lỗi) ở `changeStatus`; tương tự khi đóng form sau 404.

### BUG-2: Phía sau overlay khoá còn thấy nội dung cũ và cảnh báo sai (Minor)
- Khi `ACCOUNT_LOCKED` giữa phiên, overlay bật nhưng DOM phía sau vẫn còn menu, tên người dùng, màn chuyên đề và Alert "Không tải được danh sách chuyên đề / Bạn không có quyền thực hiện thao tác này" (lỗi 403 cùng được màn hình xử lý). Không lộ dữ liệu mới (chỉ nội dung đã có trên màn hình), người dùng bị chặn tương tác bởi overlay, F5 thì hiện màn khoá sạch.
- Đề xuất: khi trạng thái khoá, thay cây UI bằng màn khoá (như AuthGate sau F5) thay vì phủ overlay, hoặc bỏ qua cập nhật lỗi từ màn con khi `ACCOUNT_LOCKED`.

## Ghi nhận / rủi ro
- N1: gõ dở rồi bấm link sidebar trong 300ms: debounce kịp đẩy `q` lên URL, kết quả URL `?q=...` khớp ô tìm (nhất quán, không giằng co). Có thể chấp nhận.
- N2: nhận xét R3 của review còn mở: nhánh "mã hết hạn giữ thông điệp server" của `mfaErrorMessage` chưa có test và dựa khớp chuỗi `không đúng`; `logic.test.ts` không đổi.
- N3: `parseSubjectQuery` cắt `q` 100 đơn vị UTF-16 (`slice`) còn tên giới hạn 100 điểm mã: emoji ở cuối chuỗi tìm dài có thể bị cắt giữa cặp thay thế (rất hiếm, chỉ ảnh hưởng ô tìm).
- Spec cũ `chuyen-de-real.spec.ts` vẫn phụ thuộc seed tay (`seed-e2e-subjects.sh`, đã có script idempotent, đạt R2).
- Môi trường: `playwright.sh` đặt `CI=1` nên Playwright tự retry 2 lần và mỗi retry tốn một mã OTP; khi chạy e2e MFA nên thêm `--retries=0`. macOS không có `timeout`, đã dùng nền + vòng chờ.

## Không kiểm được / chưa kiểm
- Mạng ngắt/chậm với nút "Thử lại" (không giả lập thật), Safari/Firefox/thiết bị thật, 375px cho QLT với nhiều dòng thật ngoài viewport đã đo ở spec cũ.
- Kiểm chéo US-002/US-009 (AC1 "xuất hiện trong bộ lọc công khai/form khóa học", AC5, AC6): ngoài phạm vi FA2 (phụ thuộc T08/T14 chưa commit).
- MFA sai mã/hết hạn qua UI trong lượt này (tiết kiệm OTP).

## Dọn dữ liệu
Đã xoá 30 chuyên đề `E2E CD P01..P30`, chuyên đề x409 và khóa học factory (id 354) gán kèm, 4 tài khoản `e2e-qlt-qa1..4`, file cờ `.lock-*`/`.attach-*`. Giữ lại `E2E Đang gán` + khóa gán (seed cho spec cũ) và các tài khoản e2e của T28. Không flush Redis, không xoá otp_codes; `audit_logs` có các dòng staff.lock/login của lượt QA.
