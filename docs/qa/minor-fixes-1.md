# QA: Gom sửa lỗi nhỏ (minor-fixes-1)
**Kết quả:** PASS (0 bug). Pest DB riêng (phpunit.local-c.xml): T01, T03, T04, T14, T27, T28, Unit = 456 passed (gồm 23 test QA mới).

## Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| Phiên cũ sau đổi mật khẩu, host admin: csrf-token + login 200, route cần đăng nhập 401 envelope (SESSION_REVOKED/UNAUTHENTICATED) | T28/QaMinorFixesTest | PASS |
| Phiên cũ sau reset mật khẩu, host api: csrf-token + login 200, /auth/me 401 SESSION_REVOKED | T27/QaMinorFixesTest | PASS |
| Khách thiếu Accept (cả Accept: text/html, GET và PUT) trên 2 host: 401 UNAUTHENTICATED, không 500, không Location | T28/QaMinorFixesTest | PASS |
| Reset: không tồn tại / bị khoá / không có mã / có tài khoản: cùng 422 OTP_EXPIRED, cùng message, errors.code[] | T27/QaMinorFixesTest | PASS |
| Đo thời gian phản hồi reset | T27/QaMinorFixesTest | Ghi nhận (dưới) |
| Verify sai = OTP_INVALID; hết hạn/chưa gửi = OTP_EXPIRED; hết lượt = 429 TOO_MANY_ATTEMPTS; thiếu code vẫn VALIDATION_ERROR | T04/QaMinorFixesOtpTest | PASS |
| APP_ENV=production + RELAXED=true vẫn 1/phút, 5/giờ (forgot) | T27/QaMinorFixesTest | PASS |
| Message validation tiếng Việt: register, coupon admin, chapter (kể cả 400 ký tự có dấu) | T03/T28 QaMinorFixes | PASS |
| Mail: HS không email xác thực/chỉ SĐT không gửi; rollback không gửi; HTML trong tên khoá/HS escape; duyệt lần 2 = 409, không mail thứ hai; queue lỗi không hỏng duyệt, log không PII; khoá xoá mềm | T14/QaMinorFixesMailTest | PASS |

## Bug phát hiện
Không có.

## Ghi nhận
- Thời gian reset (bcrypt rounds 4 ở test, median/25 lần, ms): không tồn tại 2.8-4.8, bị khoá 2.8-3.8, tài khoản có thật nhưng không có mã 6.4-7.4. Nhánh "có tài khoản, không có mã" chậm hơn khoảng 2 lần (thêm truy vấn). Chênh vài ms, nhỏ so với chi phí bcrypt thật và đã có throttle otp-verify; mức Minor, chỉ ghi nhận, chưa đo ở rounds production.
- Lý do từ chối chứa thẻ HTML bị validation chặn 422 trước khi tới mail (phòng thủ nhiều lớp); escape được kiểm bằng ký tự `"`, `&`, và tên khoá/HS chứa thẻ.
- config:cache không chạy (sẽ ghi bootstrap/cache dùng chung với dev). Thay bằng nạp config/auth.php với env production: e2e_relaxed=false, 60s/5/10/ngày, send 1/phút; local thì nới (1000). Ngoài ra APP_ENV=production kèm cấu hình dev làm app không boot (ProductionConfigGuard), đúng thiết kế.
- Rủi ro R3 của reviewer (APP_ENV=local nhầm trên server thật nới hạn mức) vẫn còn, đề xuất ghi backlog-v2 / deploy checklist.
- Hunk limiter `coupon` (R1 review) thuộc T16, nên không commit chung với gom lỗi nhỏ.

## File test thêm
- backend/tests/Feature/T28/QaMinorFixesTest.php
- backend/tests/Feature/T27/QaMinorFixesTest.php
- backend/tests/Feature/T04/QaMinorFixesOtpTest.php
- backend/tests/Feature/T03/QaMinorFixesValidationTest.php
- backend/tests/Feature/T14/QaMinorFixesMailTest.php
