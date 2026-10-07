import { defineConfig, devices } from "@playwright/test";

/** e2e QA FW8/FW9 (chạy bằng e2e/run-home-qa.sh): `next start` bản build production (distDir .next-qa) + proxy ghi log. */
export default defineConfig({
  testDir: "./e2e",
  testMatch: /home-qa-real\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  timeout: 300_000,
  use: { baseURL: "http://api.localhost:3000" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    { command: "node e2e/fw8-qa-proxy.mjs", url: "http://127.0.0.1:8001/__log", reuseExistingServer: false, timeout: 15_000 },
    {
      command: "NEXT_DIST_DIR=.next-qa ./node_modules/.bin/next start --port 3000",
      url: "http://api.localhost:3000/khoa-hoc",
      reuseExistingServer: false,
      timeout: 120_000,
      env: { API_INTERNAL_URL: "http://api.localhost:8001", INTERNAL_API_TOKEN: "qa-fw8-token-qa-fw8-token-qa-fw8-token-0123" },
    },
  ],
});
