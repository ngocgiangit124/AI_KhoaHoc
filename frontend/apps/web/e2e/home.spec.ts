import { expect, test } from "@playwright/test";

test("trang chủ hiển thị lớp 6–12 lấy từ API và có CSP nonce", async ({ page }) => {
  const response = await page.goto("/");
  expect(response).not.toBeNull();

  const csp = response?.headers()["content-security-policy"];
  expect(csp, "response phải có header Content-Security-Policy").toBeTruthy();
  expect(csp).toContain("nonce-");
  expect(csp).toContain("frame-ancestors 'none'");

  for (const grade of [6, 7, 8, 9, 10, 11, 12]) {
    await expect(page.getByRole("link", { name: `Lớp ${grade}` })).toBeVisible();
  }
});
