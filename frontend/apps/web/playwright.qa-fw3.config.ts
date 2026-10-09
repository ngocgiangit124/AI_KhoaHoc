import { defineConfig, devices } from "@playwright/test";

/**
 * Cấu hình e2e THẬT cho Giỏ hàng, thanh toán, đơn hàng (QA FW3) — chạy bằng `e2e/run-fw3-real.sh` trong ảnh Playwright.
 * Tự `next build` + `next start` riêng (distDir `.next-qa-fw3`, không đụng dev server cổng 3000 của máy host); trình duyệt gọi API thật ở cổng 8000.
 * Dùng bản build (không phải `next dev`) vì Docker Desktop ~7,75 GB RAM: subprocess PostCSS của Turbopack dev bị chết khi máy thiếu bộ nhớ.
 */
export default defineConfig({
  testDir: "./e2e",
  testMatch: /qa-fw3-.*\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  // Máy dev dùng chung có lúc API trả lời chậm 10–30 giây: nới thời gian chờ thay vì để test chập chờn.
  timeout: 600_000,
  expect: { timeout: 45_000 },
  use: { baseURL: "http://api.localhost:3000", actionTimeout: 60_000, navigationTimeout: 120_000 },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: {
    // E2E_REUSE_BUILD=1: dùng lại bản build cũ (chỉ khi KHÔNG sửa code ứng dụng giữa hai lần chạy).
    command: `${
      process.env.E2E_REUSE_BUILD === "1" ? "" : "NODE_OPTIONS=--max-old-space-size=1536 NEXT_DIST_DIR=.next-qa-fw3 ./node_modules/.bin/next build && "
    }NEXT_DIST_DIR=.next-qa-fw3 ./node_modules/.bin/next start --port 3000`,
    url: "http://api.localhost:3000/khoa-hoc",
    reuseExistingServer: false,
    timeout: 1_500_000,
    env: { API_INTERNAL_URL: "http://api.localhost:8000" },
  },
});
