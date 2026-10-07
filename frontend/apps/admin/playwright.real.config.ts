import { defineConfig, devices } from "@playwright/test";

/**
 * Cấu hình e2e THẬT không tự dựng server: dùng dev server admin (3001) và backend (8000) đang chạy sẵn trên máy
 * (qua bộ chuyển tiếp TCP của `e2e/run-real.sh`). Tránh `webServer` của playwright.config.ts vì sẽ chạy `next dev`
 * thứ hai trên cùng thư mục `.next`.
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,
  reporter: [["list"]],
  use: { trace: "retain-on-failure" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
