import { expect, test } from "@playwright/test";

/** QA FA10: tài khoản staff đã khóa đăng nhập -> thông báo rõ (seed-e2e-staff.sh --reset: e2e-fa10-gv-dakhoa đang khóa). */
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

test("tài khoản đã khóa đăng nhập: không vào được, có thông báo rõ", async ({ page }) => {
  await page.goto("http://admin-api.localhost:3001/dang-nhap");
  await page.locator('input[name="login"]').fill("e2e-fa10-gv-dakhoa@example.com");
  await page.locator('input[name="password"]').fill("Password123!");
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page.getByText(/khóa|khoá/).first()).toBeVisible({ timeout: 15_000 });
  await expect(page).toHaveURL(/dang-nhap/);
  console.log("locked login msg:", (await page.locator("main").innerText()).replace(/\n/g, " / ").slice(0, 300));
});
