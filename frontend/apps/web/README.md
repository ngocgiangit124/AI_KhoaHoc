# @vitaminvui/web

App Next.js dành cho học sinh (`vitaminvui.vn`). Xem hướng dẫn chạy đầy đủ bằng Docker,
biến môi trường, và quy ước ở [`frontend/README.md`](../../README.md).

Chạy nhanh (từ gốc repo):

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f frontend/docker-compose.yml up web
```

Mở http://localhost:3000.
