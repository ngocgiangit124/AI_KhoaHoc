import { expect, test } from "@playwright/test";

test("gọi admin-api từ origin web (api.localhost:3000) bị CORS chặn", async ({ page }) => {
  await page.goto("http://api.localhost:3000/");

  const result = await page.evaluate(async () => {
    try {
      await fetch("http://admin-api.localhost:8000/api/v1/csrf-token", {
        credentials: "include",
      });
      return "ok";
    } catch {
      return "blocked";
    }
  });

  expect(result).toBe("blocked");
});
