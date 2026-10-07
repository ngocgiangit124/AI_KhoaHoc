# QA: FA3 — Khóa học quản trị trên design v2
**Kết quả:** PASS (không có bug Critical/Major; 2 Minor về test/seed, 1 ghi chú)

Phạm vi: `frontend/apps/admin` (khoa-hoc, components/courses, lib/courses, shell), `packages/ui/src/v2` (AdminFrame, OtpInput). Môi trường: dev server :3000/:3001 + Docker local, backend thật, Mailpit. uptime load ~4-7, RAM Docker < 6 GB.

## E2E thật (`--workers=1 --retries=0`, Playwright 1.63 trong Docker)
| Spec | Kết quả |
|---|---|
| `khoa-hoc-real.spec.ts` (dev) | 2/2 pass |
| `khoa-hoc-qa-real.spec.ts` (QA, mới) | 2/2 pass |
| `admin-real.spec.ts` | 9 pass, 1 skip (cần khoá từ ngoài). 2 test "Đổi mật khẩu lần đầu" fail lần đầu vì dữ liệu: test đã tiêu thụ cờ `must_change_password` của `e2e-gv-new`/`e2e-admin-new`; đặt lại cờ rồi chạy lại: GV pass, Admin pass (lần chạy ngay sau đó fail 1 lần do mã MFA, chạy lại đơn lẻ pass; không tái hiện, nghi tranh nhau OTP/limiter) |
| `dang-nhap.spec.ts` | 5/5 pass |
| `chuyen-de-real.spec.ts` | 4/4 pass (lần đầu 1 fail do timeout `page.goto` khi máy tải cao, 1 fail "Mất phiên" chớp; chạy lại 4/4 hai lần) |
| `chuyen-de-qa-real.spec.ts` | 8/8 pass sau khi seed 30 chuyên đề `E2E CD P01..P30` (test #2 cần > 25 chuyên đề, dữ liệu đã bị dọn) và dựng locker/attacher ngoài cho 2 test cần thao tác từ ngoài |
Tổng: 30 pass, 1 skip (có chủ ý), 0 fail thật sự sau khi chuẩn bị dữ liệu. Không test nào fail do code FA3.
Đã dọn: `seed-e2e-courses.sh --clean`, chuyên đề `E2E CD *`, khóa factory 782; mở khoá `e2e-qlt-qa2`.

## Độ phủ acceptance criteria (US-009 + FA3)
| AC | Test | Kết quả |
|---|---|---|
| AC1 tạo (đủ trường, >= 1 GV, ảnh) | khoa-hoc-real (client + 422 theo field) | PASS |
| AC2 xuất bản | khoa-hoc-real, Ngừng bán/Xuất bản lại | PASS |
| AC3 chặn khi chưa có chương/bài (`COURSE_NOT_PUBLISHABLE`) | khoa-hoc-real hộp thoại đúng câu | PASS |
| AC4 có học sinh: chặn xoá, gợi ý Ngừng bán | khoa-hoc-real (nút khoá + 409 thật) | PASS |
| AC5 xoá khi chưa có học sinh | khoa-hoc-real | PASS |
| AC6 GV chỉ thấy khóa mình | khoa-hoc-real, khoa-hoc-qa-real (API `q=` khóa người khác trả 0) | PASS |
| AC7 GV vào URL khóa lạ → 403 | khoa-hoc-real | PASS |
| AC9 GV tạo, tự là GV phụ trách; giá nhập được khi tạo, khoá khi sửa | khoa-hoc-real, khoa-hoc-qa-real (Học phí enabled ở /tao, disabled ở /sua; PUT price không đổi giá) | PASS |
| AC10 nhiều GV (gán/bỏ, không bỏ người cuối, GV bị khoá vẫn giữ) | khoa-hoc-real | PASS |
| AC8, AC11 | thuộc FA4 | n/a |
| Upload ảnh bìa | client chặn định dạng/2 MB/4000px; QA gọi API thẳng: 3 MB → 422, 6 MB → 413 của Nginx (trình duyệt thấy `Failed to fetch` vì 413 Nginx không có CORS, FE đã xử lý), file giả `.jpg` → 422 | PASS |
| R1 lưu form không mất GV đang chọn dở | khoa-hoc-qa-real | PASS |
| R2 TeacherPicker bàn phím + aria-live ("Đã bỏ …", "Đã thêm …"), focus còn trong nhóm | khoa-hoc-qa-real | PASS |
| R3 xuất bản khi còn thay đổi chưa lưu → hộp "Còn thay đổi chưa lưu"; "Quay lại để lưu" không gọi API | khoa-hoc-qa-real | PASS |
| 404 khóa không tồn tại, lỗi mạng (abort), 403/ACCOUNT_LOCKED overlay | khoa-hoc-qa-real, khoa-hoc-real | PASS |
| 375px: bảng không tràn ngang, nút Sửa 44px, ô nhập, nút "Bỏ GV" >= 44px, menu ngăn kéo | khoa-hoc-real, khoa-hoc-qa-real | PASS |

## 3 Minor của QA FA-V2
| Minor | Kiểm | Kết quả |
|---|---|---|
| BUG-1 link ngăn kéo 44px | e2e duyệt mọi link trong ngăn kéo QLT ở 375px, height >= 43.5 | PASS |
| BUG-2 bảng chuyên đề 375 | e2e `noOverflow` ở /quan-tri/chuyen-de, và `chuyen-de-qa-real` 375px | PASS |
| BUG-3 dán OTP "633 723" ở MFA | e2e dispatch paste có dấu cách vào ô OTP thật, vào được /quan-tri | PASS |

## Bug phát hiện
Không có bug Critical/Major.

### BUG-1 (Minor, test/seed): `khoa-hoc-real.spec.ts` không chạy lại được nếu không `--clean`
- Spec tiêu thụ dữ liệu seed: xoá mềm "E2E FA3 Nháp trống", xuất bản "E2E FA3 Có nội dung". Seed idempotent dùng `withTrashed()` nên không tạo lại khóa đã xoá mềm; lần chạy sau fail "Không thấy khóa ...". Cách dùng đúng: `--clean` rồi seed trước mỗi lần chạy (ghi vào header spec). Không sửa code ứng dụng.

### BUG-2 (Minor, test hygiene): e2e cũ phụ thuộc dữ liệu/ngoại cảnh không tự có
- `admin-real` "Đổi mật khẩu lần đầu" tiêu thụ cờ must_change_password (cần reset trước mỗi lần chạy); `chuyen-de-qa-real` #2 cần > 25 chuyên đề, #6 và #8 cần script ngoài (locker/attacher). Đề xuất ghi lệnh reset/seed vào `seed-e2e-subjects.sh` hoặc README e2e.

### Ghi chú (không tính bug)
- OTP 1/phút/tài khoản + nhiều lần thử: dùng `scratchpad/qa-reset.sh` (xoá `otp_codes` của `e2e-%`, flush Redis DB limiter, chỉ khi APP_ENV=local).
- Lần fail không tái hiện của test Admin "Đổi mật khẩu lần đầu" và "Mất phiên" xảy ra khi máy tải cao (load ~7); chạy lại đơn lẻ đều pass.

## Rủi ro & đề xuất
- R5 (Nổi bật #n ở danh sách) và `beforeunload` chỉ theo dõi form chính vẫn là nợ đã ghi.
- Chưa kiểm bằng trình đọc màn hình thật (VoiceOver); mới kiểm vùng `role="status" aria-live="polite"` đổi nội dung đúng và focus không rơi khỏi nhóm.
- 413 của Nginx không có header CORS nên trình duyệt chỉ báo lỗi mạng; FE đã xử lý như 413 (review đã ghi).
- Ảnh: `docs/design/mockups/v2/thuc-te/admin/qa-gv-khoa-hoc-danh-sach-{375,1280}.png`, `qa-gv-menu-ngan-keo-375.png` (đã có sẵn bộ ảnh `sau-khoa-hoc-*` của dev).
- File test mới: `frontend/apps/admin/e2e/khoa-hoc-qa-real.spec.ts`.
