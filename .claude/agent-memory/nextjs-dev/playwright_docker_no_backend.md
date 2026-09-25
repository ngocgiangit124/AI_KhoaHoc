---
name: playwright-docker-no-backend
description: Cách chạy Playwright e2e trong Docker (không cài trình duyệt lên host) khi backend thật chưa tồn tại — dùng ảnh chính thức + mock HTTP server tối thiểu
metadata:
  type: feedback
---

Khi cần smoke test Playwright nhưng (a) host không có Node/browser, (b) backend thật
chưa chạy: dùng ảnh `mcr.microsoft.com/playwright:v<version>-noble` (khớp đúng version
`@playwright/test` trong `package.json`) thay vì `playwright install --with-deps` trong
ảnh `node:22` thường — ảnh chính thức đã có sẵn browser + thư viện hệ thống, nhanh hơn
nhiều và không cần internet/apt lúc chạy test.

**Bẫy khi chạy non-root trong ảnh Playwright:** không dùng `corepack enable` (cần ghi
`/usr/bin`, bị từ chối khi `--user $(id -u):$(id -g)`). Gọi thẳng binary local đã có sẵn
từ lần `pnpm install` trước đó (bind mount dùng chung với ảnh node:22 thường):
`./node_modules/.bin/playwright test`, và trong `playwright.config.ts`, đổi
`webServer.command` từ `"pnpm run dev"` thành `"./node_modules/.bin/next dev --port ..."`
(pnpm không có sẵn trong ảnh Playwright).

**Không có backend:** viết mock HTTP server tối thiểu bằng `node:http` thuần (không thêm
package như `msw`), phân biệt host giả lập bằng header `Host` giống hệt
`Route::domain()` thật của Laravel (ví dụ phân biệt `admin-api.*` vs host khác). Set/không
set CORS header dựa theo `Origin` request để test được cả case "được phép" và "bị chặn"
— trình duyệt tự enforce CORS dựa vào response header thật, không cần giả lập gì thêm ở
phía Playwright.

**Why:** VitaminVui FE0 cần Playwright smoke test nhưng backend Laravel (T01) chưa chạy
song song, và máy host không có browser/Node phù hợp.

**How to apply:** Xem `frontend/scripts/playwright.sh`,
`frontend/apps/*/e2e/mock-api-server.mjs`, `frontend/apps/*/playwright.config.ts` trong
[[vitaminvui-fe0-workspace]] làm mẫu cho project khác có cùng ràng buộc.
