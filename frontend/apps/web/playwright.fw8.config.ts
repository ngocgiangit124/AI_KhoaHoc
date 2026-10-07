import { defineConfig, devices } from "@playwright/test";

/**
 * Cấu hình e2e THẬT cho trang chủ (FW8/FW9) — chạy bằng `e2e/run-home-real.sh` trong ảnh Playwright.
 * Tự dựng `next dev` riêng (distDir `.next-e2e-fw8`, không đụng dev server cổng 3000 của máy host) trỏ SSR vào
 * `e2e/fw8-proxy.mjs` (cổng 8001) để test làm lỗi/rỗng từng nguồn dữ liệu; trình duyệt vẫn gọi API thật ở cổng 8000.
 */
export default defineConfig({
  testDir: "./e2e",
  testMatch: /(home|course-detail)-real\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  timeout: 240_000,
  use: { baseURL: "http://api.localhost:3000" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    { command: "node e2e/fw8-proxy.mjs", url: "http://127.0.0.1:8001/__stats", reuseExistingServer: false, timeout: 15_000 },
    {
      command: "NEXT_DIST_DIR=.next-e2e-fw8 ./node_modules/.bin/next dev --port 3000",
      url: "http://api.localhost:3000/khoa-hoc",
      reuseExistingServer: false,
      timeout: 180_000,
      env: { API_INTERNAL_URL: "http://api.localhost:8001" },
    },
  ],
});
