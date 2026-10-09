# REVIEW: GL-A34 (A3 log QueryException không PII, A4 throttle)

## Dev (laravel-dev)

### Thay đổi
- `backend/bootstrap/app.php`: thêm `$exceptions->report(fn (QueryException $e) => ...)` ghi `Log::error('db.query_failed', [sql (placeholder), sqlstate, driver_code, connection, location])` rồi `return false` để chặn report mặc định. Không ghi `getMessage()` (chứa SQL đã nội suy bindings), không ghi `errorInfo[2]` (chứa "Duplicate entry '<giá trị>'"), không ghi bindings. `location` là `file:line` của frame đầu tiên trong `app/` (lấy từ trace, không kèm đối số hàm).
- `backend/app/Providers/AppServiceProvider.php`: limiter mới `free-enrollment` (10/phút + 30/ngày/user), `learn-read` (120/phút/user), `admin-write` (120/phút/user, `Limit::none()` cho method an toàn GET/HEAD/OPTIONS).
- `backend/routes/api.php`: gắn `throttle:free-enrollment` (sau `account.verified`), `throttle:learn-read` cho 2 route learn.
- `backend/routes/admin.php`: thêm `throttle:admin-write` vào cuối danh sách middleware của nhóm `staff` (một dòng + comment). File này còn thay đổi chưa commit của BE-backlog-1 (route `cheapest-course-price`); phần GL-A34 chỉ là 2 dòng đó.
- Test mới `backend/tests/Feature/GL/`: `QueryExceptionLogTest`, `StudentThrottle429Test`, `WriteRoutesThrottleTest`.
- Tài liệu: api-contract §1.6 (4 dòng), `docs/architecture/tasks.md` (mục GL-A34).

### Kết quả test kiến trúc (thiếu thật)
Test liệt kê 31 route ghi của admin-api thiếu throttle (subjects, courses, chương/bài, quiz, câu hỏi, enrollment-requests, coupons). Đã xử lý bằng limiter nhóm `admin-write`, không phải ngoại lệ. Phía `api.` chỉ `free-enrollments` thiếu (đã thêm). Ngoại lệ còn lại: `api.auth.logout`, `admin.auth.logout` (chỉ huỷ phiên của chính mình).

### Lưu ý cho reviewer/QA
- Limiter `admin-write` chồng với limiter riêng (đơn hàng 30/phút, upload 20/phút...): cả hai cùng đếm, route chặt hơn vẫn là trần hiệu lực. Hệ quả: một staff thao tác ghi tổng cộng quá 120 lần/phút trên mọi route sẽ nhận 429 (thực tế khó xảy ra với thao tác tay; kéo-thả sắp xếp chương/bài gửi 1 request/lần thả).
- `learn-read` 120/phút: một lần mở bài gọi khoảng 1-2 request; trang SSR/prefetch của FE nên kiểm lại nếu có prefetch hàng loạt.
- Hook report áp cho mọi `QueryException` (cả lệnh console/queue). Thông tin gỡ lỗi còn lại: SQL placeholder, SQLSTATE, mã driver, connection, vị trí trong `app/`. Lỗi `PDOException` thuần (không bọc QueryException) không thuộc phạm vi.
- Phản hồi 500 cho client không đổi (render không đụng tới).

### Lệnh đã chạy
Pint (đã format file mới), PHPStan `--memory-limit=2G`: No errors, `tests/Feature/GL`: 9 passed trên `vitaminvui_testing_e`. Toàn bộ suite (loại nhóm `race`): xem kết quả bên dưới. Không chạy race test, không migrate.

## Review (laravel-reviewer)
**Kết luận:** APPROVE (0 BLOCKER, 2 SHOULD, 2 NIT)
**Phạm vi:** `backend/bootstrap/app.php` (hook QueryException), `AppServiceProvider` (3 limiter), `routes/api.php`, 1 dòng `throttle:admin-write` ở `routes/admin.php`, `tests/Feature/GL/*`. Không review phần BE-backlog-1/GL-1. Chạy `tests/Feature/GL` trên DB test: 9 passed.

### Đánh giá
- A3: hook đúng hướng. Không ghi `getMessage()`, `errorInfo[2]`, bindings, previous; context chỉ có sql placeholder, sqlstate, mã driver, connection, vị trí trong `app/`. `return false` chỉ chặn report mặc định của đúng QueryException; không channel nào khác cần bản đầy đủ (không có Sentry/Telescope trong composer/config; mọi `Log::*` khác trong app chỉ ghi `$e::class`). Phản hồi 500 không đổi.
- A4: key theo user (`identity()`), IP chỉ là dự phòng cho request chưa đăng nhập, mà cả 3 limiter đều đứng sau `auth` nên NAT lớp học không bị gộp. `learn-read` 120/phút dư cho 1 bài (1-2 request; prefetch của Next là RSC, không gọi API này). `admin-write` 120/phút chỉ đếm method ghi: kéo-thả (1 request/lần thả), soạn quiz, upload (đã có 20/phút riêng chặt hơn) đều thoải mái. Cộng dồn với limiter riêng là hành vi chấp nhận được, route chặt hơn vẫn là trần hiệu lực. `free-enrollment` 10/phút + 30/ngày hợp lý (kể cả lần bị từ chối cũng đếm, đúng ý chặn vòng xin-từ chối).
- Ngoại lệ logout hợp lý (chỉ huỷ phiên của chính mình). Test kiến trúc đúng; chỉ nhận chuỗi middleware bắt đầu bằng `throttle:` nên nếu sau này dùng `ThrottleRequests::class` trực tiếp sẽ báo thiếu (lỗi an toàn, dễ thấy).

### R1 [SHOULD] Test A3 không chứng minh `return false` chặn report mặc định
- Vị trí: `backend/tests/Feature/GL/QueryExceptionLogTest.php`
- Vấn đề: `Log::spy()` chỉ thay root của facade; `Handler::reportThrowable` lấy logger qua `LoggerInterface` từ container, nên bản log mặc định (có PII) sẽ không đi qua spy. Bỏ `return false` thì cả 2 test vẫn xanh.
- Đề xuất: bắt log thật, ví dụ cấu hình channel `single` trỏ file tạm (hoặc `Log::swap` bằng `LogManager` với `TestHandler` của Monolog / đọc file log), rồi assert toàn bộ nội dung log không chứa email, SĐT, `$2y$`, "Duplicate entry"; và có đúng 1 dòng `db.query_failed`.

### R2 [SHOULD] `failed_jobs.exception` vẫn chứa message QueryException đầy đủ (PII)
- Vị trí: `config/queue.php:135` (`database-uuids`); đường queue worker ghi bảng, không đi qua hook report.
- Vấn đề: job gặp lỗi DB (mail, xoá tài khoản, export...) lưu `getMessage()` có bindings và trace vào bảng `failed_jobs`, tức là A3 chỉ che log file. Ngoài phạm vi chặt của A3 nhưng cùng mục tiêu tối thiểu hoá dữ liệu.
- Đề xuất: ghi backlog (đổi `QUEUE_FAILED_DRIVER=null` hoặc `FailedJobProvider` che message, hoặc job bắt QueryException rồi throw lại exception chung) và ghi rõ trong checklist go-live; không bắt buộc trong story này.

### R3 [NIT] Exception bọc QueryException vẫn lọt
- `report()` chỉ khớp khi chính exception là QueryException. Nếu code ở đâu đó bọc (`throw new RuntimeException(..., 0, $queryException)`) thì log mặc định in cả chuỗi previous. Hiện grep `getPrevious` trong `app/` không thấy; ghi nhận để tránh bọc về sau. SQL có literal nội suy trực tiếp (`whereRaw` nối chuỗi) cũng sẽ lọt vào trường `sql`; dự án quy ước dùng binding nên chấp nhận.

### R4 [NIT] Comment trong `AppServiceProvider` nói "xin học khóa miễn phí ... đọc màn học" gộp một khối; tách 2 comment cho dễ đọc. Không cần sửa nếu không có việc khác.

### Đối chiếu
| Yêu cầu triage | Đáp ứng |
|---|---|
| A3: log không PII, vẫn debug được | Có (sql placeholder, sqlstate, mã driver, connection, location); test R1 cần tăng độ tin cậy |
| A4: request 11/phút free-enrollments -> 429 | Có, test xanh (cả trần 30/ngày) |
| A4: learn courses/lessons throttle | Có, 120/phút/người |
| A4: test kiến trúc mọi route ghi có throttle | Có, ngoại lệ chỉ 2 logout |

### Gợi ý cho QA
- Ép lỗi unique đăng ký bằng email thật trên môi trường chạy thật (log channel thật) rồi grep `laravel.log` tìm `@`, SĐT, `$2y$`, "Duplicate".
- Kéo-thả sắp xếp nhiều chương liên tục, soạn quiz 30+ câu liên tiếp, kiểm không gặp 429; nhờ Next SSR mở trang học bình thường.
- Nhớ bộ đếm `free-enrollment-day` tồn tại trong cache, test local cần xoá cache giữa các lần chạy.

## Sửa sau review (laravel-dev)
- **R1 (đã sửa):** `tests/Feature/GL/QueryExceptionLogTest.php` viết lại, bắt log THẬT: thay `log` và `Psr\Log\LoggerInterface` trong container bằng Monolog có `TestHandler` (Handler dùng LoggerInterface để report mặc định). Assert đúng 1 bản ghi, là `db.query_failed`, `sqlstate`/`driver_code` đúng, và toàn bộ message + context (+ chuỗi exception) không chứa email, SĐT, `$2y$`, `@`, "Duplicate entry". Kiểm chứng đột biến: tạm đổi `return false` thành `return true` thì cả 2 test đỏ (2 failed); đã khôi phục `return false`, test xanh.
- **R4 (đã sửa):** tách comment `AppServiceProvider` thành 3 comment riêng cho `free-enrollment`, `admin-write`, `learn-read`.
- **R2, R3 (ghi backlog):** `docs/security/backlog-v2.md` mục "GL-A34" kèm đề xuất; R2 ghi nhắc giữ `OPS_FAILED_JOBS_RETENTION_HOURS=168` trong backlog (không sửa `docs/ops/production-checklist.md` vì GL-1 đang sửa).
- Chạy trên DB `vitaminvui_testing_d` (`phpunit.local-d.xml`): xem kết quả Pint/PHPStan/GL trong báo cáo.

## QA (laravel-qa)
**Kết quả:** PASS (0 bug thuộc GL-A34; 1 test đỏ ngoài phạm vi, xem "Ghi nhận ngoài phạm vi")

### Test QA mới (`backend/tests/Feature/GL/`)
- `QAThrottleTest.php` (11 test): ngưỡng, thông báo, Retry-After, độc lập theo người, không 429 oan, kiến trúc hai host.
- `QAQueryExceptionHttpTest.php` (1 test): đường HTTP thật qua Handler thật, ghi vào file log thật (channel `single` trỏ file tạm).

### Độ phủ
| Yêu cầu | Test | Kết quả |
|---|---|---|
| A3: log không PII, có `db.query_failed` đủ thông tin | QAQueryExceptionHttpTest: 2 route (unique trùng email, cột sai) kèm email/SĐT/`$2y$` làm binding. File log có đúng 2 dòng `db.query_failed` với sqlstate, driver_code, connection, location; không có email, SĐT, `$2y$`, "Duplicate entry", không có chuỗi `\S@\S` | PASS |
| A3: 500 không lộ gì cho client | Cùng test: body 500 không chứa email, SĐT, hash, "Duplicate", "SQLSTATE", SQL, tên bảng | PASS |
| A3 trên backend dev thật | `artisan tinker` trong container `php` (không restart/migrate): `DB::select` cột sai kèm binding PII rồi `report()`. Dòng mới trong `storage/logs/laravel.log`: `db.query_failed {"sql":"select zzz_qa from users where email = ? and phone = ? and password = ?","sqlstate":"42S22","driver_code":1054,"connection":"mysql","location":null}`; không có giá trị PII nào. `location` là null vì tinker không có frame trong `app/` (đúng thiết kế) | PASS |
| A4: free-enrollments 10/phút, lần 11 -> 429 | `AC-A4: free-enrollments 10 lần OK...` | PASS |
| A4: 30/ngày, sang ngày mới mở lại | `AC-A4: trần 30/ngày...` (travel 61s x3 rồi 429; +25 giờ lại 404 tức qua limiter) | PASS |
| A4: learn lesson/course 120/phút/người (course và lesson dùng chung một bộ đếm) | 120 OK, 121 -> 429; 60 course + 60 lesson rồi 429 | PASS |
| A4: hai người cùng IP không chung bộ đếm | free-enrollments và learn-read (cả hai cùng 127.0.0.1) | PASS |
| A4: admin-write chỉ đếm method ghi | 300 GET không tính, POST sau đó vẫn 201; đang bị chặn ghi thì GET vẫn 200 | PASS |
| A4: admin-write 120/phút, 429 | PUT curriculum/order 120 lần không 429, lần 121 -> 429; hai staff độc lập | PASS |
| Không 429 oan: học sinh mở/chuyển bài | 100 request (50 course + 50 lesson) trong 1 phút OK; qua phút mới mở lại | PASS |
| Không 429 oan: kéo-thả chương/bài, soạn quiz | 120 lần reorder liên tục; 60 POST câu hỏi + 60 PUT câu hỏi liên tục | PASS |
| Kiến trúc throttle phủ cả hai host | Đếm route ghi theo domain: `api.` > 20, `admin-api.` > 30, đều có `throttle:*`, ngoại lệ chỉ 2 logout | PASS |
| 429 tiếng Việt + Retry-After | message "Bạn thao tác quá nhanh, vui lòng thử lại sau.", code `TOO_MANY_ATTEMPTS`, `Retry-After` trong (0, 60] giây (free-enrollments), >0 (learn, admin-write) | PASS |

### Lệnh và kết quả (DB `vitaminvui_testing_d`, `phpunit.local-d.xml`, cache/limiter = array nên không cần xoá cache)
- Pint: đã format 2 file QA (1 lỗi style tự sửa); `--test` cho GL, AppServiceProvider, bootstrap/app.php, routes: sạch.
- PHPStan `--memory-limit=2G`: No errors.
- `tests/Feature/GL` + T13 + T14 + T22 + T09 + T21 (loại nhóm race): 290 passed, 1 failed. Test GL-A2 (`LoginCaptchaGateTest`) xanh ở lần chạy này.

### Ghi nhận ngoài phạm vi GL-A34 (không tính)
- `T13/PlaybackTest` "rang IP: link bai tra phi doi theo IP..." đỏ ổn định. Nguyên nhân là môi trường: `.env` dev đặt `VIDEO_BIND_IP=false` và `phpunit.local-d.xml` không ép `VIDEO_BIND_IP=true` (chỉ `phpunit.xml`, `local-e`, `local-g` có), nên bind IP bị tắt. Không liên quan throttle hay hook A3. Đề xuất: thêm `VIDEO_BIND_IP=true` vào các `phpunit.local-*.xml` còn thiếu (a, b, c, d, f, h) hoặc chạy bằng `phpunit.xml`.
- Dev log `storage/logs/laravel.log` đang nặng 31 MB và chứa dòng cũ có PII (trước khi có hook A3). Khuyến nghị dọn/xoay log dev; production chạy bản mới nên không bị ảnh hưởng.

### Bug
Không có bug cho GL-A34.

### Rủi ro còn lại (đã ghi backlog, không chặn)
- R2: `failed_jobs.exception` vẫn chứa message QueryException đầy đủ khi job lỗi DB (A3 chỉ che log file); R3: exception bọc QueryException hoặc SQL nội suy chuỗi sẽ lọt.
- Không thử được lỗi DB có PII qua nginx dev thật vì không có đường HTTP nào gây lỗi DB mà không phá/đổi dữ liệu; đã bù bằng test tích hợp HTTP trên DB test và tinker trên log dev.
- Prefetch hàng loạt phía FE gọi `/learn/*` có thể chạm 120/phút; hiện không thấy (prefetch Next là RSC). Nên theo dõi 429 sau go-live.
