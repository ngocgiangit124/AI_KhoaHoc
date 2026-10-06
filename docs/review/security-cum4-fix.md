# REVIEW: Sửa bảo mật cụm 4 (M1, M2, L1–L6)
**Kết luận (lần 2): APPROVE.** Lần 1: REQUEST CHANGES (1 High, 1 Medium, 4 Low, 1 Info), vòng 2 xem mục "Review lần 2" cuối file.
**Phạm vi:** code chưa commit, trừ `.claude/`, `frontend/`, `docs/design/`: `SecurityHeaders`, `bootstrap/app.php` (`/up`), `ProductionConfigGuard`, `OperationsServiceProvider`, `TranscodeService`, `VideoLabNotifyCommand` + migration `notified_at`, `EnrollmentDecisionMail`, `config/logging.php`, `infra/php/*`, `infra/production/**` (redis ACL, env mẫu, nginx, supervisor), checklist, test T01/T04/T11/T12/T17/T26/T31/Arch · khoảng 35 file.
**Kiểm chạy (DB e, `docker compose exec -T php`):** `pest T01 T12 T26 Arch` = 175 passed, 1 skipped. T31 qua `docker run` có mount `infra/production` = 165 passed, 0 skip. Pint sạch (565 file). `grep ... | uniq -d` helper test: rỗng. Chạy thật `redis:7` (7.4.11) tạm với ACL đã thay placeholder (xem H1).

## Tổng quan
Hướng sửa đúng: cô lập worker bằng ACL, bỏ đường worker ghi vào `queues:default`, guard chặt hơn, `/up` và CSP gọn. Khi bỏ comment khỏi file ACL, tôi chạy thật pop, migrate, size, đọc 3 key tín hiệu thì đều qua; SET/DEL/LPUSH/`queues:default`/SCRIPT/FUNCTION/CONFIG/KEYS/SHUTDOWN đều NOPERM. Lỗi chặn duy nhất là file ACL mẫu không nạp được vì còn dòng comment.

## Phát hiện

### R1 [High] `users.acl` có dòng comment `#`: Redis từ chối nạp file (aclfile và `ACL LOAD`)
- Vị trí: `infra/production/redis/users.acl` (toàn bộ phần đầu file và các khối comment giữa hai dòng `user`).
- Vấn đề: tôi thay placeholder rồi chạy `redis-server --aclfile` (7.4.11): `Aborting Redis startup because of ACL errors: users.acl:1 should start with user keyword followed by the username` (lỗi cho từng dòng comment). File ACL không hỗ trợ comment. Bản mẫu làm theo checklist "thay `<PREFIX>`..." sẽ không khởi động được; người vận hành dễ quay về `requirepass` dùng chung, mất toàn bộ M1. Test `M1: mau Redis ACL` chỉ lọc các dòng bắt đầu bằng `user ` nên không bắt được lỗi này.
- Dev ghi "đã chạy thật trên redis:7 tạm" nhưng bản commit không nạp được; có thể đã chạy bản đã lược comment.
- Đề xuất:
  - Tách toàn bộ giải thích sang `infra/production/redis/README.md` (hoặc file `.md` trong cùng thư mục). `users.acl` chỉ còn đúng 2 dòng `user ...` (không comment, không dòng trống thừa).
  - Thêm test: mọi dòng không rỗng của `users.acl` phải bắt đầu bằng `user `.
  - Đề xuất thêm bước checklist §4: `redis-server --test-memory` không dùng được; thay bằng `redis-cli ACL LOAD` trên staging và kiểm `ACL WHOAMI`/`ACL GETUSER vv_worker_video`.

### R2 [Medium] Worker bị chiếm vẫn treo được Redis bằng Lua (`+eval`)
- Vị trí: `users.acl`, dòng user `vv_worker_video` (`+eval`).
- Vấn đề: Redis kiểm từng lệnh và từng key bên trong script (đã xác nhận: `rpush queues:default`, `keys`, `script`, `config`, `eval` lồng, `evalsha` lồng đều bị chặn). Nhưng script tuỳ ý vẫn chạy được vòng lặp vô hạn. Nếu script đã ghi (RPUSH vào `queues:video` được phép) rồi mới lặp, `SCRIPT KILL` không còn dùng được và chỉ `SHUTDOWN NOSAVE` dừng được: một worker bị chiếm có thể làm Redis (phiên, cache, queue, limiter) ngừng phục vụ, tức mất sẵn sàng toàn site. Không phải đường lấy dữ liệu, nên Medium. Đã suy luận từ hành vi Redis, chưa chạy thử vòng lặp.
- Đề xuất: ghi rõ rủi ro còn lại trong ADR-002 §3a và checklist §4 (chấp nhận hoặc đưa vào backlog-v2). Giảm thiểu: Redis riêng cho queue `video` (instance thứ hai) khi dựng production thật; giám sát độ trễ Redis. Laravel dùng `EVAL` chứ không `EVALSHA` nên không thể chuyển sang script nạp sẵn nếu không tự viết driver.

### R3 [Low] Guard: kiểm `APP_ENV` hợp lệ chạy TRƯỚC bước tắt debug
- Vị trí: `ProductionConfigGuard::check()` (`throw_unless(in_array($environment, VALID_ENVIRONMENTS...))` nằm trước `config(['app.debug' => false])`).
- Vấn đề: `APP_ENV=prod` + `APP_DEBUG=true` ném lỗi trước khi debug bị ép tắt, nên exception handler vẫn render trang debug. C4-L4 chỉ vá cho các lỗi đến sau.
- Đề xuất: đặt `if (! app()->environment('local','testing')) config(['app.debug' => false]);` (chỉ khi `$debugWasOn` ghi lại được) ở đầu `check()`, trước cả kiểm `VALID_ENVIRONMENTS`. Thêm test: `APP_ENV=prod` + debug=true rồi `render` không lộ stack.

### R4 [Low] Guard M2: ký tự `#` hợp lệ không bị chặn (đạt), nhưng thiếu test và ghi chú; mật khẩu có ` #` hoặc khoảng trắng đầu/cuối vẫn bị chặn oan
- Vị trí: `ProductionConfigGuard::guardEnvWithoutInlineComments()` (`preg_match('/\s#/')`, `trim($value) !== $value`); checklist §1.1.
- Kết quả kiểm: regex chỉ khớp khoảng trắng liền trước `#`, nên `DB_PASSWORD=ab#cd` và `REDIS_PASSWORD=#abc` qua. Đúng yêu cầu. Danh sách 50+ biến hợp lý, không chứa `APP_NAME`, `MAIL_FROM_NAME` (có khoảng trắng hợp lệ). `VALID_ENVIRONMENTS` đúng. Trường hợp chặn oan còn lại: mật khẩu/secret chứa ` #` (ví dụ `.env` dùng nháy `DB_PASSWORD="a #b"`, phpdotenv trả `a #b`) hoặc khoảng trắng đầu/cuối.
- Đề xuất: (1) thêm test: `DB_PASSWORD=ab#cd`, `REDIS_PASSWORD=#abc`, `TURNSTILE_SECRET=a#b` được chấp nhận; (2) ghi vào checklist §1.1: "mật khẩu/secret sinh ngẫu nhiên (`openssl rand`), không chứa khoảng trắng; ký tự `#` được phép nếu không đứng sau dấu cách".

### R5 [Low] `videolab:notify`: thiếu test cho nhánh lỗi và backfill; cửa sổ crash có thể mất thông báo
- Vị trí: `VideoLabNotifyCommand::handle()`, migration `2026_10_17_100000_add_notified_at_to_vl_videos.php`, `TranscodeTest`.
- Đã kiểm đúng: (a) giành quyền bằng `UPDATE ... WHERE id=? AND notified_at IS NULL` là nguyên tử ở InnoDB (hai tiến trình cùng đọc, chỉ một nhận `affected=1`), nên đúng cả khi `onOneServer`/`withoutOverlapping` mất hiệu lực; (b) lỗi dispatch trả `notified_at=null` rồi ném lại: không vòng lặp vô hạn (mỗi phút một lượt), không mất thông báo; (c) worker đặt lại `notified_at=null` cùng lúc lưu `FINISHED`/`ERROR`, và `markFailed` cũng vậy, nên video thử lại vẫn được báo lại; (d) backfill dùng hằng 4,5 khớp `Video::FINISHED/ERROR`, đặt `notified_at = updated_at` nên không bắn webhook hàng loạt; (e) trễ tối đa ~1 phút + độ trễ queue `default` chỉ làm trễ bước đồng bộ asset, `videos:check-stuck` (15 phút, theo `VideoAsset` ở phía app) vẫn là lưới an toàn nên vẫn đúng; (f) `TusUploadService` vẫn dispatch webhook UPLOADED từ phía app, không bị ảnh hưởng.
- Còn lại: process chết giữa UPDATE và `dispatch` (OOM, deploy) làm mất thông báo của video đó; lưới an toàn là `check-stuck` sau 15 phút (chấp nhận). Một video lỗi dispatch làm cả lượt dừng (ném lại) nhưng lỗi là của hạ tầng Redis nên không gây "poison pill".
- Test thiếu: nhánh dispatch ném lỗi (cột trả về null), backfill migration, và hai lượt chạy liên tiếp đã có. Đề xuất thêm test mock `Bus`/`Queue` ném `RuntimeException` rồi `expect(notified_at)->toBeNull()`.

### R6 [Low] Chưa có test đăng nhập thật với `SESSION_ENCRYPT=true`
- Vị trí: `LoginService::startSession` (`Session::getHandler()->read/write`), `StudentSessionService` (`getHandler()->destroy`).
- Đã kiểm bằng đọc code: `getHandler()` trả handler thô; snapshot đọc và ghi lại là cùng chuỗi đã mã hoá, nên khôi phục phiên cũ vẫn đúng; `destroy` chỉ theo id. Sanctum SPA dùng middleware session của Laravel (`EncryptedStore`) nên tương thích; mã hoá payload phiên không đụng cookie. Tác động duy nhất: mọi phiên đang mở bị đăng xuất một lần (đã ghi checklist).
- Đề xuất: một test feature đặt `session.encrypt=true` và chạy luồng login, đổi mật khẩu bind 1 thiết bị, để chắc chắn đường `getHandler()` hoạt động.

### R7 [Info] ACL: `+evalsha` thừa; test ACL chỉ là so chuỗi
- `+evalsha`: Laravel (phpredis/predis) gọi `EVAL`, không dùng `EVALSHA` nên có thể bỏ để thu hẹp bề mặt (worker khi đó không chạy lại được script app đã cache, dù lệnh bên trong vẫn bị ACL kiểm).
- Bộ lệnh đã đủ cho `RedisQueue` Laravel 13: pop/release/later/migrate/size qua `EVAL` với lệnh bên trong `lpop zadd zrem zrangebyscore zremrangebyrank rpush llen zcard` (đã chạy thật), `delete` = `ZREM`, `block_for` = `BLPOP`, đọc tín hiệu `GET`/`MGET` (`getPausedQueues` dùng `many`→`MGET`), `SELECT`. Pattern key khớp tên thật: `RedisStore` ghép `prefix` không có dấu `:`, nên `<REDIS_PREFIX><CACHE_PREFIX>illuminate:queue:restart` đúng (đã đối chiếu `Cache::getStore()->getPrefix()` = `vitaminvui_cache`, prefix Redis `vitaminvui-database-`); các key liệt kê tường minh, không glob rộng; ACL không tách theo DB nhưng tên key khác nhau.
- Đề xuất: bỏ `+evalsha`; trong R1 thêm test tích hợp (skip khi không có `redis-server`) nạp file ACL và chạy lại các lệnh trên.

## Đối chiếu các điểm cần kiểm
| # | Kết quả |
|---|---|
| 1 ACL | Bộ lệnh đủ (đã chạy thật); không `@dangerous`; SCRIPT FLUSH/FUNCTION/CONFIG/KEYS/SHUTDOWN NOPERM; `eval` không thoát được ACL nhưng treo được Redis (R2); file không nạp được vì comment (R1) |
| 2 notify | Đúng nghiệp vụ, đua nhiều server an toàn; thiếu test nhánh lỗi/backfill (R5) |
| 3 Guard M2 | `#` hợp lệ không bị chặn; thiếu test và ghi chú (R4); thứ tự debug (R3) |
| 4 CSP | Không có view HTML nào ở `app`/`routes`; `/up` JSON, 404/405 và JSON đều có CSP (middleware global `prepend`); download/export dùng `Content-Disposition` và HLS qua `response()->file` không bị ảnh hưởng vì CSP chỉ áp cho tài liệu |
| 5 php.ini-production | Container đang chạy là image cũ (chưa rebuild: `display_errors=STDOUT`). Image mới: `memory_limit` bị `zz-vitaminvui.ini` ghi đè 512M; opcache mặc định `validate_timestamps=1`, `revalidate_freq=2` nên dev vẫn thấy code mới; `variables_order=GPCS` không làm mất env (đã thử `-d variables_order=GPCS`: `$_SERVER` và `getenv` vẫn có, guard đọc cả hai); `zend.assertions=-1` (không code nào dùng `assert()`). Cần rebuild image một lần |
| 6 SESSION_ENCRYPT + Sanctum | Tương thích (R6 đề xuất test) |
| 7 Test | Đủ cho M2/L1/L2/L4/L6; thiếu cho R1 (nạp ACL), R3, R4, R5, R6; helper không trùng tên |

## Gợi ý cho QA
- Dựng `redis:7` tạm với file ACL đã sửa: chạy `queue:work redis_video` thật cho một video nhỏ (pop, release khi lỗi, delete, `queue:restart`, `queue:pause`), `NOPERM` cho `queues:default` và DB 1.
- Chạy `videolab:notify` hai tiến trình song song trên cùng DB (kiểm không bắn đôi), và giả lập dispatch lỗi.
- Bật `SESSION_ENCRYPT=true` rồi login web và admin, đổi mật khẩu, đăng nhập thiết bị thứ hai.
- Rebuild image PHP rồi `php -i | grep display_errors` ở CLI và FPM; chạy lại `composer ci`.
- Đặt `APP_ENV=prod` + `APP_DEBUG=true` (R3).

## Review lần 2 (chỉ phần đã đổi cho R1–R7)
**Kết luận: APPROVE.** Không còn Critical/High/Medium; 2 Low mới, 1 Info.
**Kiểm chạy (DB e):** `pest T01 T05 T12 T28 Arch` = 301 passed, 2 skipped (đúng thiết kế, cần mount infra). T31 qua `docker run` có mount = 187 passed, không skip. Pint sạch (565 file). `uniq -d` helper: rỗng.

| Mục | Kết quả |
|---|---|
| R1 | Đạt. `users.acl` và `users.video-instance.acl` chỉ còn dòng `user`, redis-server 7.4.11 nạp được; test tĩnh kiểm mọi dòng bắt đầu bằng `user `. |
| R2 | Đạt về thiết kế (xem R8, R9). Connection `video` trong `config/database.php`; `redis_video` đọc `VIDEOLAB_REDIS_CONNECTION`. |
| R3 | Đạt. Debug bị ép tắt ngay đầu `check()` (trừ local/testing); test `prod`, `Production`, `uat`, `production # x` không lộ trang debug. |
| R4 | Đạt. `ab#cd`, `#abc`, `abc#` qua; ` #` và khoảng trắng đầu/cuối bị chặn. |
| R5 | Đạt. Test dispatch lỗi (Dispatcher giả) và backfill (có `down()`/`up()`). |
| R6 | Đạt. Test login học sinh (A bị B thay, payload là chuỗi mã hoá) và staff Sanctum SPA với `session.encrypt=true`. |
| R7 | Đạt. `+evalsha` đã bỏ. |

**Chạy thật `check-acl.sh` trên `redis:7` tạm (`docker run --rm`, container tự xoá):** bản dùng chung và bản `video-instance` (`SKIP_SIGNALS=1`) đều in `ACL đạt.`, exit 0. Thử ACL cố ý hỏng (bỏ `+eval`) thì script in `ACL KHÔNG đạt.` (script `exit $fail`).

### Trả lời bốn điểm cần soi
1. **`REDIS_VIDEO_*`:** biến KHÔNG định nghĩa thì rơi về Redis chính (có chủ đích, local không đổi). Biến định nghĩa nhưng rỗng (`REDIS_VIDEO_HOST=`) thì `env()` trả chuỗi rỗng, không fallback, kết nối lỗi rõ ràng chứ không âm thầm. Đúng như dev nói; env mẫu để các dòng `REDIS_VIDEO_*` ở dạng comment. Rủi ro còn lại: R8.
2. **`CACHE_STORE=array` ở worker:** `queue:restart` và `queue:pause` nằm trong cache nên MẤT tác dụng với worker. Env mẫu và `redis/README.md` đã ghi: worker tự thoát theo `--max-time=3600`, deploy bằng image mới. Checklist chưa nói cách dừng worker: R9.
3. Đã chạy (ở trên).
4. **Test migration và `finally`:** DDL gây commit ngầm trong MySQL nên `RefreshDatabase` không rollback phần sau đó; `finally` dựng lại cột nếu thiếu rồi `DB::table('vl_videos')->delete()` nên bảng sạch. An toàn khi mỗi lượt chạy dùng DB riêng (quy ước DB a–h). Hai tiến trình chạy chung một DB thì `delete()` và `down()` sẽ phá test của nhau (giống `AuditLogTriggerTest` có sẵn); không chạy `--parallel` trên cùng DB. Ghi nhận, không chặn.

### Phát hiện mới
- **R8 [Low] Tách Redis video mà app và worker lệch cấu hình thì job kẹt âm thầm.** App có `REDIS_VIDEO_*` còn worker thiếu (hoặc ngược lại): job nằm ở Redis này, worker nghe Redis kia; không có guard hay cảnh báo. Đề xuất: checklist §7 thêm bước gửi thử một video và `LLEN` queue ở Redis video phải về 0; hoặc `ops:health` cảnh báo khi `queues:video` quá tuổi/độ sâu.
- **R9 [Low] Checklist chưa nêu cách dừng worker khi deploy với `CACHE_STORE=array`.** Cần ghi: restart bằng Supervisor/container (`supervisorctl restart vitaminvui-worker-video` hoặc thay container), và xử lý job ffmpeg đang chạy (timeout tới 3600 giây; job sẽ được nhận lại sau `retry_after`).
- **R10 [Info]** `check-acl.sh` chỉ gọi `EVAL` với `llen`, chưa chạy pop/migrate đầy đủ (đã kiểm tay ở vòng 1).

### Bước tiếp theo
Chuyển `laravel-qa`. R8, R9 chỉ là tài liệu/checklist, làm ngay hoặc ghi backlog-v2.
