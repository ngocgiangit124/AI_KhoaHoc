import { expect, test } from "@playwright/test";

test("trang chủ hiển thị lớp 6–12 lấy từ API và có CSP nonce", async ({ page }) => {
  const response = await page.goto("/");
  expect(response).not.toBeNull();

  const csp = response?.headers()["content-security-policy"];
  expect(csp, "response phải có header Content-Security-Policy").toBeTruthy();
  expect(csp).toContain("nonce-");
  expect(csp).toContain("frame-ancestors 'none'");

  for (const grade of [6, 7, 8, 9, 10, 11, 12]) {
    await expect(page.getByRole("link", { name: `Lớp ${grade}`, exact: true })).toBeVisible();
  }
});

test("bản xem trước /v2 bị chặn 404 thật ở production (không đặt V2_PREVIEW); route thật vẫn 200", async ({ request }) => {
  test.skip(!(process.env.E2E_WEB_COMMAND ?? "").includes("next start"), "Chỉ kiểm với bản build production (E2E_WEB_COMMAND=next start)");
  for (const path of ["/v2", "/v2/khoa-hoc", "/v2/dang-nhap"]) {
    expect((await request.get(path)).status(), path).toBe(404);
  }
  expect((await request.get("/")).status()).toBe(200);
});
