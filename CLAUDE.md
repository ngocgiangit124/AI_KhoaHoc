# Dự án: VitaminVui
- Laravel 13, PHP 8.3, MySQL 8.4 (driver MySQL)
- Connection: MySQL
- Frontend: Nextjs + Tailwind + typescript
- Test: Pest · Code style: Pint
- Hạ tầng: Nginx, Ubuntu, Docker (local: `infra/docker-compose.yml`)
- Thuật ngữ: chưa có
- Vai trò người dùng: admin, quản lý trang, Giáo Viên, Học Sinh
- Quy ước: chưa có

## Bắt đầu phiên làm việc
- Đọc `docs/board.md` trước tiên: trạng thái task, việc tiếp theo, quy tắc đã thống nhất với PO.
- Danh sách task và định nghĩa "xong": `docs/architecture/tasks.md`. Hợp đồng API: `docs/architecture/api-contract.md`. Quyết định kiến trúc: `docs/adr/`.
- Đội agent nằm trong `.claude/agents/`. Quy trình mỗi task: dev → `laravel-reviewer` → `laravel-security` (task [SEC]) → `laravel-qa` → PO duyệt commit/push.

## Cấu trúc repo
- `backend/`: Laravel 13 API (2 host: `api.` cho học sinh, `admin-api.` cho quản trị). Xem `backend/README.md`.
- `frontend/`: workspace pnpm gồm `apps/web` (học sinh), `apps/admin` (quản trị), `packages/ui`, `packages/api-client`. Xem `frontend/README.md`.
- `infra/`: docker-compose, Nginx, cấu hình MySQL/PHP cho local.
- `docs/`: stories, design, architecture, adr, security, db, qa.

## Chạy lệnh
- Máy local: mọi lệnh PHP/Node chạy trong Docker (host không có PHP 8.3/Node 22).
  - Backend: `cd infra && docker compose exec -T php composer ci`
  - Frontend: `frontend/scripts/pnpm.sh run lint|typecheck|test|build`
- Claude Code on the web: hook SessionStart chạy `scripts/cloud-setup.sh` để cài PHP 8.3, Composer, MySQL, Redis, Node 22, pnpm trực tiếp (không cần Docker). Log ở `/tmp/vitaminvui-cloud-setup.log`.
  - Backend: `cd backend && composer ci`
  - Frontend: `cd frontend && pnpm -r run test` (và lint/typecheck/build)
  - Cloud cài MySQL bản có sẵn của Ubuntu (có thể là 8.0, không phải 8.4). Test chạy được, nhưng hành vi riêng của 8.4 phải kiểm lại trên Docker local.
