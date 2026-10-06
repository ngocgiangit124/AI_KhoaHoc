# QA: Sửa bảo mật cụm 4 (cấu hình tổng thể)
**Kết quả:** PASS (0 Critical, 0 Major, 0 Minor; 3 ghi chú Info). Không sửa code ứng dụng.

**Phạm vi:** M1 (Redis ACL worker-video, Redis riêng `REDIS_VIDEO_*`, `videolab:notify` thay webhook từ worker, `notified_at`, `SESSION_ENCRYPT`), M2 (env mẫu không chú thích cuối dòng, guard `APP_ENV`/` #`/hex), L1-L6. Nguồn: `docs/security/review-cum4-config.md`, `docs/review/security-cum4-fix.md`, `infra/production/**`.

## Số liệu
| Lượt chạy | Kết quả |
|---|---|
| `pest -c phpunit.local-c.xml --exclude-group=race` T01 T05 T11 T12 T13 T26 T28 T04 T17 Arch + `Cum4QaTest` (docker compose exec, DB c, load 3.8) | **656 passed, 2 skipped** (3574 assertions; 2 skip là test cần mount `infra/`) |
| Race T13 (`--group=race` T12 T13; T12 không có test race) | 5 passed / 197 assertions |
| T31 bằng `docker run` mount `infra/production` (DB c) | **203 passed / 746 assertions, không skip** |
| Test QA mới `tests/Feature/T31/Cum4QaTest.php` | 16 passed, Pint sạch |
| `grep -rhoE "^function [a-zA-Z_0-9]+" backend/tests \| sort \| uniq -d` | rỗng (helper QA đặt tên `c4qaProductionConfig`) |

`uptime` trước mỗi lượt: load 2.9 đến 6.5 (một lần đo 7.86 ở lúc chạy `docker run` guard, chỉ là lệnh tức thời), luôn dưới 40, không phải chờ.

## 1. E2E local: luồng VideoLab sau khi đổi webhook (PASS)
Dữ liệu `qa-c4` (course 520, lesson 1392), video `testsrc 160x120`, 2 giây, 7684 byte, tạo bằng ffmpeg trong container worker-video. Đăng nhập admin (MFA qua Mailpit), `video-uploads` trả 201, TUS tạo 201 và PATCH 204 tới `video.localhost:8000`.

| Mốc | Kết quả |
|---|---|
| 14:07:14 upload xong | status 1, asset `uploading` |
| 14:07:17 worker-video | `TranscodeVideoJob` DONE 345ms; `vl_videos.status=4`, `notified_at` null |
| 14:08:02 scheduler `videolab:notify` | `notified_at` điền; `SendVideoLabWebhookJob` chạy ở container `queue` (329ms); asset chuyển `ready` (trễ 45 giây sau transcode, trong 1 phút như thiết kế) |
| Playback `admin/courses/520/lessons/1392/playback` | 200, `kind=hls`, URL `video.localhost:8000/videolab/cdn/...` |
| HLS | master 200 `application/vnd.apple.mpegurl` (160x120); variant `120p/index.m3u8` 200; segment `.ts` 200 `video/mp2t` |
| Queue `default` | `Queue::size('default')` = 0 trong toàn bộ 10 lần đo (mỗi 10 giây); log worker-video không có dòng nào của job app |
| Chạy trùng | Đặt lại `notified_at=null`, chạy 4 tiến trình `videolab:notify` song song: tổng số webhook xếp hàng 1 (`0,1,0,0`); queue log đúng 1 `SendVideoLabWebhookJob` |

Đã dọn: course/lesson/asset/`vl_videos`/thư mục hls/file source của `qa-c4`, thư mục `/tmp/qa` trong worker. `audit_logs` không xoá (bất biến).

## 2. ACL Redis thật (PASS)
- `check-acl.sh` trên `redis:7` tạm (`docker run --rm`, tên `qa-c4-acl1`, `qa-c4-acl2`; mật khẩu sinh ngẫu nhiên, hash SHA-256, placeholder thay đủ):
  - `users.acl`: toàn bộ OK (cấm `GET` phiên DB 1, `--scan`, `LPUSH/RPUSH queues:default`, `EVAL` ghi `queues:default` và đọc key phiên, `SET/DEL queues:video`, `flushall`, `config`, `keys`; cho phép `llen`, `zcard`, `EVAL llen`, đọc `queue:restart`, cấm ghi `queue:restart`). In `ACL đạt.`, exit 0.
  - `users.video-instance.acl` + `SKIP_SIGNALS=1`: `ACL đạt.`, exit 0.
- Ca đầy đủ với `TranscodeVideoJob` thật, Redis tạm gắn vào network compose hiện có (không tạo network, không `compose run/up`):
  - Dispatch từ container php (user `default`) rồi `queue:work redis_video --queue=video --once` chạy trong container worker-video với `APP_ENV=production`, `APP_DEBUG=false`, mọi biến guard hợp lệ, user `vv_worker_video`.
  - Bản dùng chung (`users.acl`, `CACHE_STORE=redis`, đọc tín hiệu queue): job RUNNING, DONE 402ms, `vl_videos.status=4`.
  - Bản Redis riêng (`users.video-instance.acl`, `REDIS_VIDEO_*`, `CACHE_STORE=array`): job DONE 326ms, status 4.
  - Cả hai: `ACL LOG` rỗng (không có NOPERM nào do `queue:work` thật gọi), sau job `dbsize` các DB 1, 2, 3 đều 0 (không để key rác, không ghi cache heartbeat).
- Khác biệt so với `.env.worker-video.example`: dùng thông tin DB dev của container thay `vv_worker_video`/`<DB_HOST>` (không đụng `grants.sql`, đã kiểm ở QA T31).
- Đã `docker rm` các container tạm.

## 3. Guard với env thật (PASS)
`docker run --rm --env-file` (bản sao `.env.production.example` thay placeholder; image `vitaminvui-php:8.3`, mount backend, `/dev/null` đè `.env`), `php artisan about`:

| Ca | Kết quả |
|---|---|
| Env mẫu đã thay placeholder, `DB_PASSWORD=ab#cd` | Qua guard: `Environment production`, `Debug Mode OFF` |
| `APP_ENV=production   # moi truong` | Bị chặn: `APP_ENV phải đúng "production" hoặc "staging" ...` |
| `INTERNAL_API_TOKEN=<hex>   # token` / `CAPTCHA_DRIVER=turnstile # x` / `AUTH_OTP_CHANNELS=email # x` / `DB_PASSWORD=ab #cd` | Bị chặn: `Biến môi trường <KEY> chứa chú thích cuối dòng (' #') ...` |
| `APP_ENV=prod` | Bị chặn (APP_ENV không hợp lệ) |
| `APP_DEBUG=true` | Bị chặn: `APP_DEBUG phải là false ...` |
| `INTERNAL_API_TOKEN` không phải hex | Bị chặn: `phải là chuỗi hex ...` |
| `DB_PASSWORD=#abc` | Qua |

Thông điệp rõ. Lưu ý khi tái hiện: nếu `storage/logs` không ghi được (mount `:ro`) thì Laravel in thêm lỗi "could not be opened in append mode" bọc quanh thông điệp guard (vẫn có thông điệp ở dòng "while attempting to log"); với `LOG_CHANNEL=stderr` chỉ in đúng lỗi guard.

## 4. Header (PASS)
- `GET api.localhost:8000/api/v1/subjects` (200) và `/api/v1/config/public` (200): có `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'` và `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()`.
- 404 JSON cũng có đủ hai header.
- `/up` (cả api và admin-api): 200, `Content-Type: application/json`, body `{"status":"ok"}`, có CSP và Permissions-Policy.
- Giới hạn IP của `/up` là cấu hình Nginx production (`allow <IP_MONITOR_LB>`), không có ở Nginx local nên chưa kiểm được ở đây (đã có test tĩnh đọc mẫu conf, T31 pass). Cần kiểm trên staging.

## Độ phủ theo hạng mục
| Hạng mục | Kiểm | Kết quả |
|---|---|---|
| M1 worker không ghi `default`, webhook qua notify | E2E (mục 1), `TranscodeTest`, `Cum4ConfigTest`, `Cum4QaTest` (markFailed không đẩy queue, `--limit`, trạng thái 0/1/2/3/6 không báo) | PASS |
| M1 ACL | check-acl.sh x2, queue:work thật x2 | PASS |
| M1 `SESSION_ENCRYPT` bắt buộc | `Cum4ConfigTest`, guard env thật | PASS |
| M2 env mẫu / guard | docker --env-file, `Cum4ConfigTest`, `Cum4QaTest` (tab, `\n`, dấu cách giữa, tiếng Việt, `APP_DEBUG="false # x"`) | PASS |
| L1 log level cố định info | `Cum4ConfigTest` | PASS |
| L2 `/up` JSON, CSP, Permissions-Policy | curl + test (thêm 401 api và 403 admin-api Origin lạ) | PASS |
| L3 `php.ini-production` | Không kiểm lại (cần rebuild image, ràng buộc cấm). Còn mở: `php -i \| grep display_errors` sau khi build trên staging | chưa kiểm |
| L4 guard ép tắt debug | env thật + test | PASS |
| L5 stateful domains allowlist | `GuardCoverageTest`, `Cum4ConfigTest` | PASS |
| L6 Mailable `ShouldBeEncrypted` | `tests/Arch/QueuedPiiEncryptedTest` | PASS |

## Bug phát hiện
Không có.

## Rủi ro & đề xuất (Info, không chặn)
1. **Lệch múi giờ giữa cột do worker và do app ghi.** Worker chạy `UTC` (`finished_at` 07:07:17), app chạy `Asia/Ho_Chi_Minh` (`notified_at` 14:08:02). Không ảnh hưởng chức năng (notify chỉ kiểm `notified_at` null), nhưng so sánh hai cột trong báo cáo sẽ lệch 7 giờ. Cân nhắc đặt `APP_TIMEZONE` giống nhau trong env mẫu worker.
2. **Transcode lại video đã xong sẽ đặt `notified_at=null` và gửi lại webhook** (`TranscodeService::run` ghi `notified_at => null` khi hoàn tất). Hợp lý khi job chạy lại sau `retry_after`; cần chắc `SendVideoLabWebhookJob`/handler webhook idempotent (T12 đã kiểm webhook lặp ở QA trước, không kiểm lại ở đợt này).
3. **R8 và R9 (reviewer)** vẫn là việc tài liệu: checklist chưa có bước kiểm `LLEN queues:video` về 0 khi tách Redis video, và chưa nêu cách dừng worker khi `CACHE_STORE=array`. Chưa kiểm được trên hạ tầng thật: Nginx `/up` allow-list, `display_errors=Off` ở FPM, `php.ini-production`, Redis riêng trên staging.

## File QA thêm
- `/Users/admin/Documents/ViCode/AI_KhoaHoc/backend/tests/Feature/T31/Cum4QaTest.php` (16 test).
- Scratchpad: `/private/tmp/claude-501/-Users-admin-Documents-ViCode-AI-KhoaHoc/3a2cd2cd-bc7a-44ee-8290-b73adaa069b3/scratchpad/qa-c4/` (script, file ACL đã thay placeholder, log).
