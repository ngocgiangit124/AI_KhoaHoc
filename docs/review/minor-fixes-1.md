# REVIEW: Gom sửa lỗi nhỏ (minor-fixes-1)
**Kết luận:** APPROVE (0 Blocker, 0 Major, 3 Minor)
**Phạm vi:** diff chưa commit, 6 mục theo board "Việc tiếp theo" số 3. Pest DB riêng (T01, T04, T14, T27, T28, Unit): 339 passed.

## Tổng quan
Thay đổi gọn, đúng phạm vi, có test cho từng mục. Các điểm được soi kỹ (bypass phiên, redirectGuestsTo, OTP chống dò, config nới OTP, mail sau commit) đều không có lỗ hổng Critical/High.

## Phân tích các điểm nhạy cảm
- **Bypass AuthenticateSession ở 5 route cửa vào (`AuthenticateSessionExceptEntryRoutes`)**: an toàn. Route khớp theo tên (`in_array` strict, route không tên → null → không bypass). Các route này không đọc/ghi dữ liệu theo phiên cũ; login/register thay phiên. Mọi route cần đăng nhập vẫn qua AuthenticateSession gốc nên phiên cũ (đã đổi mật khẩu) vẫn bị 401 + flush. Không phải lỗ hổng.
- **`redirectGuestsTo(fn () => null)`**: ứng dụng chỉ có API (không có route `login`), nên không mất redirect nào; khách → 401 envelope. Đúng.
- **OTP_EXPIRED cho tài khoản không tồn tại/khoá**: có `Hash::check` với dummy hash cân bằng thời gian, cùng mã + cùng thông điệp với nhánh "không có mã". Khác biệt còn lại: tài khoản thật đang có mã hiệu lực + gửi mã sai → `OTP_INVALID` (trước đây cũng khác thông điệp), muốn dò phải có mã đã gửi; mức rủi ro thấp, đã có throttle `otp-verify`. Không phải Critical/High.
- **AUTH_OTP_E2E_RELAXED**: chỉ bật khi `APP_ENV` ∈ {local, testing}; production không bật được (có unit test). Giá trị đọc ở `config/auth.php` nên an toàn với `config:cache`.
- **Mail duyệt/từ chối**: gửi trong `DB::afterCommit`, bắt `Throwable` không làm hỏng việc đã commit, log chỉ có `enrollment_id` và tên lớp exception (không PII); Blade `{{ }}` escape tên/lý do; bỏ qua HS không có email xác thực; `decide()` chặn xử lý lặp nên không gửi mail đúp.

## Phát hiện
### R1 [MINOR] Giới hạn coupon bị đổi trong cùng diff, ngoài phạm vi 6 mục
- Vị trí: `app/Providers/AppServiceProvider.php` (limiter `coupon`, bỏ `Limit::perDay(30)`)
- Vấn đề: giới hạn 30 lần/ngày chuyển sang `CartService::applyCoupon` đếm lần SAI (thuộc T16 giỏ hàng, không thuộc gom lỗi nhỏ). Nếu commit riêng task này mà không kèm T16 thì mất giới hạn dò mã coupon.
- Đề xuất: không commit hunk này cùng gom lỗi nhỏ; để vào commit T16 (`git add -p`).

### R2 [MINOR] Pint đã format lẫn vào file của dev khác
- Vị trí: `StaffCreateCommand`, `StaffLockCommand`, `StaffUnlockCommand`, `MeController`, `StaffIdleTimeout`, `AuditLog`, `Course`... trong working tree.
- Đề xuất: khi commit chỉ stage đúng 6 mục; các thay đổi format để đi theo commit task sở hữu hoặc commit "style" riêng.

### R3 [MINOR] Rủi ro cấu hình `APP_ENV=local` trên server thật
- Vị trí: `config/auth.php` (`$otpRelaxed`)
- Vấn đề: bảo vệ dựa hoàn toàn vào `APP_ENV`; nếu ai đặt nhầm `local` trên môi trường công khai thì nới 1000 lần/giờ.
- Đề xuất (tuỳ chọn, ghi backlog-v2): thêm guard cảnh báo/ném lỗi khi `e2e_relaxed` bật mà `APP_DEBUG=false` và host không phải localhost; hoặc kiểm trong deploy checklist.

## Đối chiếu yêu cầu
| Mục | Code | Ghi chú |
|---|---|---|
| 1 locale vi | lang/vi, config/app.php, ValidationLocaleTest | OK |
| 2 T28 BUG-1 | middleware + sanctum.php, QaGapsTest | OK, không mở lỗ hổng |
| 3 T28 BUG-2 | bootstrap/app.php | OK |
| 4 OTP_INVALID/EXPIRED | OtpValidationException, ApiExceptionRenderer, Otp/PasswordService, api-contract §1.7 | OK; vẫn 422 + `errors.code[]` |
| 5 OTP relaxed | config/auth.php, limiter, enforceForgotLimits, .env.example | OK |
| 6 Mail duyệt/từ chối | EnrollmentDecisionMail, view, EnrollmentService | OK |

## Gợi ý cho QA
- Phiên cũ sau đổi mật khẩu: gọi `/csrf-token` và login → 200; gọi route cần đăng nhập → 401 `SESSION_REVOKED`; thử cả host admin.
- Khách thiếu `Accept: application/json` gọi route cần đăng nhập → 401 envelope.
- Reset mật khẩu: tài khoản không tồn tại, bị khoá, không có mã → cùng `OTP_EXPIRED`; so thời gian phản hồi.
- Production env + `AUTH_OTP_E2E_RELAXED=true` → vẫn 1/phút (có unit test; thử thêm bằng `config:cache`).
- Duyệt/từ chối: HS không có email xác thực không gửi; rollback không gửi; lý do chứa HTML được escape; duyệt lặp lần hai trả lỗi, không có mail thứ hai.
