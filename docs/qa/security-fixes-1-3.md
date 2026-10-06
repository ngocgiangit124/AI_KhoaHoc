# QA: Bảo mật cụm 1 + Sửa lỗi nhỏ 3 (gộp, chưa commit)

**Kết quả:** PASS (0 bug Critical/Major/Minor trong phạm vi kiểm thử; một số ghi chú ở cuối).

**Phạm vi:** H1, M1, L2, L3, I3 (review `docs/review/security-cum1-fix.md`) và "Sửa lỗi nhỏ 3" (review `docs/review/minor-fixes-3.md`). Không sửa `app/`, `routes/`, `resources/`. Không dùng `docker compose run/up`, không `git add`.

## Số liệu
| Hạng mục | Kết quả |
|---|---|
| Pest DB c, loại nhóm race: T02 T03 T04 T05 T09 T12 T13 T16 T18 T27 T28 T30 T33 và Arch | **879 passed, 2 skipped** (6210 assertions), 132 giây |
| Race riêng T03 / T13 / T16 / T18 / T28 / T33 | 1 / 5 / 2 / 5 / 1 / 3 passed. T27 không có test nhóm race (không có thư mục T27, test nằm ở T03/T04) |
| T31 (docker run có mount `infra/production`) | 112 passed (204 assertions), không skip |
| T28/HostPrefixCookieTest (cùng cách mount) | 3 passed, test mẫu env production không còn skip |
| 2 test skip ở lần chạy chính | `ffmpeg THẬT` (T12, container php không có ffmpeg) và mẫu env production của HostPrefix (cần mount, đã chạy bù ở dòng trên) |
| Test mới (3 file, 19 test) | 19 passed |
| Pint trên 3 file mới | sạch |
| `grep -rhoE "^function ..." backend/tests \| sort \| uniq -d` | rỗng |

Không có DB c bẩn nên không phải DROP/CREATE.

## E2E thật trên stack local (curl qua nginx 8000, Mailpit)
Dữ liệu: 4 user `qa-` (3 học sinh, 1 giáo viên), đã dọn (xem mục dọn dẹp).

| Kịch bản | Kết quả thực tế | |
|---|---|---|
| Đổi email thiếu `current_password` | 422 `current_password`: "Vui lòng nhập mật khẩu hiện tại." | PASS |
| Đổi email sai mật khẩu | 422 `current_password`: "Mật khẩu hiện tại không đúng." | PASS |
| Đổi email đúng mật khẩu (phiên A) | 200, có `resend_available_at` | PASS |
| Phiên B (bản sao cookie của A trước khi đổi) sau khi A đổi | 401 `SESSION_REVOKED`, "Email tài khoản đã được thay đổi, vui lòng đăng nhập lại." | PASS |
| Phiên A sau khi đổi (cookie mới) | 200 `/auth/me`, email mới, `is_verified=false` | PASS |
| Thư báo tới email cũ trong Mailpit | Có, gửi tới `qa-s1@example.com`; nội dung chỉ có tên, email mới dạng `q***@example.com`, giờ đổi, email hỗ trợ; không có liên kết, không lộ email mới đầy đủ. OTP xác thực gửi riêng tới email mới | PASS |
| Sai mật khẩu 10 lần (xen kẽ `PUT /auth/contact` 6 lần và `PUT /auth/password` 4 lần, chờ 62 giây giữa mỗi 5 lần vì throttle `password-change` 5/phút) | 10 lần đều 422 | PASS |
| Lần thứ 11 | 429 `TOO_MANY_ATTEMPTS` (do `CurrentPasswordGuard`, vì số request contact mới 7 < trần 10 giờ của `throttle:contact`) | PASS |
| Lần 12 dùng ĐÚNG mật khẩu khi đang bị khoá | vẫn 429 (đúng thiết kế: hết hạn mức thì chặn cả mật khẩu đúng) | PASS |
| `forgot` email chưa xác thực | 202, thông điệp và `resend_available_at` giống hệt email không tồn tại; Mailpit không có thư | PASS |
| Đối chứng `forgot` email đã xác thực | 202 và có thư "Mã xác thực VitaminVui của bạn" | PASS |
| Học sinh đặt mật khẩu `12345678` (đăng ký và `PUT /auth/password`) | 422 "Mật khẩu quá phổ biến" | PASS |
| Học sinh 7 ký tự | 422 "ít nhất 8 ký tự" | PASS |
| Học sinh 8 ký tự hợp lệ (`Zx9kLm2q`) | 200, `session_kept=true` | PASS |
| Staff đổi mật khẩu 11 ký tự | 422 "ít nhất 12 ký tự" | PASS |
| Staff đổi `password1234`, `qwertyuiop12` (có trong danh sách) | 422 "quá phổ biến" | PASS |
| Staff đổi `Password123!` (12 ký tự, không có trong danh sách) | 200, không chặn oan | PASS |
| Staff đổi `Vitamin-Staff-2026` | 200 | PASS |
| L2: `UPDATE audit_logs` bằng root qua `docker compose exec -T mysql mysql` (1 dòng, trong giao dịch có ROLLBACK) | ERROR 1644 (45000) "audit_logs la bat bien: khong duoc sua/xoa." | PASS |
| L2: `DELETE` 1 dòng mới nhất (ROLLBACK) | cùng lỗi 45000; số dòng vẫn 432; có 2 trigger `audit_logs_block_update/delete` | PASS |
| Heartbeat 2x bằng curl | Không làm (không bắt buộc); phủ bằng `T13/UserCreditCapTest` + race T13 (5 passed) | bỏ qua |

Ghi chú về kịch bản "2 phiên A và B": hệ thống một thiết bị một phiên (ADR-003). Đăng nhập B làm A nhận 401 `SESSION_REPLACED`, nên không có hai phiên hợp lệ song song. Để kiểm `SESSION_REVOKED`, B được dựng là bản sao cookie của A (tình huống phiên bị đánh cắp), đúng với mục tiêu của H1.

## Test tự động bổ sung (theo gợi ý các báo cáo security)
| Mục | Test | Kết quả |
|---|---|---|
| Throttle `cart`: 60 request đầu 200, thứ 61 là 429 `TOO_MANY_ATTEMPTS` có `Retry-After` (0 đến 60 giây) | `tests/Feature/T16/CartThrottleTest.php` | PASS |
| Limiter `cart` dùng chung giữa các route giỏ (xoá coupon, thêm mục cũng 429), người khác không bị ảnh hưởng | cùng file | PASS |
| Hết cửa sổ 61 giây thì dùng lại được | cùng file | PASS |
| Phiên đang chờ MFA gọi `GET/POST /admin/staff` nhận 403 `MFA_REQUIRED`, không lộ dữ liệu; qua MFA thì 200 | `tests/Feature/T28/QaSecurityFixesAuthTest.php` | PASS |
| Cookie học sinh (đăng nhập thật) đặt vào tên cookie admin ở admin-api: 401 trên `/admin/auth/me` và `/admin/staff` | cùng file | PASS |
| Cookie staff đặt vào tên cookie học sinh ở host api: 401 hoặc 403, không lộ email | cùng file | PASS |
| Cookie ngẫu nhiên ở cả hai host: 401 | cùng file | PASS |
| Guard chặn `APP_ENV` lạ/viết sai (`Local`, `LOCAL`, `Testing`, `dev`, `develop`, `qa`, `uat`, `local-prod`, `testing `, ` local`, rỗng) khi cấu hình sai | `tests/Feature/T31/UnknownAppEnvGuardTest.php` | PASS |
| Guard không chặn oan `APP_ENV=uat` khi cấu hình đúng | cùng file | PASS |

Các mục còn lại trong gợi ý security (đổi email gỡ phiên khác, forgot/reset chỉ email đã xác thực, trần sai mã giảm giá theo IP, `released_course_ids`, `VIDEO_INVALID`, trần heartbeat, `PlainText`, đơn 0đ) đã có test của Dev trong T04, T03, T16, T33, T12, T13, T09, T18; chạy xanh trong lần chạy chính.

## Bug phát hiện
Không có.

## Ghi chú và rủi ro
1. **Dọn dẹp E2E và một sơ suất của QA.** Lệnh dọn đầu tiên dùng mẫu `email like 'qa-%'` nên khớp 73 user `qa-` còn sót từ các đợt QA trước; nó đã xoá `otp_codes` (38 dòng) và `consents` (96 dòng) của các user đó (dữ liệu test cũ, trong DB dev). Việc xoá `users` hàng loạt bị khoá ngoại `course_teacher` chặn nên không user cũ nào bị xoá. Sau đó chỉ xoá đúng 4 user của lần này (id 766 đến 769). 73 user `qa-` cũ vẫn còn trong DB dev. Cần dọn nếu PO muốn.
2. Lệnh `TRUNCATE audit_logs` bị hệ thống chặn nên không thử; trigger DELETE/UPDATE đã kiểm bằng 2 lệnh có ROLLBACK. Các dòng audit của lần E2E (đăng nhập, đổi mật khẩu, đổi liên hệ) còn lại trong DB dev do trigger. Mailpit đã xoá sạch 2 lần (dữ liệu dev).
3. **Tải máy:** lệnh kiểm `uptime` trong vòng lặp chạy race không chờ khi load cao; T18 chạy lúc load 126, T27/T28/T33 lúc 52 đến 61 (do tiến trình khác trên máy). Cả 3 vẫn xanh, nhưng nên chạy lại race khi máy rảnh nếu muốn chắc về thời gian.
4. `throttle:contact` (10/giờ) và guard (10 lượt sai/giờ) cùng trần; chỉ khi trộn với đổi mật khẩu mới chạm được guard ở lần 11. Với riêng `PUT /auth/contact` thì lần 11 bị throttle trước (cùng mã `TOO_MANY_ATTEMPTS`). Không phải lỗi.
5. Chưa kiểm được trên máy này: ffmpeg thật (T12), cookie `__Host-` qua HTTPS thật (cần staging), `audit:purge` bằng user `vv_app` trên production, FE đổi liên hệ vẫn chưa gửi `current_password` (R1 của review, không thuộc backend).
6. 73 user `qa-` cũ và ghi chú R2 của review (audit tích luỹ vĩnh viễn trong DB test) vẫn còn nguyên; DB test chỉ dọn bằng `migrate:fresh`.
