# QA: T35-1 + T35-2 + T35-3 (đóng gói & triển khai production, ADR-008)

**Kết quả:** PASS (0 Critical, 0 Major; 3 Minor + 2 ghi nhận). Chạy 2026-10-10 trên Docker Desktop macOS, load 4-7. Không sửa code ứng dụng/infra; chỉ thêm script QA trong `infra/production/smoke/qa/`. Không git add/commit; không migrate DB dev. Container/volume/network/image tạm đã dọn (dev stack 12 container nguyên vẹn).

## 1. Smoke (tự chạy)
| Lệnh | Kết quả |
|---|---|
| `infra/production/smoke/smoke.sh --with-frontend` (large) | 136 đạt, 0 lỗi |
| `infra/production/smoke/smoke.sh --with-frontend --size small --keep` | 147 đạt, 0 lỗi (rồi `smoke.sh down`) |
| `infra/production/smoke/smoke.sh --with-videolab` | 135 đạt, 0 lỗi |
Sau mỗi lần `smoke.sh down` / tự dọn: 0 container, volume, network, image `vv-local/*`. Con số trùng số Dev báo.

## 2. Kịch bản thêm
| Kịch bản | Cách kiểm | Kết quả |
|---|---|---|
| Deploy lần đầu DB trống (thiếu `--ack-irreversible` thì dừng, chưa ghi IMAGE_TAG, chưa đổi container) | smoke | PASS |
| Deploy, smoke lỗi, rollback về ĐÚNG bản cũ (không phải bản trước nữa) | smoke (dev5) | PASS |
| Migrate lỗi: container giữ bản cũ, `log_bin_trust_function_creators` về 0, không tự rollback | smoke (dev4) | PASS |
| `VV-IRREVERSIBLE` thiếu `--ack-irreversible` | smoke (dev3) | PASS |
| Digest thiếu / sai định dạng, deploy + rollback không `--local`, dừng trước pull | smoke | PASS |
| Digest LỆCH sau pull (deploy không-local): dừng, container/.env không đổi, chưa preflight/migrate | `qa/digest-nonlocal.sh` (shim `docker compose pull` gắn tag cho ảnh lạ) | PASS (10/10) |
| Digest LỆCH sau pull (rollback không-local, ảnh đích vắng): dừng, không ghi .env | như trên | PASS |
| Thiếu digest WEB/ADMIN: dừng TRƯỚC pull (deploy và rollback) | như trên | PASS |
| `X-Forwarded-For`/`X-Real-IP`/`X-Client-IP`/`Forwarded` giả vào `api.` không đổi IP Laravel thấy | `qa/api-spoof.sh`: limiter `catalog` 120/phút theo `$request->ip()`; control 200=120/429=15, spoof (đổi header mỗi request) 200=120/429=15 | PASS |
| `X-Forwarded-Host` giả vào host web/admin: Next nhận `$host`; `X-Real-IP`/`X-Forwarded-For` = `$remote_addr`; `Forwarded` rỗng; `X-Internal-Token`/`X-Client-IP` bị xoá (cả `/`, `/_next/static/`, `/phu-huynh/huy-nhan-thong-bao`) | `qa/nginx-web-headers.sh`: Nginx 1.27.5 + conf production nguyên văn + backend giả | PASS (25/25) |
| 429 khi flood `api.` (700 req: 305 bị 429) và `/auth/login`; 40 client cùng IP x 3 request, 40 đăng nhập đồng loạt: không 429; `/up` không bị limit; `/index.php` gọi thẳng 404 | smoke | PASS |
| Flood host web: 700 req ra 234x200 / 466x429; 40 client x 3 ra 120x200 | `qa/nginx-web-headers.sh` | PASS |
| Log: access log Nginx (`?t=` huỷ nhận, `?k=` webhook Bunny) và log php-fpm không chứa query token | script + smoke | PASS |
| worker-video (VideoLab bật): không ra Internet, không phân giải `redis`, rootfs ro, cap rỗng, `/tmp` noexec | smoke `--with-videolab` | PASS |
| Cỡ small: `pm.max_children=5`, opcache 64, buffer pool 256M, `max_connections` 50, redo 512M, Redis `maxmemory` 64mb, 1 queue, tổng `mem_limit` 1784 MB (<= 1843); `docker stats` lúc rảnh: mysql 236/540 MB, web 108/256, admin 60/192, php 37/380 | smoke small | PASS |

## 3. Image
- `check-images.sh` (backend + worker-video): 55 đạt, 0 lỗi.
- Web/admin (build production dạng thật, `qa/check-frontend-images.sh --build`): 14 đạt, 0 lỗi. USER uid 1000; không `.env*`/`*.pem`/`*.key`; label `vv.env=production`; trong `/app/apps` không có `test-payment.momo.vn`, khoá thử Turnstile, `*.localhost`, `localhost:3000/3001/8000/8080`, không host momo; `docker history` sạch. Chuỗi `localhost`/`127.0.0.1` còn lại chỉ nằm trong mã framework Next (regex hostname, `localhost:3000` mặc định), không phải cấu hình của ta.

## 4. CI
- `rhysd/actionlint:latest` toàn repo: sạch.
- shellcheck: smoke tự chạy `-S warning` trên `deploy.sh`, `smoke/*.sh`: 0 lỗi (3 lần). `scripts/ci/*.sh` bằng `-S style`: KHÔNG chạy được trong lượt này (Docker Hub timeout lúc pull image shellcheck), Reviewer đã chạy sạch.
- Giả lập job CI trong container (mysql:8.4, redis:7 tạm, mạng riêng, repo sao chép KHÔNG có `.env`): `scripts/ci/backend-prepare.sh all` xanh (kể cả nhánh `default-mysql-client` vì `mysql-client` không có trong Debian của image), Pest `--exclude-group=race`: 2971 passed, 1 skipped, 0 failed (431 s). Pint `--test` 788 file PASS; PHPStan "No errors". Không chạy `backend-race`, `composer audit`, `pnpm audit`, frontend job (đã được Dev/Reviewer chạy).
- `collect-digests.sh` metadata giả: đủ 4 khoá in 4 dòng `IMAGE_DIGEST_*` + `# ref`; `REQUIRED_TARGETS` mặc định thiếu target thì exit 1 "Thiếu digest cho ..."; `backend web admin` không có worker-video exit 0 (3 dòng); release `web admin` thiếu admin exit 1; digest sai dạng exit 1; không file exit 2.

## 5. Pest trong container có mount `infra/production` (`phpunit.local-h.xml`)
`tests/Feature/T31` + `T35` + `T37/BunnyGuardTest` + `T38/ManualPaymentGuardTest` + `T28/HostPrefixCookieTest`: 294 passed (1123 assertions), 0 skipped.

## Bug / phát hiện
Không có Critical/Major.

### BUG-1 (Minor): smoke nuốt lỗi build, build lần đầu sau khi dọn thỉnh thoảng lỗi
- Bước tái hiện: `smoke.sh --with-frontend` hoặc `--with-videolab` khi chưa có image `vv-local/*`. 2 trong 4 lần chạy (large, videolab) in `build image lỗi` rồi thoát 1; chạy tay đúng lệnh bake ngay sau đó thì xanh (toàn `CACHED`), chạy lại smoke xanh. Cùng lúc Docker Hub báo timeout xác thực, nên nghi do mạng, không do cấu hình.
- Mong đợi: lỗi build hiện lý do (hoặc thử lại một lần). Thực tế: `>/dev/null 2>&1` che toàn bộ.
- Vị trí: `infra/production/smoke/smoke.sh` dòng ~79-84 và ~86-93. Đề xuất ghi log build vào `$LOGDIR` và in 20 dòng cuối khi lỗi.

### BUG-2 (Minor, tài liệu): `docs/ops/ci.md:19` còn ghi scope cache `vv-backend`, `vv-frontend`
Đã tách theo target (`vv-php-base`, `vv-backend`, `vv-worker-video`, `vv-web`, `vv-admin`). Là C7 của Reviewer, chưa sửa.

### BUG-3 (Minor, ghi nhận đã biết): `error.log` của Nginx ghi query token khi upstream lỗi
Thử: ngắt upstream, gọi `/phu-huynh/huy-nhan-thong-bao?t=...` và `/api/v1/webhooks/video/bunny?k=...`: access log sạch (đúng), nhưng `error.log` có dòng `request: "...?t=..."`. Đã được ghi trong `vitaminvui.conf` và `vv-api-common.conf` (quyền 0640, xoay log, token là bí mật vận hành). Không phải lỗi mới; nhắc PO/vận hành áp dụng quyền 0640 + xoay log ngắn thật trên staging.

### Ghi nhận (không phải bug)
- Rollback khi ảnh đích CÒN trên server không kiểm digest (R17, thiết kế). Thử: `.env` có `IMAGE_DIGEST_*` sai mà ảnh đích đã có, rollback vẫn thành công. Chấp nhận vì ảnh đã qua kiểm digest ở deploy cũ; chỉ rủi ro nếu ai đó retag ảnh local (đã là quyền root).
- Smoke Docker Desktop không bắt được: lỗi quyền người dùng thật (`vvdeploy`), `ufw`/`DOCKER-USER`, source IP thật mà FPM thấy qua cổng publish `127.0.0.1:9000` (xem dưới).

## Chỉ kiểm được trên staging Linux thật (danh sách cho PO)
1. Chạy `deploy.sh` bằng user `vvdeploy` (không root): quyền `/opt/vitaminvui`, env 0600, `.deploy.lock.d`, `releases.log`; `sudo -u nobody ls /srv/vitaminvui/redis` bị từ chối; dữ liệu MySQL/Redis chown 999 trên bind mount thật (smoke dùng volume có tên).
2. Pull từ GHCR thật với digest ĐÚNG; `VV_COSIGN_VERIFY=1` với ảnh đã ký (lần CI đầu); digest sai 1 ký tự phải dừng. Ảnh build local không có RepoDigest nên nhánh "digest đúng, đi tiếp" chưa kiểm được.
3. CI trên GitHub lần đầu: `docker/bake-action` với `source: .` + named context `target:php-base`, khoá `backend|worker-video|web|admin` trong metadata, Summary có đúng 3 dòng `IMAGE_DIGEST_*` (4 khi `VV_VIDEOLAB=1`), `cosign verify` theo `docs/ops/ci.md`, SHA action ghim khớp tag, PR từ fork không có job `images`, thiếu `var` Environment phải đỏ trước khi đẩy ảnh, `release-images` với SHA ngoài `main` đỏ ngay, thời gian CI và độ ổn định `backend-race`.
4. IP thật qua Nginx host + cổng publish `127.0.0.1:9000`: IP FPM thấy là gateway mạng `app` (10.231.11.1), nằm NGOÀI `TRUSTED_PROXIES=10.231.10.0/24` nên `X-Forwarded-For` giả bị bỏ qua; smoke chỉ chứng minh topology tương đương (nginx-smoke ở mạng `app` ngoài dải trusted). Kiểm lại bằng `curl` có `X-Forwarded-For` giả từ ngoài và xem log/throttle; log Nginx hiện IP khách thật.
5. `ufw` (80/443 mọi nguồn, 22 theo IP quản trị), `ss -lntp` chỉ Nginx + sshd, Docker Engine >= 28 (cổng loopback), `ip_nonlocal_bind=1`, systemd `after-docker.conf` (Nginx lên trước mạng `front`).
6. Chỉ khi bật VideoLab: luật `DOCKER-USER` chặn `10.231.12.0/24` và `fsockopen` tới `10.231.12.1:22/443`, `10.231.10.1:8081` ra `false` (bridge gateway trên host thật khác Docker Desktop).
7. TLS thật (`vv-tls.conf`, HTTP/2, HSTS do Laravel), `limit_req` dưới NAT lớp học thật, cấu hình small 1 vCPU/2 GB chịu tải thật (`docker stats`, swap, `vmstat`), kiểm bộ nhớ MySQL buffer pool 256M với dữ liệu thật.
8. `/_next/image` nhánh 200 với host tĩnh công khai (smoke chỉ chứng minh 400 chặn IP private, không 5xx).
9. Cron xoay slow log, snapshot nhà cung cấp và diễn tập khôi phục (khuyến nghị), checklist Bunny C1-C9 (`docs/qa/T37.md`, điều kiện go-live), `error.log` Nginx quyền 0640.
10. Deploy hai lần liên tiếp bằng `vvdeploy`, `kill -9` giữa chừng để kiểm khoá mồ côi và `status`; deploy có `--maintenance` với migration khoá bảng.

## Lệnh chạy lại
```
infra/production/smoke/smoke.sh --with-frontend            # rồi tự dọn khi đạt
infra/production/smoke/smoke.sh --with-frontend --size small --keep
  VV_SIZE=small infra/production/smoke/qa/digest-nonlocal.sh   # cần stack smoke --with-frontend đang chạy
  VV_SIZE=small infra/production/smoke/qa/api-spoof.sh         # cần stack đang chạy, mất ~3 phút
infra/production/smoke/smoke.sh down
infra/production/smoke/smoke.sh --with-videolab
infra/production/smoke/qa/nginx-web-headers.sh                # độc lập, tự dọn (cổng 18443)
infra/production/smoke/qa/check-frontend-images.sh --build    # rồi: docker rmi qa-local/vitaminvui-{web,admin}:production-qa
infra/production/smoke/check-images.sh                        # sau khi bake backend worker-video với VV_REGISTRY=vv-local IMAGE_TAG=dev
docker run --rm rhysd/actionlint:latest  (mount repo: -v "$PWD":/repo -w /repo)
docker run --rm --network vitaminvui_vitaminvui --env-file backend/.env -v "$PWD/backend:/var/www/backend" -v "$PWD/infra/production:/var/www/infra/production:ro" -w /var/www/backend vitaminvui-php:8.3 vendor/bin/pest -c phpunit.local-h.xml tests/Feature/T31 tests/Feature/T35 tests/Feature/T37/BunnyGuardTest.php tests/Feature/T38/ManualPaymentGuardTest.php tests/Feature/T28/HostPrefixCookieTest.php
```
Job CI giả lập: mạng Docker tạm + `mysql:8.4` (alias `mysql`) + `redis:7` (alias `redis`) + `vitaminvui-php:8.3` chạy `-u 0` trên bản sao repo không có `backend/.env`: `scripts/ci/backend-prepare.sh all && cd backend && vendor/bin/pest --exclude-group=race --colors=never` (đặt `MYSQL_HOST=mysql REDIS_ADDR=redis MYSQL_ROOT_PASSWORD=ci-root-only`).

## File QA thêm (chưa git add)
- `infra/production/smoke/qa/digest-nonlocal.sh`
- `infra/production/smoke/qa/api-spoof.sh`
- `infra/production/smoke/qa/nginx-web-headers.sh`
- `infra/production/smoke/qa/check-frontend-images.sh`

## Sửa BUG Minor (coordinator, 2026-10-10)
- BUG-1: `smoke.sh` ghi log build vào `smoke/.generated/build-{backend,frontend}.log` (tạo thư mục trước), lỗi thì in 20 dòng cuối thay vì nuốt hết.
- BUG-2: `docs/ops/ci.md` sửa scope cache gha theo target (`vv-php-base`, `vv-backend`, `vv-worker-video`, `vv-web`, `vv-admin`).
- BUG-3: giữ như ghi nhận vận hành (quyền log Nginx 0640, xoay log ngắn) — đã có trong checklist.
