# REVIEW: Sửa lỗi nhỏ 3 (T12 Minor, T33-4, T27-3, T16-1/2, T12-2, T05-5)
**Kết luận:** APPROVE (không có Critical/High; 2 Medium, 3 Low nên xử lý hoặc ghi backlog)
**Phạm vi:** `git diff` unstaged backend (CartCouponController, OtpService, PasswordService, CartService, StaffAccountService, VideoProvider + 2 adapter, VideoUploadService, TusController, TusUploadService, config/orders) + 4 test mới, 3 test sửa · 19 file. Pest T16+T33+T27+T12+T11 (loại race): 276 passed, 1 skipped. Pint sạch. Helper test toàn cục không trùng (`uniq -d` rỗng).

## Tổng quan
Thay đổi gọn, đúng backlog, có test cho từng mục. Đổi chữ ký `createVideo` đã cập nhật đủ cả hai adapter (`internal`, `fake`), một nơi gọi production và test. `max_bytes` an toàn: VideoLab luôn kẹp bằng `min(trần video.max_upload_mb, max_bytes)` nên client không thể vượt trần, chỉ có thể tự hạ. Log OTP che cả mã 6 số và email.

## Phát hiện

### R1 [Medium] Trần IP mã giảm giá dùng `RateLimiter::hit/decrement`, không nguyên tử
- Vị trí: `CartService.php::applyCoupon` (khối `$ipKey`, các `decrement`).
- Vấn đề: đây chính là lỗi QA BUG-1 của đăng nhập (mất lượt khi song song). Kẻ dò mã bắn request song song sẽ mất một phần lượt đếm nên vượt trần 150. `decrement` trên khoá đã hết hạn còn tạo giá trị âm có TTL 24h (tặng lượt). Bộ đếm theo học sinh (`coupon-fail:`) có sẵn cùng lỗi, nhưng đó là phần cũ.
- Đề xuất: dùng `App\Support\AtomicCounter` cho cả hai khoá (`hit`, `release`, `availableIn`). Tên khoá không đổi nên test hiện có (`RateLimiter::attempts/clear`) vẫn chạy.
  ~~~php
  if ($ipKey !== null && AtomicCounter::hit($ipKey, 86400) > $ipLimit) {
      AtomicCounter::release($ipKey, 86400);
      AtomicCounter::release($key, 86400);
      throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) AtomicCounter::availableIn($ipKey)]);
  }
  // các RateLimiter::decrement còn lại -> AtomicCounter::release
  ~~~
  Nên chuyển luôn khoá `coupon-fail:` để một chỗ duy nhất.

### R2 [Medium] Nguồn IP phụ thuộc `TRUSTED_PROXIES`; guard production không bắt buộc khác rỗng
- Vị trí: `CartCouponController` (`$request->ip()`), `bootstrap/app.php:82-91`, `ProductionConfigGuard::guardTrustedProxies`.
- Vấn đề: `request->ip()` đúng khi `TRUSTED_PROXIES` liệt kê Nginx/LB (không dùng được XFF giả vì chỉ tin proxy tin cậy, và đã cấm `*`). Nhưng nếu production quên đặt, mọi học sinh có cùng IP của proxy: 150 lần sai/ngày của toàn hệ thống khoá mã giảm giá cho tất cả (DoS tự gây). Guard hiện chỉ cấm `*`, không cảnh báo rỗng.
- Đề xuất: ghi vào checklist production (T31) "TRUSTED_PROXIES bắt buộc đặt", hoặc guard fail khi rỗng nếu chạy sau proxy. Cùng rủi ro với trần IP đăng nhập.

### R3 [Low] Khoá oan lớp dùng chung NAT (chấp nhận theo thiết kế, nên có giám sát)
- Vị trí: `CartService.php` (kiểm trần IP chạy trước, chặn cả khi mã nhập đúng).
- Vấn đề: một học sinh nghịch trong phòng máy/trường làm 150 lần sai thì mọi học sinh cùng IP không áp được mã (kể cả đúng) tối đa 24h (cửa sổ tính từ lần đầu). 150/ngày khá rộng nên ít xảy ra bình thường. Thông điệp 429 chung chung.
- Đề xuất: giữ 150, thêm log cảnh báo khi IP chạm trần để vận hành biết, và thông điệp 429 hướng dẫn thử lại sau. `ORDERS_COUPON_FAILS_PER_IP_PER_DAY` đã cho chỉnh/tắt, đủ.

### R4 [Low] `changeRole` và `CourseTeacherService::sync` có cửa sổ đua hẹp
- Vị trí: `StaffAccountService::changeRole` (khoá user rồi xoá `course_teacher`), `CourseTeacherService::sync` (khoá course, đọc `users.role` không khoá).
- Vấn đề: đổi vai trò (transaction A) và gán giáo viên (transaction B) đồng thời: B đọc role còn `giao_vien`, A commit xoá phân công, B chèn dòng `course_teacher` cho người không còn là giáo viên. `CoursePolicy::isAssignedTeacher` có kiểm `isTeacher()` nên không cấp quyền, nhưng dòng mồ côi hiển thị trong danh sách giáo viên khóa. Không có deadlock: A khoá user, B khoá course và không khoá user (không có chu trình). Cùng transaction cho đổi role và xoá phân công: đúng.
- Đề xuất: trong `sync` đọc `User::whereIn(...)->lockForUpdate()` hoặc `sharedLock()` khi kiểm `validNew`.

### R5 [Low] Khóa còn 0 giáo viên sau `changeRole`; admin không được báo
- Vị trí: `StaffAccountService::changeRole`.
- Vấn đề: BR "tối thiểu 1 giáo viên" của US-009 chỉ được ép ở `sync`; `changeRole` vi phạm ngầm. Không vỡ quyền: `CoursePolicy` cho staff `view/update/manageTeachers` mọi khóa nên admin vẫn quản lý và gán lại được; không giáo viên nào truy cập khóa. Khóa đã publish còn 0 giáo viên: trang công khai hiển thị rỗng tên giáo viên (cần FE chịu được mảng rỗng). Response/audit có `released_course_ids` nhưng UI chưa cảnh báo.
- Đề xuất: ghi nhận vào backlog hoặc trả `released_course_ids` ở response để FE cảnh báo "N khóa không còn giáo viên"; kiểm `CourseCatalog` không lỗi khi 0 giáo viên.

## Các điểm đã kiểm, không có lỗi
- Đổi về giáo viên (`from != Teacher`): không đụng `course_teacher`, audit không có `released_course_ids` (có test). Quyền mới chỉ có sau khi staff gán lại, đúng ý ghi chú.
- Audit chỉ chứa role và id khóa, không PII. `array_filter` loại mảng rỗng đúng; ép int + `orderBy` ổn định (test kiểm thứ tự).
- Log OTP: `OtpService` che `\d{6}` và chuỗi chứa `@`; không log trace. `PasswordService` chỉ log class và mã lỗi domain. Test dùng exception chứa email và mã nhưng khẳng định log sạch email (nên thêm khẳng định không chứa mã OTP, xem Gợi ý).
- TUS: 422 `VIDEO_INVALID` ném sau commit nên trạng thái `upload_failed` vẫn lưu; POST `Content-Type: text/plain`. Test đủ.
- `T05-5`: `->group('race')` đặt cho cả 2 test; chỉ đổi nhãn.
- `createVideo`: nhánh Bunny chưa có adapter nên không có implementer thiếu; interface docblock đúng.

## Đối chiếu yêu cầu
| Mục | Code đáp ứng | Test | Ghi chú |
|---|---|---|---|
| T12 Minor PATCH 422 VIDEO_INVALID | TusUploadService | TusUploadTest (đã sửa) | Đạt |
| T12 Minor POST text/plain | TusController | TusUploadTest (mới) | Đạt |
| T33-4 gỡ course_teacher + audit | StaffAccountService | RoleChangeReleasesCoursesTest (2 ca) | Đạt; R4, R5 |
| T27-3 log error, che PII | OtpService, PasswordService | ForgotSendFailureTest | Đạt |
| T16-1/2 trần IP 150, 429, hoàn lượt | CartService/Controller/config | CouponIpFailCapTest (2 ca) | Đạt chức năng; R1, R2, R3 |
| T12-2 max_bytes | VideoProvider, adapters, VideoUploadService | InternalProviderTest, UploadMaxBytesTest | Đạt, không vượt trần |
| T05-5 group race | BindConcurrencyTest | n/a | Đạt |

## Gợi ý cho QA
- Song song 200 request mã sai từ cùng IP: đếm thực tế phải = 150 sau khi sửa R1 (hiện sẽ lệch).
- Gửi `X-Forwarded-For` giả từ ngoài proxy tin cậy: IP trong khoá không đổi.
- Đổi vai trò giáo viên đang được gán đồng thời với gán giáo viên mới cho cùng khóa (R4).
- Khóa publish bị gỡ hết giáo viên: trang công khai và admin không lỗi.
- Thêm khẳng định log OTP không chứa mã 6 số trong `ForgotSendFailureTest`.

---

# Review lần 2 (sau khi Dev sửa R1-R5 và làm thêm cụm 1 L1, cụm 2 L1-L5, cụm 3 M1/L1-L3)
**Kết luận:** REQUEST CHANGES (1 High: trần heartbeat theo người học cắt oan người xem tốc độ > 1,25x; 1 Medium, 3 Low)
**Phạm vi:** CartService, CartCouponController, AtomicCounter, ProductionConfigGuard, CourseTeacherService, StaffAccountService/Controller (changeRoleDetailed), ProgressService, PlainText, CheckoutService/Request, VideoLab (MediaToolkit, TranscodeService, cleanup, Tus, exception, Arch test), routes limiter `cart`, config, test liên quan. Bỏ qua phần của dev "Bảo mật cụm 1". Pest T16+T18+T13+T12+T09+T33+T31+Arch (loại race): 457 passed, 6 skipped, 0 fail (T33 đã xanh).

## Kiểm lại R1-R5
- R1 Đạt: `CartService` đúng pattern kiểm chỉ đọc (`attempts >= max` thì 429, không hit) → `hit` từng khoá → thua race thì `release` mọi khoá đã hit trong request rồi 429. Hoàn lượt khi thành công/lỗi hạ tầng. `ip_hash` là HMAC-SHA256(APP_KEY) cắt 16 ký tự, log không chứa mã. Ngưỡng giữ nguyên (30 lần sai được phép).
- R2 Đạt có lưu ý (R9 bên dưới).
- R3 Đạt (log warning có `ip_hash`).
- R4 Đạt: `sync` khoá `courses`(X) → `users`(S, `orderBy id`) → `course_teacher`; `changeRoleDetailed` khoá `users`(X) → `course_teacher`. `changeRole` không khoá `courses`, nên không có chu trình. Hai bên chung chiều users → course_teacher. Nếu sync giữ S trên user trước khi changeRole xin X thì changeRole đợi, rồi xoá phân công sau commit sync: kết quả đúng.
- R5 Đạt: response `PATCH /admin/staff/{id}/role` thêm `released_course_ids` (mảng rỗng khi không phải giáo viên); `changeRole` cũ vẫn là vỏ mỏng gọi `changeRoleDetailed`.

## Phát hiện mới

### R6 [High] Trần heartbeat theo người học (75 giây/60 giây) cắt oan học sinh xem tốc độ > 1,25x và làm bài không bao giờ hoàn thành
- Vị trí: `ProgressService::capByUser`, `config/learning.php` (`user_credit_cap_seconds=75`, `user_credit_window_seconds=60`), test `HeartbeatTest` AC2 (đổi `travel(20)` thành `travel(41)`).
- Vấn đề: cấu hình hiện hành cho phép tốc độ phát tối đa 2x (`max_speed=2`, cap theo bài = elapsed*2+5). Trần mới chỉ cho 1,25x trên cả cửa sổ. Học sinh xem 1,5x hoặc 2x (rất phổ biến) bị cộng 75 trong 60 giây thực dù đã xem 90 đến 120 giây video, phần vượt cộng 0 mà vị trí vẫn tiến. Hết video, `watched_seconds` chỉ khoảng 63% đến 83% thời lượng, dưới ngưỡng 90% nên bài không bao giờ `completed`, chặn tiến độ khóa học (US-006 BR2). Không có lỗi hiển thị để học sinh biết. Thay đổi test 20 → 41 giây chính là che hành vi này: kịch bản gốc (3 heartbeat cách nhau 20 giây, mỗi lần tua 30 giây = 1,5x) hợp lệ theo cấu hình cũ, nay lần 3 bị cắt còn 15 (tổng 75 < 90) nên test đỏ, Dev dãn thời gian để test xanh thay vì xử lý nguyên nhân. Cửa sổ cố định neo ở lần cộng đầu cũng làm biên cửa sổ không đều (có thể dồn 75 cuối cửa sổ cộng 75 đầu cửa sổ kế).
- Đề xuất: đặt trần theo đúng tốc độ tối đa đã cho phép, chống mở N bài song song mà không phạt tốc độ hợp lệ. Ví dụ `user_credit_cap_seconds = max_speed * window + slack = 125` (window 60). Chống song song vẫn có tác dụng (N bài cùng lúc vẫn bị gộp chung một trần). Khôi phục test AC2 về `travel(20)` và thêm test: một bài xem 2x liên tục 120 giây video trong 60 giây thực không bị cắt; hai tab cùng bài vẫn đúng (khoá hàng tuần tự hoá, elapsed nhỏ).

### R7 [Medium] `PlainText` chặn U+200D/U+200C làm hỏng tên chứa emoji ghép (ZWJ)
- Vị trí: `app/Rules/PlainText.php` (`\x{200B}-\x{200D}`), dùng ở Chapter/Lesson/Course/Quiz/Coupon request.
- Vấn đề: tiếng Việt có dấu (NFC và NFD) và emoji đơn không bị chặn (đã kiểm khoảng chặn: chỉ bidi, ZWSP/ZWNJ/ZWJ, BOM, Cc). Nhưng emoji ghép dùng ZWJ (người nhà, cờ cầu vồng, nghề nghiệp có giới tính, tông da ghép) bị từ chối với thông báo "ký tự ẩn" khó hiểu. Với khoá học thiếu nhi đây là trường hợp thật. ZWNJ (U+200C) cần cho một số chữ khác (Ba Tư, Hindi), không liên quan tiếng Việt.
- Đề xuất: vẫn chặn U+200B, U+200C, U+FEFF, bidi; cho phép U+200D khi nằm giữa hai ký tự `\p{Extended_Pictographic}`. Ví dụ bỏ U+200D khỏi lớp ký tự và thêm kiểm `(?<!\p{Extended_Pictographic}\x{FE0F}?)\x{200D}|\x{200D}(?!\p{Extended_Pictographic})`. Thêm test cho `👨‍👩‍👧` được qua, `a\u{200D}b` bị chặn. Nếu PO chấp nhận không cho emoji ghép, ghi rõ vào contract.

### R8 [Low] `AtomicCounter::add` có thể tạo khoá không TTL
- Vị trí: `AtomicCounter.php` (Lua `ADD`: chỉ `EXPIRE` khi `v == ARGV[1]`).
- Vấn đề: `capByUser` gọi `add(+credited)` rồi `add(-excess)`. Nếu khoá hết hạn đúng giữa hai lệnh, lệnh thứ hai tạo khoá giá trị âm không TTL (mãi mãi), cấp thêm "quota âm" vĩnh viễn cho người đó. Ngược lại nếu khoá đang là 0 (sau hoàn) thì cộng lại sẽ reset TTL (v == amount). Cửa sổ hẹp, hậu quả nhỏ.
- Đề xuất: đặt TTL theo trạng thái thật, không theo giá trị: `if redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end`.

### R9 [Low] Miễn `TRUSTED_PROXIES` cho console dựa vào `runningInConsole()`
- Vị trí: `ProductionConfigGuard::guardTrustedProxies`, `config/app.php`.
- Vấn đề: dùng được với Nginx + PHP-FPM hiện tại (FPM không phải console, hằng `trusted_proxies_console_exempt` không có env nên không lạm dụng được từ cấu hình triển khai). Nhưng nếu sau này chuyển sang Octane/Swoole/RoadRunner (chạy bằng CLI) thì `runningInConsole()` là true, guard bị vô hiệu cho cả web. Ghi nhận để không quên khi đổi runtime.
- Đề xuất: thêm 1 dòng cảnh báo vào ADR/checklist T31 hoặc kiểm `PHP_SAPI` + tên lệnh (`queue:work`, `schedule:*`) thay vì mọi console.

### R10 [Low] Test AtomicCounter::add/capByUser chưa phủ nguyên tử và biên cửa sổ
- Vị trí: `UserCreditCapTest.php`.
- Vấn đề: chỉ kiểm tuần tự trên store array. Chưa có ca tốc độ 1,5x và 2x (xem R6), ca hai tab cùng bài, và ca heartbeat dồn do mạng chậm (elapsed lớn, delta lớn, cap theo bài chặn trước).

## Đã kiểm, không có lỗi
- `validateProbe`: 320x240 → bậc gốc 240p, bitrate lấy bậc thấp nhất, đường dẫn `240p/...` khớp route `[0-9]{3,4}p`. Cạnh tối thiểu 100 nên luôn 3 chữ số; master playlist ghi đúng `RESOLUTION=320x240`; không có bậc ngoài cấu hình trừ bậc "gốc" cho nguồn nhỏ, chỉ khi nguồn nhỏ hơn mọi bậc (đúng ý). Tỉ lệ > 4:1 hoặc 1:4 và cạnh < 100 bị từ chối bằng `VideoRejectedException`; 9:16 và 21:9 qua.
- Cleanup status 5: lọc theo `updated_at`, xoá `source/{guid}.bin`, tôn trọng `--dry-run`, xoá `source_path`; kể cả `keep_source`.
- `VideoInvalidUploadException` tự render JSON `{message, code}`, 422; Arch test chặn `App\Models/Services/Exceptions/Http/Support/...`. Test Arch xanh.
- `CheckoutService`: cổng chỉ kiểm khi tổng > 0, đặt sau cart-empty/409 và PAYMENT_DISABLED 503, trước `assertPayable`; đơn 0đ chạy khi `PAYMENT_GATEWAYS` rỗng; `CheckoutRequest` bỏ `in` khi rỗng. Thứ tự lỗi hợp lý, có test 3 nhánh.
- Guard: `staff_mfa` chặn ngoài local/testing; `paid_checkout` bị chặn khi `payments.ipn_ready=false` (hằng, không env nên không thể bật nhầm); có TODO T19 trong code và backlog.
- Limiter `cart` 60/phút theo người dùng cho 4 route giỏ và preview; route coupon giữ limiter riêng.

## Yêu cầu trước khi sang QA
1. Sửa R6 (bắt buộc), khôi phục test AC2 nguyên bản.
2. Nên sửa R7, R8 trong cùng đợt; R9, R10 ghi backlog hoặc làm kèm.

---

# Review lần 3 (kiểm lại R6–R10)
**Kết luận:** APPROVE (không còn High/Blocker; 1 Medium còn lại nên xử lý hoặc ghi backlog)
**Phạm vi:** `ProgressService::capByUser`, `config/learning.php`, `PlainText`, `AtomicCounter` Lua ADD, `T13/UserCreditCapTest`, `HeartbeatTest` AC2, `T09/InvisibleCharsRejectedTest`, checklist T31, contract. Pest T13+T09+Arch (loại race): 134 passed, 0 fail.

## Kết quả kiểm lại
- R6 Đạt phần chính: trần mặc định = `max_speed*window+slack` = 125 (null thì tự tính, số cụ thể ghi đè, 0 tắt). AC2 đã về `travel(20)`. Test mới phủ 2x trong 60 giây (cộng đủ 120), 3 bài song song (≤125 và >100), hai tab, heartbeat dồn sau mất mạng (≤ 20+125). Contract dòng limiter đã cập nhật.
- R7 Đạt: ZWJ chỉ hợp lệ khi đứng giữa hai `Extended_Pictographic` (cho phép FE0F/tông da đứng trước). Test: 👨‍👩‍👧 và 👩🏽‍🏫 qua; `Toán‍lớp`, ZWJ đầu/cuối bị chặn; ZWSP/ZWNJ/BOM/bidi vẫn chặn. Tiếng Việt không bị ảnh hưởng.
- R8 Đạt: Lua `EXPIRE` khi `TTL < 0` thay vì so giá trị; có test trên Redis thật (cộng âm vào khoá chưa tồn tại vẫn có TTL, cộng tiếp không làm mới TTL).
- R9 Đạt: checklist T31 ghi rõ lưu ý runtime Octane/Swoole/RoadRunner.
- R10 Đạt.

## Phát hiện còn lại

### R11 [Medium] Cửa sổ cố định neo ở lần cộng đầu: người xem 2x liên tục vẫn có thể bị cắt ở biên cửa sổ
- Vị trí: `ProgressService::capByUser` + `AtomicCounter::add` (TTL 60 từ lần cộng đầu), cấu hình mặc định 125.
- Vấn đề: xem 2x với heartbeat đều ~20 giây (FE gửi mỗi ~20 giây theo contract, mỗi lần +40). Ba heartbeat đầu cộng 120. Heartbeat thứ 4 đến ở t0+60±jitter mạng. Nếu đến trước khi khoá hết hạn (khoảng một nửa số lần), tổng 160 > 125 nên chỉ cộng được 5/40. Cửa sổ kế neo ở heartbeat sau đó. Với 2x liên tục, ước tính mất vài phần trăm đến ~10% giây xem, sát ngưỡng hoàn thành 90% (bài ngắn xem hết có thể dừng ở 87–89% nên không `completed`). Biên 5 giây (slack) quá mỏng so với jitter. Test 2x chỉ chạy 3 heartbeat nên không thấy.
- Đề xuất (chọn một):
  - Nới trần thêm một chu kỳ heartbeat: `max_speed * (window + first_interval_seconds) + slack` = 2*(60+20)+5 = 165. Vẫn chặn N bài song song (N bài chia nhau 165 mỗi 60 giây thay vì không giới hạn).
  - Hoặc dùng cửa sổ trượt/token bucket (nạp 2 giây mỗi giây, tối đa ~165).
  Thêm test 2x kéo dài 6–8 heartbeat (jitter ±1 giây quanh biên 60 giây) kỳ vọng tổng ≥ 95% giây video đã xem.

## Việc cần làm
- Không chặn QA. Nên xử lý R11 trong đợt này (đổi một dòng cấu hình + test), hoặc ghi vào backlog-v2 nếu PO chấp nhận rủi ro. Bỏ qua 2 test `T09/CurriculumOrderTest` do đếm tuyệt đối `audit_logs` (việc của dev cụm 1); lần chạy này không fail.
