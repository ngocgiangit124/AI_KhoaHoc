# REVIEW: Dựng máy local lần đầu (macOS, UID 501) sau bàn giao từ cloud
**Kết luận:** APPROVE (sau vòng 2)
**Phạm vi vòng 1:** `git diff` (chưa commit) trên nhánh `claude/zen-dirac-fmucf7` + 2 file mới chưa track. 10 file sửa, 2 file mới:
`backend/README.md`, `backend/app/Http/Resources/Auth/UserResource.php`, `backend/app/Models/User.php`,
`backend/app/Services/Auth/LoginService.php`, `backend/phpunit.xml`, `frontend/Dockerfile.dev`,
`frontend/apps/admin/.gitignore`, `frontend/apps/web/.gitignore`, `frontend/scripts/pnpm.sh`,
`scripts/cloud-setup.sh`, `frontend/apps/admin/.env.example` (mới), `frontend/apps/web/.env.example` (mới).
**Phạm vi vòng 2:** thêm `frontend/docker-compose.yml` (chỉ file này đổi so với vòng 1).

Đây không phải review theo story, mà review một loạt sửa hạ tầng/dev-tooling để chạy được `composer ci` và
build frontend trên máy local mới (macOS, UID 501), không đổi hành vi nghiệp vụ nào của T01–T03/FW1.

## Tổng quan
Phần lớn thay đổi gọn, có lý do rõ ràng và đã tự kiểm chứng tốt (giải thích *tại sao* ngay trong comment thay vì chỉ *cái gì*). Đã verify lại độc lập trong phiên review này:
- `cd infra && docker compose exec -T php composer ci` → Pint sạch, Larastan **0 lỗi** (75 file), Pest **193 passed (577 assertions)**.
- `backend/.env` có `DB_PASSWORD` ngẫu nhiên thật (không phải `secret`), không bị track bởi git; test vẫn xanh → xác nhận đúng cơ chế mô tả trong `phpunit.xml`/README.
- `.gitignore` của `apps/web`/`apps/admin`: xác nhận bằng `git status --ignored=matching` rằng 2 file `.env.example` mới là **untracked** (`??`), không phải bị ignore — phần `!.env.example` hoạt động đúng.
- `frontend/apps/web/.env.example` và `frontend/apps/admin/.env.example` khớp 1-1 với schema `env.ts`/`env.server.ts` và bảng biến trong `frontend/README.md`, không có secret, giá trị domain `*.localhost` hợp lý theo ADR-004 §2.1.
- `LoginService.php:81` đổi `??`/`?->` sang ternary tường minh: hành vi giống hệt (cột `password` là `NOT NULL` trong migration `0001_01_01_000000_create_users_table.php`, nên `$user->password` không bao giờ null khi `$user` tồn tại) — không ảnh hưởng chống dò tài khoản (test `M1: khong tim thay tai khoan van goi Hash::check dung 1 lan` vẫn pass).
- `@mixin User` / `@property Carbon|null ...` chỉ là chú thích cho Larastan, không đổi runtime.

Vòng 1 chặn lại vì R1 (sửa nửa vời lỗi HOME/UID ở Docker frontend: vá `scripts/pnpm.sh`/`Dockerfile.dev` nhưng bỏ sót `frontend/docker-compose.yml` — nơi README hướng dẫn dùng để chạy dev server hằng ngày). Vòng 2: R1 đã sửa và tự verify được bằng chạy thật, xem bên dưới.

## Phát hiện

### R1 [BLOCKER] `frontend/docker-compose.yml` chưa được vá cùng lỗi HOME/UID, dev server sẽ hỏng trên máy UID ≠ 1000 — **ĐÃ SỬA, xác nhận vòng 2**
- Vị trí: `frontend/docker-compose.yml:16` và `:39` (service `web`, `admin`).
- Vấn đề (vòng 1): `/home/node` (ảnh `node:22-bookworm-slim`) chỉ thuộc quyền UID 1000, không ghi được với `HOST_UID=501` (macOS) như `scripts/pnpm.sh`/`Dockerfile.dev` đã tự chẩn đoán và sửa cho chính nó, nhưng `docker-compose.yml` — dùng cùng image, cùng cơ chế `--user ${HOST_UID}:${HOST_GID}` — vẫn hard-code `HOME: /home/node` ở cả 2 service, trong khi đây chính là lệnh README dùng để chạy dev server hằng ngày.
- Xác nhận sửa (vòng 2):
  - `git diff frontend/docker-compose.yml` (đã kiểm lại độc lập): cả 2 service `web` và `admin` đổi `HOME: /home/node` → `HOME: /tmp`, comment cập nhật đúng lý do ("phải ghi được với mọi UID"). Không có thay đổi ngoài phạm vi này trong file.
  - Chạy thật lại (không chỉ tin lời báo cáo): container `frontend-web-1`, `frontend-admin-1` (image `vitaminvui-frontend-dev:latest`) đang chạy, publish đúng cổng `3000`/`3001`. `curl -s -o /dev/null -w '%{http_code}'` tới `http://localhost:3000/` và `http://localhost:3001/` đều trả **200** — khớp với báo cáo "không EACCES, cả 2 trả 200".
  - Kết luận: R1 đóng, không còn BLOCKER.

### R2 [NIT] `chmod -R a+rwX /opt/corepack` cấp quyền ghi cho mọi UID trong image dev — **giữ nguyên, không bắt buộc sửa**
- Vị trí: `frontend/Dockerfile.dev:9-10`.
- Vấn đề: Cách này chạy đúng cho mục tiêu (mọi UID host chạy được corepack), nhưng có tác dụng phụ là bất kỳ tiến trình nào bên trong container (dù chạy UID nào) cũng ghi/đổi được binary pnpm đã cài. Với ảnh chỉ dùng local, container `--rm`, rủi ro gần như không có.
- Trạng thái: dev xác nhận giữ nguyên (NIT, ảnh dev only) — hợp lý, không cần chặn merge vì việc này.

### Ghi nhận ngoài phạm vi (không chấm điểm, không chặn merge)
Dev báo phát hiện thêm khi chạy thật `docker compose up` cho frontend: SSR của `apps/web` gọi `API_INTERNAL_URL=http://host.docker.internal:8000` bị `UND_ERR_SOCKET` vì Nginx (`infra/`) chỉ bind `127.0.0.1` + định tuyến theo `Host` header (liên quan N3 trong ghi chú kiến trúc cũ). Đây là lỗi hạ tầng có từ trước, không phải do diff đang review gây ra, và dev đã nói sẽ ghi thành việc riêng vào `docs/board.md` thay vì sửa lẫn ở đây — hợp lý, đúng nguyên tắc không mở rộng phạm vi ngoài yêu cầu. **Đề nghị:** đảm bảo việc này thực sự được ghi vào `docs/board.md` (chưa thấy trong lần đọc board gần nhất của tôi ở vòng 1) trước khi coi task này xong hẳn, nếu không nó sẽ bị quên — không phải điều kiện để APPROVE diff hiện tại, nhưng cần theo dõi.

## Đối chiếu phạm vi thay đổi

| Mục tiêu (theo mô tả task) | Code đáp ứng | Ghi chú |
|---|---|---|
| `phpunit.xml`: bỏ ép `DB_PASSWORD=secret`, giữ `DB_DATABASE=vitaminvui_testing` ép cứng | Có | Đã verify: DB thật `vitaminvui`/`vitaminvui_testing` tách biệt, `DB_DATABASE` vẫn `force="true"` cả 2 khối `<env>`/`<server>` |
| Larastan 16 lỗi → 0 | Có | Verify độc lập: `composer ci` → Larastan "No errors" |
| Hành vi chống dò tài khoản không đổi sau khi sửa `LoginService.php` | Có | `password` NOT NULL ở DB, ternary tương đương `?? `; test M1 vẫn pass |
| Frontend chạy được với UID ≠ 1000 (macOS) | Có (vòng 2) | `Dockerfile.dev`, `pnpm.sh` **và** `docker-compose.yml` đều đã đổi `HOME` sang thư mục mọi UID ghi được; xác nhận bằng chạy thật `docker compose up web admin` → 200 ở cả 2 cổng |
| `.env.example` cho `apps/web`/`apps/admin`, đủ biến, không secret | Có | Khớp `env.ts`/`env.server.ts` và bảng biến `frontend/README.md`; `.gitignore` cho phép commit đã verify bằng `git status --ignored=matching` |

## Gợi ý cho QA
- Xác nhận trên một máy Linux/WSL2 (UID 1000) khác rằng đổi `HOME` sang `/tmp` không làm hỏng lại flow cũ (rủi ro thấp vì `/tmp` luôn ghi được, nhưng nên chạy thật một lần).
- Theo dõi việc UND_ERR_SOCKET của SSR (`API_INTERNAL_URL`/Nginx bind 127.0.0.1) có thực sự được ghi vào `docs/board.md` thành việc riêng hay không — nếu chưa, nhắc PO/dev ghi lại trước khi đóng task.
- Không cần test lại nghiệp vụ T01–T03/FW1 — toàn bộ thay đổi trong diff này (cả vòng 1 lẫn vòng 2) là hạ tầng/dev-tooling, không đổi API/response/DB schema; `composer ci` 193 test đã xanh là đủ bằng chứng không hồi quy backend.
