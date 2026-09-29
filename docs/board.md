# Bảng theo dõi VitaminVui

Cập nhật: 2026-09-28 (tối, máy local). Phiên tiếp theo (kể cả Claude Code on the web) đọc file này trước tiên.

## Trạng thái task

Danh sách task, phụ thuộc và định nghĩa "xong": `docs/architecture/tasks.md`.

| Task | Tên | Bước hiện tại | Trạng thái | Chặn bởi | Cập nhật |
|---|---|---|---|---|---|
| T01 | Khởi tạo backend Laravel 13 + Docker | Xong | ✅ Review, Security (PASS có điều kiện), QA (PASS) | — | 2026-09-28 |
| T02 | Users, audit_logs, vai trò, staff:* | Xong | ✅ như T01 | — | 2026-09-28 |
| FE0 | Khởi tạo frontend Next.js 16 | Xong | ✅ Review; 64 unit + e2e backend thật | — | 2026-09-25 |
| T03 | Đăng ký/đăng nhập học sinh | Chờ QA giai đoạn 1 | ✅ Review PASS; Security PASS có điều kiện (còn: L3 phần DB — DBA trước staging; L2 — PO; N5 — Architect sửa api-contract §1.7) | — | 2026-09-28 |
| FW1 (phần 1) | Màn đăng ký/đăng nhập/đăng xuất (apps/web) | Chờ QA giai đoạn 1 | ✅ Review PASS; Security PASS | — | 2026-09-28 |
| FW1 (phần 2) | Màn xác thực OTP | Dev xong, chưa review | Nhánh `claude/zen-dirac-fmucf7-fw1-otp` (7e5fa1f). Có 4 giả định cần đối chiếu với T04 | T04 | 2026-09-28 |
| T04 | OTP | Xong (đã gộp) | Gộp vào nhánh chính. Review APPROVE, Security PASS có điều kiện: M1/M2/N1 đã sửa. **Còn M3** (đổi liên hệ cần xác minh) — PO/Architect chốt, dev sửa trước T27. `composer ci` xanh 339 test. Chờ QA giai đoạn 1 | M3 (PO) | 2026-09-29 |
| T05 | Một phiên học sinh | Dev | Worktree `.claude/worktrees/t05` từ nhánh chính (đã có T03+T04) | — | 2026-09-29 |
| T06 | Chuyên đề | Xong (đã gộp) | Gộp vào nhánh chính (3f420ba). Review APPROVE vòng 2. `composer ci` xanh, Pest 264. Chờ QA giai đoạn 2 | — | 2026-09-28 |
| T07 → T10 | Schema nội dung → danh mục công khai | Xong (đã gộp) | Gộp vào nhánh chính (b5b753b). Review APPROVE, DBA duyệt. `composer ci` nhánh chính xanh, Pest 246. Chờ QA giai đoạn 2 | R1 (PO) trước T13 | 2026-09-28 |
| T17 | Thanh toán: abstraction + MoMo | Xong (đã gộp) | Gộp vào nhánh chính. Review APPROVE (2 vòng), Security PASS có điều kiện: M1, M2, L1–L4, R4 đã sửa. **Gate go-live:** kiểm chứng sandbox MoMo thật (R1/R2). `composer ci` xanh 450 test | Gate go-live | 2026-09-29 |
| T28 | Đăng nhập quản trị | Dev | Worktree `.claude/worktrees/t28` từ nhánh chính. Chặn T08, T24, T33, FA1+ | — | 2026-09-29 |

## Bàn giao về máy local (2026-09-28, cuối phiên Claude Code on the web)

Nhánh chính `claude/zen-dirac-fmucf7`: T03 + FW1 phần 1 đã qua review và security, chờ QA giai đoạn 1. Các nhánh phụ bên dưới **chưa review**, tách từ `57e2439`:

| Nhánh | Nội dung | Trạng thái |
|---|---|---|
| `claude/zen-dirac-fmucf7-fw1-otp` | FW1 phần 2: `/xac-thuc-otp` | Xong code, lint/typecheck/test/build xanh trên cloud |
| `claude/zen-dirac-fmucf7-t06` | T06 chuyên đề | WIP |
| `claude/zen-dirac-fmucf7-t07-t10` | T07 schema (xong code) + T10 danh mục (WIP) | WIP |
| `claude/zen-dirac-fmucf7-t17` | T17 thanh toán + MoMo | Gần xong |

### Các bước ở máy local

**1. Kéo code về**
```bash
git fetch origin
git checkout claude/zen-dirac-fmucf7      # nhánh chính (đã review + security)
git pull origin claude/zen-dirac-fmucf7
```

**2. Dựng lại môi trường và kiểm nhánh chính** (có package frontend mới và migration `consents` mới)
```bash
cd infra
VV_UID=$(id -u) VV_GID=$(id -g) docker compose up -d --build
docker compose exec php composer install            # composer.lock không đổi, chỉ để chắc vendor đủ
docker compose exec php php artisan migrate
docker compose exec -T php composer ci               # Pint + Larastan + Pest — PHẢI xanh
cd ..
frontend/scripts/pnpm.sh install                     # lock có thêm react-hook-form, @hookform/resolvers
frontend/scripts/pnpm.sh run lint
frontend/scripts/pnpm.sh run typecheck
frontend/scripts/pnpm.sh run test
frontend/scripts/pnpm.sh run build
```
Kết quả trên cloud để so: Pest 193 pass, frontend 111 test pass. Larastan chưa chạy lần nào cho code T03, nên nếu báo lỗi thì giao `laravel-dev` sửa.

**3. Làm tiếp từng nhánh phụ** (mỗi nhánh một lượt, theo thứ tự: T07-T10 → T06 → T17 → FW1-OTP)
```bash
git checkout -b t07-t10 origin/claude/zen-dirac-fmucf7-t07-t10
cd infra && docker compose exec -T php composer ci   # chạy lại test thật trong Docker
```
Hoàn thiện phần WIP → `laravel-reviewer` → `laravel-security` (task [SEC]) → gộp vào nhánh chính:
```bash
git checkout claude/zen-dirac-fmucf7
git merge --no-ff t07-t10
```

**4. Câu lệnh mẫu cho Claude Code ở local**
> Đọc docs/board.md mục "Bàn giao về máy local". Kiểm nhánh chính bằng composer ci trong Docker và test frontend; lỗi thì giao laravel-dev/nextjs-dev sửa. Sau đó làm tiếp nhánh claude/zen-dirac-fmucf7-t07-t10 (hoàn thiện T10), rồi T06, T17, FW1-OTP, theo quy trình dev → laravel-reviewer → laravel-security → gộp. Song song bắt đầu T04 từ nhánh chính. Hỏi tôi trước khi commit/push.

### Kết quả dựng máy local (2026-09-28 tối, macOS UID 501) — chưa commit, chờ PO duyệt

Nhánh chính giờ xanh trong Docker: `composer ci` (Pint, Larastan 0 lỗi, Pest 193 pass); frontend lint/typecheck/test (111)/build xanh; `next dev` web/admin lên 200. Đã sửa (review APPROVE, `docs/reviews/review-local-setup.md`):
- `backend/phpunit.xml` ép `DB_PASSWORD=secret` → 192 test lỗi trên Docker (mật khẩu local ngẫu nhiên theo M6). Bỏ ép, dùng `backend/.env`. Cloud vẫn chạy (cloud-setup đặt `.env` = secret).
- Larastan 16 lỗi: `@mixin User` ở `UserResource`, `@property` Carbon ở `User`, `LoginService:81` đổi sang ternary (hành vi chống dò tài khoản giữ nguyên).
- `frontend/Dockerfile.dev`, `scripts/pnpm.sh`, `docker-compose.yml`: chạy được với UID ≠ 1000 (`COREPACK_HOME=/opt/corepack`, `HOME=/tmp`).
- `.gitignore` apps/web, apps/admin có `.env*` nên `.env.example` chưa từng được commit → thêm `!.env.example` + tạo 2 file mẫu.

Máy mới: copy `infra/.env.example` → `infra/.env`, `backend/.env.example` → `backend/.env` (mật khẩu khớp nhau), `apps/*/.env.example` → `.env.local`; `php artisan key:generate`. Đổi `.env` thì `docker compose up -d --force-recreate php queue scheduler` (restart không nạp lại `env_file`).

**Cảnh báo chạy lệnh cho worktree:** `docker compose run -e DB_DATABASE=...` KHÔNG đổi được DB cho `artisan` (Laravel đọc `.env` của worktree/`env_file`). Muốn migrate DB test riêng thì dùng `--database`/đặt `DB_DATABASE` trong `.env` của worktree, hoặc chỉ chạy qua `pest -c phpunit.<task>.xml`. Lần đầu (2026-09-28) lệnh `migrate:fresh` của DBA đã chạy vào DB dev `vitaminvui` (lúc đó trống, không mất dữ liệu).

**Việc mở mới:** SSR của apps/web gọi `API_INTERNAL_URL=http://host.docker.internal:8000` lỗi `UND_ERR_SOCKET` (nginx bind `127.0.0.1` theo N3 + định tuyến theo Host). Trang vẫn 200 nhưng không có dữ liệu SSR. Cần Architect/laravel-dev chốt cách nối mạng 2 compose (network chung, gọi `http://nginx` với Host `api.localhost`) — làm trước T10 (danh mục công khai SSR).

### Lưu ý khi làm tiếp

1. Chạy `composer ci` đầy đủ (có Larastan) trong Docker trên nhánh chính. Trên cloud không chạy được Larastan và PHP là 8.4.
2. Mỗi nhánh phụ: chạy lại toàn bộ test trong Docker trước khi tin kết quả. Trên cloud, `vendor` của worktree là symlink về repo gốc nên Composer/Pest có thể đã nạp nhầm code của repo gốc; kết quả test agent báo ở các nhánh phụ **chưa đáng tin**.
3. Xung đột khi gộp: T06 và T07 cùng tạo `subjects` (migration `2026_09_28_090000_create_subjects_table.php`, `Subject`, `SubjectFactory`, `SubjectStatus`). Gộp T07 trước, T06 dùng lại bản của T07 và bật lại test "chặn xoá khi đang gán".
4. FW1 phần 2 giả định về T04 (đối chiếu khi làm T04): `resend_available_at` dạng ISO 8601 có offset; register chưa trả `resend_available_at` nên FE ước lượng cooldown lần đầu; email/SĐT ở màn OTP do FE tự che; 429 khoá 24h không có thời điểm mở khoá.
5. Quy trình mỗi nhánh: `laravel-reviewer` → `laravel-security` (T04, T17 [SEC]) → gộp vào nhánh chính. `laravel-qa` chạy khi xong giai đoạn.

## Việc tiếp theo (theo thứ tự)

1. **T03** (`laravel-dev`) và **FW1 phần đăng ký/đăng nhập** (`nextjs-dev`) chạy song song, cả hai bám `docs/architecture/api-contract.md` §2.2.
   - T03: làm theo tasks.md mục T03. Không làm OTP (T04), một phiên (T05), quên mật khẩu (T27), đăng nhập quản trị (T28), xác nhận phụ huynh (T29).
   - Khi T03 thêm route `auth:sanctum` đầu tiên: đổi `expect($checked)->toBe(0)` trong `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php` theo TODO, thêm `role:hoc_sinh` cho nhóm student và test kiến trúc cho host api (ghi chú của Security ở `docs/security/review-T01-T02.md`).
   - FW1: chỉ màn Đăng ký, Đăng nhập, đăng xuất. Được cài `react-hook-form` + `@hookform/resolvers`. Turnstile dùng script chính thức.
2. Mỗi task đi qua quy trình: `laravel-reviewer` → sửa → `laravel-security` (task có [SEC]) → `laravel-qa` → PO duyệt commit/push.
3. Sau đó: T04 → T05 → T27, rồi T28 (đăng nhập quản trị, phải xong trước FA1).

## Quy tắc làm việc đã thống nhất với PO

- (2026-09-29, PO) **Tạm hoãn `laravel-security` theo từng task.** Quy trình mỗi task: dev → `laravel-reviewer` → gộp. Mọi task [SEC] làm từ nay (T05, T28 trở đi) ghi vào danh sách "Chờ security cuối dự án" bên dưới; security chạy một lượt khi xong dự án, trước release. Các phát hiện security đã có (T04 M3, T17 gate sandbox) vẫn theo dõi như cũ.

- (2026-09-28, L2) Staff (admin/QLT/GV) đăng nhập đúng mật khẩu ở trang học sinh: **chuyển về trang quản trị/giáo viên** (`admin.vitaminvui.vn`). Giữ `WRONG_PORTAL`, frontend web điều hướng sang trang quản trị. PO chấp nhận rủi ro host học sinh cho biết mật khẩu staff đúng (lớp chặn còn lại: throttle 10 lần sai/giờ/tài khoản + 50/giờ/IP). Làm ở T28 + FA1; cách truyền URL trang quản trị (biến môi trường frontend hay thêm trường vào response) do Architect chốt.

- Chỉ commit/push khi PO đồng ý. `main` chỉ chứa code đã qua review. Nhánh làm việc `claude/zen-dirac-fmucf7` được commit WIP (PO đồng ý 2026-09-28).
- (2026-09-28) Task không phụ thuộc nhau chạy song song: mỗi agent một git worktree (`.claude/worktrees/`, không commit) và DB test riêng (`vitaminvui_testing_<task>`), gộp vào nhánh làm việc sau khi qua review.
- (2026-09-28) `laravel-qa` chạy một lần khi xong mỗi giai đoạn trong `tasks.md`, không chạy sau từng task. Mỗi task vẫn qua `laravel-reviewer` và `laravel-security` (task [SEC]). Báo cáo QA: `docs/qa/giai-doan-<số>.md`.
- Dev chạy mọi thứ trong Docker ở máy local. Trên cloud, `scripts/cloud-setup.sh` cài trực tiếp (xem CLAUDE.md).
- Không cài package ngoài danh sách đã duyệt trong tasks.md (cổng G2) mà không hỏi PO.
- Không tự bịa field ngoài api-contract. Thấy thiếu hoặc mâu thuẫn thì dừng và hỏi Architect.

## Chờ security cuối dự án

Task [SEC] đã gộp nhưng chưa qua `laravel-security` (do PO tạm hoãn 2026-09-29): (chưa có)

## Gate trước go-live

- **Thanh toán MoMo (T17 R1/R2):** chạy `php artisan payments:momo:verify-sandbox` với key sandbox, đối chiếu danh sách trường ký create/IPN/query và bảng `resultCode` với tài liệu MoMo hiện hành; sau đó thêm verify chữ ký phản hồi create (R2). Chưa làm thì không bật `PAYMENT_GATEWAYS=momo` ở production.
- **Pháp chế (T17):** vai trò MoMo khi nhận dữ liệu (có cần thoả thuận xử lý dữ liệu), thời hạn lưu `payment_webhook_events`/`create_response`.

## Việc đã hoãn (có người phụ trách)

- Quyền MySQL/trigger chặn sửa `audit_logs` (DBA, trước staging).
- Header bảo mật cho response do Nginx tự trả (N2), cấu hình log production (L5), giới hạn IP cho `/up`: T31.
- L2: route domain dạng tham số, khi có route như vậy.

## Chờ PO trả lời


1. Throttle `csrf` đang 30 lần/phút/IP. Có nâng lên 120 cho lớp học dùng chung NAT không?
2. Các mặc định an toàn ở `docs/architecture/README.md` §8 (MFA staff, che PII khi xuất file, TTL link HLS...).
3. LB/CDN production có chuyển tiếp `X-Forwarded-Host` từ client không (chốt ở T31).
4. Pháp chế: Luật BVDLCN 2025, ngưỡng tuổi cần phụ huynh đồng ý, thời hạn lưu log có IP, chuyển dữ liệu ra nước ngoài.
5. (T10, R1 — PO + Architect, chốt trước T13) Khoá bị unpublish: US-003 nói học sinh đã mua vẫn xem được, api-contract §2.1 nói 404 không ngoại lệ. Code đang theo api-contract.
6. (T10 — Architect) Tie-break sort `featured`: data-model ghi `published_at desc`, story + code dùng `created_at desc`. Chốt tài liệu.
7. (T04) Audit `otp.daily_limit`: reviewer tìm được cách ghi an toàn không cần sửa `ApiExceptionRenderer` — dev đang làm, Security xác nhận ở vòng [SEC]. Không cần PO trả lời.
8. (T04 → FW1 phần 2 — Architect) 429 có header `Retry-After`; T04 đang thêm vào `cors.exposed_headers` và cho mọi 429. Security khuyên FW1 tính cooldown từ `resend_available_at` + `otp.resend_cooldown_seconds`, không dựa vào `Retry-After`. Ghi rõ vào api-contract §1.6/§1.7.
9. (T04, R4 — Architect) `otp_codes.user_id` code dùng `restrictOnDelete`, data-model ghi cascade. Đồng bộ tài liệu.
10. (T04, M3 — PO + Architect, chốt trước T27) Đổi email/SĐT (`PUT /auth/contact`) hiện không cần mật khẩu, không báo về địa chỉ cũ, không audit → người dùng chung máy ở trường đổi email rồi "quên mật khẩu" là chiếm tài khoản. Security đề xuất: bắt mật khẩu hiện tại hoặc OTP kênh cũ; thông báo về địa chỉ cũ; audit; T27 tạm không gửi tới kênh vừa đổi. Đổi api-contract.
11. (T04, L1/S20 — PO) Thời hạn dọn tài khoản chưa xác thực; thời hạn lưu `otp_codes` (có PII `destination`) và `failed_jobs` — cần pháp chế.

## Nhật ký

- 2026-09-25 · US-001..018 · BA/Designer/Architect/Security/DBA · Xong tài liệu thiết kế
- 2026-09-25 · T01, T02, FE0 · Dev + Reviewer · Xong, đã sửa R1–R5
- 2026-09-28 · T01, T02 · Security + QA · Security FAIL (H1) → sửa → PASS có điều kiện; QA PASS; 114 test; commit 58fa34d
- 2026-09-28 · T03, FW1 · Dev · Tạm dừng trước khi code, bàn giao cho Claude Code on the web
- 2026-09-28 · T03, FW1 · Dev + Reviewer (2 vòng) + Security · Review PASS; Security PASS có điều kiện, đã sửa; 192 test backend, 111 test frontend. Cloud: PHP 8.4, không chạy được Larastan (mạng chặn tải phpstan) → chạy `composer ci` trên Docker local trước khi merge main
- 2026-09-28 · PO · Đổi quy trình: QA theo giai đoạn; chạy song song task độc lập (T04, T06, T07→T10, T17, FW1 phần 2)
- 2026-09-28 · Orchestrator · Dừng các agent cloud, push nhánh phụ WIP để PO chuyển về local làm tiếp
- 2026-09-29 · Orchestrator · Gộp T17 vào nhánh chính (sửa test baseline ProductionConfigGuard của T01/T04 cho khớp guard MoMo mới), 450 test xanh
- 2026-09-29 · Orchestrator · Gộp T04 vào nhánh chính, `composer ci` xanh 339 test. Bắt đầu T05 + T28 song song
- 2026-09-29 · Orchestrator · CI chạy thêm `tests/Arch` (4cbd935, trước đó bị bỏ qua). T04 security vòng 2 PASS có điều kiện (N1). T17 review APPROVE, security PASS có điều kiện
- 2026-09-28 · Orchestrator · Gộp T06 vào nhánh chính (3f420ba), `composer ci` xanh 264 test. T04 security PASS có điều kiện (M1, M2 đang sửa)
- 2026-09-28 · Orchestrator · PO duyệt: gộp T07-T10 vào nhánh chính (b5b753b), `composer ci` xanh 246 test
- 2026-09-28 · Dev + Reviewer + DBA · T07-T10 hoàn thiện, review APPROVE, DBA thêm index; T04 dev xong (Pest 236); T06 bắt đầu hoàn thiện. Worktree test: mount `-v <wt>/backend:/var/www/wt -w /var/www/wt`, DB `vitaminvui_testing_<task>`, file `phpunit.<task>.xml` (untracked)
- 2026-09-28 · Dev + Reviewer · Dựng máy local; sửa phpunit DB_PASSWORD, Larastan 16 lỗi, pnpm/compose UID, `.env.example` frontend; review APPROVE; chờ PO duyệt commit
