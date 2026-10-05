# SECURITY: T03 (Đăng ký/đăng nhập học sinh, US-001) | 2026-10-05
**Kết luận:** PASS có điều kiện

Không có Critical/High. Có 3 Medium liên quan trực tiếp tới S10 (limiter theo tài khoản). Cần sửa M2 và M3 trước khi đóng T03 hoặc trước T05. M1 cần PO chọn phương án.

**Phạm vi:** diff chưa commit trên `main`, chỉ phần `backend/` (controllers Auth, `LoginRequest`/`RegisterRequest`, `LoginService`, `RegistrationService`, `PhoneNumber`, `Captcha/*`, `ConsentService`, `Consent`, migration `consents`, `AppServiceProvider`, `bootstrap/app.php`, `config/captcha.php`, `config/privacy.php`, `routes/api.php`, test T02/T03). Đối chiếu: `docs/security/audit-2026-09-25.md` (S10, S17, S20, S21), `review-T01-T02.md`, `tasks.md` mục T03, api-contract §2.2 (khối "Bổ sung từ T03"), ADR-003/004, `docs/review/T03.md` (R1–R5 đã sửa).

## Các điểm đạt (đã kiểm)
- **S17 mass assignment:** `User::$fillable` không có `role/status/*_verified_at/parent_consent_status`. Service chỉ dùng `validated()`, sau đó `forceFill` các giá trị do server quyết định. `Consent::$fillable` không có `revoked_at`.
- **S20 enumeration ở login:** luôn chạy `Hash::check` một lần (có dummy hash). Sai mật khẩu và tài khoản không tồn tại trả cùng thông điệp, cùng status. `WRONG_PORTAL`/`ACCOUNT_LOCKED` chỉ trả khi mật khẩu đúng. Thứ tự WRONG_PORTAL trước ACCOUNT_LOCKED chấp nhận được vì cả hai chỉ lộ sau khi mật khẩu đúng. Khi bị throttle, 429 trả giống nhau dù tài khoản có tồn tại hay không.
- **S20 ở đăng ký:** captcha được kiểm trong `prepareForValidation()`, trước rule `unique`. Thiếu session/Origin thì trả 400 trước khi gọi Cloudflare (R4). Thông báo trùng email/SĐT theo đúng AC2, đã có captcha và throttle 30/giờ/IP.
- **Captcha fail-closed:** `TurnstileVerifier` trả false khi token rỗng/quá dài, khi thiếu secret, khi lỗi mạng, khi phản hồi lạ, timeout 5s. Mặc định config là `turnstile`. `ProductionConfigGuard` cấm `fake` ở production (không phân biệt hoa thường). Driver không hợp lệ thì ném exception. Fake đã yêu cầu token không rỗng.
- **Session/CSRF:** `regenerate()` sau `login()`, không dùng remember-me. Logout gọi `invalidate()` + `regenerateToken()`. Login/register cần session stateful (Origin thuộc `SANCTUM_STATEFUL_DOMAINS`) và CSRF token, nên login CSRF không làm được. Nhóm `guest:web` trả 403 JSON.
- **Throttle IP:** `TrustProxies` chỉ tin IP cụ thể, chỉ tin XFF/Port/Proto. XFF giả từ IP lạ bị bỏ qua (đã có test). Khoá SĐT được chuẩn hoá (`0…`/`+84…`/`84 …`), email được lowercase.
- **Race unique:** chỉ đọc tên index sau `for key`, không đọc giá trị trùng. Index lạ thì rethrow, không đoán field.
- **Mật khẩu:** cast `hashed`, `max:128` ở cả register và login (tránh DoS do băm), `confirmed`.
- **Response/log:** `UserResource` không có `parent_*`. Register/login trả `Cache-Control: no-store`. `dontFlash` có `password`, `password_confirmation`, `parent_*`, `captcha_token`. `parent_phone/parent_email` dùng cast `encrypted` và nằm trong `$hidden`.
- **S19 host api:** nhóm `student` có `role:hoc_sinh`. Test kiến trúc bắt buộc route công khai phải có throttle, và route ghi không cần đăng nhập phải nằm trong allowlist theo tên. `expect($checked)->toBeGreaterThan(0)` đã đổi đúng như TODO.

## Phát hiện

### M1 [Medium] Khoá cứng theo tài khoản cho phép khoá tài khoản người khác (DoS có chủ đích); lớp IP 50 lượt sai làm khoá cả trường dùng chung NAT — OWASP A07/A04 (S10)
- Vị trí: `backend/app/Services/Auth/LoginService.php` `attempt()`, vòng `tooManyAttempts` đặt trước `Hash::check`
- Mô tả & tác động:
  - Khoá `login-fail:<login>` không phụ thuộc IP. Khi khoá này đủ 10 thì **cả mật khẩu đúng cũng bị 429**. S10 trong audit 2026-09-25 đã ghi rõ "không khoá cứng để tránh DoS khoá tài khoản". Người ngoài biết email/SĐT của một học sinh (SĐT dễ đoán hoặc dễ lấy) có thể làm học sinh đó không đăng nhập được, và lặp lại mỗi giờ. Mỗi lần chỉ tốn khoảng 10 request, có thể chia ra nhiều IP. Bối cảnh thực tế: học sinh phá bạn cùng lớp trước giờ học hoặc giờ kiểm tra.
  - Khoá `login-fail-ip:<ip>` ở mức 50 lượt sai/giờ: một lớp học hoặc cả trường đi qua chung một IP NAT. Học sinh gõ sai cộng lại, hoặc một người cố tình gõ sai, là đủ khoá cả trường 1 giờ, kể cả người nhập đúng mật khẩu. Điểm này nối tiếp N4 của review T01/T02.
- Cách sửa (PO chọn, cần ghi vào api-contract §1.6):
  - Phương án A (khuyến nghị): tách làm 3 lớp.
    1. `account+IP` ở mức 10 lượt sai/giờ: khoá cứng (429).
    2. `account` toàn cục ở ngưỡng cao hơn (ví dụ 30/giờ): khi vượt thì **yêu cầu captcha Turnstile ở login** thay vì 429. Người dùng thật vẫn vào được.
    3. IP ở mức 50: cũng chuyển sang yêu cầu captcha thay vì 429, hoặc nâng ngưỡng.
  - Phương án B: giữ khoá cứng nhưng giảm thời gian khoá (ví dụ 15 phút, như AC6 đề xuất) và gửi email cảnh báo cho chủ tài khoản. Phải chấp nhận rủi ro DoS một cách tường minh trong ADR.
- Cách kiểm chứng: 10 lượt sai cho tài khoản X từ IP-1 → đăng nhập đúng của X từ IP-2 không bị 429 (phương án A: trả `CAPTCHA_REQUIRED` hoặc thành công khi có token). 50 lượt sai từ một IP → người dùng khác cùng IP vẫn đăng nhập được khi có captcha.

### M2 [Medium] Né được limiter theo tài khoản bằng biến thể có dấu của email (collation `utf8mb4_0900_ai_ci`) — OWASP A07 (S10)
- Vị trí: `LoginService::accountKey()` và `findByLogin()`. Cột `users.email` (migration `0001_01_01_000000_create_users_table.php`). `config/database.php:60`.
- Mô tả & tác động: đã kiểm chứng chỉ đọc trên MySQL local. Cột `users.email` dùng collation `utf8mb4_0900_ai_ci`, nên khi so sánh, MySQL coi chữ có dấu và chữ không dấu là một (`SELECT 'án@x.vn' = 'an@x.vn'` trả 1). `findByLogin` vì vậy tìm ra **cùng một tài khoản** với nhiều cách viết có dấu khác nhau. Trong khi đó `accountKey()` chỉ `mb_strtolower`, nên mỗi cách viết lại có **bộ đếm riêng**. Mỗi chữ cái có hàng chục biến thể dấu, nên số cách viết của một email gần như vô hạn. Lớp "10 lượt sai/giờ/tài khoản bất kể IP" (lớp chặn credential stuffing phân tán) vì thế không còn tác dụng với đăng nhập bằng email. Chỉ còn lớp IP chặn lại. `LoginRequest` không giới hạn ký tự của `login`, nên các biến thể này đi qua validation.
- Cách sửa (chọn 1, nên làm cả 2):
  1. Đếm theo **danh tính đã tra ra** chứ không theo chuỗi người dùng nhập. Tra user trước (vẫn giữ dummy hash), sau đó dùng khoá `login-fail:user:{id}` nếu có user, hoặc chuỗi đã chuẩn hoá nếu không có:
     ```php
     $user = $this->findByLogin($login);
     $accountKey = 'login-fail:'.($user !== null ? 'u:'.$user->id : 'n:'.self::accountKey($login));
     ```
     Kiểm `tooManyAttempts` sau bước tra cứu này. Cách này không lộ tài khoản tồn tại, vì 429 vẫn xảy ra với chuỗi không tồn tại sau 10 lần.
  2. Chặn từ đầu vào: chỉ chấp nhận email ASCII ở cả register và login (`regex:/^[\x21-\x7E]+$/`), hoặc đổi collation cột `email` sang `ascii_general_ci`/`utf8mb4_bin` + lưu lowercase. Việc đổi collation cần DBA.
- Cách kiểm chứng: test 11 lượt sai cho `hs@example.com` với 11 cách viết có dấu khác nhau (từ cùng IP) → lượt thứ 11 phải 429. Test đăng ký email có dấu → 422 (nếu chọn cách 2).

### M3 [Medium] Limiter "kiểm rồi mới đếm" không nguyên tử: gửi đồng thời vượt được ngưỡng 10/50 — OWASP A07 (S10)
- Vị trí: `LoginService::attempt()`. `tooManyAttempts()` chạy trước `Hash::check` (bcrypt, hàng chục đến hàng trăm ms), còn `hit()` chỉ chạy sau khi kết luận sai.
- Mô tả & tác động: các request đến cùng lúc đều đọc bộ đếm lúc nó còn dưới ngưỡng, nên đều được so mật khẩu trước khi request nào kịp `hit()`. Số lần đoán thực tế cho một tài khoản trong một đợt bị giới hạn bởi mức đồng thời (lớp chống flood 120/phút/IP, nhân với số IP), không phải bởi con số 10. Contract §1.6 và test hiện tại đều giả định đếm tuần tự. Ghi chú: cùng loại lỗi này đã được yêu cầu xử lý cho OTP ở T04 ("tăng `attempts` nguyên tử trước khi so").
- Cách sửa: đếm trước một cách nguyên tử (Redis `INCR` qua `RateLimiter::hit()` trả về số đếm mới), sau đó so. Khi đúng thì hoàn lại:
  ```php
  $accountHits = RateLimiter::hit($accountKey, self::DECAY_SECONDS);
  $ipHits = RateLimiter::hit($ipKey, self::DECAY_SECONDS);
  if ($accountHits > self::ACCOUNT_MAX_FAILURES || $ipHits > self::IP_MAX_FAILURES) {
      // vẫn băm dummy để giữ thời gian đồng đều, rồi ném 429 kèm Retry-After
  }
  $passwordOk = Hash::check(...);
  if ($ok) { RateLimiter::clear($accountKey); RateLimiter::decrement($ipKey); } // decrement nếu dùng Laravel >= 10, nếu không thì bỏ qua
  ```
  Nếu kết hợp với M2 (khoá theo user id) thì đặt `hit()` ngay sau bước tra user.
- Cách kiểm chứng: test gửi đồng thời (nhiều process hoặc `Http::pool` vào server test như kế hoạch T04): 30 request sai đồng thời cho một tài khoản thì tối đa 10 lần được so mật khẩu (đếm qua spy trên `Hash::check` hoặc qua log).

### L1 [Low] Khoá IP theo địa chỉ đầy đủ: client IPv6 xoay địa chỉ trong /64 để có bộ đếm mới — OWASP A07 (S10)
- Vị trí: `LoginService` (`login-fail-ip:`), `AppServiceProvider` (`login-flood`, `register`).
- Mô tả: một client IPv6 thường có cả dải /64. Mỗi địa chỉ là một khoá riêng, nên lớp IP gần như không có giới hạn với client IPv6 (lớp tài khoản vẫn còn, nhưng xem M2/M3).
- Cách sửa: helper `ClientIpKey::for($request)` gộp IPv6 về tiền tố /64 (IPv4 giữ nguyên), dùng chung cho mọi limiter.
- Kiểm chứng: test unit cho helper; 2 địa chỉ IPv6 cùng /64 dùng chung bộ đếm.

### L2 [Low] Đường lỗi DB ở đăng ký có thể ghi email, SĐT và hash mật khẩu vào log — OWASP A09 (S21)
- Vị trí: `RegistrationService::duplicateToValidation()` (nhánh `default => throw $e`) và mọi `QueryException` khác trong transaction đăng ký.
- Mô tả: message của `QueryException` có SQL kèm binding (email, SĐT, bcrypt hash, ciphertext phụ huynh). Handler mặc định ghi message này vào log. Lỗi unique của MySQL còn có giá trị bị trùng. Hiện chỉ xảy ra khi có lỗi DB bất thường, nhưng log production sẽ chứa PII trẻ em.
- Cách sửa: trong `withExceptions` thêm `report()`/`context()` cho `QueryException`: chỉ ghi `getCode()`, tên index, `request_id`. Không ghi `getMessage()`/bindings, ít nhất với các route `/auth/*`. Đưa vào checklist log production (L5, T31).
- Kiểm chứng: test giả lập `QueryException` trong register, dùng `Log::spy()` hoặc đọc log và khẳng định không có email/SĐT/`$2y$`.

### L3 [Low] Bỏ qua được quy tắc phụ huynh bằng cách khai tuổi giả; liên hệ phụ huynh có thể trùng của chính học sinh (R6 còn mở) — OWASP A04
- Vị trí: `RegisterRequest::rules()` (`parent_*` chỉ bắt buộc khi `isMinor()`), `RegistrationService::register()`.
- Mô tả: tuổi chỉ lấy từ `date_of_birth` do người dùng khai. Không đối chiếu với `grade_level`: ví dụ lớp 6 (khoảng 11 tuổi) mà khai sinh năm 1990 vẫn được `parent_consent_status = not_required`. Học sinh dưới 18 tuổi có thể nhập email/SĐT của chính mình làm liên hệ phụ huynh (`different:` chưa có), và tự xác nhận khi T29 gửi link.
- Cách sửa (kỹ thuật, chờ PO/pháp chế chốt): rule kiểm độ hợp lý giữa tuổi và lớp (ví dụ tuổi - lớp nằm trong [4; 9]; ngoài khoảng thì 422 hoặc vẫn yêu cầu phụ huynh). Thêm `different:email`/`different:phone` sau khi chuẩn hoá. Ở T29: không cho xác nhận từ phiên đăng nhập của chính học sinh, ghi nhận kênh và IP lúc phụ huynh xác nhận.
- Kiểm chứng: test lớp 6 + DOB 30 tuổi → 422 hoặc `pending`; `parent_email == email` → 422.

### L4 [Low] Turnstile không kiểm `hostname`/`action` trong phản hồi; staging vẫn có thể dùng `fake` — OWASP A04/A05
- Vị trí: `TurnstileVerifier::verify()`, `ProductionConfigGuard::guardCaptcha()`.
- Mô tả: nếu sitekey được dùng chung cho nhiều tên miền hoặc nhiều form, token từ trang hoặc form khác vẫn hợp lệ. Guard chỉ chặn `fake` khi `APP_ENV=production`, và production thiếu `TURNSTILE_SECRET` thì guard không báo (chỉ hỏng đăng ký, vẫn fail-closed). Staging đã có mục trong T31.
- Cách sửa: so `json('hostname')` với host frontend cấu hình (`config('app.web_host')`) và `json('action') === 'register'` (frontend đặt `action`). Guard: production và staging (`! app()->environment('local','testing')`) bắt buộc `driver=turnstile` và secret khác rỗng.
- Kiểm chứng: `Http::fake` trả `success=true` nhưng `hostname` khác → false. Test guard với secret rỗng.

### L5 [Low] Chất lượng test throttle
- Vị trí: `tests/Feature/T03/LoginTest.php:150-190`.
- Mô tả: test "11 IP khác nhau" dùng `X-Forwarded-For` giả từ IP không tin cậy, nên mọi request thực ra vẫn từ `127.0.0.1`. Test không chứng minh được lớp tài khoản hoạt động khi IP **thật sự** khác nhau. Chưa có test cho biến thể có dấu (M2) hoặc request đồng thời (M3).
- Cách sửa: dùng `withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])` cho mỗi request. Bổ sung test của M1–M3.

### Info
- **I1:** `dummyHash()` được tạo lười, một lần cho mỗi worker. Request đầu tiên của mỗi worker trên nhánh "không có tài khoản" chậm gấp đôi. Đây là nhiễu nhỏ, khó khai thác. Có thể sinh hash tĩnh lúc boot hoặc lưu hằng số.
- **I2:** `max:128` đếm theo ký tự, còn bcrypt chỉ dùng 72 **byte** đầu. Mật khẩu nhiều ký tự có dấu bị cắt âm thầm. Chấp nhận được. Có thể cân nhắc `hashing.bcrypt.limit` hoặc chuyển sang argon2id khi có quyết định.
- **I3:** `Password::defaults()` chỉ yêu cầu `min(8)`, chưa có `uncompromised()`. PO chọn (lưu ý: `uncompromised()` gọi ra ngoài tới HIBP).
- **I4:** Bảng `consents` lưu IP + user-agent (PII) để làm bằng chứng đồng ý. Cần xác định thời hạn lưu, quyền xem (chỉ admin, có audit), và có xoá/ẩn danh khi `AccountAnonymizer` (T34) chạy hay không. FK `restrictOnDelete` sẽ chặn việc xoá user, nên T34 phải ẩn danh chứ không xoá.
- **I5:** `last_login_at` được cập nhật trên mỗi lượt đăng nhập. Không có audit log cho đăng nhập học sinh. Chấp nhận ở T03; cân nhắc log sự kiện `student.login_failed_threshold` khi chạm ngưỡng (phục vụ giám sát, A09).
- **I6:** Thay đổi `phpunit.xml` bỏ `DB_PASSWORD` cố định (tốt, không còn secret trong repo). Cần kiểm CI vẫn lấy được mật khẩu từ env.

## Kết quả công cụ
- `./vendor/bin/pest` (Docker): **194 passed (680 assertions)**.
- `composer audit`: không có advisory.
- Kiểm chứng chỉ đọc trên MySQL local: `users.email` và DB đều dùng `utf8mb4_0900_ai_ci`; so sánh có dấu/không dấu trả bằng nhau (cơ sở của M2).

## Đánh giá lại S* liên quan T03
| # | Trạng thái | Ghi chú |
|---|---|---|
| S10 | ⚠️ | Có 2 lớp, đếm lượt sai, chống XFF giả. Còn M1 (khoá cứng/DoS), M2 (né limiter tài khoản bằng biến thể có dấu), M3 (không nguyên tử), L1 (IPv6) |
| S17 | ✅ | `$fillable` chặt, `forceFill`, `validated()`, có test `role=admin` |
| S20 | ✅ | Thông điệp chung, ACCOUNT_LOCKED/WRONG_PORTAL sau mật khẩu đúng, captcha trước unique. Chiếm chỗ email/SĐT chưa xác thực vẫn chờ T30 (`users:purge-unverified`) |
| S21 | ⚠️ | `dontFlash` đủ. Còn L2 (log QueryException) |
| S7 | ⚠️ | Consent của chính học sinh đã có. L3, I4 chờ T29/T34 và pháp chế |

## Việc chuyển `laravel-dev`
1. **M2:** khoá limiter theo user id đã tra ra (và/hoặc chặn email không phải ASCII). Bắt buộc trước khi đóng T03.
2. **M3:** đổi sang hit-trước-rồi-so (nguyên tử). Nên làm cùng M2 vì cùng sửa một đoạn code.
3. **M1:** chờ PO chọn A/B. Nếu chọn A thì cần thêm captcha ở login (phối hợp `nextjs-dev`).
4. L1 helper IP /64, L2 scrub `QueryException`, L4 kiểm `hostname/action` và mở rộng guard, L3 `different:` (sau khi PO chốt).

## Test `laravel-qa` nên thêm
- 11 lượt sai cho một email với 11 cách viết có dấu khác nhau → lượt 11 phải 429 (M2).
- 30 request sai đồng thời cho một tài khoản → tối đa 10 lần được so mật khẩu (M3).
- 10 lượt sai cho X từ IP-1, rồi X đăng nhập đúng từ IP-2: kỳ vọng theo phương án PO chọn (M1).
- Sửa các test throttle dùng `REMOTE_ADDR` thật thay vì XFF (L5).
- `QueryException` giả trong register → log không có email/SĐT/hash (L2).
- Turnstile `hostname` sai → `CAPTCHA_FAILED` (L4).
- Lớp 6 + DOB người lớn; `parent_email == email` (L3, sau khi PO chốt).

## Điểm cần pháp chế / PO quyết
- **M1:** chấp nhận khoá cứng (rủi ro DoS) hay chuyển sang yêu cầu captcha khi vượt ngưỡng; thời gian khoá (10/giờ theo contract hay 5/15 phút theo AC6).
- **L3:** khi tuổi khai báo không khớp lớp học thì có bắt buộc đồng ý của phụ huynh không; liên hệ phụ huynh có được trùng của học sinh không. Liên quan Luật Bảo vệ dữ liệu cá nhân 2025 / Nghị định 356/2025 về dữ liệu trẻ em: **cần bộ phận pháp chế xác nhận**.
- **I4:** thời hạn lưu IP/user-agent trong `consents`, và cách xử lý khi xoá hoặc ẩn danh tài khoản: **cần bộ phận pháp chế xác nhận**.
- **I3:** có bật kiểm mật khẩu bị lộ (`uncompromised`) không.
