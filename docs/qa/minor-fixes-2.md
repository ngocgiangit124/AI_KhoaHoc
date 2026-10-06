# QA: Sửa lỗi nhỏ 2 (T03 M2, M3; T27-5)

**Kết quả:** PASS sau QA lại (xem mục "QA lại" cuối file). Lần QA đầu: FAIL (1 Major: bộ đếm đăng nhập không nguyên tử khi nhiều tiến trình chạy thật; 0 Critical). Các phần còn lại PASS.

**Phạm vi:** `LoginService` (`reserveAttempts`/`releaseAttempts`, khoá theo user id, `accountKey` bỏ dấu), `StaffAuthService`, `PasswordService::reset` (mọi lỗi mã là 422 `OTP_EXPIRED`), timeout race test 180s ở 11 file. Theo `docs/review/minor-fixes-2.md`.

## Số liệu (DB `vitaminvui_testing_c`, Docker)
| Lệnh | Kết quả |
|---|---|
| `pest -c phpunit.local-c.xml tests/Feature/T03 T27 T28 T04 --exclude-group=race` | **340 passed / 2029 assertions** (gồm 14 test QA mới) |
| Race: `T18/CheckoutRaceTest`, `T22/AttemptRaceTest`, `T33/StaffRaceTest`, `T27/ResetConcurrencyTest` | **13 passed / 266 assertions** (160s, không timeout) |
| `T03/LoginRaceTest` (mới, nhóm `race`, 15 tiến trình) | **FAIL ngẫu nhiên: 5/17 lần chạy** (xem BUG-1) |
| Pint trên 5 file mới | sạch |

## Độ phủ mục tiêu
| Mục | Test | Kết quả |
|---|---|---|
| M2: email hoa/có dấu + SĐT (nhiều dạng) cùng 1 bộ đếm | `T03/LoginQaMinor2Test` "M2: email viet hoa/co dau va SDT..." (10 cách viết, lượt 11 bằng SĐT và email đều 429) | PASS |
| M3: lượt 11 mật khẩu đúng vẫn 429, có `Retry-After` hợp lệ | `LoginQaMinor2Test` "M3: sau 10 luot sai..." | PASS |
| Hết TTL đăng nhập lại được (`travel(3601)`) | `LoginQaMinor2Test` "M3: het TTL" | PASS |
| Không tồn tại giống tài khoản thật sai mật khẩu (status, body trừ `request_id`, header trừ `date`/`set-cookie`/`x-ratelimit-remaining`; đều đúng 1 lần `Hash::check`) | `LoginQaMinor2Test` "BR5: tai khoan khong ton tai giong het..." | PASS |
| Thời gian phản hồi hai nhánh xấp xỉ (trung vị, hệ số 3) | `LoginQaMinor2Test` "BR5: thoi gian..." | PASS (đo thô, bcrypt cost 4) |
| Lượt 11 của tài khoản không tồn tại là 429 như tài khoản thật | `LoginQaMinor2Test` "M3: khong ton tai..." | PASS |
| T27-5: 5 ca (mã sai, hết 5 lượt, không có mã, không tồn tại, bị khoá) cùng status/body/header | `T27/QaMinor2ResetTest` | PASS |
| Staff: MFA sau đăng nhập đúng (viết hoa email, vài lượt sai trước) vẫn chạy, bộ đếm được hoàn | `T28/QaMinor2StaffTest` | PASS |
| Staff bị khoá: sai mật khẩu giống hệt không tồn tại; mật khẩu đúng mới lộ `ACCOUNT_LOCKED` và hoàn lượt | `QaMinor2StaffTest` | PASS |
| Staff: ghost và tài khoản thật cùng 429 ở lượt 11 | `QaMinor2StaffTest` | PASS |
| R5: 15 tiến trình sai cùng lúc, số lần so mật khẩu ≤ 10 | `T03/LoginRaceTest` | **FAIL ngẫu nhiên (BUG-1)** |
| Timeout 180s ở 11 file race | grep `setTimeout`; 4 file chạy xanh | PASS |

## Bug phát hiện

### BUG-1: Bộ đếm đăng nhập không nguyên tử khi đồng thời thật, vượt hạn 10 lượt (Major)
- Bước tái hiện: `cd infra && docker compose exec -T php vendor/bin/pest -c phpunit.local-c.xml tests/Feature/T03/LoginRaceTest.php`, chạy 5 đến 10 lần. Test tạo 1 học sinh thật, bắn 15 tiến trình PHP riêng gọi `LoginService::attempt` sai mật khẩu cùng lúc (cùng IP), dùng Redis thật (`CACHE_LIMITER=redis-limiter`, vì store `array` không chia sẻ giữa tiến trình). Worker: `tests/Support/login_race_worker.php`.
- Mong đợi: đúng 10 lần so mật khẩu (kết quả `wrong`), 5 request 429.
- Thực tế: 5/17 lần chạy có hơn 10 lần so mật khẩu. Số lượt sai được chấp nhận quan sát được: 11, 12, 13, 15, 15 (có lần cả 15 request đều được so mật khẩu, không có 429 nào).
- Vị trí nghi ngờ: `LoginService.php:101-121` (`reserveAttempts`) kết hợp `Illuminate\Cache\RateLimiter::increment()` của framework: `add($key,0)` rồi `increment`, và nếu `! $added && $hits == $amount` thì `put($key, 1)`, ghi đè mất các lượt INCR đã được tính trong cửa sổ đầu. Đường hoàn lượt (`decrement`, cũng gọi qua `increment` với -1) của các request bị chặn cũng chạy cùng lúc và có thể đẩy bộ đếm xuống dưới số thật. Chưa tách được nguyên nhân chính xác trong phạm vi QA, cần Dev điều tra. Đề xuất: dùng đếm nguyên tử thuần (`Cache::increment` trên khoá đã có TTL, hoặc Lua script Redis: INCR rồi EXPIRE khi giá trị 1), và đừng `decrement` ở đường chặn, thay bằng kiểm `> max` chỉ-đọc sau INCR.
- Ảnh hưởng: kẻ tấn công gửi song song thực sự có thể đoán nhiều hơn 10 mật khẩu mỗi cửa sổ (quan sát tối đa 15 trên 15 request đồng thời). Mức Major vì mục tiêu M3 ("tối đa 10") chưa đạt trong điều kiện thật; ảnh hưởng thực tế bị giới hạn bởi throttle route `login` và ngưỡng IP 50. Cùng cơ chế dùng cho `StaffAuthService` (chưa có race test riêng cho staff).
- Test `LoginRaceTest` giữ khẳng định chặt (≤ 10), sẽ xanh ổn định sau khi sửa. Hiện đang đỏ ngẫu nhiên, nên cân nhắc loại khỏi `composer ci` cho tới khi sửa, hoặc cho chạy cùng nhóm `race`.

## Rủi ro & đề xuất
- BUG-1 chưa kiểm cho staff (`staff-login-fail:*`); sau khi sửa nên thêm race test tương tự.
- Header `x-ratelimit-remaining` của route `throttle:login` giảm theo bucket IP nên giữa 2 request khác nhau, đã loại khỏi phép so sánh (không liên quan tài khoản).
- Test timing chỉ là đo thô (bcrypt cost 4), không thay cho đo trên cost thật.
- `LoginRaceTest` ghi vào Redis DB limiter thật (khoá `login-fail:u:<id>`, `login-fail-ip:10.77.x.y`) và tự `clear` trong `finally`; id user của DB test có thể trùng khoá của DB dev trong cửa sổ vài giây.
- Review R1/R2 (IP bị chặn không lan khoá tài khoản): đã được Dev sửa bằng kiểm chỉ-đọc trước, test của Dev `R1 ...` xanh.

## File đã thêm
- `backend/tests/Feature/T03/LoginQaMinor2Test.php` (6 test)
- `backend/tests/Feature/T03/LoginRaceTest.php` (1 test race, nhóm `race`)
- `backend/tests/Support/login_race_worker.php`
- `backend/tests/Feature/T27/QaMinor2ResetTest.php` (2 test)
- `backend/tests/Feature/T28/QaMinor2StaffTest.php` (3 test)
- `docs/qa/minor-fixes-2.md`

## QA lại (sau khi Dev sửa BUG-1 bằng `AtomicCounter`)
**Kết quả:** PASS. BUG-1 đã đóng.

| Việc | Kết quả |
|---|---|
| `LoginRaceTest` + `StaffLoginRaceTest` (15 tiến trình, Redis thật, `CACHE_PREFIX` riêng) chạy 12 lần liên tiếp | **12/12 xanh** (2 test, 42 assertions mỗi lần). Trước khi sửa: 5/17 đỏ |
| T03+T27+T28+T04 (không gồm race) | **342 passed / 2060 assertions** (có thêm 2 test QA mới ở dưới) |
| 4 file race đã đổi timeout (T18, T22, T33, T27 ResetConcurrency) | 13 passed / 266 assertions |
| Redis dev sau khi chạy race, quét `*racetest*` ở DB 0-4 | 0 khoá sót |
| Pint file mới | sạch |

Đối chiếu `AtomicCounter` với `RateLimiter` trên Redis thật (test mới `T03/LoginAtomicCounterRedisTest`, prefix `racetest-qa-*`, tự dọn):
- Khoá Lua (`store->getPrefix().clean(key)`) trùng khoá `RateLimiter::attempts/clear` đọc/xoá, kể cả khoá có dấu tiếng Việt (`clean()` giống nhau). PASS.
- TTL của khoá Lua là 3600s (EXPIRE khi giá trị = 1). `AtomicCounter::availableIn` đọc TTL nên `Retry-After` đúng (1..3600). PASS.
- 10 lượt sai, lượt 11 với mật khẩu đúng: 429 kèm `Retry-After` > 0; đăng nhập thành công sau đó xoá bộ đếm về 0. PASS.
- `release` không xuống dưới 0. PASS.
- Ghi nhận (không phải bug, không ảnh hưởng hiện tại): nhánh Lua không tạo khoá `:timer` nên `RateLimiter::availableIn()`/`tooManyAttempts()` của framework trả 0/false cho các khoá này (test khẳng định `availableIn` = 0). Code hiện chỉ dùng `AtomicCounter::*`, nên an toàn; nếu sau này ai gọi `RateLimiter::tooManyAttempts` trên khoá `login-fail*` sẽ sai (còn tự xoá khoá). Cần giữ quy ước chỉ đi qua `AtomicCounter`.
- Ghi nhận: `RateLimiter::attempts()` trên Redis trả chuỗi (`'3'`), `AtomicCounter::attempts` ép `int` nên so sánh ngưỡng đúng.

File QA thêm: `backend/tests/Feature/T03/LoginAtomicCounterRedisTest.php` (2 test). `docs/security/backlog-v2.md`: dòng MF2-R5 đánh dấu đã xử lý.
