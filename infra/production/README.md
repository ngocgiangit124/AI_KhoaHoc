# Mẫu cấu hình production/staging (T31)

CHỈ LÀ MẪU. Không dùng cho local (local dùng `infra/docker-compose.yml`). Thay mọi `<...>` và `vitaminvui.vn`
bằng giá trị thật trước khi dùng; không commit giá trị thật. Quy trình đầy đủ: `docs/ops/production-checklist.md`.

| File | Dùng cho |
|---|---|
| `nginx/conf.d/vitaminvui.conf` | Nginx: host 444, api, admin-api, listener nội bộ :8081 cho SSR, video (VideoLab), static, web + admin (proxy Next.js) |
| `nginx/snippets/vv-api-common.conf` | Phần chung 2 host API và video (chặn file nhạy cảm, PATH_INFO, webhook, front controller) |
| `nginx/snippets/vv-deny.conf` | Chặn file nhạy cảm và `/index.php/` (dùng cho cả host video) |
| `nginx/snippets/vv-videolab-tus.conf` | Cấu hình location TUS (dùng cho `/videolab/tus` và `/videolab/tus/`) |
| `nginx/snippets/vv-tls.conf` | TLS dùng chung |
| `nginx/snippets/vv-real-ip.conf` | `real_ip` sau Cloudflare (`CF-Connecting-IP` + dải IP Cloudflare) |
| `scripts/update-cloudflare-ips.sh` | Cập nhật dải IP Cloudflare trong `vv-real-ip.conf` (cron hàng tuần, tự `nginx -t` + reload) |
| `supervisor/vitaminvui.conf` | queue `default,exports`, `worker-video` (máy riêng), scheduler |
| `mysql/grants.sql` | User DB: app, worker-video, migrate |
| `redis/users.acl` | Redis ACL: user `default` (app) và `vv_worker_video` (chỉ queue `video`), cụm 4 M1 |
| `.env.production.example` | Biến môi trường app (không có secret thật) |
| `.env.worker-video.example` | Biến môi trường riêng cho worker-video |
