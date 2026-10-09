# Cấu hình production/staging (T31 mẫu + T35 đóng gói Docker)

CHỈ LÀ MẪU và công cụ triển khai. Không dùng cho local (local dùng `infra/docker-compose.yml`). Thay mọi `<...>` và `vitaminvui.vn`
bằng giá trị thật trước khi dùng; không commit giá trị thật. Quy trình đầy đủ: `docs/ops/production-checklist.md`. Thiết kế: `docs/adr/ADR-008-dong-goi-trien-khai.md`.

| File | Dùng cho |
|---|---|
| `nginx/conf.d/vitaminvui.conf` | Nginx: host 444, api, admin-api, listener nội bộ :8081 cho SSR, video (VideoLab), static, web + admin (proxy Next.js) |
| `nginx/snippets/vv-api-common.conf` | Phần chung 2 host API và video (chặn file nhạy cảm, PATH_INFO, webhook, front controller) |
| `nginx/snippets/vv-deny.conf` | Chặn file nhạy cảm và `/index.php/` (dùng cho cả host video) |
| `nginx/snippets/vv-videolab-tus.conf` | Cấu hình location TUS (dùng cho `/videolab/tus` và `/videolab/tus/`) |
| `nginx/snippets/vv-tls.conf` | TLS dùng chung |
| `nginx/snippets/vv-real-ip.conf` | IP khách thật = `$remote_addr` (DNS-only, không proxy; PO 2026-10-10). Không tin header nào |
| `nginx/snippets/vv-real-ip.cloudflare.conf` | MẪU khi bật Cloudflare proxy sau này (`CF-Connecting-IP` + dải IP Cloudflare); không include mặc định |
| `scripts/update-cloudflare-ips.sh` | TUỲ CHỌN, chỉ khi bật Cloudflare proxy: cập nhật dải IP trong file real-ip (tự `nginx -t` + reload). KHÔNG cron mặc định |
| `nginx/optional/videolab.conf` | Host `video.` của VideoLab: TUỲ CHỌN, KHÔNG nạp mặc định (V1 dùng Bunny) |
| `supervisor/vitaminvui.conf` | THAM CHIẾU (đã thay bởi compose, ADR-008): queue `default,exports`, `worker-video`, scheduler |
| `mysql/grants-users.sql` | User DB: app, migrate, worker-video (CHỈ tạo user + quyền schema của app/migrate). Chạy một lần trước deploy đầu tiên |
| `mysql/grants-worker.sql` | Quyền bảng `vl_videos`/`failed_jobs` của `vv_worker_video`; `deploy.sh` tự chạy sau mỗi migrate (D4) |
| `redis/users.acl`, `redis/users.video-instance.acl` | Redis ACL: `default` (app), `vv_worker_video` (chỉ queue `video`), `vv_healthcheck` (nopass, chỉ `+ping`, cho healthcheck compose) |
| `.env.production.example` | Biến môi trường app (không có secret thật) |
| `.env.worker-video.example` | Biến môi trường riêng cho worker-video |
| `.env.migrate.example`, `.env.mysql.example`, `compose.env.example` | T35-1: env `migrate` (user `vv_migrate`), `mysql`, biến nội suy compose (`/opt/vitaminvui/.env`) |
| `docker-compose.yml` | T35-1: project `vvstack`: php, queue x2, scheduler, worker-video, migrate (profile `tools`), mysql, redis, redis-video, web, admin |
| `docker-bake.hcl` | T35-1: build `backend`, `worker-video` (dùng `infra/php/Dockerfile.prod`, `infra/worker-video/Dockerfile`) |
| `deploy.sh` | T35-1: `deploy <sha>`, `rollback [<tag>]`, `status`. Backup = snapshot ổ đĩa của nhà cung cấp (không còn script dump, PO 2026-10-10) |
| `mysql/my.cnf`, `redis/redis.conf`, `redis/redis-video.conf` | T35-1: cấu hình MySQL 8.4 và hai instance Redis |
| `systemd/nginx.service.d/after-docker.conf` | T35-1: Nginx host khởi động sau Docker (cần địa chỉ `10.231.10.1`) |
| `smoke/` | T35-1: stack smoke local `vvsmoke` (`smoke.sh`, `check-images.sh`), xem mục cuối |

## Dựng server lần đầu (Ubuntu 24.04, 1 server, ADR-008)

Làm theo thứ tự; staging và production giống hệt nhau (khác giá trị bí mật, tên miền, `VV_ENV`).

1. **Gói hệ thống.** Docker Engine + Compose plugin từ repo chính thức của Docker (không dùng bản `docker.io` của Ubuntu). Nginx **>= 1.25.1** từ repo nginx.org (mẫu dùng `http2 on;`; bản 1.24 của Ubuntu 24.04 không đủ). certbot (webroot `/var/www/acme`). `ufw`.
   **cosign (V3-4):** production đặt `VV_COSIGN_VERIFY=1` nên server PHẢI có `cosign` (ghim phiên bản): `COSIGN_V=<phiên bản>; curl -fsSLO https://github.com/sigstore/cosign/releases/download/v$COSIGN_V/cosign-linux-amd64 && curl -fsSLO https://github.com/sigstore/cosign/releases/download/v$COSIGN_V/cosign_checksums.txt && sha256sum --ignore-missing -c cosign_checksums.txt && sudo install -m 0755 cosign-linux-amd64 /usr/local/bin/cosign` (đối chiếu checksum với trang release trước khi cài). Staging có thể đặt `VV_COSIGN_VERIFY=0`. `--local`/smoke luôn tắt bước này.
   **Server nhỏ 2 GB (`VV_SIZE=small`):** tạo swap 2 GB trước khi cài gì khác: `sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab`; `sysctl vm.swappiness=10`. KHÔNG build image trên server (chỉ `pull`): build cần vài GB RAM.

2. **User.** `vvdeploy` (nhóm `docker`; người này tương đương root, chỉ người PO chỉ định) và `vvapp` (UID/GID 10001, `nologin`) để quyền file khớp user trong image:
   `groupadd -g 10001 vvapp && useradd -u 10001 -g 10001 -M -s /usr/sbin/nologin vvapp`.
3. **Thư mục và quyền (ADR-008 §8.5):**
   ```
   # S8: user chạy deploy.sh phải GHI được /opt/vitaminvui (khoá .deploy.lock.d, .env tạm, releases.log). Nhóm docker đã tương đương root nên
   # việc vvdeploy sửa được script không làm tăng quyền; đừng chmod 777 hay chạy bằng root để "sửa" lỗi quyền.
   install -d -o vvdeploy -g vvdeploy -m 0750 /opt/vitaminvui /opt/vitaminvui/env /opt/vitaminvui/conf
   install -d -o vvapp -g vvapp -m 0751 /var/www/backend/storage/app
   install -d -o vvapp -g vvapp -m 0755 /var/www/backend/storage/app/videolab /var/www/backend/storage/app/videolab/hls /var/www/uploads
   install -d -o vvapp -g vvapp -m 0700 /var/www/backend/storage/app/private
   install -d -o vvapp -g vvapp -m 0755 /var/www/backend/storage/app/purifier
   install -d -o vvapp -g vvapp -m 0750 /var/www/backend/storage/logs
   # S6: dữ liệu MySQL/Redis (phiên, job, PII) chỉ root đọc được. Không còn thư mục backups (backup = snapshot nhà cung cấp).
   install -d -o root -g root -m 0700 /srv/vitaminvui
   install -d -o root -g root -m 0700 /srv/vitaminvui/mysql /srv/vitaminvui/redis
   getent group 999   # GID 999 của image mysql/redis: kiểm không trùng group hệ thống nào có thành viên lạ
   # Chỉ khi bật VideoLab (VV_VIDEOLAB=1): install -d -o root -g root -m 0700 /srv/vitaminvui/redis-video
   ```
   **Kiểm quyền bằng đúng user vận hành** (không root, không user cá nhân): `sudo -u vvdeploy test -w /opt/vitaminvui && echo ok`; chạy `sudo -u vvdeploy /opt/vitaminvui/deploy.sh status` (phải qua, không lỗi quyền khoá/`.env`/`releases.log`); `sudo -u nobody ls /srv/vitaminvui/redis` phải bị từ chối. `vvdeploy` chỉ cần ghi được `/opt/vitaminvui` (hết nhu cầu truy cập `/srv/vitaminvui`). Smoke trên máy dev chạy bằng một user nên KHÔNG bắt được lỗi quyền kiểu này.
   Trên host KHÔNG có mã và KHÔNG tạo `/var/www/backend/public` (Nginx chỉ dùng `fastcgi_pass`, đường dẫn SCRIPT_FILENAME là đường dẫn trong container). `storage/framework` không mount.
4. **Copy vào `/opt/vitaminvui/`** (chủ `vvdeploy`): `docker-compose.yml`, `deploy.sh` (0750); `conf/my.cnf` (0644, không có secret), `conf/redis.conf`; `compose.env.example` thành `.env` (điền `VV_ENV`, `VV_REGISTRY`; staging đặt `VV_MYSQL_BUFFER_POOL=1G`, `VV_MYSQL_MEM_LIMIT=2g`). `deploy.sh`, `docker-compose.yml` và image phải cùng một release (label `vv.compose-version` được kiểm). **VideoLab TẮT mặc định** (`VV_VIDEOLAB=0`): chỉ khi bật mới thêm `conf/redis-video.conf`, `conf/users.video-instance.acl`, `conf/grants-worker.sql`, `env/worker-video.env` (mục 12).
5. **Env (0600, chủ `vvdeploy`)** trong `/opt/vitaminvui/env/`: `app.env`, `migrate.env`, `mysql.env`, `web.env`, `admin.env` từ các file `*.example` (thêm `worker-video.env` chỉ khi bật VideoLab). `app.env` V1: `VIDEO_PROVIDER=bunny`, `VIDEOLAB_ENABLED=false`, điền 5 biến `BUNNY_*` (guard không cho khởi động nếu thiếu). Mật khẩu/khoá sinh bằng `openssl rand -hex 32` (hoặc `-base64 32`). **Giá trị không được chứa ký tự `$`** (compose nội suy `env_file`; dùng hex/base64). Mỗi chú thích nằm dòng riêng (không `KEY=value # ghi chú`). `MYSQL_ROOT_HOST` giữ `localhost` (không `%`). Redis không cần env file: healthcheck dùng user `vv_healthcheck` trong ACL.
6. **Redis ACL** (không có script sinh, làm tay): mật khẩu băm `printf %s "$PW" | sha256sum | cut -d' ' -f1`.
   `conf/users.acl` = hai dòng `user default ...` và `user vv_healthcheck ...` của `redis/users.acl` (KHÔNG có dòng worker) với `<SHA256_MAT_KHAU_APP>` thay bằng băm của `REDIS_PASSWORD`. `chown 999:999` + `chmod 0400` cho `conf/users.acl` và `conf/redis.conf` (user `redis` trong container là UID 999). Chỉ khi bật VideoLab: `conf/users.video-instance.acl` = `redis/users.video-instance.acl` (giữ cả dòng `vv_healthcheck`) với `<SHA256_MAT_KHAU_APP>` (băm `REDIS_VIDEO_PASSWORD`), `<SHA256_MAT_KHAU_WORKER>` (băm mật khẩu trong `worker-video.env`) và `<PREFIX>` = `REDIS_PREFIX`; cùng quyền 0400 owner 999.
7. **Docker và mạng.** `docker login ghcr.io` bằng user `vvdeploy` (PAT chỉ `read:packages`, lưu `~vvdeploy/.docker/config.json` 0600). Nếu 3 dải `10.231.10-12.0/24` trùng mạng của nhà cung cấp thì đổi `VV_SUBNET_*` trong `.env` và mọi chỗ liên quan (xem `compose.env.example`).
8. **Dựng dữ liệu** (lần đầu, trước khi deploy, để cấp user ở bước 9): `cd /opt/vitaminvui && IMAGE_TAG=bootstrap ./deploy.sh compose up -d --wait mysql redis` (`IMAGE_TAG` chỉ để compose nội suy được; `deploy.sh compose` nạp đúng cỡ máy). Mạng `front` (chứa `10.231.10.1`) chỉ có sau khi `deploy.sh` dựng web/admin lần đầu; Nginx host vẫn khởi động được nhờ `ip_nonlocal_bind=1` ở bước 10. Lưu ý: `mysql` vẫn gắn vào mạng `video` (rỗng khi VideoLab tắt) vì compose không gắn mạng có điều kiện theo profile; không có container nào khác trong mạng đó nên không có rủi ro.
9. **MySQL user (MỘT lần).** Điền `mysql/grants-users.sql`: `<DB>=vitaminvui`, `<APP_HOST>=<MIGRATE_HOST>=10.231.11.%`, `<WORKER_HOST>=10.231.12.%` (chỉ khi bật VideoLab; khớp `VV_WORKER_DB_HOST` trong `.env`; mặc định KHÔNG tạo user worker, bỏ tiền tố `-- VIDEOLAB: ` ở dòng `CREATE USER` nếu bật) và 3 mật khẩu (`<MAT_KHAU_APP>` = `DB_PASSWORD` của `app.env`, `<MAT_KHAU_MIGRATE>` = `migrate.env`, `<MAT_KHAU_WORKER>` = `worker-video.env`). Điền trong `/dev/shm` hoặc qua stdin, không để file có mật khẩu trên đĩa: `sed ... grants-users.sql | docker compose -p vvstack exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'`. Chỉ khi bật VideoLab: quyền bảng của `vv_worker_video` KHÔNG cấp ở đây (bảng chưa có): `deploy.sh` chạy `conf/grants-worker.sql` sau mỗi migrate rồi tự kiểm worker chỉ có `vl_videos` + `failed_jobs`. Đổi mật khẩu về sau: `ALTER USER ... IDENTIFIED BY` (`CREATE USER IF NOT EXISTS` không đổi mật khẩu). **Không xoá, đổi tên (`RENAME USER`), đổi host hay thu hồi `TRIGGER` của `vv_migrate`**: trigger bất biến `audit_logs` mang `DEFINER=vv_migrate@'10.231.11.%'` (xoá/đổi tên -> mọi UPDATE/DELETE `audit_logs` lỗi 1449; thu hồi TRIGGER -> lỗi 1142 và `audit:purge` hỏng). Đổi dải mạng Docker = đổi host user này và tạo lại trigger. `deploy.sh` và `deploy.sh status` kiểm: đủ 2 trigger, DEFINER còn tồn tại và còn quyền TRIGGER.
10. **Nginx host.** Copy `nginx/` (điền `<IP_NOI_BO_NGINX>=10.231.10.1`, `<IP_NEXT_SERVER>=10.231.10.0/24`, `<IP_MONITOR_LB>`: IP giám sát và `127.0.0.1`, vì `deploy.sh` smoke bằng `curl --resolve api.<domain>:443:127.0.0.1`). Cài `systemd/nginx.service.d/after-docker.conf` và `net.ipv4.ip_nonlocal_bind=1` (phòng Nginx lên trước mạng `front`). KHÔNG cài cron `update-cloudflare-ips.sh` (chỉ khi bật Cloudflare proxy, mục 12).
11. **Firewall (ufw).** `ufw default deny incoming`; SSH (22) chỉ từ IP quản trị (`ufw allow from <IP_QUAN_TRI> to any port 22 proto tcp`); 80 và 443 mở cho MỌI nguồn (DNS-only, Nginx host là lớp ngoài cùng; PO 2026-10-10). Docker publish cổng vượt qua ufw: compose chỉ publish `127.0.0.1:9000/3000/3001`, MySQL/Redis không publish. Cho phép `10.231.10.0/24` tới `10.231.10.1:8081` (SSR). Kiểm: `ss -lntp` chỉ có Nginx (80/443 và `10.231.10.1:8081`) và sshd; từ máy ngoài chỉ chạm được 80/443/22(IP quản trị).
   **Docker Engine >= 28** (S4: cổng publish loopback trên bản cũ hơn có lỗi chạm được từ máy cùng L2). Server dành riêng: chỉ root và `vvdeploy`, không cài dịch vụ khác (mọi process cục bộ nối được `127.0.0.1:9000` FastCGI không xác thực).
   **Chỉ khi bật VideoLab, mạng `video` (S5):** `internal: true` chặn chiều ra Internet nhưng bridge vẫn mang IP gateway `10.231.12.1` trên host; chặn worker tới host: `iptables -I DOCKER-USER -s 10.231.12.0/24 -j DROP` (và ufw `deny in on <br-video>`; tên bridge: `docker network inspect vvstack_video -f '{{.Id}}'` lấy 12 ký tự đầu, `br-<id>`). Kiểm trên staging, từ trong worker-video: `php -r 'var_dump(@fsockopen("10.231.12.1",22,$e,$s,2), @fsockopen("10.231.12.1",443,$e,$s,2), @fsockopen("10.231.10.1",8081,$e,$s,2));'` phải ra ba lần `false`.

12. **DNS-only, video và origin (quyết định PO 2026-10-10, ADR-008).** TẠM BỎ Cloudflare proxy: DNS trỏ thẳng server, Nginx host là lớp ngoài cùng. `vv-real-ip.conf` KHÔNG tin `CF-Connecting-IP`/`X-Forwarded-For` và không có `set_real_ip_from` (IP thật = `$remote_addr`); `TRUSTED_PROXIES` chỉ dải mạng Docker nội bộ. Bảo vệ lớp ngoài do Nginx (`limit_req`, host lạ trả 444), Turnstile (không phụ thuộc proxy) và firewall (mục 11). **Video V1 qua Bunny Stream/CDN** (`VIDEO_PROVIDER=bunny`): production KHÔNG mở host `video.`, KHÔNG chạy VideoLab/worker-video/redis-video, KHÔNG có bản ghi DNS `video.` và không có `video.` trong `server_name` của Nginx. **Điều kiện go-live: checklist Bunny C1–C9 (`docs/qa/T37.md`) đạt trên staging.** Pháp chế vẫn cần xác nhận Turnstile và Bunny (bên xử lý ở nước ngoài); điểm "proxy giải mã traffic" không còn.
    **Bật Cloudflare proxy (tuỳ chọn sau, không sửa code ứng dụng):** (1) bật proxy (đám mây cam) cho các bản ghi; (2) trên server `cp nginx/snippets/vv-real-ip.cloudflare.conf /etc/nginx/snippets/vv-real-ip.conf`, chạy `scripts/update-cloudflare-ips.sh` một lần (cron hàng tuần) để làm mới dải IP, `nginx -t && nginx -s reload`; (3) firewall: 80/443 chỉ cho dải Cloudflare (IPv4+IPv6, lấy từ `https://www.cloudflare.com/ips-v4|ips-v6`, tự làm và tự cập nhật; nếu không, kẻ ngoài đi thẳng origin và tự đặt `CF-Connecting-IP` để giả IP) và/hoặc Authenticated Origin Pulls (mTLS); (4) Cloudflare đặt SSL mode Full (strict); (5) kiểm: `curl --resolve api.<domain>:443:<IP origin>` từ máy ngoài Cloudflare bị từ chối, qua Cloudflare 200, và log Nginx hiện IP khách thật; (6) báo pháp chế (traffic giải mã tại edge); (7) `/videolab/library/` và token CDN ràng IP (chỉ khi bật VideoLab) dùng IP thật sau proxy.
    **Bật lại VideoLab (chỉ khi PO đổi quyết định):** `VV_VIDEOLAB=1` trong `.env`; `VIDEO_PROVIDER=internal` (hoặc `bunny,internal`), `VIDEOLAB_ENABLED=true` và các khoá `VIDEOLAB_*`, `REDIS_VIDEO_*` trong `app.env`; thêm các file ở mục 4/5/6/9; copy `nginx/optional/videolab.conf` vào `conf.d`, thêm `video.<domain>` vào `server_name` của server :80 và chứng chỉ; điền `<IP_APP_SERVER>` (access log staging). Host này DNS-only như các host khác nên không có giới hạn upload 100 MB của Cloudflare. Chuyển từ bật sang tắt thì `docker compose -p vvstack stop worker-video redis-video && docker compose -p vvstack rm -f worker-video redis-video` (KHÔNG dùng lệnh "down").

13. **Backup = snapshot của nhà cung cấp (PO 2026-10-10): KHUYẾN NGHỊ, không còn là điều kiện go-live và `deploy.sh` không đòi cờ xác nhận.** Không có script dump/binlog/GPG. Nên bật **snapshot ổ đĩa tự động hằng ngày**, giữ **>= 7 ngày**, trong bảng điều khiển nhà cung cấp (cả ổ chứa `/srv/vitaminvui` và `/var/www/*`), và chụp tay trước deploy có migration (`deploy.sh` chỉ IN nhắc khi có migration chờ). Nên diễn tập khôi phục snapshot một lần trên staging (ghi ngày, người làm, thời gian; sau đó `deploy.sh status` thoát 0 và `SHOW TRIGGERS LIKE 'audit_logs'` đủ 2). **Rủi ro nếu không có snapshot hoặc khi dùng nó:** khôi phục cả máy (không từng bảng), mất tối đa ~1 ngày dữ liệu, không khôi phục theo thời điểm; snapshot cùng nhà cung cấp với server. **Bảo mật snapshot (V3-5):** bật MFA cho mọi tài khoản nhà cung cấp, dùng user/API key quyền tối thiểu, hạn chế ai được xoá snapshot hoặc tạo server từ snapshot (snapshot chứa cả `env/*.env`), bật khoá chống xoá nếu có; xác nhận snapshot mã hoá lúc lưu và đặt tại Việt Nam (pháp chế). Binlog MySQL vẫn giữ 7 ngày TRÊN ĐĨA (`my.cnf`) để khôi phục tay khi cần. Sau khôi phục từ snapshot chạy lại các lệnh purge (`users:purge-unverified`, yêu cầu xoá dữ liệu): dữ liệu đã xoá có thể sống lại. Cron duy nhất còn lại: xoay slow log hằng tuần: `20 3 * * 0 cd /opt/vitaminvui && ./deploy.sh compose exec -T mysql sh -c 'mv /var/lib/mysql/slow.log /var/lib/mysql/slow.log.1; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "FLUSH SLOW LOGS"'`.
14. **Deploy.** Trước deploy ĐẦU TIÊN dán các dòng `IMAGE_DIGEST_BACKEND`, `IMAGE_DIGEST_WEB`, `IMAGE_DIGEST_ADMIN` (từ `images-*.lock` của run CI) vào `/opt/vitaminvui/.env`: thiếu thì `deploy.sh` dừng (S2). Rồi `ssh vvdeploy@server`, `cd /opt/vitaminvui && ./deploy.sh deploy <sha40> --ack-irreversible` (lần go-live đầu DB trống: cần `--ack-irreversible` vì migration backfill T29 nằm trong danh sách chờ, an toàn vì bảng trống). Khuyến nghị chụp snapshot trước. Không cần bước nào sau deploy đầu tiên.

## Triển khai hằng ngày

```
./deploy.sh deploy <sha40> [--maintenance] [--ack-irreversible] [--worker-video=graceful|skip]
./deploy.sh rollback [<tag>] [--force-schema-ahead] [--worker-video=graceful|skip]
./deploy.sh status
```
- `deploy`: kiểm env file (0600) và nhãn image, `pull`, preflight guard bằng env app và env worker (chưa đổi gì nếu lỗi), migrate MỘT lần bằng `vv_migrate` (`log_bin_trust_function_creators` bật ngay trước, luôn về 0 bằng `trap`, kể cả khi lỗi), đổi `php queue scheduler web admin`, smoke (`/up`, `config/public`, web, admin, `schedule:list`, không còn migration chờ), cuối cùng `worker-video` (dừng nhẹ chờ job ffmpeg, tối đa 3660 s; `--worker-video=skip` để lần sau). Lỗi sau khi đổi container: KHÔNG tự rollback, script in lệnh rollback.
- Không bao giờ dùng lệnh "down" của compose (xoá mạng; Nginx mất `10.231.10.1`).
- `--maintenance`: `php artisan down` trước migrate (driver bảo trì `cache` dùng chung mọi container), `up` ngay sau khi đổi container và trước smoke. Dùng khi migration khoá bảng (CHECK COPY, migration 30) và giờ thấp điểm. Không dùng `--secret` (API không có trang bypass).
- Bật cờ tính năng không cần deploy: sửa `env/app.env`, `docker compose -p vvstack up -d --no-deps php queue scheduler` (container mới tự `optimize`).
- Rollback đưa mọi service về `PREVIOUS_TAG`, KHÔNG migrate; dừng nếu DB có migration mà image cũ không biết (cần `--force-schema-ahead`). Migration mang dòng `// VV-IRREVERSIBLE: <lý do>` (hiện có: backfill T29 `2026_10_20_110000`): rollback qua nó = khôi phục snapshot, mất dữ liệu phát sinh sau đó.
- Có migration chờ thì `deploy.sh` chỉ IN nhắc chụp snapshot nhà cung cấp (khuyến nghị, không cờ bắt buộc). Migration `VV-IRREVERSIBLE` vẫn cần `--ack-irreversible`. Khôi phục = khôi phục snapshot (mục 13).
- `rollback`: image của tag đích còn trên server thì KHÔNG pull lại; thiếu thì bắt buộc `IMAGE_DIGEST_*` của tag đó (như deploy) rồi mới pull (R17). `./deploy.sh compose <đối số>` chạy docker compose với đúng project, env và cỡ máy (vd. `./deploy.sh compose ps`); dùng nó thay cho gọi `docker compose` tay.
- Giữ 3 tag image gần nhất mỗi repository; `releases.log` ghi mỗi lần. `.env` ghi `IMAGE_TAG`/`PREVIOUS_TAG` NGAY TRƯỚC khi đổi container (lỗi ở các bước trước đó, như preflight, kiểm cờ, migrate, không ghi gì); vì vậy `rollback` sau smoke lỗi về đúng bản cũ.
- **Digest BẮT BUỘC** (S2): trước mỗi deploy dán các dòng `IMAGE_DIGEST_BACKEND`, `IMAGE_DIGEST_WEB`, `IMAGE_DIGEST_ADMIN` (thêm `IMAGE_DIGEST_WORKER_VIDEO` nếu bật VideoLab) từ `images-*.lock` của run CI vào `/opt/vitaminvui/.env` (lấy qua kênh khác server, `docs/ops/ci.md`). Thiếu hoặc sai định dạng thì `deploy.sh` dừng TRƯỚC `pull`; image kéo về lệch digest thì dừng trước khi đổi gì. `--local` (smoke) được miễn. Đặt `VV_COSIGN_VERIFY=1` (cần `cosign` trên server) để còn kiểm chữ ký keyless của workflow `main` cho từng image.
- `rollback` không tham số về `PREVIOUS_TAG`. Sau một deploy lỗi rồi deploy bản sửa thành công, `PREVIOUS_TAG` trỏ vào bản lỗi (xem `releases.log`, dòng `smoke-failed`): muốn quay về bản tốt hơn thì `rollback <tag>` tường minh.
- Khoá `.deploy.lock.d` lưu pid; `deploy.sh` tự gỡ khoá của tiến trình đã chết (khoá của tiến trình còn sống thì từ chối).
- `deploy.sh status` thoát != 0 khi `log_bin_trust_function_creators` khác 0 hoặc trigger `audit_logs` có vấn đề (cho giám sát).

### Hai cỡ máy (`VV_SIZE` trong `/opt/vitaminvui/.env`; bộ giá trị ở `sizes/large.env`, `sizes/small.env`)

| | `large` (mặc định, 4 vCPU / 8 GB) | `small` (1 vCPU / 2 GB + swap 2 GB) |
|---|---|---|
| MySQL | pool 2G, `max_connections` 100, redo 1G, `mem_limit` 3g | pool 256M, `max_connections` 50, redo 512M, `performance_schema` tắt, `mem_limit` 540m |
| PHP-FPM | `pm.max_children` 20 (start 4, spare 2-6), opcache 192 MB, 2g | `pm.max_children` 5 (start 2, spare 1-3), opcache 64 MB, 380m |
| queue | 2 bản x 768m | 1 bản x 160m |
| scheduler | 512m | 128m |
| web / admin | 1g / 768m | 256m / 192m, `NODE_OPTIONS=--max-old-space-size=160` / `112` |
| redis | không giới hạn | `maxmemory` 64mb, 128m |
| Tổng trần | ~9,3 GB | ~1,78 GB (+ swap) |
| VideoLab | tuỳ chọn | KHÔNG (không đủ RAM) |

Máy small chạy sát trần: đo `docker stats` ở staging, theo dõi swap (`vmstat`), và coi 4 GB là mức an toàn hơn cho tải thật. Chỉnh riêng một giá trị bằng cách đặt lại trong `.env` (thắng `sizes/*.env`). Smoke kiểm giá trị thật trong container: `smoke.sh --with-frontend --size small`.

### Tổng giới hạn bộ nhớ (R14) trên server 8 GB, VideoLab TẮT

| Service | `mem_limit` |
|---|---|
| mysql (buffer pool 2G) | 3g |
| php-fpm | 2g |
| queue x2 | 2 x 768m |
| scheduler | 512m |
| web | 1g |
| admin | 768m |
| redis (không giới hạn; đặt `maxmemory`) | ~0,3g thực tế |
| Tổng trần | ~9,3 GB |

Trần, không phải đặt chỗ: mức dùng thật nhỏ hơn nhiều. Phải đối chiếu `docker stats` trên staging (tải thật) trước khi chốt và đặt `maxmemory` cho Redis chính. Bật VideoLab thêm worker-video 2g + redis-video 256m: server 8 GB không đủ trần, giảm `cpus`/`mem_limit` hoặc dùng server lớn hơn.

## Smoke local (`smoke/`, project `vvsmoke`, KHÔNG liên quan stack dev `vitaminvui`)

```
infra/production/smoke/smoke.sh                    # build image nếu thiếu, dựng stack giả, chạy toàn bộ kiểm, dọn khi xong nếu đạt
infra/production/smoke/smoke.sh --with-frontend    # thêm web/admin (T35-2); --keep giữ stack để kiểm tay; --build build lại image
infra/production/smoke/smoke.sh --with-frontend --size small   # cỡ máy small 2 GB, kiểm giá trị thật trong container
infra/production/smoke/smoke.sh --with-videolab    # bật VideoLab + worker-video (sandbox, ACL); mặc định TẮT như production V1 (Bunny)
infra/production/smoke/smoke.sh down               # dọn container, volume, network, image tạm, thư mục .generated
infra/production/smoke/check-images.sh [tag]       # chỉ kiểm image đã build
```
Env giả sinh bởi `smoke/make-env.sh` (secret `openssl rand`, không đọc `backend/.env`). Tên miền smoke `*.vvsmoke.internal` (không dùng `.test`/`.example`: `ProductionConfigGuard` chặn các TLD đó trong `SANCTUM_STATEFUL_DOMAINS`). Cổng host: `127.0.0.1:18080` (nginx-smoke), `13000/13001` (web/admin). Subnet `10.231.20-22.0/24`. Không có cổng nào trùng dev.
