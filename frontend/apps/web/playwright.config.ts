import { defineConfig, devices } from "@playwright/test";

/**
 * Smoke test FE0 (tasks.md).
 *
 * Mặc định dùng mock HTTP tối thiểu (`e2e/mock-api-server.mjs`) đứng vai host `api` —
 * không phụ thuộc backend thật. Đặt `E2E_REAL_BACKEND=1` (xem `scripts/playwright.sh
 * web --real-backend`) để gọi thẳng backend Laravel thật đang chạy ở `api.localhost:8000`
 * (infra/docker-compose.yml của laravel-dev).
 */
// Web chạy ở api.localhost:3000 (cùng site với API api.localhost:8000) — Chromium coi
// localhost/app.localhost và api.localhost là cross-site nên cookie SameSite=Lax bị bỏ (419).
const useRealBackend = process.env.E2E_REAL_BACKEND === "1";

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [["list"]],
  use: {
    baseURL: "http://api.localhost:3000",
    trace: "on-first-retry",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
  webServer: [
    ...(useRealBackend
      ? []
      : [
          {
            command: "node e2e/mock-api-server.mjs",
            port: 8000,
            reuseExistingServer: !process.env.CI,
            timeout: 15_000,
          },
        ]),
    {
      // Gọi thẳng binary local (không qua "pnpm run dev") để chạy được trong ảnh
      // mcr.microsoft.com/playwright (không có pnpm/corepack sẵn — xem scripts/playwright.sh).
      command: "./node_modules/.bin/next dev --port 3000",
      url: "http://api.localhost:3000",
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: useRealBackend
        ? // Container chạy --network host + --add-host (scripts/playwright.sh) nên
          // api.localhost phân giải đúng về backend thật trên host.
          { API_INTERNAL_URL: "http://api.localhost:8000" }
        : // Cùng container/tiến trình với mock-api-server ở trên -> gọi thẳng 127.0.0.1,
          // không cần host.docker.internal (chỉ cần cho `docker compose up` dev thường).
          { API_INTERNAL_URL: "http://127.0.0.1:8000" },
    },
  ],
});
