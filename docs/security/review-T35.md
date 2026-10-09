# SECURITY: T35-1 + T35-2 "Đóng gói và triển khai production (ADR-008)" | 2026-10-10
**Kết luận:** PASS có điều kiện

Không có Critical hay High. Có 3 Medium, 6 Low và một số Info. Lên staging được. Trước khi go-live production phải xong:
- S1: bắt buộc mã hoá bản sao lưu trước khi đẩy ra ngoài server.
- S2: ghim image theo digest (hoặc kiểm chữ ký). Làm ở T35-3.
- S3: PO quyết cách chặn truy cập thẳng vào origin khi host `video.` để DNS-only, rồi dev làm theo.
- Các mục "kiểm trên staging" ở cuối file. Smoke mới chạy trên Docker Desktop macOS, nên chưa kiểm được ufw, bind mount trên Linux và hành vi mạng của Docker Engine thật.

**Phạm vi:** working tree chưa commit, gồm:
- Backend: `infra/php/Dockerfile.prod`, `Dockerfile.prod.dockerignore`, `infra/php/prod/*`.
- Production: `infra/production/{docker-compose.yml, deploy.sh, backup-db.sh, docker-bake.hcl, *.example, mysql/*, redis/*, nginx/*, systemd/*, scripts/*, smoke/*}`.
- Frontend: `frontend/{Dockerfile, .dockerignore, docker-bake.hcl}`, `apps/*/.env.production.example`, `apps/*/next.config.ts`, `tsconfig.build.json`.

Đối chiếu với ADR-008 (kể cả "Quyết định PO 2026-10-09"), `docs/review/T35-1.md`, `docs/review/T35-2.md` và tasks.md mục T35.

**Đã kiểm thực tế:**
- Build tạm image backend (`vvsec-tmp/vitaminvui-backend:t35sec`, dùng cache), inspect xong rồi xoá. Không đụng project `vitaminvui` (dev).
- Image web/admin không build lại: reviewer T35-2 đã build và kiểm. Mục này chỉ phân tích tĩnh.

## Điểm tốt (đã xác minh)
- **Secret trong image:**
  - `Config.Env` của image backend không có `APP_ENV`, `APP_KEY` hay secret nào. Chỉ có `FPM_MAX_CHILDREN` và biến của image gốc.
  - `docker history --no-trunc` chỉ có `APP_ENV=local` và `APP_KEY=base64:AAAA…AAA=` (khoá giả toàn chữ A) nằm trong lệnh `RUN package:discover`. Khoá này không phải secret, không vào ENV/ARG và không được dùng lúc chạy, vì guard và `env_file` cấp khoá thật. Xem S10.
  - Không có `.env*`. Không có `tests/`, `phpunit*.xml`, `composer`, `git`.
  - `.dockerignore` backend là allowlist (`*`, `!backend`, `!infra/php/prod`) và loại `.env*`, `*.pem`, `*.key`, `.claude`.
  - Frontend: không có ARG/ENV cho biến server-only. `INTERNAL_API_TOKEN` chỉ được đọc trong `apps/web/env.server.ts` và `lib/api.server.ts` (có `import "server-only"`). Không bật `productionBrowserSourceMaps`.
- **Non-root, bất biến:**
  - Backend chạy UID 10001. Mã (`app/`, `config/`, `vendor/`, `public/`) thuộc root, UID 10001 không ghi được (thử `touch` bị từ chối). Chỉ `storage/` và `bootstrap/cache` ghi được.
  - Mọi service đều có `cap_drop: [ALL]` và `no-new-privileges`. MySQL/Redis chỉ `cap_add` tối thiểu cho entrypoint.
  - worker-video có `read_only`, `tmpfs /tmp`, `init`, giới hạn CPU/RAM/PID.
- **Mạng:**
  - Chỉ publish `127.0.0.1:9000/3000/3001`. MySQL/Redis không publish.
  - Mạng `video` có `internal: true`. Worker chỉ thấy `mysql` và `redis-video`.
  - `MYSQL_ROOT_HOST=localhost`: root chỉ vào được bằng socket trong container.
  - `local_infile=OFF`, `skip-name-resolve`.
  - Redis có ACL băm SHA-256, `protected-mode yes`. Redis 7.4 mặc định khoá `CONFIG SET dir`, `DEBUG`, `MODULE` (`enable-protected-configs`, `enable-debug-command` và `enable-module-command` mặc định đều tắt).
- **Header nội bộ:**
  - Mọi host công khai đều xoá `X-Internal-Token`/`X-Client-IP` (server web/admin xoá bằng `proxy_set_header … ""`, snippet API/video xoá bằng `fastcgi_param … ""`). Header chỉ đi qua được listener `10.231.10.1:8081`, nơi có `allow 10.231.10.0/24; deny all`, `limit_except GET HEAD` và ép Host bằng `fastcgi_param HTTP_HOST`.
  - `TRUSTED_PROXIES=10.231.10.0/24`. Vì PHP nhận `REMOTE_ADDR` qua FastCGI (IP thật sau `real_ip`), khách Internet không lọt vào dải tin cậy.
- **Log:**
  - php-fpm dùng `access.format` với `%r` (đã kiểm `php-fpm -tt`: URI không kèm query) và bỏ `/fpm-ping` khỏi log. Không bật `pm.status_path`.
  - Nginx dùng format `vv_noargs` cho webhook Bunny và trang huỷ nhận thư.
  - `my.cnf` tắt slow log (log này chứa tham số truy vấn).
- **deploy.sh:**
  - Mọi lệnh có `set -euo pipefail`. `<sha>` phải khớp `^[0-9a-f]{40}$`; với `--local` thì khớp `^[A-Za-z0-9._-]{1,40}$`. Nhãn backup khớp `^[A-Za-z0-9._-]{1,64}$`, nên không chèn lệnh hay đường dẫn được.
  - Mật khẩu root MySQL không đi qua script: container tự mở rộng `$MYSQL_ROOT_PASSWORD` trong `sh -c '…'`, còn SQL truyền qua `-e VV_SQL`.
  - Không có `set -x`, không in `compose config`. `trap` luôn trả `log_bin_trust_function_creators` về 0.
  - Script kiểm các file `env/*.env` có quyền 0600, kiểm label `vv.compose-version`/`vv.env`/`revision`, và không có `compose down`.
- **Backup:** dùng `umask 077`, thư mục 0700, ghi ra file `.partial` rồi `mv`, kiểm "Dump completed". Hỗ trợ gpg (nhưng chưa bắt buộc, xem S1).
- **Supply chain:**
  - Base image ghim digest: php 8.3.35, composer 2.10.3, node 22 bookworm-slim, mysql 8.4.11, redis 7.4.11, nginx-smoke.
  - Cài `composer install --no-dev --no-scripts` theo lock, `pnpm install --frozen-lockfile`, pnpm 9.15.9 ghim qua corepack.
- **Script cập nhật dải IP Cloudflare:** chỉ dùng HTTPS, kiểm từng dòng CIDR, đặt độ dài prefix tối thiểu, chạy `nginx -t` và tự khôi phục bản cũ nếu lỗi.

## Phát hiện

### S1 [Medium] Bản sao lưu DB (toàn bộ PII) có thể bị đẩy ra ngoài server ở dạng không mã hoá — OWASP A02
- **Vị trí:** `infra/production/backup-db.sh:47-67`, `infra/production/README.md:57` (bước 13).
- **Mô tả và tác động:**
  - Mã hoá chỉ bật khi đặt `VV_BACKUP_GPG_RECIPIENT`. Nếu vận hành đặt `VV_BACKUP_UPLOAD_CMD` mà quên biến kia, script vẫn đẩy `.sql.gz` thô ra nơi lưu ngoài server.
  - Bản dump chứa họ tên, email, SĐT, thông tin phụ huynh/học sinh (có trẻ vị thành niên), đơn hàng và hash mật khẩu. Lộ bucket hoặc tài khoản lưu trữ là lộ dữ liệu hàng loạt.
  - Bản trên server (14 ngày) cũng không mã hoá. Chấp nhận được nếu server bị kiểm soát chặt, nhưng nên mã hoá luôn để một file không thành điểm lộ.
  - Thêm hai điểm phụ:
    - `--trust-model always` bỏ qua kiểm khoá. Nếu recipient là tên hoặc email, gpg có thể chọn nhầm khoá.
    - Nhánh gpg không kiểm "Dump completed", nên bản dump bị cắt vẫn được coi là thành công.
- **Cách sửa:**
  ```bash
  if [[ -n "${VV_BACKUP_UPLOAD_CMD:-}" && -z "${VV_BACKUP_GPG_RECIPIENT:-}" ]]; then
      echo "backup-db: từ chối đẩy bản sao KHÔNG mã hoá ra ngoài server (đặt VV_BACKUP_GPG_RECIPIENT)" >&2; exit 1
  fi
  # recipient phải là fingerprint đầy đủ 40 hex
  [[ -z "${VV_BACKUP_GPG_RECIPIENT:-}" || "$VV_BACKUP_GPG_RECIPIENT" =~ ^[0-9A-F]{40}$ ]] || { echo "backup-db: recipient phải là fingerprint" >&2; exit 2; }
  # nhánh gpg: tee ra kiểm "Dump completed" trước khi mã hoá
  dump | tee >(tail -n 3 | grep -q "Dump completed" || echo TRUNCATED > "$tmp.flag") | gzip -c | gpg ... --output "$tmp"
  ```
  - Khoá bí mật để giải mã KHÔNG nằm trên server.
  - README cần ghi quy trình thử khôi phục bản `.gpg` mỗi quý.
- **Kiểm chứng:**
  - Smoke chạy `VV_BACKUP_UPLOAD_CMD=true backup-db.sh` khi không có recipient: phải thoát khác 0.
  - Chạy với recipient giả (fingerprint của khoá test): ra file `.sql.gz.gpg` quyền 0600, `gpg -d` khôi phục được.

### S2 [Medium] Server kéo image theo tag (đổi được), không ghim digest hay kiểm chữ ký — OWASP A08
- **Vị trí:**
  - `infra/production/docker-compose.yml:35,106,190,209` (`image: …:${IMAGE_TAG}`).
  - `deploy.sh:317` (`dc pull`) và `deploy.sh:168-171` (kiểm label `revision`).
- **Mô tả và tác động:**
  - Tag `<sha>` trên GHCR ghi đè được bởi bất kỳ ai có quyền `packages: write`: workflow trên `main`, collaborator, token CI bị lộ.
  - Label `org.opencontainers.image.revision` do chính người build đặt, nên không chứng minh được nguồn gốc.
  - Hệ quả: image độc hại chạy cùng `app.env` (APP_KEY, mật khẩu DB, khoá VideoLab, SMTP), tức chiếm toàn bộ dữ liệu.
  - Rollback cũng tin image đang có trên server theo tag.
- **Cách sửa (làm ở T35-3, trước go-live):**
  1. CI ghi lại digest của mỗi image sau `--push` (`docker buildx imagetools inspect --format '{{json .Manifest.Digest}}'`) vào artifact hoặc release note của commit đó.
  2. `deploy.sh deploy <sha>` nhận thêm file hoặc tham số chứa 4 digest (đều khớp `^sha256:[0-9a-f]{64}$`). Compose dùng `image: …@${BACKEND_DIGEST}` (hoặc `tag@digest`). Hoặc sau `pull`, so `docker image inspect --format '{{index .RepoDigests 0}}'` với digest mong đợi, lệch thì dừng.
  3. Tốt hơn nữa: ký bằng `cosign` keyless (OIDC GitHub) trong CI, `deploy.sh` chạy `cosign verify --certificate-identity …/release-images.yml@refs/heads/main` trước khi `up`.
  4. Ghi digest vào `releases.log` để rollback đúng bản.
  - Đồng thời: bật "immutable tags" nếu GHCR hỗ trợ, và áp branch protection cho `main` và các workflow có `packages: write`.
- **Kiểm chứng:** smoke dùng digest sai thì `deploy.sh` phải dừng trước bước `up`. `releases.log` phải có digest.

### S3 [Medium] Đi thẳng vào origin, bỏ qua Cloudflare, khi host `video.` để DNS-only; luật ufw theo dải Cloudflare chưa tự động — OWASP A05
- **Vị trí:** `infra/production/README.md:55-56` (bước 11, 12), `nginx/snippets/vv-real-ip.conf`.
- **Mô tả và tác động:**
  - README khuyên để `video.` DNS-only (giới hạn 100 MB của gói Free) và "mở 443 cho mọi nguồn riêng host video". ufw lọc theo cổng, không lọc theo tên host. Vì vậy mở 443 cho mọi nguồn là mở luôn api, admin-api và admin, và IP origin lộ qua bản ghi DNS của `video.`.
  - Kẻ tấn công gọi thẳng origin (SNI hoặc Host `admin-api…`) sẽ né WAF, bot management, rule rate limit và Cloudflare Access (nếu sau này dùng để che admin) của Cloudflare.
  - `real_ip` không bị giả được (chỉ tin `CF-Connecting-IP` từ dải Cloudflare), nên throttle theo IP và captcha của app vẫn đúng. Tác động chủ yếu là mất lớp bảo vệ ở biên và chống DDoS.
  - Ngoài ra `update-cloudflare-ips.sh` chỉ cập nhật Nginx. Luật ufw "80/443 chỉ cho dải Cloudflare" làm tay và không tự cập nhật: Cloudflare thêm dải thì người dùng bị chặn, còn bỏ dải thì không ai gỡ.
- **Cách sửa (PO chọn một):**
  - (a) Bật **Authenticated Origin Pulls** (mTLS của Cloudflare) cho mọi `server` trừ `video.`:
    ```nginx
    ssl_client_certificate /etc/ssl/cloudflare/authenticated_origin_pull_ca.pem;
    ssl_verify_client on;
    ```
    Khi đó mở 443 cho mọi nguồn cũng không ai ngoài Cloudflare vào được api, admin-api, admin hay web.
  - (b) Gán IP công khai thứ hai riêng cho `video.`. Nginx `listen <IP2>:443` chỉ cho server video, ufw mở `<IP2>:443` cho mọi nguồn, còn IP chính chỉ nhận dải Cloudflare.
  - (c) Để `video.` qua proxy Cloudflare và hạ `VIDEO_MAX_UPLOAD_MB`/`VIDEOLAB_CHUNK_MAX_MB` dưới 100 MB.
  - Với luật ufw: thêm bước sinh luật ufw từ cùng nguồn trong `update-cloudflare-ips.sh` (hoặc script riêng), có kiểm như phần Nginx.
- **Kiểm chứng (staging):**
  - Từ một máy ngoài Cloudflare: `curl --resolve admin-api.<domain>:443:<IP origin> https://admin-api.<domain>/up` phải bị từ chối (TLS handshake lỗi hoặc timeout).
  - Qua Cloudflare: vẫn 200.

### S4 [Low] FastCGI `127.0.0.1:9000` trên host không có xác thực, nên process cục bộ bất kỳ chiếm được php-fpm — OWASP A05
- **Vị trí:** `docker-compose.yml:46-47`, `nginx/conf.d/vitaminvui.conf:13`.
- **Mô tả:**
  - Mọi user hoặc process trên host (kể cả không thuộc nhóm `docker`, ví dụ agent giám sát hay một dịch vụ bị chiếm) mở được TCP tới `127.0.0.1:9000`. Chúng có thể gửi FastCGI với `PHP_ADMIN_VALUE=auto_prepend_file…`/`allow_url_include=On` và chạy mã PHP trong container `php`, tức đọc được toàn bộ `app.env`.
  - Bản Docker Engine cũ (trước 28.x) còn có lỗi cho máy cùng L2 chạm tới cổng publish trên loopback.
  - Rủi ro thấp vì server dành riêng và ít user, nhưng tác động là chiếm toàn bộ.
- **Cách sửa:**
  - Giữ server không có user hay dịch vụ lạ, ghi rõ trong README: "chỉ root, vvdeploy; không cài dịch vụ khác".
  - Bắt buộc Docker Engine >= 28 từ repo chính thức. README đã ghi repo chính thức; thêm phiên bản tối thiểu.
  - Phương án mạnh hơn (V2): php-fpm nghe Unix socket trong volume chia sẻ `/run/vitaminvui/php.sock`, mode 0660, group = group của Nginx host. Nginx dùng `fastcgi_pass unix:/run/vitaminvui/php.sock`. Cách này cũng bỏ được hop docker-proxy.
- **Kiểm chứng:**
  - `docker version` >= 28.
  - Từ một máy khác trong cùng mạng riêng của nhà cung cấp, thử tới `<IP server>:9000` và route `127.0.0.1` qua server: phải thất bại.

### S5 [Low] Cần kiểm container worker-video (mạng internal) có chạm được host qua gateway `10.231.12.1` không; tmpfs chưa `noexec` — OWASP A05
- **Vị trí:** `docker-compose.yml:101-127, 240-245`.
- **Mô tả:**
  - `internal: true` chặn chiều ra Internet (FORWARD), nhưng tuỳ phiên bản Docker, bridge vẫn mang IP gateway trên host. Gói đi tới host (chain INPUT) chỉ bị ufw chặn.
  - Nếu ufw cấu hình sai, hoặc mở 443 cho mọi nguồn theo S3, worker bị chiếm (qua lỗi ffmpeg) sẽ gọi được Nginx host, sshd và các dịch vụ host khác.
  - Smoke trên macOS chỉ chứng minh worker "không ra Internet" và "không phân giải `redis`".
  - `tmpfs: /tmp` mặc định cho phép thực thi: kẻ tấn công thả được binary vào `/tmp` dù rootfs chỉ đọc.
- **Cách sửa:**
  ```yaml
  tmpfs:
    - /tmp:rw,noexec,nosuid,nodev,size=512m
  ```
  - Trước khi đặt `noexec`, kiểm ffmpeg/PHP không cần thực thi trong `/tmp`.
  - Thêm luật ufw tường minh `deny in on <br-video>`, hoặc luật iptables `DOCKER-USER` chặn mọi gói từ `10.231.12.0/24` tới host trừ khi cần.
- **Kiểm chứng (staging, Linux thật):**
  - Chạy trong worker-video: `php -r 'var_dump(@fsockopen("10.231.12.1",22,$e,$s,2), @fsockopen("10.231.12.1",443,$e,$s,2), @fsockopen("10.231.10.1",8081,$e,$s,2));'`. Cả ba phải là `false`.

### S6 [Low] Thư mục dữ liệu MySQL/Redis trên host để 0755; file AOF/RDB của Redis có thể đọc được bởi mọi user host — OWASP A02
- **Vị trí:** `infra/production/README.md:43` (`install -d -o root -g root -m 0755 /srv/vitaminvui/{mysql,redis,redis-video}`).
- **Mô tả:**
  - Redis ghi `appendonlydir/` (0755) và file AOF/RDB (0644) theo umask mặc định. Các file này chứa phiên đăng nhập (DB 1), cache, job trong queue (mail phụ huynh, email/SĐT) và khoá limiter.
  - Với thư mục cha 0755, mọi user trên host đọc được.
  - MySQL tự đặt 0750/0640, nhưng GID 999 trên host Ubuntu có thể trùng một group hệ thống.
- **Cách sửa:**
  - `install -d -o root -g root -m 0700 /srv/vitaminvui`. Docker daemon chạy root nên vẫn bind mount được, và các thư mục con vẫn để entrypoint của image tự chown.
  - Hoặc đặt 0700 cho từng thư mục `mysql`, `redis`, `redis-video` sau lần khởi tạo đầu.
  - Kiểm `getent group 999` trên host.
- **Kiểm chứng:** `sudo -u nobody ls /srv/vitaminvui/redis` phải bị từ chối.

### S7 [Low] Container web/admin: mã do user chạy sở hữu, rootfs ghi được; backend cũng chưa đặt giới hạn tài nguyên — OWASP A05
- **Vị trí:**
  - `frontend/Dockerfile:75` (`COPY --from=build --chown=node:node /out/ ./`).
  - `docker-compose.yml:188-224` (không có `read_only`).
  - Khối `php`/`queue`/`scheduler` không có `mem_limit`/`pids_limit`.
- **Mô tả:**
  - Khi Next bị RCE (ví dụ một CVE của Next hoặc sharp), kẻ tấn công sửa được `server.js` và chunk JS phục vụ cho mọi người dùng tới khi container được tạo lại.
  - Backend đã làm đúng: mã thuộc root.
  - Không có giới hạn tài nguyên thì một service rò bộ nhớ có thể làm MySQL trên cùng máy bị OOM.
- **Cách sửa:**
  - Bỏ `--chown`, để mã thuộc root. Chỉ tạo `apps/<app>/.next/cache` thuộc `node` (bộ tối ưu ảnh ghi vào đó).
  - Compose cho web/admin: `read_only: true`, cộng `tmpfs: [/tmp]` và volume hoặc tmpfs cho `/app/apps/<app>/.next/cache`.
  - Đặt `mem_limit` và `pids_limit` hợp lý cho `php`, `queue`, `web`, `admin` sau khi đo trên staging.
- **Kiểm chứng:**
  - `docker compose exec web sh -c 'touch /app/apps/web/server.js'` phải bị từ chối.
  - `/_next/image` vẫn 200 trên staging.

### S8 [Low] `deploy.sh`: không kiểm định dạng `PREVIOUS_TAG`/`IMAGE_TAG` đọc từ `.env`; quyền `/opt/vitaminvui` theo README làm script không ghi được — OWASP A08
- **Vị trí:**
  - `deploy.sh:415-420` (`prev` không kiểm regex) và `deploy.sh:69-80, 95-101, 278`.
  - `README.md:37` (`/opt/vitaminvui` có chủ `root:vvdeploy`, mode 0750).
- **Mô tả:**
  1. `rollback` lấy `PREVIOUS_TAG` từ `.env` và đưa thẳng vào nội suy compose mà không kiểm. Tag là `sha` nên ảnh hưởng thấp: `.env` chỉ vvdeploy ghi được, và người đó đã tương đương root. Dù vậy nên kiểm giống `deploy`.
  2. **Lỗi chức năng có hệ quả bảo mật.** Với mode 0750 và chủ root, user `vvdeploy` không tạo được `.deploy.lock.d`, file tạm `mktemp .env.XXXXXX` hay `releases.log`. Khi đó `acquire_lock` báo nhầm "đang có deploy khác chạy". Vận hành dễ "sửa" bằng `chmod 777` hoặc chạy bằng root, mất ý nghĩa phân quyền. Smoke không bắt được vì dùng thư mục `.generated`.
- **Cách sửa:**
  - Thêm `[[ "$prev" =~ ^[0-9a-f]{40}$ ]] || [[ $LOCAL -eq 1 ]] || die "PREVIOUS_TAG không hợp lệ"`. Kiểm tương tự cho `cur`.
  - Tách phần ghi được ra thư mục riêng `/opt/vitaminvui/state/` (chủ `vvdeploy`, 0700) chứa `.env`, lock và `releases.log`. Giữ `/opt/vitaminvui` (compose, script, `env/`, `conf/`) thuộc root để vvdeploy không sửa được script.
  - Hoặc đơn giản hơn: README ghi `install -o vvdeploy -g vvdeploy -m 0750 /opt/vitaminvui`, chấp nhận vvdeploy sửa được script, vì nhóm docker đã bằng root.
- **Kiểm chứng:** QA trên staging chạy `deploy.sh deploy` bằng `vvdeploy` (không sudo) với đúng quyền theo README, phải qua được bước khoá và bước ghi `.env`.

### S9 [Low] PAT classic `read:packages` và cách lưu credential — OWASP A07
- **Vị trí:** ADR-008 §8.13, `README.md:51`.
- **Mô tả:**
  - PAT classic `read:packages` đọc được MỌI package mà tài khoản sở hữu PAT truy cập được, không giới hạn theo repo.
  - `docker login` lưu token dạng base64 (gần như rõ) trong `~vvdeploy/.docker/config.json`.
  - Nếu PAT thuộc tài khoản PO, lộ PAT là lộ mọi package riêng của PO.
- **Cách sửa:**
  - Dùng tài khoản máy riêng (machine user), chỉ được thêm vào package `vitaminvui-*` với quyền read. PAT staging và production tách riêng (ADR đã ghi), hạn 90 ngày, có lịch xoay vòng.
  - Có thể dùng `docker-credential-pass` để không lưu token rõ.
  - Đây là điểm PO quyết (tài khoản sở hữu PAT).

### S10 [Info] Các ghi nhận nhỏ
- **Khoá giả trong `docker history`:** `APP_KEY=base64:AAAA…=` ở bước `package:discover`. Không phải secret. Tuy vậy, nếu một môi trường lỡ quên đặt `APP_KEY` thì guard đã chặn (cần giữ test guard). Tuỳ chọn: thay bằng `APP_KEY=base64:$(head -c32 /dev/urandom | base64)` để không ai sao chép khoá cố định này, hoặc dùng `RUN --mount=type=secret`.
- **Image backend runtime còn `gcc`, header `-dev` và `curl`:** do `PHPIZE_DEPS` của image gốc và base `infra/php/Dockerfile`. Đồng thời còn binary setuid (`su`, `passwd`, `mount`…), nhưng `no-new-privileges` cộng `cap_drop ALL` đã vô hiệu. Có thể dọn ở một stage cuối (V2) để giảm bề mặt tấn công.
- **`pecl install redis` không ghim phiên bản:** nằm trong `infra/php/Dockerfile`. Đề xuất `pecl install redis-6.x.y`. Gói apt (`libfcgi-bin`, `ffmpeg`) cũng không ghim, chấp nhận vì base đã ghim digest. Smoke dùng `axllent/mailpit:latest` (chỉ local).
- **Access log php-fpm có `%R` (IP khách):** log này ghi vào log Docker json-file (20m x 5 mỗi container, không có hạn thời gian). IP là dữ liệu cá nhân, nên đưa vào chính sách lưu log (xem phần pháp chế).
- **`grants.sql` sau khi điền mật khẩu nằm trên đĩa (README bước 9):** nên sinh trong `/dev/shm` hoặc đẩy qua stdin (như `grants.smoke.sh`), rồi `shred -u`.
- **`REDISCLI_AUTH` trong env của container redis:** người thuộc nhóm `docker` đọc được qua `docker inspect`, nhưng người này vốn đã bằng root. Chấp nhận. Hai file env mới (PO cần xác nhận theo T35-1) không làm tăng rủi ro so với phương án healthcheck không xác thực.
- **Server source map của Next (`.next/server/**/*.js.map`):** không được phục vụ ra ngoài (review T35-2 R3). Giữ nguyên được.
- **Chưa chạy `composer audit`/`pnpm audit`:** lần review này chỉ làm việc local, không gửi request ra ngoài. Commit `6783c71` ghi `pnpm audit --prod` sạch. T35-3 phải đưa hai lệnh audit vào CI làm cổng chặn.

## Kết quả công cụ
- `docker buildx bake … --load backend` (tag tạm `vvsec-tmp/…:t35sec`): xanh. Đã inspect `Config.Env`, `User=10001:10001`, label, `docker history --no-trunc`, quyền thư mục mã, `php -i` (`display_errors=Off`, `expose_php=Off`, `allow_url_include=Off`), `php-fpm -tt`. Image đã xoá sau khi kiểm.
- `composer audit`, `npm/pnpm audit`: không chạy (lý do ở S10).
- Shellcheck và smoke: dùng kết quả của dev T35-1 (93 kiểm đạt, shellcheck 0 lỗi). Chưa chạy lại.

## Kiểm trên staging (QA hoặc dev, bắt buộc trước production)
1. **S3:** gọi thẳng IP origin cho các host không phải `video.` phải thất bại.
2. **S5:** từ worker-video không chạm được `10.231.12.1:{22,80,443}` và `10.231.10.1:8081`.
3. **S8:** `deploy.sh` chạy được bằng `vvdeploy` với đúng quyền thư mục theo README.
4. **S4:** `docker version` >= 28. Không có user hay dịch vụ lạ trên host.
5. **ufw:** `ufw status verbose` chỉ có 22 (nguồn tin cậy), 80/443 từ dải Cloudflare (cả IPv4 lẫn IPv6), `10.231.10.0/24 -> 10.231.10.1:8081`. `ss -lntp` không có listener `0.0.0.0` nào ngoài Nginx và sshd.
6. **Quyền file:** env 0600 chủ vvdeploy, `/srv/vitaminvui/backups` 0700, `storage/app/private` 0700. `docker compose exec php id` ra UID 10001.
7. **Access log:** sau khi gọi `/api/v1/webhooks/video/bunny?k=x` và `/phu-huynh/huy-nhan-thong-bao?t=x`, `docker compose logs php` và `/var/log/nginx/*.log` không chứa `k=x`/`t=x`.

## Chuyển cho `laravel-dev`
- S1: chặn upload khi chưa mã hoá, recipient phải là fingerprint, kiểm dump bị cắt ở nhánh gpg.
- S2 (T35-3): ghi digest hoặc ký cosign trong CI, `deploy.sh` kiểm digest, `releases.log` ghi digest.
- S5: `tmpfs` của worker thêm `noexec,nosuid,nodev`; README thêm luật chặn mạng video tới host.
- S6: README đổi quyền `/srv/vitaminvui` thành 0700.
- S7: bỏ `--chown` trong Dockerfile frontend, web/admin dùng `read_only`.
- S8: kiểm regex cho tag đọc từ `.env`, sửa mô hình quyền `/opt/vitaminvui`.
- S10: ghi chú về grants trong `/dev/shm`, ghim phiên bản `pecl redis`.

## Test `laravel-qa` nên thêm vào smoke
- `backup-db.sh` có `VV_BACKUP_UPLOAD_CMD` mà không có recipient thì thoát khác 0.
- Có recipient thì ra file `.gpg` quyền 0600 và khôi phục được.
- `deploy.sh rollback` với `PREVIOUS_TAG` sai định dạng thì dừng.
- Digest lệch thì deploy dừng (sau T35-3).
- `check-images.sh`: web/admin không ghi được vào `server.js`. worker-video có `/tmp` `noexec` (đọc `/proc/mounts`).
- Kiểm access log php-fpm không chứa query string (gọi một URL có `?k=` qua `nginx-smoke` rồi grep log `php`).

## Điểm cần pháp chế / PO quyết
- **Cloudflare proxy và chuyển dữ liệu ra nước ngoài (cần bộ phận pháp chế xác nhận):**
  - Khi bật proxy, TLS kết thúc ở edge Cloudflare. Toàn bộ request và response (họ tên, SĐT, email phụ huynh, nội dung bài làm, cookie phiên) đi qua và được giải mã tại hạ tầng Cloudflare, có thể đặt ngoài Việt Nam.
  - Điều này có thể bị coi là chuyển hoặc cho bên thứ ba xử lý dữ liệu cá nhân ra nước ngoài theo Luật Bảo vệ dữ liệu cá nhân 2025 và Nghị định 356/2025/NĐ-CP, trong khi PO đã chọn "không chuyển dữ liệu ra nước ngoài" (board, #4).
  - Cần pháp chế xác nhận và, nếu cần, có hồ sơ đánh giá tác động chuyển dữ liệu, hợp đồng xử lý dữ liệu với Cloudflare, hoặc chọn CDN/WAF có PoP và xử lý tại Việt Nam.
- **Nơi lưu backup ngoài server (S1):** phải đặt tại Việt Nam (cùng nguyên tắc trên), có mã hoá, có thời hạn giữ rõ ràng.
- **Thời hạn lưu và xoá:**
  - Bản backup (14 ngày trên server, từ 30 ngày trở lên ở ngoài) và binlog (7 ngày) vẫn chứa dữ liệu của người dùng đã bị xoá (`users:purge-unverified`, yêu cầu xoá dữ liệu).
  - Cần chính sách ghi rõ dữ liệu trong bản sao lưu tự hết hạn theo vòng xoay, và khi khôi phục từ backup phải chạy lại các lệnh purge. Cần pháp chế xác nhận thời hạn.
  - Log Docker (IP khách trong access log php-fpm) và log Nginx cũng cần thời hạn lưu.
- **PO:**
  - Chọn phương án S3 (a/b/c).
  - Chọn tài khoản sở hữu PAT, dùng tài khoản máy riêng (S9).
  - Chấp nhận 2 file env Redis (`REDISCLI_AUTH`): về bảo mật không có phản đối.
  - Danh sách người có SSH/nhóm `docker` (tương đương root).

---

# Vòng 2 | 2026-10-10
**Kết luận:** PASS có điều kiện

Không có Critical hay High. S1, S4–S8 và S10 đã đóng. Hai điều kiện còn lại đều nhỏ và phải xong trước go-live production:
- S2: chữ ký và digest đã có trong CI, nhưng `deploy.sh` mới coi digest là TUỲ CHỌN.
- S3: PO đã chốt về thiết kế, nhưng mẫu cấu hình còn để VideoLab/`video.` làm mặc định.

Có thêm một lỗi quyền thư mục mới (V2-1).

**Phạm vi:**
- Mục "Sửa sau review/DBA/security" trong `docs/review/T35-1.md`, cùng `deploy.sh`, `backup-db.sh`, `backup-binlog.sh`, `docker-compose.yml`, `frontend/Dockerfile`, `infra/php/Dockerfile`, `README.md`.
- T35-3: `.github/workflows/{ci.yml, release-images.yml}`, `scripts/ci/{backend-prepare.sh, check-frontend-vars.sh, collect-digests.sh}`, `docs/ops/ci.md`.
- Quyết định PO 2026-10-10 ở cuối ADR-008.

Vòng này chỉ đọc mã, không build lại image. Smoke 142/142 do dev chạy; tôi không chạy lại.

## Xác minh các mục vòng 1
| Mục | Trạng thái | Ghi chú |
|---|---|---|
| S1 backup | **Đóng** | Script từ chối upload khi thiếu recipient. Recipient phải là fingerprint 40 hex. Nhánh gpg kiểm "Dump completed" qua `awk` trước khi nén/mã hoá. `backup-binlog.sh` áp cùng quy tắc. Còn phải thử gpg thật và giải mã thật trên staging, vì smoke dùng gpg giả. |
| S2 digest/ký | **Còn mở một phần** (xem dưới) | `check_digests` đúng: regex `sha256:<64 hex>`, so với `RepoDigests`, dừng trước khi đổi gì, ghi `digest_backend` vào `releases.log`. CI ghi `images.lock` và ký cosign. |
| S3 origin | **Đóng về thiết kế, còn việc cấu hình** (xem dưới) | |
| S4 FastCGI loopback | **Đóng (chấp nhận rủi ro)** | README ghi Docker Engine >= 28 và server dành riêng. Unix socket để V2. |
| S5 worker/host | **Đóng** | tmpfs có `noexec,nosuid,nodev`. README có luật `DOCKER-USER` và lệnh kiểm `fsockopen`. Vẫn phải kiểm trên Linux thật. |
| S6 quyền dữ liệu | **Đóng, nhưng sinh V2-1** | `/srv/vitaminvui` và các thư mục dữ liệu đều 0700, có kiểm `getent group 999`. |
| S7 web/admin | **Đóng** | Mã thuộc root, chỉ `.next/cache` thuộc `node`. Có `read_only`, tmpfs `noexec`. `mem_limit`/`pids_limit` có cho php, queue, scheduler, web, admin và mysql. |
| S8 deploy.sh | **Đóng** | `TAG_RE` áp cho tag đọc từ `.env` khi `rollback`. Script dừng rõ ràng nếu `$VV_HOME` không ghi được. `/opt/vitaminvui` thuộc `vvdeploy`. Chạy bằng `vvdeploy` thật trên staging vẫn phải kiểm. |
| S9 PAT | **Chờ PO** | |
| S10 | **Đóng** | Ghim `pecl install redis-6.3.0`. Grants làm qua `/dev/shm` hoặc stdin. Giữ khoá giả trong `package:discover` (đã chấp nhận). |
| R3 (review) | **Tốt hơn đề xuất** | User `vv_healthcheck nopass -@all +ping` thay cho `REDISCLI_AUTH`, nên không còn mật khẩu `default` trong env của container redis. Smoke đã kiểm `GET`/`CONFIG` trả NOPERM. |

## S2: CI (T35-3) và phần còn mở
**Tốt:**
- `permissions: {}` ở mức workflow. Mỗi job chỉ xin `contents: read`; riêng `packages: write` và `id-token: write` chỉ có ở job `images` (điều kiện `push` lên `main`) và `release`.
- Mọi action ghim theo commit SHA. `persist-credentials: false`. Không có `pull_request_target`.
- Không có secret nào ngoài `GITHUB_TOKEN`. `NEXT_PUBLIC_*` lấy từ `vars`.
- `check-frontend-vars.sh` chỉ in tên biến, bắt buộc `https://`, chặn localhost, `REPLACE` và khoá thử.
- **Injection:** `inputs.sha` chỉ đi vào shell qua `env: INPUT_SHA` và được kiểm `^[0-9a-f]{40}$` ở bước đầu. Script kiểm SHA là tổ tiên của `main` và `HEAD` khớp SHA. Các biểu thức `${{ }}` còn lại nằm trong `with:`/`env:`, không có biểu thức nào do người dùng điều khiển chen vào `run:`.
- `collect-digests.sh` kiểm định dạng digest.
- Cosign keyless: `docs/ops/ci.md` có lệnh `cosign verify` với `--certificate-identity-regexp` ràng `…/(ci|release-images).yml@refs/heads/main` và issuer của GitHub.
- Audit gate: `composer audit --locked` (chặn mọi advisory) và `pnpm audit --prod --audit-level=high`.
- Environment `production` có Required reviewers và chỉ cho nhánh `main`.

**Còn mở (điều kiện go-live, Medium cho tới khi sửa):**
- `deploy.sh` vẫn coi `IMAGE_DIGEST_*` là tuỳ chọn ("đặt thì kiểm"). Vận hành quên dán `images.lock` thì deploy theo tag như cũ và S2 mất tác dụng.
- Chữ ký cosign chỉ có giá trị khi được kiểm, nhưng `cosign verify` mới là bước tay trong tài liệu.
- Lý do cần bắt buộc: một PR từ nhánh CÙNG repo (không phải fork) sửa được `ci.yml` để chạy job có `packages: write` trên sự kiện `pull_request`. Khi đó GitHub cấp quyền theo khai báo trong file, và cài đặt "Workflow permissions: read" không giới hạn được quyền khai báo tường minh. Người đó ghi đè được tag `<sha>` trên GHCR. Lớp chặn duy nhất là digest kiểm ở server và identity `refs/heads/main` của cosign.
- **Cách sửa:**
  ```bash
  # check_digests: production/staging (không --local) bắt buộc đủ digest
  [[ -n "$want" ]] || { [[ $LOCAL -eq 1 ]] && continue; die "thiếu $var (dán từ images-*.lock của run CI): bắt buộc khi không --local"; }
  ```
  - Khuyến nghị thêm: nếu có `cosign` trên server, `deploy.sh` chạy `cosign verify` cho 4 ref `repo@digest` với đúng identity trong `ci.md`, lỗi thì dừng. Không có `cosign` thì dừng, trừ khi truyền cờ `--no-verify-signature` và cờ đó được ghi vào `releases.log`.
- **Kiểm chứng (smoke):** deploy không `--local` mà thiếu `IMAGE_DIGEST_WEB` thì phải dừng trước `pull`/`up`.

**Ghi nhận khác về CI:**
- [Info] `release-images.yml:59` chạy `git fetch --no-tags origin main` trong khi `persist-credentials: false`. Repo private sẽ lỗi xác thực. Đây là lỗi chức năng, không phải lỗi bảo mật. `fetch-depth: 0` đã có `origin/main`, nên dùng thẳng `git merge-base --is-ancestor "$INPUT_SHA" origin/main`.
- [Info] Bản build production dùng chung cache `type=gha,scope=vv-frontend` với staging. Chỉ job trên `main` ghi được scope này (cache của PR nằm ở ref của PR), nên chấp nhận được. Nếu muốn tách hẳn thì đặt scope `vv-frontend-prod`.
- [Info] Rekor công khai ghi tên repo, đường dẫn workflow, ref và SHA commit, dù repo và package đều private. PO cần chấp nhận việc lộ metadata này.
- [Info] Service container của CI (`mysql:8.4`, `redis:7`) không ghim digest. Chỉ dùng cho test, chấp nhận.
- [Info] `release-images` không chạy lại audit cho SHA cũ. Advisory mới công bố sau lần CI của SHA đó sẽ không bị chặn. Có thể thêm bước `pnpm audit --prod --audit-level=critical` vào job release.

## S3: đánh giá sau quyết định PO 2026-10-10
Quyết định "video qua Bunny, production không mở `video.`, mọi host qua Cloudflare, firewall chỉ dải Cloudflare" **đóng rủi ro về thiết kế**: không còn lý do mở 443 cho mọi nguồn, không cần mTLS hay IP thứ hai. Phần triển khai vẫn còn các việc sau.

**Còn phải làm (điều kiện go-live):**
1. `.env.production.example` vẫn có `VIDEO_PROVIDER=internal`, `VIDEOLAB_ENABLED=true` và `VIDEOLAB_HOST=video.…`. Production theo quyết định mới phải là `VIDEO_PROVIDER=bunny`, `VIDEOLAB_ENABLED=false`. Nên để `ProductionConfigGuard` hoặc checklist chặn trường hợp VideoLab bật mà không có host `video.` được duyệt.
2. `nginx/conf.d/vitaminvui.conf` vẫn có server `video.` (TUS, `/videolab/cdn`, `/videolab/library`) và có `video.vitaminvui.vn` trong `server_name` của server :80. Bản production phải bỏ server này, hoặc tách thành file `videolab.conf` không include mặc định. Không tạo bản ghi DNS cho `video.`.
3. Compose và `deploy.sh` mặc định vẫn dựng `worker-video` và `redis-video`, và `check_inputs` đòi `worker-video.env`. Khi không dùng VideoLab:
   - Đưa hai service này vào profile `videolab` (giống `migrate` ở profile `tools`).
   - `deploy.sh` chỉ đụng tới chúng khi bật profile, chỉ đòi `worker-video.env` và `grants-worker.sql` khi bật.
   - Bỏ `REDIS_VIDEO_*` khỏi `app.env` nếu Bunny không dùng queue video (dev xác nhận).
   - Không chạy container ffmpeg không dùng tới thì giảm bề mặt tấn công.
4. README mục 12 vẫn khuyên `video.` DNS-only và còn khối "CHỜ PO: phương án S3". Cần thay bằng quyết định mới và ghi điều kiện go-live là checklist Bunny C1–C9 (`docs/qa/T37.md`).
5. Luật ufw 80/443 theo dải Cloudflare vẫn làm tay và không tự cập nhật. Cần sinh từ cùng nguồn trong `update-cloudflare-ips.sh` (hoặc script riêng), có kiểm CIDR như phần Nginx. Phải gồm cả IPv6 và xoá dải cũ.
6. **Kiểm chứng trên staging:**
   - Gọi thẳng IP origin cho mọi host thì thất bại.
   - `ss -lntp` chỉ có Nginx (80/443 và `10.231.10.1:8081`) và sshd.
   - `docker compose ps` không có worker-video.

## Phát hiện mới
### V2-1 [Low] `/srv/vitaminvui` 0700 root làm `vvdeploy` không vào được `/srv/vitaminvui/backups` — OWASP A05
- **Vị trí:** `infra/production/README.md:47-48`.
- **Mô tả:**
  - Thư mục cha thuộc `root` với mode 0700, nên `vvdeploy` không có quyền đi qua (`x`) để tới `backups/` và `backups/binlog/`, dù hai thư mục này thuộc `vvdeploy`.
  - Hệ quả: `backup-db.sh` và `backup-binlog.sh` chạy bằng `vvdeploy` (cron, `deploy.sh`) sẽ lỗi "Permission denied". Vận hành dễ "sửa" bằng `chmod 755` hoặc chạy backup bằng root.
  - Smoke không bắt được vì dùng thư mục `.generated`.
- **Cách sửa:** đặt `/srv/vitaminvui` là `root:root 0711` (chỉ cho đi qua, không cho liệt kê). Các thư mục `mysql`, `redis`, `redis-video` vẫn 0700, nên ý của S6 giữ nguyên. Hoặc chuyển backup ra `/srv/vitaminvui-backups` (thuộc `vvdeploy`, 0700).
- **Kiểm chứng (staging):** `sudo -u vvdeploy /opt/vitaminvui/backup-db.sh manual` chạy được. `sudo -u nobody ls /srv/vitaminvui/redis` bị từ chối.

### V2-2 [Info] README mục 6 có hai dòng mâu thuẫn về `conf/users.acl`
- **Vị trí:** `infra/production/README.md:55-56`.
- **Mô tả:** dòng 55 (mới) ghi "hai dòng `default` + `vv_healthcheck`", dòng 56 (cũ) ghi "chỉ dòng `default`". Làm theo dòng 56 thì healthcheck Redis luôn lỗi.
- **Cách sửa:** xoá dòng 56.

## Chuyển cho `laravel-dev`
- S2: `check_digests` bắt buộc khi không `--local`. Tuỳ chọn thêm `cosign verify` trong `deploy.sh`.
- S3: làm việc 1–5 ở mục trên (env mẫu Bunny, gỡ server `video.`, profile `videolab`, README §12, sinh luật ufw).
- V2-1: đổi `/srv/vitaminvui` thành 0711. V2-2: xoá dòng README cũ.
- T35-3: sửa `git fetch` trong `release-images.yml` (Info).

## Test `laravel-qa` nên thêm
- Smoke: deploy không `--local` mà thiếu hoặc sai digest thì dừng trước khi đổi container.
- Smoke: bỏ profile `videolab` thì stack chạy đủ mà không có worker-video, và `deploy.sh` không đòi `worker-video.env`.
- Staging:
  - Chạy backup và binlog bằng `vvdeploy` với quyền theo README.
  - Thử gpg thật: mã hoá bằng fingerprint rồi giải mã trên máy khác.
  - Gọi thẳng IP origin thì thất bại.
  - `cosign verify` với identity trong `ci.md` qua cho image của `main`.

## Điểm cần pháp chế / PO quyết (cập nhật)
- **S9 (PAT):** vẫn chờ PO. Khuyến nghị dùng tài khoản máy riêng, chỉ có quyền read trên package `vitaminvui-*`.
- **Cloudflare:** PO giữ proxy và xin pháp chế xác nhận trước go-live (cần bộ phận pháp chế xác nhận). Bunny Stream cũng là bên xử lý ở nước ngoài, có thể nhận IP và hành vi xem của học sinh qua token phát video. Cần đưa Bunny vào cùng hồ sơ pháp chế với Cloudflare và Turnstile.
- **Nơi lưu backup ngoài server:** PO chưa chốt và vẫn là điều kiện go-live. Script đã chặn upload không mã hoá.
- **Rekor:** PO cần chấp nhận việc nhật ký minh bạch công khai ghi tên repo private và tên workflow.

---

# Vòng 3 | 2026-10-10
**Kết luận:** PASS có điều kiện

Không có Critical hay High. Các mục S2, S3, V2-1, V2-2 và C1–C3 (T35-3) đã đóng. S1 không còn áp dụng. Bỏ proxy (DNS-only) sinh thêm 1 Medium (V3-1) và 4 Low (V3-2 đến V3-5). Danh sách điều kiện go-live ở cuối mục này.

**Phạm vi:**
- Quyết định PO 2026-10-10 ở cuối ADR-008: tạm bỏ Cloudflare proxy, backup bằng snapshot của nhà cung cấp, VideoLab tuỳ chọn, CDN Bunny `cdn.vitaminvui.asia`.
- Mục "Sửa vòng 3" trong `docs/review/T35-1.md`, mục "Sửa sau review" trong `docs/review/T35-3.md`.
- Các file `nginx/{conf.d,snippets,optional}`, `deploy.sh` (`require_digests`, `--ack-snapshot`), `docker-compose.yml` (profile), `.env.production.example`, `README.md` mục 11–14, `.github/workflows/*.yml`, `backend/bootstrap/app.php` (TrustProxies).

Vòng này chỉ đọc mã, không build image. Smoke 128/128 và 127/127 do dev chạy.

## Xác minh
| Mục | Trạng thái | Ghi chú |
|---|---|---|
| S1 backup | **Không áp dụng** | Script dump, binlog và GPG đã bị xoá. Rủi ro mới của snapshot nằm ở V3-5. |
| S2 digest/ký | **Đóng** | `require_digests` chạy trước `pull` và bắt buộc `IMAGE_DIGEST_*` khi không `--local` (regex `sha256:<64 hex>`). `check_digests` so lại sau `pull`. `VV_COSIGN_VERIFY=1` gọi `cosign verify` với identity `refs/heads/main`. Cosign vẫn là tuỳ chọn, xem V3-4. |
| S3 origin | **Đóng** | Không có proxy nên không còn đường vòng qua proxy. Server `video.` đã tách sang `nginx/optional/videolab.conf` và bị gỡ khỏi `server_name` của server :80. `worker-video`/`redis-video` thuộc profile `videolab` (`VV_VIDEOLAB=0`). Mẫu env: `VIDEO_PROVIDER=bunny`, `VIDEOLAB_ENABLED=false`, `BUNNY_CDN_HOST=cdn.vitaminvui.asia`. |
| V2-1 quyền thư mục | **Đóng** | Không còn thư mục backups. `/srv/vitaminvui` 0700 root là đúng: `vvdeploy` chỉ cần ghi `/opt/vitaminvui`. |
| V2-2 README | **Đóng** | Đã xoá dòng ACL cũ. |
| T35-3 C1 | **Đóng** | Dùng `refs/remotes/origin/main`, không còn `git fetch` thiếu thông tin xác thực. |
| T35-3 C2 | **Đóng** | `collect-digests.sh` có `REQUIRED_TARGETS`: thiếu digest của target bắt buộc thì thoát 1. |
| T35-3 C3 | **Đóng** | `targets`/`REQUIRED_TARGETS` dùng biểu thức `vars.VV_VIDEOLAB == '1' && '…' \|\| '…'`. Biểu thức chỉ trả một trong hai hằng chuỗi nên không chèn được. Cache đã tách scope `vv-web`/`vv-admin`. |
| S9 PAT | **Chờ PO** | |

## DNS-only: IP thật, X-Forwarded-For, rate limit
**Kết quả: khách bên ngoài KHÔNG giả được IP.**
- **Nginx:** `vv-real-ip.conf` chỉ còn chú thích. Không có `set_real_ip_from`, `real_ip_header` hay `real_ip_recursive`, nên `$remote_addr` là địa chỉ TCP thật. `limit_req` của `vv_web`/`vv_admin` khoá theo `$binary_remote_addr`, tức IP thật.
- **Host API (FastCGI):** PHP nhận `REMOTE_ADDR` bằng IP công khai của khách. IP này không thuộc `TRUSTED_PROXIES=10.231.10.0/24`, nên `TrustProxies` bỏ qua `X-Forwarded-For`/`-Proto`/`-Port` do khách tự gửi.
  - `bootstrap/app.php` chỉ tin `FOR|PORT|PROTO`, không tin `X-Forwarded-Host`. Vì vậy chiêu đổi Host qua header không phá được ranh giới học sinh/quản trị.
  - Request smoke từ `127.0.0.1` (`curl --resolve`) cũng không nằm trong dải tin cậy.
- **Host web/admin (proxy tới Next):** Nginx ghi đè `X-Forwarded-For $remote_addr` (không nối chuỗi), xoá `X-Internal-Token`/`X-Client-IP`. `lib/catalog/api.ts` lấy IP từ `x-forwarded-for` đã bị ghi đè đó.
- **Listener `:8081` (SSR):** `REMOTE_ADDR` là IP container web trong `10.231.10.0/24`, nên được tin. Laravel tính throttle theo `X-Client-IP` khi có `X-Internal-Token` đúng. Container admin cũng ở dải này nhưng không có token.
- **Một điểm còn lỏng:** header `X-Forwarded-Host`, `Forwarded` và `X-Real-IP` do khách gửi vẫn đi nguyên tới Next. Xem V3-3.

### V3-1 [Medium] Host `api.`/`admin-api.` không có `limit_req`/`limit_conn`/timeout ở Nginx; DNS-only không còn lớp chặn nào phía trước — OWASP A04
- **Vị trí:** `nginx/conf.d/vitaminvui.conf` (server `api.` và `admin-api.`), `nginx/snippets/vv-api-common.conf`.
  - Tìm `limit_req`, `limit_conn`, `client_*_timeout` chỉ thấy `limit_req` ở host web và admin.
- **Mô tả và tác động:**
  - Throttle hiện chỉ có ở Laravel, nghĩa là mỗi request đã chiếm một worker FPM (`pm.max_children=20`) trước khi bị từ chối.
  - Một IP đơn lẻ gửi vài trăm request/giây, hoặc mở nhiều kết nối chậm kiểu slowloris, là đủ chiếm hết FPM. Khi đó toàn bộ API (đăng nhập, làm bài, SSR catalog) ngừng phục vụ.
  - Trước đây Cloudflare gánh phần này. Giờ IP server công khai qua DNS, không có WAF hay CDN đứng trước.
- **Cách sửa (mức khởi điểm, chỉnh sau khi đo staging, tính cả lớp học chung NAT):**
  ```nginx
  limit_req_zone  $binary_remote_addr zone=vv_api:10m rate=20r/s;
  limit_req_zone  $binary_remote_addr zone=vv_api_auth:10m rate=2r/s;   # /api/v1/auth/*, OTP, quên mật khẩu
  limit_conn_zone $binary_remote_addr zone=vv_conn:10m;
  # http {} hoặc từng server công khai:
  client_header_timeout 10s; client_body_timeout 10s; send_timeout 15s; keepalive_timeout 30s;
  # server api / admin-api:
  limit_conn vv_conn 50;
  location / { limit_req zone=vv_api burst=100 nodelay; limit_req_status 429; ... }
  location ^~ /api/v1/auth/ { limit_req zone=vv_api_auth burst=10 nodelay; limit_req_status 429; ... }
  ```
  - Không áp limit lên listener `:8081`: SSR đã có trần tổng riêng (`CATALOG_SSR_TOTAL_PER_MINUTE`).
  - Hỏi nhà cung cấp server về chống DDoS tầng mạng (L3/L4) có sẵn. Ghi vào README cách bật lại Cloudflare nhanh khi bị tấn công (mục "Bật Cloudflare proxy" đã có).
- **Kiểm chứng:** smoke chạy `nginx-smoke` với snippet mới, bắn 300 request/giây từ một IP vào `/api/v1/config/public` thì phải có 429. Vẫn phải đạt 200 khi chạy 40 client song song cùng IP ở mức bình thường.

### V3-2 [Low] Host `admin.`/`admin-api.` mở ra toàn Internet, không còn lớp lọc ở biên — OWASP A01
- **Vị trí:** `nginx/conf.d/vitaminvui.conf` (dòng `# allow <IP_VAN_PHONG>; deny all;` vẫn để comment).
- **Mô tả:** đã có MFA cho nhân viên, captcha khi sai nhiều lần và trần đăng nhập theo tài khoản/IP. Tuy vậy, khi không có proxy, mọi lỗ hổng chưa biết ở luồng admin đều chạm được trực tiếp từ Internet.
- **Cách sửa (PO quyết):** giới hạn `admin.` và `admin-api.` theo IP văn phòng hoặc VPN (bỏ comment `allow … deny all`). Nếu đội làm việc từ xa với IP động, ghi rõ đây là rủi ro chấp nhận.

### V3-3 [Low] Header `X-Forwarded-Host`/`Forwarded`/`X-Real-IP` do khách gửi đi nguyên tới Next — OWASP A05
- **Vị trí:** các `location` proxy tới `next_web`/`next_admin` trong `nginx/conf.d/vitaminvui.conf`.
- **Mô tả:**
  - Next dùng `x-forwarded-host` khi dựng URL tuyệt đối và khi kiểm Origin của Server Action.
  - `lib/catalog/api.ts` lùi về `x-real-ip` nếu thiếu `x-forwarded-for`. Hiện Nginx luôn đặt `x-forwarded-for` nên chưa khai thác được, nhưng phụ thuộc thứ tự fallback là mong manh.
  - Nên chuẩn hoá luôn để không phụ thuộc hành vi của Next.
- **Cách sửa:** thêm vào mọi khối proxy:
  ```nginx
  proxy_set_header X-Forwarded-Host $host;
  proxy_set_header X-Real-IP        $remote_addr;
  proxy_set_header Forwarded        "";
  ```
- **Kiểm chứng:** gửi `curl -H 'X-Forwarded-Host: evil.example' -H 'X-Real-IP: 1.2.3.4'` qua `nginx-smoke`, rồi xem log hoặc header mà Next nhận: phải ra `$host` và IP thật.

### V3-4 [Low] `cosign verify` vẫn tuỳ chọn ở production — OWASP A08
- **Vị trí:** `deploy.sh:309-313`, `compose.env.example`.
- **Mô tả:**
  - Digest bắt buộc đã chặn được việc ghi đè tag.
  - Cosign còn chặn thêm một trường hợp: vận hành dán nhầm digest từ run của một PR hoặc nhánh khác (cùng repo có `packages: write`). Identity `refs/heads/main` sẽ từ chối image đó.
- **Cách sửa:** đặt `VV_COSIGN_VERIFY=1` mặc định cho production trong `compose.env.example`. README cài `cosign` (ghim phiên bản và checksum) ở bước "Gói hệ thống". Staging có thể để 0.

### V3-5 [Low] Backup bằng snapshot cùng nhà cung cấp — OWASP A05 (và PDPL)
- **Mô tả:** đây là rủi ro PO đã chấp nhận về mặt dữ liệu: mất tối đa khoảng 1 ngày, không khôi phục theo thời điểm. Còn ba điểm bảo mật:
  - (1) Tài khoản nhà cung cấp là "chìa khoá chung" cho cả server lẫn snapshot. Mất tài khoản (phishing, lộ API key) là mất hoặc bị xoá hết, kể cả trường hợp bị tống tiền.
  - (2) Snapshot chứa toàn bộ PII, kể cả `env/*.env` (APP_KEY, mật khẩu DB). Ai tạo được server từ snapshot là có đủ khoá.
  - (3) Snapshot MySQL đang chạy là crash-consistent. InnoDB thường tự phục hồi được, nhưng đây là lý do bắt buộc diễn tập khôi phục.
- **Cách sửa:**
  - Bật MFA cho mọi tài khoản của nhà cung cấp. Tách user hoặc API key chỉ có quyền tối thiểu, và giới hạn ai được tạo server từ snapshot hoặc xoá snapshot.
  - Bật khoá chống xoá snapshot nếu nhà cung cấp hỗ trợ.
  - Xác nhận snapshot được mã hoá lúc lưu và nằm tại Việt Nam.
  - README mục 13 đã có bước diễn tập khôi phục và chạy lại purge sau khôi phục: giữ nguyên.

### Ghi nhận về việc lộ IP server (Info)
- Server `default_server` trả 444 kèm chứng chỉ tự ký nên quét theo IP không lấy được chứng chỉ thật. Tuy vậy, tên host vẫn lộ qua Certificate Transparency của Let's Encrypt, việc này không tránh được.
- SSH chỉ mở cho IP quản trị (README mục 11). Nên tắt `PasswordAuthentication`, chỉ dùng khoá.
- Áp ufw cho cả IPv6. Nếu Nginx chưa cấu hình IPv6 thì không tạo bản ghi `AAAA`.
- Không còn lý do cron `update-cloudflare-ips.sh` (đã không còn mặc định). Lý do tồn tại của S3 cũng hết.

## Điều kiện go-live còn lại
**Sửa mã hoặc cấu hình (`laravel-dev`):**
1. V3-1: `limit_req`, `limit_conn` và timeout cho `api.`/`admin-api.`, riêng `/api/v1/auth/*` chặt hơn. Kiểm bằng `nginx-smoke`.
2. V3-3: chuẩn hoá `X-Forwarded-Host`, `X-Real-IP`, `Forwarded` ở các khối proxy tới Next.
3. V3-4: `VV_COSIGN_VERIFY=1` mặc định production, README cài `cosign`.

**Kiểm trên staging (QA, Linux thật):**
4. Quét từ máy ngoài: chỉ có 80/443, cộng 22 khi nguồn là IP quản trị, áp cho cả IPv4 và IPv6. `ss -lntp` chỉ có Nginx và sshd.
5. `deploy.sh` chạy bằng `vvdeploy` với đúng quyền theo README. `require_digests` và `cosign verify` chạy với image thật đã ký từ CI.
6. Snapshot tự động hằng ngày giữ ít nhất 7 ngày. Diễn tập khôi phục một lần, kiểm `deploy.sh status` và `SHOW TRIGGERS`.
7. Checklist Bunny C1–C9 (`docs/qa/T37.md`) đạt.
8. Gửi `X-Forwarded-For: 1.2.3.4` vào `api.` và `vitaminvui.vn`: log Laravel và bộ đếm throttle vẫn ghi IP thật.

**PO / pháp chế:**
9. S9: tài khoản máy riêng sở hữu PAT `read:packages`.
10. V3-2: allow-list admin theo IP/VPN, hoặc ghi chấp nhận rủi ro.
11. V3-5: MFA và tài khoản quyền tối thiểu ở nhà cung cấp server. Snapshot đặt tại Việt Nam và được mã hoá lúc lưu (cần bộ phận pháp chế xác nhận vị trí lưu).
12. Pháp chế xác nhận Turnstile và Bunny (bên xử lý ở nước ngoài, nhận IP và hành vi xem của học sinh). Điểm "proxy giải mã traffic" không còn áp dụng. Cũng cần xác nhận thời hạn lưu binlog 7 ngày và snapshot từ 7 ngày trở lên: hai nơi này vẫn còn dữ liệu của người đã yêu cầu xoá.

## Test `laravel-qa` nên thêm
- Smoke `nginx-smoke`: vượt ngưỡng `vv_api`/`vv_api_auth` thì trả 429. Mức bình thường từ 40 client cùng IP vẫn đạt 200.
- Smoke: `X-Forwarded-For` hoặc `X-Real-IP` giả gửi vào `api.` thì `request()->ip()` vẫn là IP kết nối thật. Có thể kiểm qua log throttle hoặc một route debug chỉ có trong smoke.
- Smoke: `X-Forwarded-Host` giả gửi vào host web thì Next nhận `$host`.
- Đã có và giữ nguyên: thiếu hoặc sai digest thì dừng; có migration chờ mà thiếu `--ack-snapshot` thì dừng trước khi đổi gì.
