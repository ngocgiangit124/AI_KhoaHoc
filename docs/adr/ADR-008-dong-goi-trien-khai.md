# ADR-008: Đóng gói và triển khai staging/production (image GHCR + Docker Compose trên 1 server, Nginx ở host)

**Trạng thái:** Accepted (PO duyệt 2026-10-09)
**Liên quan:** T35 (T35-1, T35-2, T35-3 trong `docs/architecture/tasks.md`), T31 (mẫu `infra/production/`), ADR-002 §3a.3 (sandbox worker-video), ADR-004 §2.1, §2.7, §2.8 (host, CSP nonce, listener SSR `:8081`), `docs/ops/go-live-readiness.md` mục 6 (H1, H2, H6, H9–H11), mục 8 (thứ tự deploy, rollback), mục 9 (D2, D3, D5), `docs/ops/production-checklist.md`.

## Bối cảnh

- Chưa có `.github/workflows`, chưa có compose production, chưa có cách đóng gói Next.js (readiness H1, H2). Go-live bị chặn ở đây.
- Mẫu T31 (đã review 2 vòng + QA dựng Nginx thật) giả định: Nginx ở host; PHP-FPM ở `127.0.0.1:9000`; Next web `127.0.0.1:3000`, admin `127.0.0.1:3001`; listener nội bộ `<IP_NOI_BO_NGINX>:8081` cho SSR; `X-Accel-Redirect` tới `/var/www/backend/storage/app/videolab/hls/`; tên miền tĩnh đọc `/var/www/uploads`; 3 user MySQL (`vv_app`, `vv_migrate`, `vv_worker_video`) ràng theo host; Redis ACL; worker-video chạy máy/container riêng, không ra Internet.
- Đội nhỏ. Hạ tầng: 1 server staging + 1 server production Ubuntu. Local đã chạy toàn bộ bằng Docker. `infra/php/Dockerfile` đã dùng `php.ini-production` (C4-L3), ghi chú "T35 dùng nguyên Dockerfile này hoặc kế thừa".
- Ràng buộc kỹ thuật:
  - `NEXT_PUBLIC_*` được nhúng vào bundle lúc `next build`; CSP của `proxy.ts` dựng từ các biến này.
  - `ProductionConfigGuard` chạy ở mọi tiến trình (kể cả `queue:work`, `artisan` lúc build nếu `APP_ENV` thiếu thì mặc định `production`).
  - Có migration không hoàn tác được (T29 backfill `2026_10_20_110000`, `down()` rỗng). Migration tạo trigger cần `log_bin_trust_function_creators=1` tạm thời.
  - ADR-002 §3a.3: worker-video nằm trong mạng Docker `internal: true`, chỉ tới được MySQL/Redis.
  - PO: không chuyển dữ liệu ra nước ngoài (board, "Chờ PO trả lời" #4).

## Các phương án

### 1. Chạy backend
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Trên host: PHP 8.3 apt + Supervisor + release symlink, `composer install` trên server | Khớp nguyên văn mẫu Supervisor T31 | Server phải có toolchain (composer, git); môi trường lệch local/CI; worker-video vẫn cần Docker (ADR-002) nên thành mô hình lai; Ubuntu 22.04 không có PHP 8.3 trong apt |
| **B. Image PHP-FPM + Docker Compose** | Một image chạy ở CI, staging, production; rollback bằng đổi tag; server chỉ cần Docker + Nginx; worker-video kế thừa image tự nhiên | Phải giữ đường dẫn dữ liệu trùng giữa host và container (Nginx ở host đọc file); recreate container PHP gián đoạn vài giây |
| C. Orchestrator (Kubernetes, Swarm, Kamal, Coolify) | Rolling update, nhiều máy | Thừa cho 1 server; Kamal/Coolify mang proxy riêng, đụng Nginx T31 |

### 2. Queue và scheduler
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Supervisor trong container PHP | Dùng lại file `supervisor/vitaminvui.conf` | Nhiều tiến trình/container; khó restart riêng, khó đọc log, healthcheck mập mờ |
| **B. Service compose riêng, cùng image** | Ánh xạ 1-1 với 3 program Supervisor; `restart: unless-stopped` thay `autorestart`, `stop_grace_period` thay `stopwaitsecs`, `deploy.replicas` thay `numprocs` | Thêm vài khối trong compose |

### 3. Frontend web + admin
| Phương án | Ưu | Nhược |
|---|---|---|
| A. `next start` dưới systemd trên host | Không cần Docker cho Node | Server phải có Node 22 + pnpm và build tại chỗ |
| **B. Image Node `output: "standalone"`, build riêng cho từng môi trường** | Bất biến, khớp CSP lúc build; rollback bằng tag | `NEXT_PUBLIC_*` nhúng lúc build nên mỗi môi trường một image; production phải build lại từ cùng commit (không "promote" được image staging) |
| C. Một image, thay `NEXT_PUBLIC_*` lúc khởi động (placeholder + sed, hoặc `window.__ENV`) | Một image cho mọi môi trường | Sửa JS đã build lúc chạy; CSP trong `proxy.ts` vẫn đọc giá trị đã nhúng; dễ sai âm thầm. Loại |

### 4. Nginx
| Phương án | Ưu | Nhược |
|---|---|---|
| **A. Ở host (giữ T31)** | Không đổi cấu hình đã review; certbot webroot `/var/www/acme`; log `/var/log/nginx`; `X-Accel-Redirect` đọc file trên đĩa host | Cần quy tắc đường dẫn trùng (mục 8.5) |
| B. Trong container | Gói trọn trong compose | Viết lại đường dẫn, chứng chỉ, log; mất giá trị review T31. Loại |

### 5. MySQL và Redis (V1)
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Cài ở host (apt) | Quen thuộc | Ubuntu không có MySQL 8.4 trong apt (cần repo Oracle); worker-video ở mạng `internal: true` không tới được dịch vụ host, phải mở đường riêng, trái ADR-002 §3a.3 |
| B. Dịch vụ managed | Backup/PITR/HA có sẵn | Chi phí; worker-video phải có đường ra ngoài (phá `internal: true`) hoặc private link; nhà cung cấp phải đặt dữ liệu ở Việt Nam |
| **C. Container trong cùng compose, dữ liệu bind mount trên host** | Không tốn thêm; cùng phiên bản với local/CI (`mysql:8.4`); mạng `internal` cho worker-video chạy đúng như local; tách được Redis riêng cho queue `video` (R2) mà không tốn server | Tự lo backup (H9); một server là điểm hỏng duy nhất |

### 6. Registry và cách deploy
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Build ngay trên server | Không cần registry | Server cần mã nguồn + toolchain; chậm; khó tái lập |
| **B. GHCR + `deploy.sh <sha>` do người vận hành chạy qua SSH** | GitHub không giữ khoá SSH vào server; đơn giản | Thao tác tay |
| C. GHCR + Actions SSH deploy (`workflow_dispatch`) | Một nút bấm | Secret GitHub mang quyền tương đương root (nhóm `docker`) trên server. Có thể thêm sau cho riêng staging |

## Quyết định

> **Cập nhật (T35-1 vòng 3-4):** khi thân bài dưới mâu thuẫn với mục "Điều chỉnh khi hiện thực T35-1" và các mục "Quyết định PO 2026-10-10" ở cuối file thì các mục cuối THẮNG. Đã thay: Cloudflare proxy (tạm bỏ), backup bằng script (`backup-db.sh`, `--skip-backup`, thư mục `backups/`; nay là snapshot nhà cung cấp), `grants.sql` (tách `grants-users.sql`/`grants-worker.sql`), VideoLab/`video.` (tuỳ chọn, V1 dùng Bunny), digest tuỳ chọn (nay bắt buộc ngoài `--local`), `--ack-snapshot` (đã bỏ).

Chọn **1B, 2B, 3B, 4A, 5C, 6B**. Tóm tắt: CI build image và đẩy GHCR theo SHA commit; mỗi server chạy một Docker Compose project `vvstack` gồm PHP-FPM, queue, scheduler, worker-video, MySQL, Redis, Redis video, web, admin; Nginx của T31 ở host proxy vào cổng loopback; người vận hành chạy `deploy.sh` trên server. Mẫu T31 chỉ thay giá trị placeholder, không đổi cấu trúc.

### 8.1 Sơ đồ (một server, staging và production giống hệt)

```
Internet ─▶ Nginx host (T31, :80/:443)
             ├─ api / admin-api / video ─ fastcgi 127.0.0.1:9000 ─▶ [php]  (mạng app)
             ├─ vitaminvui.vn ─────────── http 127.0.0.1:3000 ────▶ [web]  (mạng front)
             ├─ admin.vitaminvui.vn ───── http 127.0.0.1:3001 ────▶ [admin](mạng front)
             ├─ static.* ──────────────── đọc /var/www/uploads (host)
             └─ /_protected_hls/ ──────── đọc /var/www/backend/storage/app/videolab/hls (host)
           Nginx host listen 10.231.10.1:8081 ◀── SSR từ [web] (gateway mạng front)

mạng app   (10.231.11.0/24): php, queue x2, scheduler, migrate (chạy 1 lần), mysql, redis, redis-video
mạng video (10.231.12.0/24, internal): worker-video, mysql, redis-video
mạng front (10.231.10.0/24): web, admin
```

### 8.2 Image (tên dùng chung cho T35-1, T35-2, T35-3)

Registry `ghcr.io/ngocgiangit124` (package private). Local/smoke dùng tiền tố `vv-local` (không đẩy đi đâu).

| Image | Tag | Build | Ghi chú |
|---|---|---|---|
| `vitaminvui-backend` | `<sha>` (40 ký tự hex của commit) | `infra/php/Dockerfile.prod`, context = gốc repo, bake `infra/production/docker-bake.hcl` target `backend` | Dùng chung staging và production (build một lần, dùng hai nơi) |
| `vitaminvui-worker-video` | `<sha>` | `infra/worker-video/Dockerfile` (không sửa), `BASE_IMAGE` = image backend cùng SHA (bake target `worker-video`) | Có mã nguồn từ image backend + ffmpeg (checklist §7) |
| `vitaminvui-web` | `staging-<sha>`, `production-<sha>` | `frontend/Dockerfile` `APP=web`, bake `frontend/docker-bake.hcl` target `web` | Một image mỗi môi trường |
| `vitaminvui-admin` | `staging-<sha>`, `production-<sha>` | `frontend/Dockerfile` `APP=admin`, target `admin` | Một image mỗi môi trường |

- Không dùng tag trôi (`latest`, `staging-latest`). Deploy luôn theo SHA. Tag git SemVer (PO gắn) chỉ để tra cứu SHA.
- Label bắt buộc: `org.opencontainers.image.revision=<sha>`, `vv.compose-version=<số>` (backend), `vv.env=staging|production` (web, admin). `deploy.sh` từ chối image web/admin có `vv.env` khác `VV_ENV` của server, và từ chối khi `vv.compose-version` khác file compose đang có trên server.
- Nền tảng: `linux/amd64` (server). Base image ghim theo phiên bản vá (vd. `php:8.3.x-fpm`, `node:22.x-bookworm-slim`, `mysql:8.4.x`, `redis:7.4.x`) và digest khi làm T35-1/T35-2.

### 8.3 Service compose (`infra/production/docker-compose.yml`, project `vvstack`)

| Service | Image | Lệnh | Số bản | Mạng | Cổng host | Ghi chú |
|---|---|---|---|---|---|---|
| `php` | backend | `php-fpm` | 1 | app | `127.0.0.1:9000:9000` | healthcheck FPM ping |
| `queue` | backend | `php artisan queue:work redis --queue=default,exports --tries=3 --sleep=1 --timeout=60 --max-time=3600` | 2 (`deploy.replicas`) | app | không | `stop_grace_period: 70s` |
| `scheduler` | backend | `php artisan schedule:work` | 1 | app | không | |
| `worker-video` | worker-video | CMD của Dockerfile (`queue:work redis_video --queue=video ...`) | 1 | video | không | `read_only`, `tmpfs /tmp`, `cap_drop: [ALL]`, `no-new-privileges`, `cpus: 2`, `mem_limit: 2g`, `pids_limit: 256`, `stop_grace_period: 3660s`, `VV_OPTIMIZE=0` |
| `migrate` | backend | `php artisan migrate --force` | chạy 1 lần (`profiles: [tools]`, `restart: "no"`) | app | không | env `app.env` + `migrate.env` (ghi đè `DB_USERNAME=vv_migrate`, `DB_PASSWORD`), `VV_OPTIMIZE=0` |
| `mysql` | `mysql:8.4.x` | mặc định + `my.cnf` | 1 | app, video | không | không publish cổng; quản trị qua `docker compose exec` |
| `redis` | `redis:7.4.x` | `redis-server /usr/local/etc/redis/redis.conf` | 1 | app | không | ACL `users.acl` (chỉ dòng `default`), `appendonly yes` |
| `redis-video` | `redis:7.4.x` | như trên, conf riêng | 1 | app, video | không | ACL `users.video-instance.acl` (R2) |
| `web` | web | `node apps/web/server.js` | 1 | front | `127.0.0.1:3000:3000` | `HOSTNAME=0.0.0.0`, `PORT=3000` |
| `admin` | admin | `node apps/admin/server.js` | 1 | front | `127.0.0.1:3001:3001` | `PORT=3001` |

- Mọi service: `restart: unless-stopped` (trừ `migrate`), log driver `json-file` `max-size: 20m`, `max-file: 5`; container backend và Next chạy non-root, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`.
- Không có cổng nào publish ra `0.0.0.0` (Docker publish vượt qua ufw). MySQL/Redis không publish.
- `deploy.sh` không bao giờ chạy `docker compose down` (xoá mạng làm Nginx mất địa chỉ `10.231.10.1`), chỉ `up -d`, `pull`, `run --rm`.
- Mở rộng web khi load test staging chưa đạt (README frontend R11, readiness H8): thêm service `web-2` ở `127.0.0.1:3002` và dòng `server 127.0.0.1:3002;` vào upstream `next_web`. Không làm ở V1 cho tới khi có số đo.

### 8.4 Mạng, IP và giá trị điền vào mẫu T31

Subnet đặt trong file `.env` của compose (đổi nếu trùng mạng nội bộ của nhà cung cấp server): `VV_SUBNET_FRONT=10.231.10.0/24` (gateway `10.231.10.1`), `VV_SUBNET_APP=10.231.11.0/24`, `VV_SUBNET_VIDEO=10.231.12.0/24` (`internal: true`).

| Chỗ trong mẫu T31 | Giá trị khi chạy Docker |
|---|---|
| `upstream php_fpm / next_web / next_admin` | giữ nguyên `127.0.0.1:9000/3000/3001` |
| `listen <IP_NOI_BO_NGINX>:8081` | `10.231.10.1:8081` (gateway mạng front). Nginx phải khởi động sau Docker: drop-in systemd `infra/production/systemd/nginx.service.d/after-docker.conf` (`After=docker.service`, `Wants=docker.service`) |
| `allow <IP_NEXT_SERVER>` (listener 8081) | `10.231.10.0/24` |
| `API_INTERNAL_URL` (web) | `http://10.231.10.1:8081` |
| `TRUSTED_PROXIES` | `10.231.10.0/24` + IP load balancer nếu có (PO, mục "Điểm chờ PO") |
| `allow <IP_APP_SERVER>` (`/videolab/library/`) | Nguồn thật của request từ container `php` tới `video.<domain>`: không có LB thì là IP trong `10.231.11.0/24`; có LB/proxy trước Nginx thì là IP công khai của server. Xác định trên staging bằng access log, không đoán |
| `allow <IP_MONITOR_LB>` (`/up`) | IP giám sát + `127.0.0.1` (smoke test của `deploy.sh` gọi `curl --resolve api.<domain>:443:127.0.0.1`) |
| `grants-users.sql` + `grants-worker.sql` `<APP_HOST>`, `<MIGRATE_HOST>` | `10.231.11.%` |
| `grants-users.sql` + `grants-worker.sql` `<WORKER_HOST>` | `10.231.12.%` |
| `my.cnf` | thêm `skip-name-resolve` (grants theo IP) |

### 8.5 Đường dẫn trên host

Quy tắc: thư mục nào Nginx host đọc trực tiếp thì **đường dẫn trên host trùng đường dẫn trong container**. Nhờ vậy Nginx, `VIDEOLAB_PATH`, `UPLOADS_PATH` của mẫu T31 không đổi.

| Host | Mount vào container | Chủ sở hữu, quyền | Dùng bởi |
|---|---|---|---|
| `/opt/vitaminvui/` | không | `vvdeploy:vvdeploy` 0750 (user chạy deploy.sh phải ghi được: khoá, `.env` tạm, `releases.log`) | `docker-compose.yml`, `.env` (biến compose), `deploy.sh`, `backup-db.sh`, `releases.log` |
| `/opt/vitaminvui/env/*.env` | `env_file` | `vvdeploy` 0600 | mục 8.6 |
| `/opt/vitaminvui/conf/` | `my.cnf`, `redis.conf`, `redis-video.conf`, `users.acl`, `users.video-instance.acl` (chỉ đọc) | `vvdeploy` 0600, riêng ACL đọc được bởi UID của redis trong container | mysql, redis |
| `/var/www/backend/storage/app` | cùng đường dẫn | `10001:10001`, 0751; `videolab/` 0755; `private/` 0700 | php, queue, scheduler, migrate |
| `/var/www/backend/storage/app/videolab` | cùng đường dẫn | như trên | worker-video (chỉ thư mục này) |
| `/var/www/backend/storage/logs` | cùng đường dẫn | `10001:10001` 0750 | php, queue, scheduler |
| `/var/www/uploads` | cùng đường dẫn (`UPLOADS_PATH`) | `10001:10001` 0755 | php, queue, scheduler; Nginx đọc |
| `/srv/vitaminvui/{mysql,redis[,redis-video]}` | thư mục dữ liệu (0700 root) | theo image | mysql, redis |

- Trên host, `/var/www/backend` chỉ chứa dữ liệu (`storage/app`, `storage/logs`), không có mã. Không tạo `/var/www/backend/public` trên host (Nginx `try_files` rơi về `/index.php` và FastCGI dùng đường dẫn trong container).
- UID/GID backend trong image: `10001:10001` (build arg `UID/GID` của `infra/php/Dockerfile`), tránh trùng user `ubuntu` (1000) trên server. Host tạo user `vvapp` (10001, nologin) để quyền đọc được.
- `storage/framework` (cache view, `bootstrap/cache`) KHÔNG mount: mỗi container tự sinh lúc khởi động, không trộn giữa bản cũ và bản mới.

### 8.6 Biến môi trường và secret

| File trên server | Nạp cho | Mẫu trong repo |
|---|---|---|
| `env/app.env` | php, queue, scheduler, migrate | `infra/production/.env.production.example` |
| `env/migrate.env` | migrate (ghi đè) | `infra/production/.env.migrate.example` (mới: `DB_USERNAME=vv_migrate`, `DB_PASSWORD=`) |
| `env/worker-video.env` | worker-video | `infra/production/.env.worker-video.example` |
| `env/mysql.env` | mysql | `infra/production/.env.mysql.example` (mới: `MYSQL_ROOT_PASSWORD`, `MYSQL_ROOT_HOST=localhost`, `MYSQL_DATABASE=vitaminvui`; không đặt `MYSQL_USER`, user do `grants-users.sql` + `grants-worker.sql` tạo) |
| `env/web.env` | web (chỉ biến chạy) | `frontend/apps/web/.env.production.example` phần "runtime" |
| `env/admin.env` | admin (chỉ biến chạy) | `frontend/apps/admin/.env.production.example` phần "runtime" |
| `/opt/vitaminvui/.env` | biến nội suy compose: `VV_ENV`, `VV_REGISTRY`, `IMAGE_TAG`, `PREVIOUS_TAG`, `VV_SUBNET_*` | `infra/production/compose.env.example` (mới) |

Thay đổi mẫu env (T35-1):
- `app.env`: `DB_HOST=mysql`, `REDIS_HOST=redis`, bật `REDIS_VIDEO_HOST=redis-video` (+ `PORT`, `USERNAME=default`, `PASSWORD`); `APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=redis` (để `php artisan down` ở một container áp cho mọi container; hiện mẫu ghi `file`).
- `worker-video.env`: `DB_HOST=mysql`, `REDIS_VIDEO_*` trỏ `redis-video`, `CACHE_STORE=array`, bỏ `REDIS_HOST`/`REDIS_PASSWORD` của Redis chính (đúng hướng dẫn R2 đã có trong mẫu).

Biến `NEXT_PUBLIC_*` là **build arg** (công khai theo định nghĩa), lưu ở GitHub Environment `staging`/`production` dạng `vars`, không phải `secrets`. Mọi secret (APP_KEY, mật khẩu DB/Redis, `INTERNAL_API_TOKEN`, SMTP, Turnstile secret, `VIDEOLAB_*`, `PRIVACY_NOTICE_TOKEN_KEY`) chỉ nằm ở `env/*.env` trên server, không bao giờ là build arg, không vào image, không vào GitHub.

### 8.7 Ánh xạ mẫu T31 sang mô hình Docker

| Mẫu T31 | Trạng thái |
|---|---|
| `nginx/conf.d/vitaminvui.conf`, `snippets/*` | Giữ nguyên cấu trúc; chỉ điền placeholder theo mục 8.4. Nginx host phải **>= 1.25.1** (mẫu dùng `http2 on;`): cài từ repo nginx.org, không dùng bản 1.24 của Ubuntu 24.04 |
| `mysql/grants.sql` | Giữ nguyên; điền host theo mục 8.4; chạy bằng `docker compose exec -T mysql mysql -uroot -p...` |
| `redis/users.acl` | Redis chính chỉ dùng dòng `default` (worker không chạm Redis chính). File mẫu không đổi |
| `redis/users.video-instance.acl` | Dùng cho `redis-video`. `check-acl.sh` chạy với `SKIP_SIGNALS=1` |
| `supervisor/vitaminvui.conf` | Không dùng ở chế độ Docker; giữ làm tham chiếu (thêm dòng đầu ghi "thay bởi compose, ADR-008"). Ánh xạ: `numprocs` → `deploy.replicas`, `stopwaitsecs` → `stop_grace_period`, `autorestart` → `restart: unless-stopped`, `user=www-data` → `USER` của image |
| `.env.production.example`, `.env.worker-video.example` | Sửa giá trị như mục 8.6 |
| Checklist §1.1 (kiểm PHP), §6, §7, §11 ("release symlink") | Cập nhật: kiểm `php -i` bằng `docker compose exec php`; "giữ >= 3 release" thành "giữ >= 3 tag image trên server"; quy trình deploy/rollback theo mục 8.11, 8.12 |

### 8.8 Image backend (`infra/php/Dockerfile.prod`)

- Kế thừa `infra/php/Dockerfile` (không sửa file gốc): bake target `php-base` build file gốc với `UID=10001 GID=10001`, rồi `Dockerfile.prod` `FROM ${BASE_IMAGE}` (named context `vv-php-base` = `target:php-base`). Giữ `php.ini-production` + `zz-vitaminvui.ini`, không có xdebug.
- Thêm: `docker-php-ext-enable opcache` + `infra/php/prod/zz-opcache.ini` (`opcache.enable=1`, `enable_cli=0`, `validate_timestamps=0`, `memory_consumption=192`, `max_accelerated_files=20000`); pool `infra/php/prod/zz-fpm-pool.conf` (`pm=dynamic`, `pm.max_children=${FPM_MAX_CHILDREN}` mặc định 20, `pm.max_requests=500`, `ping.path=/fpm-ping`, giữ `clear_env=no` của image gốc); `libfcgi-bin` cho healthcheck.
- `composer install --no-dev --prefer-dist --no-interaction --no-scripts`, rồi `composer dump-autoload --optimize --no-dev --no-scripts`, rồi `php artisan package:discover` với biến tạm CHỈ trong lệnh `RUN` đó (`APP_ENV=local`, khoá giả) vì guard chặn `production` thiếu biến. Image cuối không có `APP_ENV`.
- Không chạy `config:cache`/`route:cache` lúc build (route theo `Route::domain(config(...))` phụ thuộc env). Entrypoint `infra/php/prod/entrypoint.sh`: khi `VV_OPTIMIZE` khác `0` thì `php artisan optimize` (config, events, routes, views) rồi `exec "$@"`; lỗi guard làm container thoát ngay.
- `Dockerfile.prod.dockerignore` loại: `backend/.env*`, `backend/vendor`, `backend/storage/*` (giữ khung thư mục), `backend/tests`, `backend/phpunit*.xml`, `**/node_modules`, `.git`, `frontend/`, `docs/`, `infra/.env`, `**/*.pem`, `**/*.key`.

### 8.9 Image frontend (`frontend/Dockerfile`)

- `next.config.ts` của 2 app thêm `output: "standalone"` và `outputFileTracingRoot` = gốc workspace `frontend/`.
- Nhiều stage: cài phụ thuộc bằng `pnpm install --frozen-lockfile` (pnpm 9.15.9 qua corepack, Node 22) → build với `ARG APP` và các `ARG NEXT_PUBLIC_*` (mặc định chuỗi rỗng, không bao giờ để undefined) → runner `node:22-bookworm-slim`, chép `.next/standalone`, `.next/static`, `public`; `USER node`; `NODE_ENV=production`, `NEXT_TELEMETRY_DISABLED=1`.
- Build arg web: `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_HOSTS`, `NEXT_PUBLIC_TURNSTILE_SITE_KEY`, `NEXT_PUBLIC_MOMO_HOSTS`. Build arg admin: `NEXT_PUBLIC_ADMIN_API_URL`, `NEXT_PUBLIC_ADMIN_URL`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL`. Thêm `VV_ENV` để gắn label.
- Biến chạy (không nhúng): web `API_INTERNAL_URL`, `INTERNAL_API_TOKEN`, `V2_PREVIEW`; admin `V2_PREVIEW`. Production để `V2_PREVIEW` trống.
- `NEXT_PUBLIC_MOMO_HOSTS` (readiness E2, D5): V1 staging và production đều rỗng (MoMo tắt). `env.ts` đổi mặc định thành rỗng; `.env.example` local vẫn đặt rõ `test-payment.momo.vn`.

### 8.10 CI (GitHub Actions)

- `.github/workflows/ci.yml`, chạy ở `pull_request` và `push` lên `main`, `permissions: contents: read`:
  - `backend-static`: PHP 8.3 (`shivammathur/setup-php`, extension như Dockerfile), `pint --test`, `phpstan analyse`.
  - `backend-test`: service `mysql:8.4`, `redis:7`; `/etc/hosts` thêm `127.0.0.1 mysql redis` (phpunit.xml ép `DB_HOST=mysql`, giống `scripts/cloud-setup.sh`); bước SQL tạo `vitaminvui_testing` + user `vitaminvui`, `SET GLOBAL transaction_isolation='READ-COMMITTED'`, `SET GLOBAL log_bin_trust_function_creators=1` (service container không truyền được tham số dòng lệnh); `pest --exclude-group=race`.
  - `backend-race`: cùng chuẩn bị, `pest --group=race`, chạy sau `backend-test`, `timeout-minutes` riêng.
  - `frontend`: Node 22, pnpm 9.15.9, `pnpm install --frozen-lockfile`, `lint`, `typecheck`, `test`.
  - `images` (chỉ `push` lên `main`, cần 4 job trên xanh, `permissions: packages: write`): bake backend + worker-video (`<sha>`) và web + admin bản staging (`staging-<sha>`), đẩy GHCR, cache `type=gha`.
- `.github/workflows/release-images.yml` (`workflow_dispatch`, input `sha`): `environment: production` (cần PO duyệt), kiểm image backend `<sha>` đã có, build web + admin `production-<sha>` từ đúng `sha`.
- Không có e2e trong CI V1 (cần backend thật). Không dùng `pull_request_target`. Action ghim theo commit SHA. Một `concurrency` group cho `main`.

### 8.11 Deploy (`infra/production/deploy.sh`, chạy trên server bằng user `vvdeploy` thuộc nhóm `docker`)

`deploy.sh deploy <sha> [--maintenance] [--ack-irreversible] [--worker-video=graceful|skip]`:
1. Kiểm đầu vào: `<sha>` đúng 40 hex; các file `env/*.env` tồn tại và 0600; label `vv.compose-version`, `vv.env` khớp; `docker compose config -q`.
2. `docker compose pull` 4 image theo tag mới.
3. Preflight không đụng hệ thống đang chạy: `docker compose run --rm --no-deps -e VV_OPTIMIZE=0 php php artisan about --only=environment` với env app; tương tự với worker-video. Guard lỗi thì dừng.
4. `backup-db.sh` (trừ khi `--skip-backup`): `mysqldump --single-transaction --routines --triggers --events` vào `/srv/vitaminvui/backups/<thời điểm>-<sha>.sql.gz`.
5. Nếu có migration chờ (`migrate:status --pending` bằng image mới): file chứa dấu `VV-IRREVERSIBLE` mà thiếu `--ack-irreversible` thì dừng. `--maintenance` thì `php artisan down --secret=...` trước. Đặt `SET GLOBAL log_bin_trust_function_creators=1` (root, `docker compose exec mysql`), `docker compose run --rm migrate`, rồi luôn trả về `0` (kể cả khi lỗi, dùng `trap`). Mỗi deploy migrate đúng một lần, trước khi đổi container.
6. `docker compose up -d --no-deps php queue scheduler web admin` với tag mới. PHP gián đoạn vài giây khi recreate (chấp nhận ở V1; deploy ngoài giờ học). Nginx không cần reload vì cổng publish không đổi.
7. Smoke: `/up` 200 qua Nginx (`--resolve 127.0.0.1`), `/api/v1/config/public` có `paid_checkout_enabled=false`, web `/khoa-hoc` 200 (làm ấm jsdom, README frontend), admin `/dang-nhap` 200, `schedule:list` đủ lệnh readiness §5, `migrate:status` không còn chờ.
8. `worker-video`: `graceful` (mặc định) recreate với `stop_grace_period` 3660 s; `skip` để lần sau.
9. Ghi `/opt/vitaminvui/.env` (`PREVIOUS_TAG` ← tag cũ, `IMAGE_TAG` ← mới) và `releases.log` (thời điểm, người chạy, tag cũ, tag mới, có migrate hay không). Dọn image cũ, giữ 3 tag gần nhất.
10. Lỗi ở bước 6–7: script không tự rollback, in lệnh rollback và trạng thái migrate để người vận hành quyết.

`deploy.sh status`: tag đang chạy, tag trước, health từng service. `--local` (chỉ dùng cho smoke): bỏ `pull`, `VV_HOME` trỏ thư mục smoke.

Bật cờ tính năng (readiness §4) KHÔNG cần deploy: sửa `env/app.env`, `docker compose up -d --no-deps php queue scheduler` (container mới tự `optimize`).

### 8.12 Rollback

- `deploy.sh rollback`: đưa mọi service về `PREVIOUS_TAG`, **không chạy migrate**. Trước khi đổi, so bảng `migrations` trong DB với thư mục `database/migrations` của image cũ: DB có migration mà image cũ không biết thì dừng, liệt kê tên, chỉ chạy tiếp khi có `--force-schema-ahead` (người vận hành xác nhận schema mới tương thích ngược).
- Quy ước đã có của dự án giữ nguyên: migration phải tương thích ngược với code bản trước (thêm cột nullable trước, xoá cột ở release sau như T29-1, T36-1), nên rollback code không cần rollback DB trong trường hợp thường.
- Migration không hoàn tác được (`down()` rỗng hoặc mất dữ liệu): dòng đầu file ghi chú `// VV-IRREVERSIBLE: <lý do>`; T35-1 thêm dấu này cho `2026_10_20_110000_backfill_parent_consent_status`. Rollback qua migration loại này = khôi phục backup của bước 4 (mất dữ liệu phát sinh sau đó; người quyết do PO chỉ định, readiness §8). Riêng T29: code cũ chạy lại sẽ ghi `pending`; khi deploy lại bản mới phải chạy lại kiểm/bù `parent_consent_status` (readiness §2). Lần go-live đầu DB trống nên rollback DB = khôi phục bản trống.
- Không dùng `migrate:rollback` tự động; không bao giờ `migrate:fresh|reset`, `db:wipe` trên server.
- Job đã vào queue do code mới tạo có thể lỗi ở code cũ: sau rollback xem `failed_jobs`.

### 8.13 Secret và quyền truy cập

- Server kéo image bằng PAT classic chỉ scope `read:packages` (GHCR chưa nhận đủ fine-grained token), lưu ở `~vvdeploy/.docker/config.json` (0600), hết hạn 90 ngày, token staging và production tách riêng. CI dùng `GITHUB_TOKEN` (`packages: write` chỉ ở job image).
- Người có SSH vào nhóm `docker` trên production tương đương root: chỉ người PO chỉ định.
- Env file 0600, nằm ngoài thư mục mã (không có mã trên host), sao lưu riêng (H9). Kiểm sau build: `docker history` và `grep` trong image không có secret, không có `.env`.

## Hệ quả

Tích cực:
- Một image backend chạy giống nhau ở CI, staging, production; server không cần PHP/Node/composer.
- Mẫu T31 (Nginx, grants, ACL) dùng lại nguyên cấu trúc; worker-video có sandbox mạng đúng ADR-002 và Redis riêng (R2) không tốn thêm server.
- Rollback code = đổi tag, vài giây. Không còn lỗi "Nginx giữ IP php cũ" như ở local (Nginx nối qua cổng publish cố định).

Tiêu cực / rủi ro:
- Frontend phải build riêng cho production (không promote được image staging). Giảm rủi ro: build từ cùng SHA, `--frozen-lockfile`, label `vv.env`, kiểm CSP trong T35-2.
- Một server: MySQL, Redis, PHP, Next, ffmpeg dùng chung CPU/RAM/đĩa. worker-video bị giới hạn 2 CPU/2 GB; đĩa `videolab` phải giám sát (checklist §7). Server hỏng là sập toàn site: phụ thuộc backup ngoài server (H9).
- Tự vận hành MySQL (backup, binlog, nâng cấp vá). DBA review `my.cnf` và `backup-db.sh`.
- Recreate PHP gây gián đoạn vài giây mỗi deploy; deploy có migration phá khoá (migration 30, CHECK COPY) cần `--maintenance` và giờ thấp điểm.
- Nhóm `docker` là quyền root: kiểm soát người SSH.

## Điểm chờ PO

1. **MySQL/Redis**: đồng ý container trên cùng server cho V1 (khuyến nghị, không tốn thêm), hay mua dịch vụ managed (có backup/PITR sẵn nhưng tốn chi phí hằng tháng, phải đặt tại Việt Nam, và worker-video cần đường riêng tới DB). Chuyển sang managed sau này chỉ đổi `DB_HOST` và host trong `grants-users.sql` + `grants-worker.sql`, cộng phần mạng cho worker.
2. **Cấu hình server**: production đề xuất tối thiểu 4 vCPU / 8 GB RAM / SSD theo dung lượng video dự kiến (VideoLab giữ HLS + nguồn 7 ngày trên đĩa); staging 2–4 vCPU / 4–8 GB (giảm `cpus` của worker-video nếu 2 vCPU). Ubuntu 24.04 LTS, amd64.
3. **Nơi lưu backup ngoài server** (H9) và thời hạn giữ.
4. **Có load balancer/Cloudflare proxy trước Nginx không**: quyết `vv-real-ip.conf`, `TRUSTED_PROXIES`, `allow` của `/videolab/library/`.
5. **Ai được SSH/deploy production** và ai duyệt build frontend production (GitHub Environment `production` cần người duyệt).
6. Tài khoản GitHub sở hữu PAT `read:packages` cho server (tài khoản PO hay tài khoản máy riêng).
7. Chấp nhận gián đoạn vài giây mỗi lần deploy, và bảo trì ngắn (`--maintenance`) khi có migration khoá bảng.

## Quyết định PO 2026-10-09
- Duyệt mô hình image GHCR + Docker Compose `vvstack` trên Ubuntu, Nginx ở host, `deploy.sh <sha>` qua SSH.
- MySQL/Redis: container trên cùng server (không managed) cho V1; backup ra ngoài server (nơi lưu: chờ PO).
- **Có Cloudflare proxy** trước Nginx: `vv-real-ip.conf` dùng `real_ip_header CF-Connecting-IP` + `set_real_ip_from` các dải IP Cloudflare (IPv4 + IPv6, có script cập nhật định kỳ từ `https://www.cloudflare.com/ips-v4|ips-v6`); `TRUSTED_PROXIES` = `10.231.10.0/24` (Nginx host → container) — app tin IP do Nginx đã chuẩn hoá; firewall chỉ mở 80/443 cho dải IP Cloudflare (chặn truy cập thẳng origin). `/videolab/library/` (allow) và token video ràng IP phải dùng IP thật sau Cloudflare; host video/TUS cân nhắc DNS-only (không proxy) vì giới hạn kích thước upload 100 MB của Cloudflare gói Free — T35-1 ghi rõ.
- Còn chờ PO: cấu hình server, nơi lưu backup, người được SSH/deploy và duyệt build production, tài khoản sở hữu PAT, chấp nhận gián đoạn vài giây mỗi deploy.

## Quyết định PO 2026-10-10
- **Video (S3 review-T35):** video phát và upload qua **Bunny Stream/CDN** ("đang update qua cdn của bunny"). Production KHÔNG mở host `video.` (VideoLab/TUS) ra Internet → mọi host đi qua Cloudflare proxy, firewall chỉ mở 80/443 cho dải IP Cloudflare; không cần Authenticated Origin Pulls hay IP thứ hai. Hệ quả: `VIDEO_PROVIDER=bunny` ở production, **checklist Bunny C1–C9 (`docs/qa/T37.md`) chạy đạt trên staging là điều kiện go-live**; worker-video/VideoLab không triển khai ở V1 trừ khi PO đổi lại.
- **Cloudflare & pháp chế:** giữ Cloudflare proxy; ghi vào chính sách quyền riêng tư và xin pháp chế xác nhận trước go-live (traffic giải mã tại edge có thể ở nước ngoài; Turnstile nhận IP người dùng). Nếu pháp chế không đồng ý → chuyển DNS-only (cấu hình `vv-real-ip.conf`/firewall đổi được).
- **Backup ngoài server:** CHƯA quyết (đề xuất object storage tại Việt Nam, giữ 30 ngày, GPG). `VV_BACKUP_UPLOAD_CMD` để trống tới khi PO chốt — điều kiện go-live.

## Quyết định PO 2026-10-10 (bổ sung): TẠM BỎ Cloudflare proxy
- PO: "tạm thời bỏ". DNS trỏ thẳng server (DNS-only), Nginx host là lớp ngoài cùng. Thay thế mục "Có Cloudflare proxy" ở "Quyết định PO 2026-10-09" và phần Cloudflare/firewall ở mục 2026-10-10 phía trên.
- Hệ quả cấu hình: `vv-real-ip.conf` KHÔNG tin header `CF-Connecting-IP` (không `set_real_ip_from` dải Cloudflare; IP thật = `$remote_addr`); firewall mở 80/443 cho mọi nguồn, SSH giới hạn theo IP quản trị; `update-cloudflare-ips.sh` giữ lại làm tuỳ chọn khi bật lại proxy (không cron mặc định). `TRUSTED_PROXIES` chỉ dải nội bộ Nginx → container.
- Video vẫn qua Bunny (không mở `video.`). Turnstile (captcha) vẫn dùng — không phụ thuộc proxy. Pháp chế vẫn cần xác nhận Turnstile + Bunny (bên xử lý ở nước ngoài); điểm "proxy giải mã traffic" không còn.
- Bật lại proxy sau này: đổi `vv-real-ip.conf` + firewall theo README mục "Bật Cloudflare proxy", không sửa code ứng dụng.

## Điều chỉnh khi hiện thực T35-1 (đã review chấp nhận; Dev ghi 2026-10-10)
Các điểm khác thiết kế ban đầu ở trên, theo thứ tự bảng §8:
- **§8.11 bước 5 (migrate):** chỉ migrate (và chỉ nâng `log_bin_trust_function_creators`) khi có migration chờ; bảo trì `--maintenance` dùng `artisan down` KHÔNG `--secret` (API không có trang bypass), `up` ngay sau khi đổi container, trước smoke. Đầu mỗi deploy `SET GLOBAL log_bin_trust_function_creators=0` tự chữa nếu lần trước bị SIGKILL.
- **§8.11 ghi tag:** `IMAGE_TAG`/`PREVIOUS_TAG` ghi NGAY TRƯỚC khi đổi container (lỗi ở preflight/backup/migrate không ghi gì); `rollback [<tag>]` nhận tag tường minh.
- **§8.6/§8.3 Redis healthcheck:** không có `env/redis*.env`; user ACL `vv_healthcheck on nopass -@all +ping` trong cả hai file ACL.
- **§8.7 grants:** `grants.sql` tách thành `grants-users.sql` (user + quyền schema app/migrate, chạy một lần trước deploy đầu) và `grants-worker.sql` (quyền bảng `vl_videos`/`failed_jobs`, `deploy.sh` chạy sau mỗi migrate, chỉ khi bật VideoLab). `deploy.sh` kiểm trigger bất biến `audit_logs` (đủ 2, DEFINER tồn tại và còn TRIGGER) sau mỗi migrate.
- **Sao lưu (PO 2026-10-10, thay §8.11 bước 4 `backup-db.sh`):** KHÔNG có script dump/binlog/GPG. Backup = snapshot ổ đĩa tự động hằng ngày của nhà cung cấp (khuyến nghị, giữ >= 7 ngày); `deploy.sh` không đòi cờ xác nhận, chỉ IN nhắc chụp snapshot khi có migration chờ; migration `VV-IRREVERSIBLE` vẫn cần `--ack-irreversible`. Binlog MySQL giữ 7 ngày trên đĩa.
- **Compose:** `cap_add` tối thiểu cho mysql/redis; `mem_limit`/`pids_limit` cho php, queue, scheduler, web, admin, mysql; web/admin `read_only`; buffer pool MySQL qua `VV_MYSQL_BUFFER_POOL`.
- **Digest bắt buộc (S2):** ngoài `--local`, `deploy.sh` đòi `IMAGE_DIGEST_*` (dừng trước `pull`) và kiểm digest sau `pull`; `VV_COSIGN_VERIFY=1` kiểm thêm chữ ký keyless của workflow `main`.
- **VideoLab tuỳ chọn (PO 2026-10-10):** `worker-video`, `redis-video` thuộc profile compose `videolab`; `VV_VIDEOLAB` (mặc định 0) trong `/opt/vitaminvui/.env` điều khiển mọi bước worker của `deploy.sh`; Nginx host `video.` tách ra `nginx/optional/videolab.conf` (không nạp mặc định). Mẫu `.env.production.example`: `VIDEO_PROVIDER=bunny`, `BUNNY_CDN_HOST=cdn.vitaminvui.asia`, `VIDEOLAB_ENABLED=false`.
- **Không Cloudflare proxy (PO 2026-10-10):** `vv-real-ip.conf` không tin header nào (IP thật = `$remote_addr`); mẫu bật proxy `vv-real-ip.cloudflare.conf` + `update-cloudflare-ips.sh` là tuỳ chọn (README mục 12 "Bật Cloudflare proxy").

## Quyết định PO 2026-10-10 (bổ sung): Backup = snapshot nhà cung cấp
- PO: "bỏ phần chạy back up đi cho nhẹ hệ thống" → chọn **snapshot ổ đĩa tự động hằng ngày của nhà cung cấp server** thay cho script backup (dump đêm, binlog mỗi giờ, kiểm khôi phục hằng tuần đều bỏ).
- Giữ: binlog cục bộ 7 ngày (khôi phục tay khi cần); `deploy.sh` đòi `--ack-snapshot` khi có migration chờ (người vận hành xác nhận đã chụp snapshot ngay trước deploy).
- Rủi ro chấp nhận: khôi phục theo cả máy, mất tối đa ~1 ngày dữ liệu, không khôi phục theo thời điểm; snapshot nằm ở hạ tầng nhà cung cấp (chọn nhà cung cấp tại Việt Nam). Điều kiện go-live: bật snapshot tự động (giữ ≥7 ngày) và diễn tập khôi phục 1 lần trên staging.

## Quyết định PO 2026-10-10 (bổ sung 2)
- Thêm cấu hình máy nhỏ `VV_SIZE=small` (1 vCPU / 2 GB, Vultr $12) cho staging và giai đoạn đầu ít người; `large` (4 vCPU / 8 GB) cho production khi đông. Đổi bằng biến, không sửa code.
- Deploy **không** đòi xác nhận snapshot (`--ack-snapshot` bỏ): chỉ cảnh báo khuyến nghị chụp snapshot khi có migration; vẫn giữ `--ack-irreversible`. Snapshot tự động của nhà cung cấp là khuyến nghị, không còn là điều kiện go-live (rủi ro mất dữ liệu khi sự cố do PO chấp nhận).
- Security vòng 3: thêm rate limit/timeout Nginx cho api/admin-api (V3-1), chuẩn hoá header proxy (V3-3), cosign verify mặc định production (V3-4).
- **Cỡ máy `VV_SIZE=small|large` (PO bổ sung 2):** bộ giá trị ở `infra/production/sizes/{small,large}.env` (MySQL pool/connections/mem, FPM children, opcache, số queue, mem_limit, `NODE_OPTIONS`, Redis `maxmemory`); `deploy.sh` nạp trước `.env`. Small = ~1,78 GB tổng trần, VideoLab phải tắt.
- **Chống flood/slowloris (security V3-1):** `limit_req`/`limit_conn`/timeout cho `api.`/`admin-api.` (ngưỡng tính cả lớp ~40 học sinh chung NAT); chuẩn hoá `X-Forwarded-Host`/`X-Real-IP`/`Forwarded` ở khối proxy tới Next (V3-3); `VV_COSIGN_VERIFY=1` mặc định production (V3-4).
- **Rollback (R17):** image tag đích còn trên server thì không pull; thiếu thì bắt buộc digest như deploy.
