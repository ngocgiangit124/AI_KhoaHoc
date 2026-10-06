# Nợ bảo mật hoãn sang v2

PO quyết định (2026-10-05): tạm dừng bước `laravel-security` và việc sửa lỗi bảo mật không nghiêm trọng để đẩy tiến độ.
Các mục dưới đây được chấp nhận rủi ro tạm thời. **Review lại toàn bộ và sửa khi hệ thống hoàn thành (v2), bắt buộc trước production/go-live.**
Task có cờ [SEC] trong `tasks.md` vẫn đi qua dev → reviewer → QA, chỉ bỏ cổng `laravel-security` cho đến khi PO bật lại (sau khi BA hoàn chỉnh hệ thống).

## T03 (nguồn: `docs/security/review-T03.md`, kết luận PASS có điều kiện, không có Critical/High)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| M1 | Medium | Khoá được tài khoản người khác (10 lượt sai/giờ theo tài khoản; 50 lượt/IP khoá cả trường dùng chung NAT). Đề xuất: khoá theo tài khoản+IP, vượt ngưỡng thì bắt captcha ở login thay vì 429; sửa contract §1.6 | Hoãn v2 (cần PO chọn) |
| M2 | Medium | Né giới hạn theo tài khoản bằng email có dấu (collation `utf8mb4_0900_ai_ci`, `accountKey()` tạo bộ đếm khác nhau). Sửa: đếm theo user id / ASCII-fold | **Đã sửa 2026-10-06 ("Sửa lỗi nhỏ 2")**: `LoginService::attempt` tìm user trước, khoá đếm theo user id (`login-fail:u:{id}`), không có tài khoản thì `accountKey()` bỏ dấu (`Str::ascii`) + hạ chữ; áp cả đăng nhập staff. Test `tests/Feature/T03/LoginThrottleHardeningTest.php` |
| M3 | Medium | Bộ đếm không nguyên tử (kiểm trước, đếm sau) nên request đồng thời vượt ngưỡng. Sửa: `RateLimiter::hit()` trước khi so mật khẩu | **Đã sửa 2026-10-06 ("Sửa lỗi nhỏ 2")**: `RateLimiter::hit()` (INCR nguyên tử) TRƯỚC khi so mật khẩu, vượt ngưỡng → 429; mật khẩu đúng thì `decrement` hoàn lượt (vẫn chỉ đếm lượt sai). Ngưỡng giữ nguyên (10/tài khoản, 50/IP; staff theo config). Áp cả staff (`StaffAuthService`) |
| L1 | Low | Khoá IPv6 theo địa chỉ đầy đủ, nên gộp /64 | Hoãn v2 |
| L2 | Low | `QueryException` ở nhánh rethrow đăng ký ghi SQL kèm binding (email, SĐT, hash) vào log | Hoãn v2 |
| L3 | Low | Khai tuổi giả để bỏ qua phụ huynh (không đối chiếu `grade_level`); liên hệ phụ huynh được trùng của chính học sinh (R6 review) | Hoãn v2, cần pháp chế |
| L4 | Low | Turnstile không kiểm `hostname`/`action`; guard chưa chặn `fake` ở staging, chưa báo thiếu `TURNSTILE_SECRET` | Hoãn v2 (trước T31) |
| L5 | Low | Test "11 IP" dùng `X-Forwarded-For` giả nên thực chất cùng 1 IP; nên dùng `REMOTE_ADDR` | Hoãn v2 |
| Info | — | Dummy hash tạo lười theo worker; bcrypt chỉ dùng 72 byte đầu trong khi `max:128`; chưa có `uncompromised()`; IP/UA trong `consents` cần thời hạn lưu và xử lý khi xoá tài khoản (T34, pháp chế); kiểm CI sau khi bỏ `DB_PASSWORD` cố định trong `phpunit.xml` | Hoãn v2 |

Test QA gợi ý khi làm v2: 11 lượt sai bằng 11 cách viết có dấu của một email → lượt 11 phải 429; 30 request sai đồng thời → tối đa 10 lần so mật khẩu; 10 lượt sai từ IP-1 rồi đăng nhập đúng từ IP-2 (theo phương án M1); test throttle bằng `REMOTE_ADDR`; log không có PII khi `QueryException`; Turnstile `hostname` sai → `CAPTCHA_FAILED`.

## Ghi chú tiến trình
M2, M3 đã sửa 2026-10-06 ("Sửa lỗi nhỏ 2"); M1, L1–L5 chưa có code (xác nhận 2026-10-05). Code T03 hiện chỉ gồm R1–R5 của review (limiter chỉ đếm lượt sai, regex index cho race unique, captcha fake yêu cầu token, no-store). `composer ci` xanh: 194 test.

## Sửa lỗi nhỏ 2 — ghi nhận từ review (2026-10-06, `docs/review/minor-fixes-2.md`)

R1, R2 (Medium) đã sửa: `LoginService::reserveAttempts()` kiểm chỉ-đọc mọi khoá trước (vượt ngưỡng → 429, không hit), rồi mới `hit`; vượt do đua → hoàn mọi khoá đã hit. Dùng chung cho `StaffAuthService`.

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| MF2-R3 | Low | `decrement` khi khoá vừa hết hạn có thể tạo bộ đếm -1 (tặng tối đa 1 lượt). Đã giảm nhẹ: `releaseAttempts()` chỉ hoàn khi `attempts > 0` (còn cửa sổ đua cực hẹp) | Chấp nhận |
| MF2-R4 | Low | Tài khoản có thật: email và SĐT chung 1 bộ đếm; không tồn tại: 2 bộ đếm riêng, nên kẻ biết cả hai định danh có oracle yếu | Hoãn v2 |
| MF2-R5 | Low | Chưa có race test đa tiến trình cho đăng nhập (30 request song song → đúng 10 lần so mật khẩu); hiện dựa vào tính nguyên tử INCR của cache | **Đã xử lý 2026-10-06 (QA minor-fixes-2, BUG-1):** QA viết `T03/LoginRaceTest` + `T28/StaffLoginRaceTest` (15 tiến trình, Redis thật) phát hiện `RateLimiter::hit` không nguyên tử (vượt 10 lượt); Dev thay bằng `AtomicCounter` (Lua), 12/12 lần chạy liên tiếp xanh |

## T04 (OTP) — điểm nghi ngờ ghi nhận, không chặn (2026-10-06)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T04-1 | Medium | `PUT /auth/contact` không yêu cầu mật khẩu hiện tại: kẻ có phiên bị đánh cắp đổi được email/SĐT (và về sau là email nhận mã đặt lại mật khẩu T27). Đề xuất: bắt `current_password` hoặc OTP tới liên hệ cũ khi tài khoản đã xác thực | Hoãn v2 (trước T27) |
| T04-2 | Low | Mã OTP lưu bcrypt (`Hash::make`) theo data-model: không gian 10^6 nên lộ DB là dò ra mã trong giây lát (chỉ có giá trị trong 10 phút). Đề xuất HMAC-SHA256 với khoá riêng nếu muốn cứng hơn | Hoãn v2 |
| T04-3 | Low | Đếm verify 5/phút và 20/ngày theo throttle middleware (cache): đếm cả request sai định dạng, khoá hết ngày thay vì "khoá xác thực 24h" có audit như data-model §3.1; trần ngày chưa ghi audit cho verify | Hoãn v2 |
| T04-4 | Low | `otp-send` throttle đếm cả request 422 (vd. kênh sai) vào cooldown 1/phút | Hoãn v2 |
| T04-5 | Info | Cảnh báo/khoá xác thực 24h theo IP + user khi bị dò hàng loạt; chưa có metric/cảnh báo | Hoãn v2 |

## T05 (một phiên học sinh) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T05-1 | Low | Request mang cookie của session đã bị xoá (có tombstone) vẫn được StartSession tái tạo một session rỗng cùng id cũ (hành vi mặc định Laravel với id lạ). Không cấp quyền nhưng id đó vẫn được dùng lại (fixation nhẹ); login gọi `regenerate()` (Laravel tự xoá payload cũ khi `login()`; dev giữ snapshot để khôi phục nếu bind lỗi) nên không bị lợi dụng. Đề xuất: bỏ id lạ/rỗng ở middleware đầu pipeline | Hoãn v2 |
| T05-2 | Low | Tombstone nằm ở cache còn session nằm ở store `session`: nếu cache bị `cache:clear`/mất thì thiết bị cũ nhận `UNAUTHENTICATED` thay vì `SESSION_REPLACED` (vẫn bị chặn, chỉ sai thông điệp). Cần Redis DB cache tách riêng đúng ADR-004 §6 | Hoãn v2 (T31) |
| T05-3 | Low | `device_id` chỉ so sánh để chọn thông điệp (đúng ADR); kẻ biết UUID thiết bị chủ có thể ép thông báo `SESSION_EXPIRED` thay `SESSION_REPLACED` (không cấp quyền) | Chấp nhận |
| T05-4 | Info | `StudentSessionService::revoke()` đã sẵn cho T27 (đổi/đặt lại mật khẩu) và luồng khoá học sinh; hiện chưa có lệnh/endpoint khoá học sinh nào gọi nó (`staff:lock` chỉ cho staff/GV). Khi có chức năng khoá học sinh (quản trị) phải gọi `revoke($user, 'locked')`; đổi mật khẩu khi đang đăng nhập phải bind lại phiên hiện tại (ADR-003) | Theo dõi ở T27 |
| T05-5 | Info | Chưa có test song song thật (2 process login cùng lúc) cho `lockForUpdate` của `bind()` | QA bổ sung nếu cần |

## T28 (đăng nhập quản trị) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-07)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T28-1 | Low | Throttle đăng nhập sai quản trị cũng khoá được tài khoản staff từ người ngoài (10 lần/giờ), như M1 của T03; chưa có captcha/cảnh báo khi chạm ngưỡng; request bị 429 chưa ghi audit | Hoãn v2 |
| T28-2 | Low | MFA dùng chung trần OTP 5/giờ, 10/ngày với mọi purpose: admin đăng nhập nhiều lần/ngày có thể tự khoá MFA; chưa có "khoá xác thực 24h" có audit | Hoãn v2 |
| T28-3 | Low | Session cũ sau đổi mật khẩu/regenerate (`regenerate(false)`) còn nằm ở store đến hết TTL (bị AuthenticateSession huỷ khi dùng lại); không có tombstone để báo `SESSION_REVOKED` cho staff | Hoãn v2 |
| T28-4 | Low | Nhận diện thiết bị mới của giáo viên dựa vào `X-Device-Id` do client tự khai (kẻ có mật khẩu có thể tái dùng UUID đã biết để tránh email cảnh báo; cookie thiết bị ký phía server sẽ tốt hơn) | Hoãn v2 |
| T28-5 | Info | Staff không bị một-phiên: nhiều phiên song song được phép; thông điệp WRONG_PORTAL của host api học sinh (T03) còn nêu "trang học sinh" | Theo dõi |
| T28-6 | Low | Request không có `Accept: application/json` tới route cần đăng nhập trả 500 "Route [login] not defined" (có từ trước T28, review R6). Đề xuất `shouldRenderJsonWhen` cho `api/*` hoặc `redirectGuestsTo(fn () => null)` + test 401 | Hoãn v2 |

## T17 (cổng thanh toán MoMo) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T17-1 | Medium | Danh sách trường ký của **phản hồi query** (`MoMoSigner::QUERY_RESPONSE_FIELDS`, đang = danh sách IPN) và vector chữ ký **chưa đối chiếu tài liệu/sandbox MoMo** (không truy cập được offline). Sai danh sách → verify fail-closed (không bao giờ nhầm thành công) nhưng đối soát sẽ không chạy được | **Phải kiểm phản hồi query với sandbox MoMo thật trước khi làm T20** (nếu MoMo không ký phản hồi query: cần PO/Security đổi ADR §7b); đối chiếu cả vector chính thức. PO quyết định |
| T17-2 | Low | Đã xử lý (R3): mặc định `MOMO_PAY_URL_HOSTS` chỉ `payment.momo.vn`; sandbox đặt rõ trong `.env.example` local. Guard production chưa ép giá trị này | Chấp nhận |
| T17-3 | Low | Chưa dùng tham số hạn link MoMo (`orderExpireTime`) và chưa xác nhận mã resultCode "giao dịch không tồn tại/hết hạn" (đã tách `EXPIRED_CODES` [1005, 42] và `FAILED_CODES`; mã 8000/10/11/99/1005/42/1001-1007 CHƯA đối chiếu tài liệu; query gặp mã lạ → Pending; IPN mã lạ → Failed theo ADR); hạn mức 1.000–50.000.000đ lấy từ config, cần xác nhận | Kiểm ở sandbox |
| T17-4 | Info | Chưa có `MOMO_IP_ALLOWLIST` (tuỳ chọn, phụ thuộc IP thật qua proxy, S10) và chưa giới hạn tần suất gọi query (thuộc T20) | Theo dõi |

## T27 (quên/đổi mật khẩu học sinh) — điểm nghi ngờ ghi nhận, không có Critical/High (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T27-1 | Low | Throttle `otp-verify` cho `reset` theo tài khoản (5/phút, 20/ngày) cho phép kẻ ngoài cố tình khoá việc đặt lại mật khẩu của nạn nhân (DoS nhẹ); chặn dò mã vẫn do giới hạn 5 lần/mã. Chưa có cảnh báo khi chạm ngưỡng | Hoãn v2 |
| T27-2 | Low | Chưa gửi email cảnh báo "mật khẩu vừa được thay đổi" sau reset/đổi (câu hỏi mở US-015); chưa kiểm N mật khẩu gần nhất (chỉ chặn trùng mật khẩu hiện tại khi đổi) | Hoãn v2 |
| T27-3 | Low | `forgot` gửi OTP sau response (`defer`) nên timing đồng đều, nhưng queue mail và lỗi gửi bị nuốt (chỉ log): người dùng không biết mã không tới. Cần giám sát tỉ lệ lỗi gửi OTP ở vận hành | Theo dõi |
| T27-4 | Low | Reset thành công không xoá bộ đếm đăng nhập sai (`login-fail:*`) của tài khoản | Hoãn v2 |
| T27-5 | Medium | `reset` (không captcha) còn lộ tài khoản tồn tại qua THÔNG ĐIỆP: tài khoản có mã hiệu lực + mã sai → "Mã OTP không đúng", không tồn tại → "đã hết hạn" (kẻ ngoài gọi `forgot` rồi `reset` mã bậy). Phần TIMING đã sửa (QA BUG-1: `dummyHash()` cache liên request qua `Cache::rememberForever` theo cost, mỗi nhánh đúng 1 lần `Hash::check`, có test đếm băm); còn lại phần thông điệp. Giữ AC3 theo quyết định PO; v2 cân nhắc thông điệp chung hoặc captcha ở reset | **Đã sửa 2026-10-06 ("Sửa lỗi nhỏ 2")**: `PasswordService::reset` đổi mọi lỗi mã (sai, hết lượt 5 lần, thua race) thành 422 `OTP_EXPIRED` + thông điệp "hết hạn", giống tài khoản không tồn tại/bị khoá/không có mã. Contract §1.7 và khối T27 đã cập nhật. Test `T27/QaMinorFixesTest` + `PasswordResetTest`. **Ghi chú FW1:** màn đặt lại mật khẩu không còn phân biệt "mã sai" và "hết hạn"; hiển thị thông điệp hết hạn kèm nút "Gửi lại mã" cho cả hai |
| T27-6 | Low | R4 review: `StudentSessionService::killSession` (tombstone + destroy) chạy trong transaction của reset/change; commit lỗi sau đó → văng phiên oan. Đề xuất `DB::afterCommit` trong `revoke()` (đụng code T05, chạy lại test T05) | Hoãn v2 |
| T27-7 | Low | R6 review: cooldown OTP 60s dùng chung mọi purpose nên quên mật khẩu ngay sau đăng ký (<60s) không nhận mã dù vẫn 202; mã `reset_password` còn hiệu lực chưa bị vô hiệu khi `change()` | Hoãn v2 |

## T10 (danh mục công khai + chi tiết khóa) — ghi nhận (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T10-1 | Low | `CourseDetailResource` trả `description` như đã lưu; api-contract §4 yêu cầu sanitize cả khi trả ra. `HtmlSanitizer` (Purifier) thuộc T08: khi T08 xong, bọc `description` bằng sanitizer ở resource (1 dòng) | Đã đóng: resource lọc bằng HtmlSanitizer khi đọc (review R1) |
| T10-2 | Info | `/courses` + `/subjects` + chi tiết chỉ giới hạn `throttle:catalog` 120/phút/IP; không có cache phía Laravel (chỉ HTTP + Data Cache Next). Cân nhắc micro-cache Nginx khi load test FW2 | Theo dõi |
| T10-3 | Info | `avatar_url`/`bio` giáo viên là dữ liệu công khai của khóa; `bio` là văn bản thuần, FE phải render dạng text (không HTML) | Ghi nhận |

## T14 (EnrollmentService, duyệt đăng ký) — ghi nhận từ review (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T14-1 | Low | R5: giáo viên không phụ trách gọi approve/reject với id bất kỳ: id có thật → 403, không có → 404 (dò được "id enrollment tồn tại"). Đồng nhất bằng 404 cho GV không phụ trách nếu cần | Hoãn v2 |
| T14-2 | Low | R9: route `POST /courses/{course}/free-enrollments` chưa có throttle; vòng "xin → bị từ chối → xin lại" tạo dòng lịch sử không giới hạn. Đề xuất throttle ~10/phút/user | Hoãn v2 |

## T08 (quản trị khóa học) — điểm ghi nhận (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T08-1 | Low | Upload ảnh chưa có throttle riêng (chỉ staff/GV đã đăng nhập, 2 MB, ≤ 4000x4000 → GD giải mã ~64 MB RAM); chưa có quota số ảnh/giờ và job dọn ảnh mồ côi khi process chết giữa lưu file và commit | Hoãn v2 |
| T08-2 | Low | Nginx/STATIC_URL phục vụ disk `uploads` (header CSP sandbox, nosniff, tên miền không cookie) thuộc T31; local chưa có vhost tĩnh | Theo dõi T31 |
| T08-3 | Info | Giáo viên đã gán vẫn sửa được `description`/`title` khóa đang bán (chỉ staff mới publish): thay đổi nội dung công khai không qua duyệt lại; ghi audit `course.update` nhưng không lưu nội dung cũ | Chấp nhận MVP |

## T09 (chương/bài) — điểm ghi nhận (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T09-1 | Low | Chưa có throttle riêng cho ghi chương/bài/reorder (chỉ staff/GV đã đăng nhập); reorder cho tối đa 1.000 bài/chương và khoá dòng khóa học trong lúc ghi | Review v2 |
| T09-2 | Low | Xoá bài/chương chỉ chặn khi đã có `lesson_progress`; T13 phải bỏ qua bài đã xoá mềm khi ghi tiến độ (race xoá bài ↔ heartbeat đầu tiên không khoá được từ phía T09) | Theo dõi ở T13 |
| T09-3 | Info | Link ngoài: whitelist host + ID regex, nhưng chưa kiểm video có thật/ở chế độ riêng tư; không HEAD ra ngoài (tránh SSRF) | Chấp nhận |
| T09-4 | Info | Review m1: link Vimeo không công khai (có hash) bị từ chối; chỉ hỗ trợ video công khai | Chấp nhận |
| T09-5 | Low | Review m2: trần payload reorder (đã thêm 500 chương, 1.000 bài/chương); còn thiếu trần tổng số chương/bài mỗi khóa khi tạo (Nit) | Review v2 |
| T09-6 | Info | Review m3: asset mồ côi khi bài đổi khỏi `upload`; dọn ở T11 | Theo dõi T11 |

## T15 (mã giảm giá quản trị) — ghi nhận từ review (2026-10-05)
- R2: S18 cho mã fixed phụ thuộc giá khóa hiện tại; chưa có khóa trả phí thì mã fixed lớn không bị ép giới hạn, và hạ giá/xuất bản khóa rẻ sau đó không kiểm lại mã cũ. T16 (`CouponEvaluator`) chặn mã fixed lớn hơn giá trị đơn/khóa áp dụng.
- R3: `is_restricted=true` nhưng `coupon_course`/`coupon_subject` rỗng (sau cascade) không audit; T16 phải trả `COUPON_NOT_APPLICABLE`, không coi là áp toàn bộ. UI nên cảnh báo khi `courses_count + subjects_count = 0`.
- Thời gian: so sánh bind Carbon theo `config('app.timezone')` (`now()`), không dùng `NOW()` của MySQL; không truyền Carbon UTC khi app timezone khác UTC (QA T15).

## T16 (giỏ hàng) — ghi nhận (2026-10-05)
- T16-1 | Low | Dò mã: `COUPON_EXPIRED`/`COUPON_ALREADY_USED`/`COUPON_NOT_APPLICABLE` cho biết mã tồn tại (chỉ inactive/upcoming/không tồn tại gộp `COUPON_INVALID` theo S18). Bù bằng throttle 10/phút + 60/giờ/IP + trần 30 lần sai/ngày/HS (đếm ở cache, mất khi flush Redis). Cân nhắc đếm thêm theo IP cho lần sai.
- T16-2 | Low | Trần "30 lần sai/ngày" tính theo tài khoản; tài khoản mới tạo hàng loạt vẫn dò được theo IP ở mức 60/giờ.
- T16-3 | Info | `GET /cart` có ghi DB (gỡ mã hết hiệu lực) dưới khoá dòng `carts`; chỉ xảy ra khi giỏ có mã.

- T16-4 (Low): trần 30 lần nhập sai mã/ngày/HS lưu trong RateLimiter (Redis); flush Redis hoặc mất key thì bộ đếm về 0. Chấp nhận ở v1.

## T11 (contract video) — ghi nhận (2026-10-05)

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T11-1 | Low | Webhook `internal` chưa kiểm HMAC header (ADR nói "để thực hành"); hiện an toàn nhờ pull-verify `getVideo()`. Thêm HMAC khi viết `InternalVideoProvider::parseWebhook` (T12) | T12 |
| T11-2 | Low | Hạn mức 20 GB/ngày tính theo `declared_size_bytes` của asset tạo trong ngày lịch (múi giờ app), gồm cả asset failed/mồ côi; chưa hoàn lại hạn mức khi upload thất bại | v2 nếu PO muốn |
| T11-3 | Low | `POST video-uploads` chỉ throttle 20/phút theo user (limiter chuỗi), chưa có limiter đặt tên riêng | v2 |
| T11-4 | Info | `videos:check-stuck` và `videos:prune-orphans` cần scheduler chạy (`schedule:run`) ở staging/production; `internal`/`bunny` chưa có adapter nên prune asset provider đó sẽ báo lỗi và giữ dòng tới khi adapter có | Theo dõi T12 / go-live |
| T11-5 | Info | Link TUS trả cho FE chứa chữ ký có hạn ≤ 6h gắn `VideoId`; endpoint `GET .../video` không trả lại chữ ký | — |

## T21 (soạn quiz) — ghi nhận (2026-10-05)

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T21-1 | Low | Chưa có throttle riêng cho ghi quiz/câu hỏi (chỉ staff/GV được gán, đã đăng nhập); mỗi lần tạo/sửa/xoá khoá dòng `courses`/`quizzes` | Review v2 |
| T21-2 | Low | Văn bản quiz (`QuizText`) từ chối thẻ HTML nhưng không kiểm cú pháp LaTeX; bảo vệ phía hiển thị dựa vào FE (`katex trust:false`, không innerHTML). Cần test FE v2 với payload KaTeX độc (`\href`, `\url`) | FE v2 |
| T21-3 | Info | `QuizOption.is_correct` và `QuizQuestion.explanation` nằm trong `$hidden` (không lộ qua serialize mặc định). T22 bắt buộc dùng Resource riêng cho lượt đang làm và test khẳng định không có `is_correct`/`explanation` | T22 |
| T21-4 | Info | Copy-on-write kiểm "có lượt làm" bằng `JSON_CONTAINS(question_ids)` lọc theo `quiz_id` (bảng `quiz_attempts` do T22 tạo; chưa có bảng → coi như chưa có lượt). T22 phải khoá/đọc dòng `quizzes` (`lockForUpdate`/`sharedLock`) khi chốt `question_ids` để không lọt giữa kiểm và sửa tại chỗ | T22 |
| T21-5 | Low | Review R1: `QuizText` chặn bidi/zero-width/UTF-8 sai; chưa chuẩn hoá Unicode (NFC) hay chặn homoglyph | Review v2 |
| T11-6 | Low | Review R5: `parseWebhook` của adapter thật phải kiểm chữ ký (Bunny/HMAC) và trả null nếu sai; cân nhắc throttle webhook theo guid (kẻ ẩn danh kích hoạt 1 lệnh gọi API ra ngoài mỗi request, đã chặn bởi throttle 120/phút/IP) | T12 / adapter Bunny |
| T11-7 | Info | Review R6: 404 (provider chưa bật) so với 204 cho phép dò allowlist provider; bỏ qua được | — |
| T11-8 | Info | `config('video.upload_ttl_minutes')` dùng `min(360, env)` cắt im lặng giá trị lớn hơn (cố ý, có comment) | — |

## T33 (tài khoản staff) — ghi nhận (2026-10-05)

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T33-1 | Low | Mật khẩu khởi tạo/đặt lại trả trong response JSON (hiển thị một lần cho admin, `no_store`), không gửi email; đúng CLI. Kênh an toàn hơn (link đặt mật khẩu có hạn) để v2 nếu PO muốn. | v2 |
| T33-2 | Low | Huỷ phiên dùng "phiên bản huỷ phiên" trong cache (Redis); nếu Redis mất dữ liệu thì phiên cũ của người đã bị huỷ có thể sống lại tới hết idle/12 giờ (người đang khoá vẫn bị chặn bởi `account.active`). | v2 |
| T33-3 | Low | `audit_logs` chưa có chỉ mục theo `(subject_type, subject_id, created_at)`/`created_at` đã có; lọc kết hợp nhiều điều kiện trên bảng lớn có thể chậm (đã dùng simplePaginate, không đếm tổng). | v2 |
| T33-4 | Info | Đổi vai trò giáo viên → vai trò khác không gỡ các dòng `course_teacher` của họ (CoursePolicy vẫn cho GV chỉ khóa được gán; vai trò mới có quyền rộng hơn). | — |
| T33-5 | Info | Khoá/đổi vai trò/đặt lại mật khẩu không vô hiệu hoá mã OTP MFA đang chờ của người bị tác động (mã vẫn cần phiên hợp lệ + mật khẩu mới để dùng). | v2 |

## Gom sửa lỗi nhỏ 1 — ghi nhận từ review (2026-10-05)

| Mã | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| MF1-1 | Low | `AUTH_OTP_E2E_RELAXED` chỉ dựa vào `APP_ENV`. Đặt nhầm `APP_ENV=local` trên server thật thì hạn mức OTP nới thành 1000/giờ. Thêm vào checklist deploy (T31) hoặc guard kiểm thêm. | Hoãn v2 / T31 |

## T26 (queue/scheduler + throttle catalog SSR) — ghi nhận từ review (2026-10-05)

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T26-1 | Low | Token SSR rỗng ở production âm thầm tắt tính năng (mọi người dùng chung bucket). | Đã xử lý phần lớn: cờ `INTERNAL_API_REQUIRED` + log warning khi boot; còn phải bật cờ ở checklist deploy (T31) |
| T26-2 | Low | Trần tổng `ssr-total` 6000/phút là điểm DoS chung (botnet qua SSR làm 429 cho mọi trang). | v2: WAF/CDN, SSR retry/fallback cache khi 429 |
| T26-3 | Low | Nginx chưa xoá header nội bộ `X-Internal-Token`/`X-Client-IP`. | Đã xử lý ở `vv-common.conf` (api, admin-api); kiểm lại ở staging/production (T31) và bảo đảm SSR đi đường nội bộ |
| T33-6 | Low | (review R1) Version huỷ phiên nằm trong cache: mất cache thì phiên của người bị đổi vai trò còn mang MFA cũ (khoá/mật khẩu vẫn chặn nhờ DB/băm). Hướng sửa v2: lưu `users.session_version` trong DB. | v2 |

## T13 (học & tiến độ) — ghi nhận từ dev (2026-10-05)

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T13-1 | Low | Heartbeat đầu tiên của mỗi bài được cộng tới 45s (coi như 20s trôi qua) dù chưa phát gì; tua nhanh bằng devtool vẫn đạt 90% với tốc độ cộng tối đa ~2,5× thời gian thật (2× + 5s bù mỗi heartbeat, heartbeat 20s) (hạn chế đã chấp nhận ở US-006). Muốn chặt hơn: cấp token phát gắn với `playback` và chỉ cộng khi đã có lần cấp link gần đây. | v2 |
| T13-2 | Low | Cảnh báo bất thường (>3 IP, >60 bài/giờ) chỉ ghi log, đếm trong cache (race làm sai số nhẹ); chưa có cảnh báo tự động/khoá. | v2 |
| T13-3 | Low | `playback` công khai (preview) throttle theo IP 30/phút: lớp học dùng chung NAT có thể chạm trần; link preview không ràng IP nên chia sẻ được trong 15 phút (chấp nhận, bài preview vốn công khai). | Chấp nhận |
| T13-4 | Info | Ràng IP chỉ chống chia sẻ link thô; người dùng đổi mạng (Wi-Fi ↔ 4G) nhận 403 từ CDN và player phải gọi lại `/playback` (đúng ADR-002 §4). | Chấp nhận |
| T13-5 | Info | Quyền thu hồi giữa phiên được kiểm lại mỗi heartbeat/playback nhưng link HLS đã cấp còn dùng được tới hết TTL 15 phút (không thể thu hồi token CDN). | Chấp nhận |
| T13-6 | Info | Log kênh `playback` chứa IP + UA, giữ 90 ngày (PO uỷ quyền tự quyết thời hạn): đưa vào chính sách lưu giữ log khi lên production (T31). | T31 |

## T12 (VideoLab) — ghi nhận từ dev (2026-10-06)

| # | Mức | Mô tả | Xử lý |
|---|---|---|---|
| T12-1 | Low | Đã đóng T11-1/T11-6 phần `internal`: `parseWebhook` kiểm HMAC `X-VideoLab-Signature` (sai → bỏ qua). Chưa throttle webhook theo guid (đã có throttle:webhook theo IP) | Bunny adapter |
| T12-2 | Low | `max_bytes` của video VideoLab = `video.max_upload_mb` (trần chung), KHÔNG phải kích thước khai ở `video-uploads` (interface `createVideo(title)` không mang `size`). Hạn mức/ngày và kiểm `size` vẫn ở nghiệp vụ. Muốn đúng ADR §3a.5 phải mở rộng interface `VideoProvider` (đụng T11/Fake) | Backlog nếu PO muốn |
| T12-3 | Low | TUS `PATCH` giữ khoá hàng DB trong lúc ghi chunk (≤ 8 MB) để chặn ghi chồng; đủ cho dev, cần xem lại nếu nhiều upload đồng thời trên 1 hàng (không xảy ra: 1 upload/guid) | Theo dõi |
| T12-4 | Low | Chưa có Nginx rate-limit riêng cho `/videolab/cdn/` (chỉ `throttle:600,1` ở TUS); production cần `limit_req` + `X-Accel-Redirect` (đã có cấu hình local, bật `VIDEOLAB_ACCEL_REDIRECT`) | Go-live |
| T12-5 | Low | Allow-list Nginx cho `/videolab/library/*` ở local gồm toàn dải private (Docker publish port nên Nginx thấy IP gateway); production phải thay bằng IP app server cụ thể | Go-live |
| T12-6 | Low | Worker video local mount `../backend` chỉ đọc + `/dev/null` đè `.env`, nhưng vẫn thấy toàn bộ mã nguồn và nhận DB/Redis credential qua env (cần để ghi `vl_videos`). Production nên dùng DB user riêng chỉ có quyền `vl_*` và image build sẵn không mount mã nguồn | Go-live |
| T12-7 | Info | Không có DRM/chống tải segment (ADR-002 đã chấp nhận); token HLS ràng IP chỉ khi T13 truyền `ip` | T13 |
| T12-8 | Info | Phát hiện nội dung ffmpeg độc hại phụ thuộc cấu hình khoá cứng (`-protocol_whitelist file`, `-f`, magic bytes, `-format_whitelist`); nên cập nhật ffmpeg định kỳ (CVE demuxer) | Vận hành |


## T18 (checkout) — ghi nhận từ dev (2026-10-06)

Không có Critical/High. Điểm ghi nhận:
- **T18-1 (Medium):** đơn pending bị thay (`superseded`) hoặc quá `expires_at` do checkout huỷ trực tiếp, KHÔNG đối soát attempt cũ trước khi huỷ (khác job huỷ 12h của T20). Nếu HS đã trả tiền ở link cũ thì IPN đến muộn đi đường `cancelled → paid` + `needs_review` (ADR-001 §3). Giảm nhẹ: chỉ tạo link mới khi không còn attempt chưa xác nhận.
- **T18-2 (Low):** attempt `created` mà tiến trình chết sau khi cổng đã tạo giao dịch (trước khi ghi `pay_url`) bị đánh dấu `error` sau 45 giây; job đối soát (T20) chỉ quét attempt có `pay_url` nên link "mồ côi" không được đối soát chủ động (IPN vẫn khớp theo `gateway_order_id`).
- **T18-3 (Low):** `parent.consent` là bản tạm, nay sau cờ `features.parent_consent_enforced` (tắt mặc định); ngưỡng tuổi/nội dung pháp lý ở T29. `POST /courses/{id}/free-enrollments` chưa gắn `parent.consent` (contract yêu cầu) — làm cùng T29.
- **T18-4 (Low):** `link_ttl_minutes`/`min_amount`/`max_amount` của cổng `fake` không có trong config (chỉ MoMo có) nên đơn dùng cổng fake mặc định TTL 30 phút, không kiểm hạn mức; chỉ ảnh hưởng local/testing.
- **T18-5 (Low):** HS tạo/huỷ đơn pending liên tục (10 lần/phút) để chiếm lượt mã trong `coupon_hold_minutes` (30 phút): đã giới hạn bởi hạn giữ chỗ + throttle `checkout`, chưa có trần số lần/ngày.
- Nhắc T19/T20: thứ tự khoá `carts → orders → courses → enrollments → coupons` (courses trước coupons); `markPaid` đã có retry deadlock ở mức ngoài.
| T12-9 | Low | Review R5: xoá video khi worker đang transcode có thể để lại `hls/{guid}`/`.work-{guid}` mồ côi; `videolab:cleanup` chưa quét thư mục không có bản ghi và chưa cứu video kẹt status 1–3 (job mất do flush Redis) | Backlog |
| T12-10 | Low | Review R2/R7: worker dùng tài khoản DB đầy đủ quyền (đã làm sạch env của ffmpeg); production nên có user MySQL riêng chỉ quyền `vl_videos` + jobs/cache; ffprobe stdout chưa giới hạn kích thước; so `Content-Type` TUS bằng `!==` (không nhận `; charset`) | Go-live |
| T12-11 | Info | Review R3 đã xử lý: CDN không truy vấn DB mỗi segment (dựa vào sự tồn tại của `hls/{guid}/` sau rename + token), thêm `throttle:1200,1`; ghi chú limit_req/X-Accel vào README | Đã xong |

- **T18-6 (Low, review R6):** `payment_attempts.create_response` lưu nguyên JSON phản hồi cổng: rà T17 `rawResponse` không chứa chữ ký/secret/PII, mask và đặt thời hạn lưu khi T19 hoàn thiện.
- **T18-7 (Info, review R1):** cờ `FEATURE_PARENT_CONSENT_ENFORCED` (mặc định `false`, PO tạm bỏ ngưỡng tuổi phụ huynh ở v1). T29 phải bật cờ khi có luồng đồng ý phụ huynh + nội dung pháp lý, trước go-live nếu pháp chế yêu cầu.

## T22 (làm quiz, API học sinh) — ghi nhận (2026-10-06), không có Critical/High

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T22-1 | Low | Học sinh vẫn thấy lời giải của quiz sau khi nộp rồi làm lại nhiều lần: đáp án đúng của câu lộ ngay từ lượt đầu (BR5, quyết định PO) nên có thể chia sẻ đáp án; chưa xáo thứ tự câu/đáp án (ngoài phạm vi story) | Chấp nhận (đúng yêu cầu PO) |
| T22-2 | Low | Throttle autosave 120/phút/lượt và 30/phút/người cho start/submit/show/history; chưa có trần số lượt "tạo mới" mỗi giờ (mỗi lần nộp xong học sinh được bắt đầu ngay lượt mới, lưu 1 dòng JSON ≤ vài KB) | Hoãn v2 (theo dõi dung lượng `quiz_attempts`) |
| T22-3 | Info | Ân hạn nộp bài `QUIZ_SUBMIT_GRACE_SECONDS` (mặc định 30 giây) cho phép tối đa ~30 giây autosave sau `expires_at`; đồng hồ lấy từ server PHP (không từ DB `NOW()`) nên nhiều app server phải đồng bộ NTP | Hoãn v2 |
| T22-4 | Info | Lượt quá hạn được tự nộp theo kiểu làm lười (đọc/ghi) và quét `quizzes:auto-submit-expired` mỗi phút; cần scheduler chạy ở staging/production | Cần kiểm khi deploy (T26/T31) |
| T22-5 | Low | Autosave chưa giới hạn tổng số lần ghi/lượt ngoài throttle 120/phút/lượt (không ảnh hưởng toàn vẹn; `answers` tối đa 1 key/câu nên dung lượng bị chặn) | Hoãn v2 |

## T23 (Khóa học của tôi + tiến độ, API học sinh) — ghi nhận từ dev (2026-10-06), không có Critical/High

| # | Mức | Nội dung | Trạng thái |
|---|---|---|---|
| T23-1 | Info | Chỉ đọc dữ liệu của chính user đăng nhập (mọi truy vấn lọc `user_id` từ session, không nhận user_id từ request); trang tiến độ dùng `assertCanLearnCourse` (chưa sở hữu: 403 `COURSE_NOT_OWNED`, khóa nháp 404). Không trả đáp án/giải thích quiz, không trả URL/ID video | Đã xong |
| T23-2 | Low | `/me/courses` trả `pending`/`rejected` (tối đa `learning.my_courses.status_list_limit`) kèm `rejection_reason` của admin: nội dung do admin nhập, FE phải render dạng text (không HTML) | Nhắc FE (FW6) |
| T23-3 | Info | `enrollments.last_accessed_at` chỉ ghi tối đa 1 lần/5 phút (T13) nên thứ tự "học gần nhất" lệch tối đa 5 phút giữa các khóa; mốc p95 300 ms: log channel `learning` (`duration_ms`, `slow`), cần đo trên staging với dữ liệu thật (DBA #10) | Theo dõi ở T31 |
| T23-4 | Low | Danh sách tính % theo lô nhưng bài học tiếp tải toàn bộ id bài của các khóa trong trang (tối đa 30 khóa/trang): nếu khóa có hàng nghìn bài cần denormalize `resume_lesson_id` | Hoãn v2 |
| T23-5 | Low | Review R3: `progress()` đọc `lesson_progress` 2 lần (outline + chi tiết) và `percent` lấy từ `course_percent` còn đếm bài tự cộng (2 nguồn, hiện nhất quán vì xoá chương xoá cả bài); chấp nhận ở MVP | Hoãn v2 |
| T23-6 | Low | Review R4: `statusList()` lấy `limit*3` dòng rồi loại trùng khóa, HS có >60 dòng bị từ chối mới nhất thuộc ít khóa sẽ thiếu khóa (cực hiếm); sửa bằng subquery `MAX(id)` theo khóa | Hoãn v2 |
