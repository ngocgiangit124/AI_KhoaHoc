# CI và build image (T35-3, ADR-008 §8.10)

Workflow: `.github/workflows/ci.yml` (PR + push `main`) và `.github/workflows/release-images.yml` (chạy tay: build lại web/admin production của một SHA cũ, hoặc dùng ở mô hình `staged`). Biến repo `VV_DEPLOY_MODE` chọn mô hình: `single` (một server, mặc định khi rỗng) hoặc `staged` (staging + production). GitHub KHÔNG deploy: người vận hành chạy `deploy.sh <sha>` trên server qua SSH (ADR-008 §8.11).

## Job trong `ci.yml`

| Job | Chạy khi | Nội dung |
|---|---|---|
| `backend-static` | PR, main | PHP 8.3, `pint --test`, `phpstan analyse --memory-limit=2G`, `composer audit --locked` (chặn khi có advisory; abandoned chỉ báo) |
| `backend-test` | PR, main | MySQL 8.4 + Redis 7 (service), `scripts/ci/backend-prepare.sh all`, `pest --exclude-group=race` |
| `backend-race` | PR, main, sau `backend-test` | như trên, `pest --group=race`, tự chạy lại đúng 1 lần nếu lỗi (kèm `::warning::`) |
| `frontend` | PR, main | Node 22, pnpm 9.15.9 (corepack), `pnpm install --frozen-lockfile`, `lint`, `typecheck`, `test`, `pnpm audit --prod --audit-level=high` (chặn) |
| `images` | CHỈ push `main`, sau 4 job trên xanh | bake + push GHCR: backend (+ worker-video nếu `VV_VIDEOLAB=1`) (`<sha>`), web, admin (`<env>-<sha>`). `single` (mặc định): Environment `production`, tag `production-<sha>`. `staged`: Environment `staging`, tag `staging-<sha>` |

- Quyền: `permissions: {}` toàn workflow; mỗi job `contents: read`; chỉ `images` (và `release`) thêm `packages: write`.
- Không `pull_request_target`. PR (kể cả từ fork) không chạy `images`, không đọc `vars`/`secrets` của Environment.
- Action ghim commit SHA, phiên bản ở comment cuối dòng. Cập nhật có chủ ý (đổi SHA + comment, chạy actionlint).
- `concurrency`: PR/nhánh huỷ run cũ; `main` xếp hàng, không huỷ giữa chừng khi đang đẩy image (GitHub chỉ giữ run chờ mới nhất, nên commit trung gian có thể không có image: deploy luôn theo SHA đã có image).
- Cache: composer (khoá theo `composer.lock`), pnpm store (khoá theo `pnpm-lock.yaml`), Docker `type=gha` (scope theo target: `vv-php-base`, `vv-backend`, `vv-worker-video`, `vv-web`, `vv-admin`).
- MySQL: service không nhận tham số dòng lệnh nên `backend-prepare.sh` đặt `SET GLOBAL transaction_isolation='READ-COMMITTED'`, `log_bin_trust_function_creators=1`, `time_zone='+07:00'`, rồi kiểm lại isolation. Kết nối của app còn tự đặt READ COMMITTED qua INIT_COMMAND (`config/database.php`).
- Mật khẩu `ci-root-only` / `secret` trong workflow chỉ cho service container tạm của job, không phải secret thật.

## Digest và chữ ký image (security S2)

- `images` (và `release-images`) ghi digest từng image vừa đẩy: tóm tắt job (tab Summary) và artifact `images-staging.lock` / `images-production.lock` (giữ 90 ngày). Nội dung:
  ```
  IMAGE_DIGEST_BACKEND=sha256:<64 hex>
  # ref ghcr.io/ngocgiangit124/vitaminvui-backend@sha256:<64 hex>
  ...
  ```
  `single`: `images-production.lock` có đủ `IMAGE_DIGEST_BACKEND`, `_WEB`, `_ADMIN` (thêm `_WORKER_VIDEO` chỉ khi `VV_VIDEOLAB=1`). `staged`: `images-staging.lock` đủ bộ như trên; `images-production.lock` của `release-images` chỉ có web và admin, backend dùng digest của lần staging cùng SHA.
- Người vận hành chép các dòng `IMAGE_DIGEST_*` (không cần dòng `# ref`) vào `/opt/vitaminvui/.env` trên server trước `deploy.sh deploy <sha>`; deploy.sh dừng nếu image kéo về lệch digest. Digest lấy qua kênh khác server (tab Summary của GitHub), không lấy từ chính registry.
- Mỗi image được ký cosign keyless (Sigstore, định danh OIDC của workflow; `id-token: write` chỉ ở job `images`/`release`). Kiểm trước khi deploy (máy có cosign):
  ```
  cosign verify ghcr.io/ngocgiangit124/vitaminvui-backend@sha256:<digest> \
    --certificate-identity-regexp '^https://github.com/ngocgiangit124/AI_KhoaHoc/\.github/workflows/(ci|release-images)\.yml@refs/heads/main$' \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com
  ```
  Cần `docker login ghcr.io` bằng PAT `read:packages` (package private). Chữ ký và bản ghi minh bạch (Rekor) ghi tên repo/workflow công khai dù package private.
- `VCS_REF` = SHA commit được truyền vào bake (label `org.opencontainers.image.revision` của web/admin); backend dùng `IMAGE_TAG` (= SHA) cho cùng label.

## Tuỳ chọn VideoLab (worker-video)

PO 2026-10-10: video qua Bunny, VideoLab mặc định tắt. Image `vitaminvui-worker-video` chỉ được build, đẩy và ký khi biến `VV_VIDEOLAB` = `1` (vars của repo; T35-1 dùng cùng tên). Không đặt thì chỉ backend + web + admin; `collect-digests.sh` nhận `REQUIRED_TARGETS` và thoát lỗi nếu thiếu digest của bất kỳ image nào trong danh sách (nên không có chuyện image không được ký mà job vẫn xanh).

## Theo dõi retry race và xử lý audit

- `backend-race` chạy lại 1 lần và ghi `::warning::backend-race lỗi lần 1`. Tìm cảnh báo này ở Annotations của run; nếu xuất hiện hơn 2 lần/tuần thì coi là lỗi cần điều tra (test race tồn tại để bắt lỗi đồng thời thật). Tên test lỗi lần 1 nằm trong log của bước (`--colors=never`).
- `composer audit` và `pnpm audit` là cổng bắt buộc, advisory mới công bố có thể làm đỏ PR không liên quan. Xử lý theo thứ tự: (1) nâng gói (`composer update <gói>` / override `pnpm.overrides`); (2) nếu chưa có bản vá: ngoại lệ có thời hạn, có ticket và PO duyệt, ghi mã advisory + ngày hết hạn + ticket trong PR, rồi thêm tạm `--ignore=<ID>` (composer: `composer audit --locked --ignore=PKSA-...`; pnpm: `pnpm audit --ignore=GHSA-...` hoặc `pnpm.auditConfig.ignoreGhsas`) vào bước audit; gỡ khi hết hạn. Không tắt cổng.
- Lịch chạy audit hằng tuần (`schedule`) chưa có; đề xuất thêm sau lần chạy đầu trên GitHub.

## Chạy lại ngoài GitHub

```
# MySQL 8.4 + Redis 7 đang chạy, máy chạy có mysql client và quyền ghi /etc/hosts (root hoặc sudo)
MYSQL_ROOT_PASSWORD=... scripts/ci/backend-prepare.sh all     # db + backend/.env
cd backend && vendor/bin/pest --exclude-group=race && vendor/bin/pest --group=race
```
Trong mạng Docker: đặt `MYSQL_HOST`/`REDIS_ADDR` là tên container (script tự phân giải sang IP để ghi `/etc/hosts`). Frontend: `frontend/scripts/pnpm.sh -r run lint|typecheck|test`.

## Cấu hình GitHub (PO làm, mục (d) của tasks.md)

1. Đẩy repo lên `github.com/ngocgiangit124/AI_KhoaHoc` (hoặc đổi `VV_REGISTRY` trong 2 workflow và label `source` trong `infra/production/docker-bake.hcl` nếu tên khác).
2. Settings > Actions > General: Workflow permissions = "Read repository contents and packages permissions" (token mặc định chỉ đọc; workflow tự xin thêm). Tắt "Allow GitHub Actions to create and approve pull requests" nếu bật.
3. Settings > Environments: tạo Environment **`production`**. Mô hình một server: KHÔNG bật Required reviewers (job `images` chạy thẳng sau CI xanh; an toàn dựa vào bảo vệ nhánh `main` ở bước 6). Có thể giới hạn "Deployment branches" = chỉ `main`. (Mô hình `staged`: thêm Environment `staging` không cần duyệt và bật Required reviewers cho `production`, xem mục "Khi có staging".)
4. Environment `production`, tab **Variables** (không phải Secrets; giá trị công khai vì nhúng vào bundle), tên giống hệt build arg ADR-008 §8.9:

| Var | Dùng cho | Bắt buộc |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | web | có (https) |
| `NEXT_PUBLIC_SITE_URL` | web | có (https) |
| `NEXT_PUBLIC_STATIC_URL` | web, admin | có (https) |
| `NEXT_PUBLIC_VIDEO_HOSTS` | web | có |
| `NEXT_PUBLIC_TURNSTILE_SITE_KEY` | web, admin | có (khoá THẬT của môi trường) |
| `NEXT_PUBLIC_MOMO_HOSTS` | web | không; V1 để rỗng (MoMo tắt) |
| `NEXT_PUBLIC_ADMIN_API_URL` | admin | có (https) |
| `NEXT_PUBLIC_ADMIN_URL` | admin | có (https) |
| `NEXT_PUBLIC_VIDEO_UPLOAD_URL` | admin | có (https) |

   Biến repo (Settings > Secrets and variables > Actions > Variables, không phải Environment): `VV_DEPLOY_MODE` (bỏ trống = `single`), `VV_VIDEOLAB` (bỏ trống = tắt).

   Giá trị mẫu: `frontend/apps/{web,admin}/.env.production.example` (phần BUILD). Bước `scripts/ci/check-frontend-vars.sh` dừng build khi thiếu var, URL không phải https, hoặc chứa `localhost`/`REPLACE`/khoá thử/`test-payment`. Chỉ in tên var lỗi, không in giá trị.
5. Secrets: KHÔNG tạo secret nào cho CI. Image dùng `GITHUB_TOKEN` tự cấp. Secret ứng dụng (APP_KEY, mật khẩu DB/Redis, SMTP, `INTERNAL_API_TOKEN`...) chỉ nằm ở `/opt/vitaminvui/env/*.env` trên server (ADR-008 §8.6, §8.13).
6. Settings > Branches: bảo vệ `main`, bắt buộc 4 check `backend-static`, `backend-test`, `backend-race`, `frontend` xanh trước khi merge.
7. Package GHCR: lần push đầu tạo package ở chế độ private, gắn repo nhờ label `org.opencontainers.image.source`. Kiểm ở Packages: visibility private; nếu package chưa liên kết repo, vào Package settings > "Manage Actions access" thêm repo với quyền Write (để lần sau `GITHUB_TOKEN` đẩy được).

## Quy trình phát hành (một server, `VV_DEPLOY_MODE=single`)

Cấu hình GitHub một lần (mục "Cấu hình GitHub" ở trên): Environment `production` + vars `NEXT_PUBLIC_*`, bảo vệ nhánh `main`, PAT `read:packages` cho server (mục dưới).

Mỗi lần phát hành:
1. Merge PR vào `main`. Chờ CI xanh: 4 job test rồi `images` (không cần ai duyệt). Image đẩy lên GHCR: `vitaminvui-backend:<sha>`, (`vitaminvui-worker-video:<sha>` chỉ khi `VV_VIDEOLAB=1`), `vitaminvui-web:production-<sha>`, `vitaminvui-admin:production-<sha>`, đã ký cosign.
2. Mở run `images` > Summary, chép các dòng `IMAGE_DIGEST_*` (hoặc tải artifact `images-production.lock`) và `<sha>` 40 ký tự. Run bị thay bởi commit mới hơn (GitHub chỉ giữ run chờ mới nhất) thì dùng SHA đã có image.
3. Nếu bản này có migration: chụp snapshot Vultr trước (khuyến nghị; `deploy.sh` chỉ nhắc, không đòi).
4. SSH vào server, dán `IMAGE_DIGEST_*` vào `/opt/vitaminvui/.env` (`VV_ENV=production`), chạy `./deploy.sh deploy <sha>`. Digest lệch hoặc cosign lỗi thì dừng, chưa đổi gì.
5. Lỗi sau deploy: `./deploy.sh rollback` (về `PREVIOUS_TAG`). Migration đã chạy không tự đảo: xem snapshot.

Build lại một SHA cũ (ví dụ image hết hạn cache, hoặc đổi vars `NEXT_PUBLIC_*` mà không có commit mới): Actions > `release-images` > Run workflow (nhánh `main`), nhập `sha`; workflow kiểm sha 40 hex, thuộc lịch sử `main`, image backend `<sha>` đã có, rồi build web/admin `production-<sha>` với vars `production`. Ở mô hình `single`, Environment `production` không có người duyệt nên workflow này chạy ngay. Lưu ý: vars `NEXT_PUBLIC_*` được nhúng lúc build; muốn đổi giá trị phải build lại bằng cách này (hoặc commit mới) rồi lấy digest mới.

## Khi có staging (`VV_DEPLOY_MODE=staged`)

Đặt biến repo `VV_DEPLOY_MODE=staged`. Hai server: staging và production.
- Cấu hình thêm: Environment `staging` (không cần duyệt; có vars `NEXT_PUBLIC_*` giá trị staging) và bật Required reviewers + "Deployment branches = main" cho `production`; PAT `read:packages` riêng cho mỗi server.
- Merge vào `main` > CI xanh > `images` (Environment `staging`) đẩy `vitaminvui-backend:<sha>`, `vitaminvui-web:staging-<sha>`, `vitaminvui-admin:staging-<sha>`, artifact `images-staging.lock`. Deploy staging: `deploy.sh deploy <sha>` (`VV_ENV=staging`).
- Production: Actions > `release-images` > Run workflow (nhánh `main`), nhập `sha`; chờ người duyệt Environment `production`; build `production-<sha>` từ đúng commit. Deploy production: `deploy.sh deploy <sha>` (`VV_ENV=production`, backend dùng chung image với staging, digest backend lấy từ `images-staging.lock`).

## PAT cho server kéo image

- PAT **classic**, chỉ scope `read:packages` (GHCR chưa nhận fine-grained). Tài khoản sở hữu: PO quyết (tài khoản máy riêng được khuyến nghị) và phải có quyền đọc package.
- Hết hạn 90 ngày (mô hình `staged`: một PAT riêng cho mỗi server) (đặt nhắc lịch gia hạn).
- Trên server, user `vvdeploy`: `echo "<PAT>" | docker login ghcr.io -u <tài khoản> --password-stdin` (không đặt PAT trên dòng lệnh; file `~vvdeploy/.docker/config.json` chmod 0600).
- Mất PAT: thu hồi ở GitHub, tạo lại, đăng nhập lại.

## Việc chưa làm được ở local

- `images`, `release-images` chỉ chạy được trên GitHub (cần `GITHUB_TOKEN`, cache gha). Local đã kiểm bằng actionlint + shellcheck + `bake --print` và build `--load` (xem `docs/review/T35-3.md`).
- Thời gian CI thực tế: ghi vào `docs/review/T35-3.md` sau lần chạy đầu trên GitHub.

## Ghi chú T35-1 vòng 3: `deploy.sh` đòi digest
- `deploy.sh deploy <sha>` (không `--local`) BẮT BUỘC `IMAGE_DIGEST_BACKEND`, `IMAGE_DIGEST_WEB`, `IMAGE_DIGEST_ADMIN` trong `/opt/vitaminvui/.env` (dán từ `images-*.lock`), thêm `IMAGE_DIGEST_WORKER_VIDEO` chỉ khi `VV_VIDEOLAB=1`. Thiếu thì dừng trước `pull`; lệch thì dừng trước khi đổi container.
- `VV_COSIGN_VERIFY=1` trong `.env` (server có `cosign` và đã `docker login ghcr.io`): `deploy.sh` chạy `cosign verify` với identity `refs/heads/main` của `ci.yml`/`release-images.yml` như ở trên cho từng image trước khi đổi gì.
- T35-3 chỉ build/ký image `worker-video` khi `VV_VIDEOLAB` bật.
