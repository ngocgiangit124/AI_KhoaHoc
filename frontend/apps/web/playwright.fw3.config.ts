import { defineConfig, devices } from "@playwright/test";

/**
 * Cấu hình e2e THẬT cho Giỏ hàng, thanh toán, đơn hàng (FW3, US-022) — chạy bằng `e2e/run-fw3-real.sh` trong ảnh Playwright.
 * Tự `next build` + `next start` riêng (distDir `.next-fw3`, không đụng dev server cổng 3000 của máy host); trình duyệt gọi API thật ở cổng 8000.
 * Dùng bản build (không phải `next dev`) vì Docker Desktop ~7,75 GB RAM: subprocess PostCSS của Turbopack dev bị chết khi máy thiếu bộ nhớ.
 */
/** Thư mục build; đặt `E2E_DIST` (ví dụ `.next-fw3-1`) khi nhiều agent chạy song song. */
const DIST = process.env.E2E_DIST ?? ".next-fw3";

export default defineConfig({
  testDir: "./e2e",
  testMatch: /gio-hang-thanh-toan-real\.spec\.ts/,
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
      process.env.E2E_REUSE_BUILD === "1" ? "" : `NODE_OPTIONS=--max-old-space-size=1536 NEXT_DIST_DIR=${DIST} ./node_modules/.bin/next build && `
    }NEXT_DIST_DIR=${DIST} ./node_modules/.bin/next start --port 3000`,
    url: "http://api.localhost:3000/khoa-hoc",
    reuseExistingServer: false,
    timeout: 1_500_000,
    env: { API_INTERNAL_URL: "http://api.localhost:8000" },
  },
});
