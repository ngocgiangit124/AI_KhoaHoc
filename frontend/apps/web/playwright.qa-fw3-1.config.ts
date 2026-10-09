import { defineConfig, devices } from "@playwright/test";

/** QA FW3-1: build riêng `.next-qa-fw3-1` (xoá sau khi xong), chạy bằng `e2e/run-qa-fw3-1.sh`. */
const DIST = process.env.E2E_DIST ?? ".next-qa-fw3-1";
export default defineConfig({
  testDir: "./e2e",
  testMatch: /qa-fw3-1-.*\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  timeout: 600_000,
  expect: { timeout: 45_000 },
  use: { baseURL: "http://api.localhost:3000", actionTimeout: 60_000, navigationTimeout: 120_000 },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: {
    command: `${
      process.env.E2E_REUSE_BUILD === "1" ? "" : `NODE_OPTIONS=--max-old-space-size=1536 NEXT_DIST_DIR=${DIST} ./node_modules/.bin/next build && `
    }NEXT_DIST_DIR=${DIST} ./node_modules/.bin/next start --port 3000`,
    url: "http://api.localhost:3000/khoa-hoc",
    reuseExistingServer: false,
    timeout: 1_500_000,
    env: { API_INTERNAL_URL: "http://api.localhost:8000" },
  },
});
