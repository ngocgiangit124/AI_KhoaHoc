# REVIEW: Sửa bảo mật cụm 1 (H1, M1, L2, L3, I3)
**Kết luận:** APPROVE (không có Critical/High ở backend; 0 BLOCKER; 2 Medium, 4 Low nên xử lý hoặc ghi backlog). Điều kiện đi kèm: FE đổi liên hệ phải gửi `current_password` trước khi phát hành (R1).
**Phạm vi:** code chưa commit, chỉ phần cụm 1: `UpdateContactRequest`, `ContactService`, `CurrentPasswordGuard`, `ContactChangedMail`, `PasswordService`, `StudentSessionService`, `ApiExceptionRenderer` (nhánh contact_changed), `NotCommonPassword`, `StaffPassword`, `AppServiceProvider`, `UserFactory`, `config/auth.php`, migration trigger, `AuditPurgeCommand`, docker-compose, grants.sql, env production mẫu, checklist, `OperationsServiceProvider`, các test mới · khoảng 40 file. Không review phần của "Sửa lỗi nhỏ 3".
**Kiểm chạy:** Pest (DB e) T02 T03 T04 T05 T27 T28 T30: 503 passed, 1 skipped. Pint sạch trên các thư mục chạm tới. `grep ... | uniq -d` helper toàn cục: rỗng.

## Tổng quan
H1 được vá đúng gốc: bắt `current_password` trước mọi thay đổi (kể cả tài khoản chưa xác thực), đếm lượt sai nguyên tử, báo email cũ, huỷ phiên khác, và `forgot/reset` chỉ qua email đã xác thực. Chuỗi chiếm tài khoản ở báo cáo security không còn đi trọn được. Test phủ khá đầy đủ. Các điểm yếu còn lại là FE chưa theo, thiếu nguồn gốc danh sách mật khẩu, và một số chi tiết vận hành của trigger.

## Kiểm các đường vòng H1
- Đổi SĐT cùng luồng: cùng `update()`, `CurrentPasswordGuard::assert` chạy trước nên bắt `current_password` như email. SĐT KHÔNG phải kênh reset (reset chỉ email đã xác thực) nên không thành đường chiếm tài khoản. Chỉ khác: đổi SĐT không gửi thư báo và không huỷ phiên khác (R3, Low).
- Khoá chéo: khoá `current-password-fail:u:{id}` chỉ dùng cho đổi mật khẩu và đổi liên hệ. KHÔNG dùng chung với khoá đăng nhập (`login-fail:*`), nên lượt sai ở contact không khoá đăng nhập. Chiều ngược lại: kẻ cầm phiên đánh cắp có thể đốt 10 lượt để chủ nhân bị chặn đổi mật khẩu/liên hệ 1 giờ (R4, Low; chủ vẫn dùng `forgot`).
- Race đổi email và forgot: an toàn nhiều lớp. `invalidateForChannels` huỷ OTP email chưa dùng trong transaction `lockForUpdate`; `reset` kiểm `destination == email hiện tại`; callback trong `consume` gọi lại `isEligible` (email đã đổi thì `email_verified_at = null` nên từ chối và rollback tiêu thụ mã). Mã gửi tới email cũ rồi email đổi: không dùng được.
- Thư báo: chỉ chứa tên, email mới dạng che (`a***@domain`), giờ đổi, email hỗ trợ; không mã, không liên kết, không IP. `ShouldBeEncrypted` + queue, lỗi gửi chỉ log `exception::class`. Chỉ gửi khi email cũ đã xác thực (đúng: không tin địa chỉ chưa xác thực). Tên miền của email mới lộ cho chủ email cũ: chấp nhận được, đó là mục đích cảnh báo.
- `SESSION_REVOKED` với lý do contact_changed: thông điệp "Email tài khoản đã được thay đổi, vui lòng đăng nhập lại." Không lộ email mới hay giá trị nào; chỉ phiên đã từng đăng nhập hợp lệ mới nhận được. Chấp nhận.
- Phiên hiện tại: `revoke` rồi `regenerate` rồi `bind`; lỗi bind thì mọi phiên đã bị huỷ (fail-safe), cùng mẫu `PasswordService::change`.

## Phát hiện

### R1 [Medium] FE `ChangeContactForm` chưa gửi `current_password`: màn đổi liên hệ sẽ luôn 422 sau khi backend lên
- Vị trí: `frontend/apps/web/components/auth/ChangeContactForm.tsx`, `frontend/apps/web/lib/auth/api.ts` (grep không có `current_password`).
- Vấn đề: đã ghi vào `docs/architecture/tasks.md` (FW1, ghi chú Bảo mật cụm 1) nhưng chưa có code. Không phải lỗi backend; là nợ phải làm cùng nhịp phát hành.
- Đề xuất: giao `nextjs-dev`: thêm ô mật khẩu hiện tại, hiển thị `errors.current_password[0]` và 429 `Retry-After`; sau đổi email gọi lại `/auth/me`. Không phát hành backend này một mình.

### R2 [Medium] Helper `vvTestWipeAuditLogs()` không tồn tại; `tests/Pest.php` chỉ thêm `use Illuminate\Support\Facades\DB;` thừa
- Vị trí: `backend/tests/Pest.php:4`.
- Vấn đề: yêu cầu nêu helper có try/finally dựng lại trigger, nhưng grep toàn repo không thấy. Dev chọn hướng khác: bỏ `DB::table('audit_logs')->delete()` ở các test commit thật, thay bằng đếm theo mức nền (`$base`) và comment "DB test riêng". Hệ quả: dòng audit mới tích luỹ vĩnh viễn trong DB test e (trigger chặn xoá), chỉ `migrate:fresh` dọn. Test gốc nào còn assert đếm toàn bảng sẽ dễ vỡ về sau. Import `DB` ở Pest.php là code chết.
- Đề xuất: xoá import thừa, hoặc thật sự viết helper tạm gỡ trigger bằng `try { DROP TRIGGER } finally { up() }`. Hướng hiện tại chấp nhận được nếu ghi rõ trong `tests/README` rằng DB test không dọn được `audit_logs`. Test `down()/up()` của migration đã dùng try/finally đúng.

### R3 [Low] Đổi SĐT không báo và không huỷ phiên khác
- Vị trí: `ContactService::update` (`if ($emailChanged)` bao cả thông báo và revoke).
- Vấn đề: hiện SĐT không phải kênh khôi phục nên không chiếm được tài khoản. Nhưng SĐT là định danh đăng nhập và là kênh OTP khi bật `sms`; khi sms bật, `forgot` qua SMS (nếu thêm sau này) sẽ mở lại đường vòng.
- Đề xuất: ghi backlog (đã ghi "vẫn hoãn V2"); khi bật kênh sms phải thêm thư báo email cho đổi SĐT.

### R4 [Low] Khoá lượt sai dùng chung có thể bị đốt để chặn chủ tài khoản đổi mật khẩu 1 giờ
- Vị trí: `CurrentPasswordGuard.php` (khoá `current-password-fail:u:{id}`, 10/giờ).
- Vấn đề: kẻ có phiên đánh cắp gọi 10 lần sai là chủ không đổi được mật khẩu/liên hệ trong 1 giờ; phục hồi bằng `forgot` (vẫn chạy). Không ảnh hưởng đăng nhập. Đánh đổi hợp lý so với dò mật khẩu.
- Đề xuất: ghi nhận trong backlog, không cần sửa.

### R5 [Low] Danh sách mật khẩu phổ biến không ghi nguồn và giấy phép
- Vị trí: `backend/resources/data/common-passwords.txt` (11.799 dòng, 111 KB).
- Vấn đề: không có tệp/ghi chú nào nêu nguồn (SecLists, NCSC, tự biên soạn?) hay giấy phép. File thuần chữ thường, không trùng, không CRLF, tất cả >= 8 ký tự (đúng thiết kế vì min 8), có mẫu tiếng Việt (`matkhau123`, `vitaminvui`). So khớp `mb_strtolower(trim())` đúng với file chữ thường. Hiệu năng: nạp lazy vào `static` khi có validate mật khẩu (khoảng 12k phần tử, vài ms, dưới 1 MB), không nạp mỗi request chung, không cần cache thêm; dưới Octane giữ lại giữa request (tốt).
- Đề xuất: thêm `resources/data/README.md` ghi nguồn, giấy phép, ngày tạo; nếu lấy từ SecLists (MIT) giữ thông báo bản quyền.

### R6 [Low] Chi tiết vận hành của trigger `audit_logs` và cờ binlog
- Trigger DELETE: `OLD.created_at >= NOW() - INTERVAL 24 MONTH + INTERVAL 1 DAY` đúng thứ tự phép tính (trừ 24 tháng, cộng 1 ngày), tức ngưỡng thực là "cũ hơn 24 tháng trừ 1 ngày"; dòng quá hạn 23 tháng 29 ngày xoá được (vài giờ non so với hạn lưu pháp lý, chấp nhận vì đệm cố ý). App `audit:purge` cắt ở 24 tháng nên luôn nằm trong vùng cho phép.
- Múi giờ: `config/database.php` đặt phiên MySQL `+07:00` và `APP_TIMEZONE=Asia/Ho_Chi_Minh` nên `NOW()` và `created_at` cùng múi; đệm 1 ngày dư sức cho lệch UTC.
- `down()` có, dùng `DROP TRIGGER IF EXISTS`; test down/up có try/finally.
- `audit:purge` trên production: DELETE của `vv_app` kích trigger mà KHÔNG cần quyền TRIGGER (quyền này chỉ cần khi tạo/xoá trigger), nên không vỡ. `vv_app` không gỡ được trigger (không có DROP/TRIGGER), `TRUNCATE` cần DROP nên cũng bị chặn. Đúng ý L2.
- Lưu ý: trigger mang `DEFINER = vv_migrate`; nếu user này bị xoá/đổi tên, mọi UPDATE/DELETE audit (kể cả `audit:purge`) báo lỗi 1449. Fail-closed nên an toàn nhưng làm purge ngừng. Thêm một dòng vào checklist: không xoá `vv_migrate`, hoặc tạo trigger với `DEFINER` là tài khoản cố định.
- `--log-bin-trust-function-creators=1`: chỉ thêm vào compose local; production chỉ nằm trong checklist/grants. Tác động bảo mật: bỏ yêu cầu SUPER khi tạo trigger/hàm với binlog bật, tức user có quyền TRIGGER/CREATE ROUTINE tạo được đối tượng không an toàn cho replication. `vv_app` không có các quyền đó nên rủi ro thấp ở local. Với production nên ưu tiên `SET GLOBAL` trong khung deploy bằng tài khoản quản trị rồi trả về 0, hoặc cấp quyền tạm cho `vv_migrate`, thay vì ghi cố định vào my.cnf. Cần xác minh trên MySQL 8.4 thật rằng chỉ cấp SET_USER_ID là đủ như checklist đang nói.

### R7 [Low] Nhận xét nhỏ khác
- `Password::min(12)->rules([...])` của `StaffPassword` không còn `letters()/numbers()` như đề xuất gốc của báo cáo security (chỉ dài + không phổ biến + không chứa phần local email). Với min 12 và danh sách chặn thì chấp nhận được; ghi rõ là cố ý.
- Quy tắc "chứa phần trước @" chỉ áp khi phần local >= 4 ký tự (chống chặn oan); hợp lý.
- `UserFactory::defaultPlainPassword()` ném lỗi ngoài testing/local; `demo_password` mặc định hard-code `Demo-VitaminVui-2026` nhưng seeder demo đã bị chặn ngoài local (test T02 đã xác nhận). Chấp nhận.

## Đối chiếu
| Hạng mục | Code đáp ứng | Ghi chú |
|---|---|---|
| H1 `current_password` bắt buộc | `UpdateContactRequest`, `CurrentPasswordGuard`, `ContactService::update` | Cả tài khoản chưa xác thực. 10 lượt sai/giờ/user dùng chung với đổi mật khẩu; sai thì 422 + audit; hết hạn mức thì 429 |
| H1 thư báo email cũ | `ContactChangedMail`, `notifyPreviousEmail` | Chỉ khi email cũ đã xác thực; không lộ PII ngoài email mới dạng che |
| H1 huỷ phiên khác, xoay cookie | `revokeOtherSessions`, `REASON_CONTACT_CHANGED` | Test `ContactReauthTest` |
| H1 forgot/reset chỉ email đã xác thực | `PasswordService::isEligible` | Cả reset và callback trong `consume`; test `ForgotVerifiedChannelTest` |
| M1 staff min 12, không phổ biến, không chứa local email | `StaffPassword`, `ChangeStaffPasswordRequest` | Test `StaffPasswordPolicyTest` |
| M1 học sinh chặn phổ biến | `Password::defaults()` kèm `NotCommonPassword` | Đạt (xem R5) |
| L2 trigger | migration `2026_10_16_100000`, `AuditPurgeCommand::MIN_RETENTION_MONTHS` | Đạt, xem R6; helper `vvTestWipeAuditLogs` không có (R2) |
| L3 cookie `__Host-` | env mẫu, checklist, `HostPrefixCookieTest` | Xem dưới |
| I3 lịch `ops:health --log` | `OperationsServiceProvider` (`command('ops:health --log')`) | Đúng, sửa lỗi "option does not accept a value"; có `OpsHealthScheduleTest` |
| FE đổi liên hệ | Chưa | R1 |

L3: `ConfigureHostContext` đã ép `domain=null` và `secure` ở production; không có `Domain` nên hợp lệ với `__Host-`; test khẳng định Secure, `Path=/`, không Domain, HttpOnly cho cả 2 host. Sanctum stateful/CSRF dùng token header từ `/csrf-token` (không phụ thuộc tên cookie); `EncryptCookies` đọc tên động. Rủi ro còn lại: nếu `SESSION_PATH` hoặc nginx thêm `Domain=` thì trình duyệt âm thầm bỏ cookie. Checklist đã nêu.

## Gợi ý cho QA
- PUT /auth/contact: thiếu, sai, đúng mật khẩu; 11 lượt sai thì 429; sau mật khẩu đúng kiểm bộ đếm được hoàn.
- Đua hai request đổi email song song (khoá hàng `lockForUpdate`), và đổi email đồng thời với `forgot`/`reset` đã có mã.
- Hai phiên: đổi email ở phiên A, phiên B nhận 401 `SESSION_REVOKED`, phiên A vẫn sống với cookie mới.
- Tài khoản email chưa xác thực: không có thư báo, `forgot` không gửi mã.
- Trigger trên MySQL 8.4 thật: UPDATE/DELETE bằng user `vv_app` bị chặn, `audit:purge` vẫn chạy; thử `--months=23` bị từ chối.
- Mật khẩu: `Password123` hoa/thường, khoảng trắng đầu/cuối, `12345678`, mật khẩu staff 12 ký tự chứa phần local email.
- Staging HTTPS thật: cookie `__Host-` có `secure; path=/` và không `domain=`, đăng nhập cả hai portal.
