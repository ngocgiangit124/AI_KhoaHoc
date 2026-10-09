import { defineConfig, devices } from "@playwright/test";

/** e2e THẬT GL-A2: dùng dev server web (:3000) và backend (:8000) đang chạy (qua bộ chuyển tiếp TCP của `e2e/run-gla2-real.sh`), không dựng server. */
export default defineConfig({
  testDir: "./e2e",
  testMatch: /gla2-real\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  timeout: 240_000,
  expect: { timeout: 30_000 },
  use: { baseURL: "http://api.localhost:3000", actionTimeout: 30_000, navigationTimeout: 90_000 },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
