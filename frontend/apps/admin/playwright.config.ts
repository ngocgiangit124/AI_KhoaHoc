import { defineConfig, devices } from "@playwright/test";

/**
 * Smoke test FE0 (tasks.md). Khởi cả app `admin` (3001) và `web` (../web, 3000) vì test
 * "gọi admin-api từ origin web bị chặn" cần mở trang web thật để lấy đúng Origin trình
 * duyệt.
 *
 * Mặc định dùng mock HTTP tối thiểu (`e2e/mock-api-server.mjs`) đứng vai cả 2 host
 * `api`/`admin-api` — không phụ thuộc backend thật. Đặt `E2E_REAL_BACKEND=1` (xem
 * `scripts/playwright.sh admin --real-backend`) để gọi thẳng backend Laravel thật đang
 * chạy ở `api.localhost:8000`/`admin-api.localhost:8000`.
 */
const useRealBackend = process.env.E2E_REAL_BACKEND === "1";

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [["list"]],
  use: {
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
            env: {
              MOCK_ADMIN_ORIGIN: "http://admin.localhost:3001",
            },
          },
        ]),
    {
      // Gọi thẳng binary local — xem giải thích ở apps/web/playwright.config.ts.
      command: "./node_modules/.bin/next dev --port 3001",
      url: "http://admin.localhost:3001",
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
    {
      command: "../web/node_modules/.bin/next dev --port 3000",
      cwd: "../web",
      url: "http://localhost:3000",
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: useRealBackend
        ? { API_INTERNAL_URL: "http://api.localhost:8000" }
        : { API_INTERNAL_URL: "http://127.0.0.1:8000" },
    },
  ],
});
