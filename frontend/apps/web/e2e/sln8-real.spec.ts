import { expect, test, type Page } from "@playwright/test";

/**
 * SLN8 — trang chi tiết khóa: người sở hữu bấm "Tiếp tục học" / bấm bài trong mục lục thì vào đúng trang học.
 * Dữ liệu: `e2e/seed-e2e-sln8.sh --reset` (in `course=.. l1=.. l2=..`; truyền qua E2E_SLN8). Chạy bằng `e2e/run-sln8-real.sh`.
 */
const ids = Object.fromEntries((process.env.E2E_SLN8 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần backend thật (E2E_REAL_BACKEND=1) và E2E_SLN8 từ seed-e2e-sln8.sh");

async function login(page: Page) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill("sln8-hs@example.com");
  await page.getByLabel(/^Mật khẩu/).fill("matkhau-123");
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
}

test("người sở hữu: Tiếp tục học và bấm bài trong mục lục đều vào đúng trang học", async ({ page }) => {
  await login(page);
  await page.goto("/khoa-hoc/e2e-sln8-khoa");
  const cont = page.getByRole("link", { name: "Tiếp tục học" }).first();
  await expect(cont).toBeVisible();
  await expect(page.getByText("Sắp mở trang học")).toHaveCount(0);
  await cont.click();
  await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}(/bai/\\d+)?$`));

  await page.goto("/khoa-hoc/e2e-sln8-khoa");
  await page.getByRole("link", { name: /Bài SLN8 hai/ }).first().click();
  await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l2}$`));
  await expect(page.getByRole("heading", { level: 1, name: "Bài SLN8 hai" })).toBeVisible();
});
