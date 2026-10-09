# GL-A1 — Nâng `next` vá lỗ hổng (SSRF Image Optimization, cache poisoning)

Nguồn: `docs/security/go-live-triage.md` mục A1. Ngày: 2026-10-09.

## Thay đổi

| Gói | Cũ | Mới | Nơi |
|---|---|---|---|
| `next` | 16.3.6 | 16.3.8 (bản vá duy nhất sau 16.3.7) | `frontend/apps/web/package.json`, `frontend/apps/admin/package.json` |
| `eslint-config-next` | 16.3.6 | 16.3.8 | như trên |
| `sharp` (bắc cầu qua next) | 0.35.4 | 0.35.5 | `pnpm.overrides` ở `frontend/package.json` |
| `source-map-js` (bắc cầu qua jsdom/css-tree) | 1.2.1 | 1.2.2 | `pnpm.overrides` ở `frontend/package.json` |

Không thêm package mới. `pnpm-lock.yaml` cập nhật (next, sharp, source-map-js, @next/*). `next.config.ts` của web và admin không cần đổi; `images.remotePatterns` giữ nguyên.

Cần override vì sau khi nâng `next` thì `sharp@0.35.4` vẫn còn (high), và `source-map-js@1.2.1` đi qua jsdom/isomorphic-dompurify.

## Audit

- Trước: `pnpm audit --prod` báo 8 lỗ (1 low, 4 moderate, 3 high).
- Sau khi nâng next (chưa override): 2 high còn lại (`sharp`, `source-map-js`).
- Sau override: `No known vulnerabilities found` (prod).
- Ghi chú: `pnpm audit` đầy đủ (gồm devDependencies) còn 7 lỗ (2 moderate, 3 high, 2 critical) ở phần dev, ngoài phạm vi GL-A1. Chưa phân tích; nên có task riêng nếu cổng G2 tính cả dev.

## Kết quả kiểm tra (Docker `vitaminvui-frontend-dev`)

- `lint` web + admin: PASS.
- `typecheck`: `packages/*`, web, admin PASS khi chạy `tsc --noEmit` với tsconfig tạm bỏ qua `.next*` (cờ `pnpm typecheck` gốc lỗi vì `.next/dev/types/validator.ts` của dev server dùng chung còn tham chiếu `app/(site)/thanh-toan/da-gui/page` đã không còn; lỗi cũ, không do nâng bản, tự hết khi dev server sinh lại type).
- `test`: web 586/586 PASS. Admin 578/580 lượt đầu: 2 test của `OrderDetailScreen.test.tsx` timeout 5 giây do máy quá tải (load average 8-11, setup jsdom 345 giây); chạy lại riêng file này 13/13 PASS.
- `next build` web + admin với `NEXT_DIST_DIR=.next-gla1`: PASS (Next.js 16.3.8, Turbopack). Thư mục `.next-gla1` đã xoá. Để né validator cũ ở trên, build chạy với `.next` được phủ bằng thư mục rỗng trong container (không đụng `.next` thật).
- Smoke (build riêng, web :3100, admin :3101, container tạm đã gỡ): `/`, `/khoa-hoc`, `/dang-nhap` (web) và `/dang-nhap` (admin) đều 200; log server không lỗi. Ảnh tĩnh SVG `/trang-chu/...svg` 200. Chưa mở bằng trình duyệt thật nên chưa đọc console trình duyệt; không có ảnh raster trong `public/` và container không tới được máy chủ ảnh `localhost:8080` nên chưa chạy tối ưu ảnh thật.
- Kiểm chứng SSRF: `GET /_next/image?url=http://169.254.169.254/...` trả 400 ở cả web và admin.

## Cần coordinator

- Dev server :3000/:3001 dùng chung `node_modules` nên **cần restart** để nạp next 16.3.8 (không tự restart).
- Sau restart, `.next/dev/types` sẽ sinh lại và hết lỗi typecheck validator cũ.
- Chưa chạy e2e smoke (đăng nhập, giỏ, đơn) vì cần backend thật; QA chạy bước này.
