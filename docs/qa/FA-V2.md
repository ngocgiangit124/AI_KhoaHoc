# QA: FA-V2
**Kết quả:** PASS (không có bug Critical/Major; 3 bug Minor, không chặn commit)

Phạm vi: `frontend/apps/admin` sang design v2 (shell, FA1 đăng nhập/MFA/đổi mật khẩu lần đầu/403, FA2 chuyên đề, 2 Minor FA2, cổng `/v2` ở production). Code FA3 ngoài phạm vi. Môi trường: backend thật `admin-api.localhost:8000`, dev server :3001, Playwright 1.63 (Docker), `--workers=1 --retries=0`.

## E2E thật (26 pass / 0 fail / 1 skip)
| Spec | Kết quả |
|---|---|
| `admin-real.spec.ts` | 9 pass, 1 skip (test khoá cần `E2E_LOCK_TEST=1`, đã phủ bằng test khoá THẬT trong `chuyen-de-qa-real`) |
| `dang-nhap.spec.ts` | 5 pass |
| `chuyen-de-real.spec.ts` | 4 pass |
| `chuyen-de-qa-real.spec.ts` | 8 pass (sau khi sửa 2 test lỗi thời, xem dưới) |

Test lỗi thời đã sửa (chỉ trong `e2e/chuyen-de-qa-real.spec.ts`):
- Phân trang: `Pagination` v2 dùng liên kết "Trước"/"Sau" và số trang trong `nav[aria-label="Phân trang"]` (chữ "Trang 1/2" chỉ hiện dưới `sm`), test cũ tìm button "Trang trước/Trang sau". Đã đổi selector.
- "Hai tab cùng phiên": test cũ khẳng định BUG-1 FA2 (dòng chết còn lại sau 404 khi Ẩn/Hiện/sửa). Lỗi này đã được dev sửa nên test cũ đứng chờ switch. Đổi thành khẳng định hành vi mới (dòng tự biến mất sau 404 khi sửa và khi bật/tắt switch; thêm ca 1b cho switch).

Ghi chú vận hành e2e: `seed_e2e.php` cũ xoá user nên lỗi FK `course_teacher`; đã seed theo kiểu upsert. Mỗi tài khoản staff bị giới hạn OTP 5/giờ + cooldown 60s, nên QA tạo thêm `e2e-qlt-qa1..4`, `e2e-admin-m1..m3`, `e2e-qlt-m1` và 30 chuyên đề `E2E CD P01..P30`. Đã xoá khoá limiter (Redis DB limiter) khi cần; mở khoá lại các user bị `staff:lock`.

## Độ phủ yêu cầu (kiểm tay bằng Playwright script + curl)
| Hạng mục | Kết quả |
|---|---|
| Overlay khoá giữa phiên (`staff:lock` khi đang mở `/quan-tri/chuyen-de`) | PASS: `<dialog>` modal (`:modal`), focus vào "Về trang đăng nhập"; Tab/Shift+Tab 18 lần chỉ luân phiên link <-> document (không lọt ra menu/nội dung nền); Esc 1 lần không đóng; không tràn ngang ở 375 |
| MFA `OtpInput` v2 | PASS: 1 `<input>` (`autocomplete=one-time-code`, `maxlength=6`); dán bằng clipboard thật; mã sai -> 422, thông báo "Mã xác nhận không đúng", ô xoá + focus lại + `aria-invalid`; mã hết hạn (ép `otp_codes.expires_at`) -> "Mã OTP đã hết hạn. Bấm 'Gửi lại mã'..."; nút gửi lại khoá trong cooldown, gửi lại xong nhập mã mới vào `/quan-tri` |
| Đổi mật khẩu lần đầu | PASS: 11 ký tự -> lỗi client "Mật khẩu tối thiểu 12 ký tự", không gọi API; mật khẩu phổ biến -> `errors.password[0]` "Mật khẩu quá phổ biến..." dưới field; chứa phần trước @ -> "Mật khẩu không được chứa tên đăng nhập..."; xác nhận khác -> "Xác nhận mật khẩu không khớp"; 12 ký tự hợp lệ -> `/quan-tri`; 375 không tràn ngang |
| 403 | PASS: `/quan-tri/khong-co-quyen` hiện "Bạn không có quyền truy cập trang này."; 375 không tràn ngang. (Route không tồn tại `/quan-tri/tai-khoan-staff` cho GV ra 404 chuẩn) |
| Chuyên đề 375px | PASS: không cuộn ngang trang; ô tìm/select/nút Tạo/Sửa/Xoá/Switch đều >= 44px (trừ BUG-1, BUG-2); ngăn kéo mở/đóng bằng Esc, có Đăng xuất (chuyển về `/dang-nhap`) |
| Ẩn/hiện chuyên đề vừa bị xoá ở tab khác | PASS (e2e ca 1 và 1b): toast "Chuyên đề không còn tồn tại." và danh sách tự tải lại |
| Cổng `/v2` bản `next start` (build với `--max-old-space-size=1536`, load < 10, RAM Docker ~4 GB) | PASS: không `V2_PREVIEW`: `/v2`, `/v2/`, `/v2//quan-tri`, `/v2/quan-tri/khoa-hoc`, `/V2/...`, `/%76%32`, `/%56%32` đều 404, có và không có header `next-router-prefetch`/`purpose: prefetch`/`rsc`; 404 mang CSP + nosniff + HSTS + Referrer + Permissions; payload RSC prefetch chỉ chứa not-found, không có nội dung xem trước; `/dang-nhap`, `/quan-tri/chuyen-de` 200. `V2_PREVIEW=1`: `/v2`, `/v2/quan-tri/khoa-hoc`, `/v2/quan-tri/chuyen-de` 200 (có cả prefetch). Đã dừng container `qa-fav2-start`; dev server :3000/:3001 không bị đụng |

## Bug phát hiện
### BUG-1: Link menu ngăn kéo 375px cao 40px (< 44px)
- Mức độ: Minor
- Tái hiện: 375px, đăng nhập QLT, mở "Menu". Kết quả mong đợi: mục "Tổng quan", "Chuyên đề" cao >= 44px (quy ước chạm). Thực tế: 40px.
- Vị trí nghi ngờ: `frontend/packages/ui/src/v2/layout/AdminFrame.tsx` (NavDrawer/NavItem), gợi ý R6 của review.

### BUG-2: Cột "Thao tác" (Sửa/Xoá) của bảng chuyên đề nằm ngoài khung nhìn ở 375px
- Mức độ: Minor
- Tái hiện: 375px, `/quan-tri/chuyen-de`. Bảng rộng 430px trong khung cuộn ngang rộng 341px; nút Sửa ở x=343 (ngoài khung), phải vuốt ngang trong bảng, không có dấu hiệu nhìn thấy. Mong đợi: thao tác chính thấy được hoặc có gợi ý cuộn (hoặc thu hẹp cột Switch ~150px).
- Vị trí nghi ngờ: `frontend/apps/admin/components/subjects/SubjectsScreen.tsx` (bảng/cột ở mobile).

### BUG-3: Dán mã OTP có khoảng trắng ("123 456") bị cắt còn 5 chữ số
- Mức độ: Minor
- Tái hiện: màn MFA, dán "633 723". Mong đợi: 633723 và tự gửi. Thực tế: ô nhận "63372" vì `maxLength=6` cắt chuỗi dán trước khi `onChange` lọc chữ số, không tự gửi; phải gõ thêm số cuối. Mã trong email là 6 chữ số liền nên ít gặp.
- Vị trí nghi ngờ: `frontend/packages/ui/src/v2/OtpInput.tsx` (`maxLength` + `onlyDigits`; xử lý `onPaste` để lọc chữ số trước).

## Rủi ro và đề xuất
- Chromium "close watcher": bấm Esc hai lần liên tiếp rất nhanh (không có thao tác ở giữa) có thể đóng `<dialog>` overlay khoá dù đã `preventDefault` ở `cancel`. Không lộ dữ liệu vì lớp `AuthGate` phía sau cũng hiển thị "Tài khoản đã bị khóa" (R7: nội dung khoá hiện hai lần trong DOM). Chấp nhận được; có thể chặn thêm `onClose` mở lại `showModal()`.
- Skip link "Bỏ qua tới nội dung" cao 36px: chỉ hiện khi focus, bỏ qua.
- Dữ liệu e2e để lại (không ảnh hưởng): chuyên đề `E2E CD *`, khoá học factory gán chuyên đề, tài khoản `e2e-*`. Dọn chuyên đề: `e2e/seed-e2e-subjects.sh --clean`.
- Ảnh: đã ghi đè `docs/design/mockups/v2/thuc-te/admin/sau-chuyen-de-375.png` và thêm `sau-menu-ngan-keo-375.png`, `sau-khoa-tai-khoan-1280.png`, `sau-khoa-tai-khoan-375.png` (chụp từ dev server nên có nút "N" của Next dev ở góc).
