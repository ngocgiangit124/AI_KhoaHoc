# BE-backlog-1 — Gói việc backend nhỏ trước go-live

## Dev (2026-10-09)

Không có migration. Không sửa frontend. Hợp đồng đã cập nhật ở `docs/architecture/api-contract.md` (đánh dấu "BE-backlog-1"), task ở `docs/architecture/tasks.md`.

| # | Việc | Thay đổi | Test (`backend/tests/Feature/BEB1/`) |
|---|---|---|---|
| 1 | FW6 `best_attempt_id` | `QuizAttemptService::bestAttemptIdsForCourse` (1 truy vấn theo `user_id, course_id`, sắp `score DESC, submitted_at ASC, id ASC`, lấy dòng đầu mỗi quiz); `MyCoursesService::progress` thêm `quizzes[].best_attempt_id` | `BestAttemptIdTest` (hoà điểm, lượt đang làm, lượt người khác, null, đếm truy vấn không đổi theo số quiz) |
| 2 | FA5 `has_attempts` + cờ | `QuizAttemptUsage` (service mới): `markQuizzes` (1 truy vấn `IN`), `markQuestions` (1 truy vấn `EXISTS` + `JSON_CONTAINS`); `QuizResource`/`QuizQuestionResource` thêm `has_attempts` khi đã gắn; `QuizController`, `QuizQuestionController` gắn cờ ở list/show/store/update/reorder; `MeController` thêm `quiz_time_limit_enabled` từ `config('features.quiz_time_limit')` (config đã có, cùng nguồn `/config/public`) | `AdminQuizHasAttemptsTest` |
| 3 | FA7 giá rẻ nhất | `GET /admin/coupons/cheapest-course-price` → `{cheapest_course_price: int\|null}`; dùng lại `CouponService::cheapestSellingPrice()` (đã là định nghĩa của kiểm "giảm hết" S18, nay `public`); `CouponPolicy@viewAny` | `CheapestCoursePriceTest` |
| 4 | FA10 | `StaffAccountService::changeRoleDetailed` trả thêm `released_courses [{id,title}]` (1 truy vấn, kể cả khóa xoá mềm); controller trả thêm khoá, giữ `released_course_ids` | `ReleasedCoursesTest` |
| 5a | T29-S4 | `Mask::emailsInText`; `ParentNoticeMail::send()` bắt mọi lỗi và ném `RuntimeException` MỚI (thông điệp `Lớp: nội dung đã che`, không `previous`) nên `failed_jobs.exception` và log worker không có `@`; `failed()` ghi `parent_notice.mail_failed` đã che | `ParentNoticeHardeningTest` |
| 5b | T29-S6 | Limiter `parent-notice:global` trong `ParentNotifier::deliver`, cửa sổ 1 giờ; config `privacy.parent_notice_global_hourly_cap` / env `PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP` (mặc định 500; < 1 = không gửi, fail-closed); vượt thì bỏ thư + `Log::warning('parent_notice.global_cap_reached')`. Đặt SAU mọi điều kiện loại thư nên thư bị loại không tốn lượt. Thêm biến vào `.env.example` và `infra/production/.env.production.example` | `ParentNoticeHardeningTest` |
| 6 | Race T18 rò dữ liệu | Nguyên nhân: `cleanup` của `tests/Support/checkout_race_worker.php` chỉ xoá người tạo của khóa đầu (`creator`), còn giáo viên của khóa 2, 3 (`setup_multi`) và admin tạo mã giảm giá (`coupons.created_by`) bị bỏ sót, nằm lại trong `users` của DB test. Sửa: cleanup tự lấy `created_by` của mọi khóa và mã từ DB, xoá `course_teacher`/`teacher_profiles` liên quan rồi xoá user. Trước sửa: sau T18 còn 7 user (3 giáo viên, 4 admin); sau sửa: 0 | `CheckoutRaceCleanupTest` (group `race`) |

### Kết quả
- Pint: PASS. PHPStan `--memory-limit=2G`: No errors.
- Pest trên `vitaminvui_testing_e` (`phpunit.local-e.xml`): `tests/Feature/BEB1` 19 test xanh (+2 race cleanup); T18 race chạy lại: 5 xanh, `users=0` sau khi chạy; `T36/AdminTeacherProfilesTest` 21 xanh sau T18. Lượt rộng (BEB1, T15, T21, T22, T23, T28, T29, T33, T36, T39, bỏ nhóm race): 722 passed, 1 skipped.
- Đã xoá tay 7 user rò sẵn trong DB test `vitaminvui_testing_e` (chỉ DB test).

### Điểm cần PO/architect quyết
1. **Trần tổng 500 thư/giờ**: PO 2026-10-09 đã chốt 500/giờ (env `PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP`).
2. **T29-S6 phần "trần 3 địa chỉ phụ huynh khác nhau/tài khoản/ngày"** ("cân nhắc" trong backlog) CHƯA làm: cần quyết định nghiệp vụ và nơi đếm (đổi `parent_email`). Giữ ở backlog.
3. **T29-S6 "đưa số `parent_notice.sent`/giờ vào cảnh báo T26"**: mới có log cảnh báo khi chạm trần (`parent_notice.global_cap_reached`); chưa thêm chỉ số vào `ops:health`. Cần quyết ngưỡng cảnh báo khi làm T26.
4. **T29-S4**: `OPS_FAILED_JOBS_RETENTION_HOURS` mặc định 720 giờ (backlog đề xuất 168). Email đã được che nên không còn PII ở `failed_jobs`; để PO/vận hành quyết có giảm hạn lưu không.
5. `has_attempts` ở câu hỏi chỉ để hiển thị (không khoá); nơi quyết định copy-on-write vẫn là `QuizContentService::hasAttempts` dưới khoá, nên cờ có thể lệch nhất thời nếu có học sinh bắt đầu làm ngay sau khi FE tải.

### QA cần kiểm kỹ
- `best_attempt_id` khi HS có nhiều lượt cùng điểm; lượt đang làm không được chọn; quiz đã xoá mềm không xuất hiện.
- `has_attempts` sau copy-on-write (câu mới `false`, id đổi) và với quiz có lượt đang làm (chưa nộp) vẫn là `true`.
- Worker queue thật: ép lỗi SMTP có email và xác nhận `failed_jobs.exception` không chứa `@`.
- Chạy T18 race rồi T36 (cùng DB test) để xác nhận không rò.

## Review (laravel-reviewer, 2026-10-09)

**Kết luận:** APPROVE (0 BLOCKER, 2 SHOULD, 3 NIT)
**Phạm vi:** diff chưa commit của backend + `QuizAttemptUsage.php`, `tests/Feature/BEB1/*`, 2 file `.env*.example`. Bỏ qua phần GL-1. Chạy: BEB1 trên `vitaminvui_testing_h` (DB g đang bị agent khác dùng, báo "table migrations already exists"): lần đầu 19 pass + 2 fail (2 test race `CheckoutRaceCleanupTest`, lúc DB có tiến trình khác chạy cùng), chạy lại riêng 2 test race: 2 pass. Pint/PHPStan không chạy lại (tin báo cáo Dev).

### Tổng quan
Gọn, đúng hợp đồng, số truy vấn không đổi theo số quiz/câu (1 truy vấn `IN`/`EXISTS` cho cả danh sách), route mới đặt trước `{coupon}`, quyền qua `CouponPolicy@viewAny` (giáo viên 403). `/admin/auth/me` vẫn phẳng (đã có `JsonResource::withoutWrapping()` toàn cục) nên không vỡ hình dạng cũ. Limiter dùng `RateLimiter::hit` (add+increment nguyên tử trên Redis, có xử lý key hết hạn giữa chừng).

### Phát hiện
#### R1 [SHOULD] Thư bị chặn bởi trần tổng vẫn tốn lượt trần theo địa chỉ
- Vị trí: `app/Services/Privacy/ParentNotifier.php` (deliver: `RateLimiter::hit('parent-notice:'.addressHash)` chạy TRƯỚC khối `parent-notice:global`).
- Vấn đề: khi chạm trần tổng, thư bị bỏ nhưng bộ đếm 24h của địa chỉ đã tăng; kẻ spam làm đầy trần tổng sẽ "đốt" luôn hạn mức 5 thư/ngày của các phụ huynh thật, ngay cả sau khi cửa sổ 1 giờ qua đi. Tài liệu Dev ghi "thư bị loại không tốn lượt" chưa đúng với nhánh này. Thư mất vĩnh viễn (không retry) — chấp nhận được nhưng nên ghi rõ.
- Đề xuất: hoàn lượt khi bị trần tổng chặn:
  ~~~php
  $addressKey = 'parent-notice:'.ParentNoticeToken::addressHash($email);
  // ... sau khi global vượt/cấu hình sai:
  RateLimiter::decrement($addressKey);
  ~~~
  hoặc đổi thứ tự: kiểm `tooManyAttempts('parent-notice:global', $globalCap)` trước, `hit` global sau cùng khi đã qua mọi điều kiện.

#### R2 [SHOULD] Test race đếm theo số lượng toàn bảng nên mong manh khi DB test dùng chung
- Vị trí: `tests/Feature/BEB1/CheckoutRaceCleanupTest.php` (so `users/courses/coupons count` trước-sau).
- Vấn đề: bất kỳ tiến trình nào ghi vào cùng DB trong lúc chạy (nhiều agent/pest song song, như lần chạy đầu của tôi) làm test đỏ giả. Đã thấy 2 fail ở lần chạy chung, xanh khi chạy riêng.
- Đề xuất: so theo tập id: lấy `$ids['users']` + `created_by` của các khóa/mã mà worker trả về rồi `assertDatabaseMissing` đúng các id đó, thay vì so tổng số dòng.

#### R3 [NIT] `Mask::emailsInText` còn lọt vài dạng, dù mục tiêu là "không còn `@`"
- Vị trí: `app/Support/Mask.php` regex.
- Kiểm thực tế: `o'brien@x.com` → `o'***` (lộ ký tự đầu phần local vì `'` bị loại khỏi lớp ký tự local, mà `'` là hợp lệ trong local); `"a b"@x.com` và `user@[192.168.0.1]` KHÔNG bị che (còn `@`). Đã đúng với: `+`, subdomain, chữ hoa, `<a@b>`, `a@b,c@d`, `(a@b)`, IDN/unicode (`phụ-huynh@trường.edu.vn`), `ph@example.com:25`. Ký tự `＠` (full-width) không phải `@` nên bỏ qua là hợp lý.
- Đề xuất: đơn giản và chặt hơn, bắt mọi cụm quanh `@`:
  ~~~php
  return preg_replace('/[^\s<>,;()]*@[^\s<>,;()]*/u', '***', $text) ?? '***';
  ~~~
  (nếu input không phải UTF-8 hợp lệ thì trả `'***'` cả chuỗi — hiện tại cũng fail-safe như vậy.)

#### R4 [NIT] Ném lại không `previous` làm mất stack trace gốc
- Vị trí: `app/Mail/ParentNoticeMail.php::send`.
- Retry/backoff KHÔNG bị ảnh hưởng: `tries/backoff/timeout` là thuộc tính của mailable do `SendQueuedMailable` đọc, và job vẫn ném exception nên Laravel vẫn thử lại và gọi `failed()` khi hết lượt. Chỉ mất trace/mã lỗi gốc; tên lớp exception và thông điệp đã che vẫn còn nên đủ để chẩn đoán lỗi SMTP. Chấp nhận được; nếu cần debug sâu có thể thêm `Log::debug` đã che file:line của `$e` (không email).

#### R5 [NIT] Ghi chú tài liệu
- Câu "Thư bị loại bởi các điều kiện trên không tốn lượt" (api-contract §2.8.2) cần sửa theo R1 nếu giữ thứ tự hiện tại. Quyết định "trần 500/giờ" và việc thư vượt trần mất vĩnh viễn (không xếp hàng lại) nên PO biết: tấn công đăng ký hàng loạt có thể chặn thư order_paid hợp lệ trong giờ đó (đánh đổi chấp nhận được so với spam).

### Đối chiếu yêu cầu
| Mục | Code | Ghi chú |
|---|---|---|
| 1 `best_attempt_id` | `bestAttemptIdsForCourse` + `MyCoursesService::progress` | Thêm đúng 1 truy vấn (user_id, course_id); quiz xoá mềm đã loại vì lấy `quizIds` từ `quizRows`; sắp `score DESC, submitted_at ASC, id ASC` khớp `MAX(score)` của `bestScoresForCourse`. Đạt |
| 2 `has_attempts` + `quiz_time_limit_enabled` | `QuizAttemptUsage`, 2 Resource (`when` chỉ khi đã gắn nên API học sinh không lộ), `MeController` | list/show/store/update/reorder đều gắn; không N+1. Đạt |
| 3 `cheapest-course-price` | route trước `{coupon}`, `viewAny`, dùng lại `CouponService::cheapestSellingPrice` | Giáo viên 403. Đạt |
| 4 `released_courses` | `changeRoleDetailed` | 1 truy vấn lấy tiêu đề kể cả khóa xoá mềm, giữ `released_course_ids`. Đạt |
| 5a T29-S4 | `Mask::emailsInText`, `ParentNoticeMail::send/failed` | Xem R3, R4. Đạt có góp ý |
| 5b T29-S6 | `parent-notice:global` | Fail-closed khi cap < 1; `hit` nguyên tử. Xem R1 |
| 6 cleanup race T18 | `checkout_race_worker.php` cleanup | Xoá user theo `created_by` lấy từ DB của đúng các khóa/mã của lượt chạy, không đụng dữ liệu dùng chung ngoài các id đó; xoá `course_teacher`/`teacher_profiles` trước user. Đạt (xem R2 về test) |

### Gợi ý cho QA
- Worker queue thật + SMTP lỗi: xác nhận `failed_jobs.exception` và log không có `@`, job vẫn thử lại 3 lần theo backoff 60/300/900.
- Đẩy ~cap+1 thư trong 1 giờ (đặt cap=2 qua env): thư thứ 3 bị bỏ; sau khi bỏ, kiểm hạn mức theo địa chỉ (R1).
- `has_attempts` với lượt đang làm dở, sau copy-on-write (câu mới `false`).
- Chạy T18 race rồi T36/AdminTeacherProfilesTest trên cùng DB để xác nhận không rò user.

## Sửa sau review (Dev, 2026-10-09)

- **R1:** `ParentNotifier::deliver` kiểm trần tổng (`tooManyAttempts`) TRƯỚC bộ đếm theo địa chỉ; `hit` trần tổng vẫn đặt cuối cùng (nguyên tử), nếu vượt do đua thì `RateLimiter::decrement` hoàn lượt địa chỉ. Thư bị trần tổng chặn không tốn lượt 5/ngày. Test mới trong `ParentNoticeHardeningTest` (trần tổng 1, trần địa chỉ 2: 3 thư bị chặn vẫn để `attempts` của địa chỉ = 0; hết cửa sổ thì địa chỉ gửi được, attempts = 1).
- **R2:** `CheckoutRaceCleanupTest` kiểm theo tập id cụ thể (`$ids['users']`, `created_by` của khóa/mã đọc trước khi dọn, id khóa, id mã), không so tổng dòng toàn bảng.
- **R3:** `Mask::emailsInText` dùng `/[^\s<>,;()]*@[^\s<>,;()]*/u`; thêm ca `o'brien@x.com`, `"a b"@x.com`, `user@[192.168.0.1]`, danh sách nhiều địa chỉ vào test.
- **R4:** giữ nguyên (chấp nhận).
- **R5:** api-contract §2.8.2 sửa câu theo R1, ghi "PO 2026-10-09 chốt 500/giờ" và cảnh báo thư vượt trần mất vĩnh viễn.
- Chạy trên DB test `vitaminvui_testing_f` (`phpunit.local-f.xml`).

## QA (laravel-qa, 2026-10-09)

**Kết luận: PASS** (0 bug Critical/Major/Minor trong phạm vi task; 1 lưu ý môi trường, 2 rủi ro nhỏ).

### Test QA đã thêm (`backend/tests/Feature/BEB1/QA*Test.php` + `backend/tests/Support/qa_*.php`)
| Nhóm | File | Số test | Kết quả |
|---|---|---|---|
| FW6 `best_attempt_id` | `QABestAttemptTest` | 5 | PASS |
| FA5 `has_attempts` + `/me` | `QAQuizFlagsTest` | 8 (kể cả dataset) | PASS |
| FA7 + FA10 | `QACouponStaffTest` | 6 | PASS |
| T29-S4/S6 + worker thật | `QAParentNoticeTest` | 30 (kể cả dataset) | PASS |

Phủ chi tiết:
- **FW6:** hoà điểm + hoà cả `submitted_at` -> id nhỏ nhất; lượt cao điểm hơn nộp sau vẫn thắng; điểm 0 vẫn là lượt hợp lệ; lượt đang làm không được chọn (quiz chỉ có lượt đang làm -> null); quiz xoá mềm không xuất hiện (và id lượt của nó không lộ); IDOR: lượt cao điểm/nộp sớm của HS khác không bao giờ xuất hiện (chưa làm -> null); lượt có `course_id` khác không lẫn. Số truy vấn không tăng theo số quiz (test Dev).
- **FA5:** lượt đang làm dở -> quiz và câu trong `question_ids` là `true`; lượt của quiz khác chứa cùng id câu không làm bật cờ (lọc theo `quiz_id`); reorder trả cờ đúng từng câu; sửa câu đã có lượt -> bản sao `false`, id đổi; store `false`; GV không được giao 403, khách 401; API học sinh không lộ khoá `has_attempts`; `/admin/auth/me` cho GV/QLT có `quiz_time_limit_enabled` bool theo `FEATURE_QUIZ_TIME_LIMIT`, khách 401, không lộ trường nhạy cảm.
- **FA7:** admin/QLT 200; GV 403; khách 401; HS (phiên web) bị chặn 401/403; khoá nháp/xoá mềm/giá 0 không tính; giá 1 đồng tính được; không khoá nào -> `null`; kiểu số nguyên; `GET /admin/coupons/{id}` vẫn 200, id lạ 404, POST vào đường dẫn mới không phải route ghi.
- **FA10:** `released_courses` khớp thứ tự `released_course_ids`, đúng khoá `{id,title}`, kể cả khoá xoá mềm; GV gọi endpoint đổi vai trò 403, khách 401.
- **T29-S4 `Mask::emailsInText`:** 14 dạng (`+tag`, subdomain, hoa, `<a@b>`, trong ngoặc kép, IDN tiếng Việt, xuống dòng, danh sách `,`/`;`, `[IP]`, `"a b"@x`, lặp, `@` lẻ, rỗng, không có `@`) và UTF-8 hỏng (trả `***`, không lộ). Đều không còn `@`.
- **T29-S4 WORKER THẬT:** không dùng container `queue` (trỏ DB dev). Test chạy tiến trình `php artisan queue:work database --once` ×3 trên DB `vitaminvui_testing_h` với SMTP giả (`qa_fake_smtp.php`) từ chối người nhận và nhắc lại địa chỉ trong thông điệp (như SMTP thật). Kết quả: `failed_jobs.exception` = `RuntimeException: UnexpectedResponseException: ... "550 5.1.1 <***>: Recipient address rejected..."`, không có `@`, không có địa chỉ; log của worker (stderr) không có địa chỉ, có `parent_notice.mail_failed`; job thử đúng 3 lần (attempts 1, 2, rồi vào `failed_jobs`, `jobs` rỗng). Test tự dọn `jobs`/`failed_jobs`/user (chỉ DB test).
- **T29-S6:** trần tổng (cap nhỏ): đúng N thư đi, thư vượt bị bỏ + `Log::warning('parent_notice.global_cap_reached')` (context có `cap`, không có email); cap `0`, `-5`, `''`, `'abc'`, `null` -> không gửi, không tốn lượt địa chỉ; thư bị trần địa chỉ chặn không tốn lượt trần tổng; thư bị trần tổng chặn không tốn lượt địa chỉ (R1 đã sửa đúng); cửa sổ 1 giờ có TTL.

### Lệnh đã chạy (DB `vitaminvui_testing_h`, `phpunit.local-h.xml`, load ~10-11 < 20)
- Pint (BEB1 + script QA): đã chuẩn hoá style; PHPStan `--memory-limit=2G`: **No errors**.
- BEB1 + T15 T21 T22 T23 T29 T33 T36 (bỏ nhóm race): **650 passed**, 0 fail.
- Race: T18 trọn bộ + `CheckoutRaceCleanupTest` (có `FEATURE_MANUAL_PAYMENT=false`): **55 passed**; ngay sau đó T36 trọn bộ: **273 passed**; đếm `users/courses/coupons` trong DB test trước và sau T18 và sau T36: **0/0/0** (không còn rò user).
- Trong lần chạy rộng, T18 không nằm trong lượt (đúng yêu cầu loại race), T18 chạy riêng ở bước race.

### Lưu ý môi trường (không phải bug ứng dụng)
- `phpunit.local-h.xml` (và các `local-*` khác nếu sao chép từ nó) thiếu `FEATURE_MANUAL_PAYMENT=false` mà `phpunit.xml`/`phpunit.local-e.xml` có. Vì container nạp `backend/.env` (`FEATURE_MANUAL_PAYMENT=true`, `PAYMENT_GATEWAYS=fake`), 6 test T18 (nhóm "V2"/"QA V2"/"M1 cum 3": 503 `PAYMENT_DISABLED`) đỏ giả khi chạy bằng `local-h`. Chạy với `-e FEATURE_MANUAL_PAYMENT=false` thì xanh. Đề xuất bổ sung dòng đó vào các file `phpunit.local-*.xml` (ngoài phạm vi QA được sửa).

### Rủi ro / đề xuất (không chặn)
1. `has_attempts` ở câu hỏi chỉ để hiển thị; nơi quyết định copy-on-write vẫn là `QuizContentService::hasAttempts` dưới khoá (Dev đã ghi) nên cờ có thể lệch nhất thời. Chấp nhận.
2. `failed_jobs` giờ không còn email, nhưng payload job đã `ShouldBeEncrypted`; `OPS_FAILED_JOBS_RETENTION_HOURS=720` giữ nguyên là quyết định của PO (đã nêu ở mục "Điểm cần PO quyết").
3. Thư vượt trần tổng mất vĩnh viễn (không xếp lại), PO đã chấp nhận ở R5.
4. Đã ghi `audit_logs` (`parent_notice.sent`) khi xếp thư; audit bất biến nên test worker thật để lại 1 dòng audit trong DB test mỗi lần chạy (vô hại, bị xoá khi `migrate:fresh`).
