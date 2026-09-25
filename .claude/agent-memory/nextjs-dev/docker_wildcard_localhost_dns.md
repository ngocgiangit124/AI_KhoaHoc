---
name: docker-wildcard-localhost-dns
description: Cách cho container Docker phân giải được *.localhost (api.localhost, admin.localhost...) để chạy Playwright e2e với backend thật
metadata:
  type: feedback
---

Trình duyệt (Chrome/Firefox) tự đặc cách phân giải mọi `*.localhost` về `127.0.0.1` mà
không qua DNS/hosts hệ thống. **glibc resolver mặc định trong container Docker (Debian/
Ubuntu base) KHÔNG có đặc cách này** — `curl`/Node `fetch`/`getaddrinfo` gọi
`api.localhost` sẽ lỗi (không resolve được, không phải do backend sai) trừ khi khai rõ.

**Cách sửa đã kiểm chứng:** chạy container với `--network host` (container dùng chung
network namespace với máy host — cổng publish trên host truy cập được qua `127.0.0.1`
ngay trong container) + `--add-host <domain>:127.0.0.1` cho từng subdomain cần dùng (ví
dụ `--add-host api.localhost:127.0.0.1 --add-host admin-api.localhost:127.0.0.1`). glibc
resolver đọc `/etc/hosts` trước DNS (thứ tự mặc định `hosts: files dns` trong
nsswitch.conf) nên `--add-host` giải quyết được cho cả `curl` (đại diện hành vi Node
`fetch`) LẪN trình duyệt Chromium chạy trong container đó.

**Why:** Cần chạy Playwright e2e thật (không mock) cho VitaminVui khi backend Laravel đã
lên qua `infra/docker-compose.yml` (host `api.localhost:8000`, `admin-api.localhost:8000`
theo ADR-004 §2.1) — cả SSR (Node fetch trong Next.js dev server) và test trình duyệt
(Playwright/Chromium) đều cần resolve đúng các domain này từ trong container Docker
riêng biệt (không chung compose project với backend).

**How to apply:** Xem `frontend/scripts/playwright.sh` (cờ `--real-backend`) —
`docker run --network host --add-host <domain>:127.0.0.1 ...`. Áp dụng mọi lúc cần
container Docker gọi tới service khác trên cùng máy host qua domain `*.localhost` mà
không muốn sửa `/etc/hosts` của máy host thật. Lưu ý `--network host` chỉ hoạt động đúng
kiểu Linux-native trên Docker Desktop backend WSL2 — không chắc hoạt động y hệt trên
Docker Desktop Windows/Mac (thường có giới hạn với `--network host`).
