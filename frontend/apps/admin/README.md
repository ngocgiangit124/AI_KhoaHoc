# @vitaminvui/admin

App Next.js dành cho quản trị (`admin.vitaminvui.vn`) — admin, quản lý trang, giáo viên.
Xem hướng dẫn chạy đầy đủ bằng Docker, biến môi trường, và quy ước ở
[`frontend/README.md`](../../README.md).

Chạy nhanh (từ gốc repo):

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f frontend/docker-compose.yml up admin
```

Mở http://admin-api.localhost:3001 (Chrome/Firefox tự phân giải `*.localhost` về 127.0.0.1; cùng site với
admin-api.localhost:8000 — xem `frontend/README.md`; copy `.env.example` thành `.env.local`).
