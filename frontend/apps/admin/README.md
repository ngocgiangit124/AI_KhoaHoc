# @vitaminvui/admin

App Next.js dành cho quản trị (`admin.vitaminvui.vn`) — admin, quản lý trang, giáo viên.
Xem hướng dẫn chạy đầy đủ bằng Docker, biến môi trường, và quy ước ở
[`frontend/README.md`](../../README.md).

Chạy nhanh (từ gốc repo):

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f frontend/docker-compose.yml up admin
```

Mở http://admin.localhost:3001 (cần thêm `admin.localhost` vào trình duyệt hoặc dùng
trực tiếp — Chrome/Firefox tự phân giải `*.localhost` về 127.0.0.1).
