import { expect, test, type Page } from "@playwright/test";

/**
 * GL-A2-FE — đăng nhập quản trị sai nhiều lần thì đòi captcha Turnstile, rồi sang bước MFA như cũ (backend thật, CAPTCHA_DRIVER=fake).
 * Cần admin dev server có NEXT_PUBLIC_TURNSTILE_SITE_KEY (khoá thử 1x00000000000000000000AA). Dữ liệu: `web/e2e/seed-e2e-gla2.sh --reset`.
 */
const ADMIN = "http://admin-api.localhost:3001";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

async function submit(page: Page, password: string) {
  await page.locator('input[name="password"]').fill(password);
  const resp = page.waitForResponse((r) => r.url().endsWith("/api/v1/admin/auth/login"));
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  return resp;
}

test("admin: sai 5 lần -> widget -> đúng mật khẩu kèm token -> bước MFA", async ({ page }) => {
  test.setTimeout(180_000);
  const bodies: Array<Record<string, unknown>> = [];
  page.on("request", (req) => {
    if (req.url().endsWith("/api/v1/admin/auth/login") && req.method() === "POST") bodies.push(JSON.parse(req.postData() ?? "{}"));
  });
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill("e2e-gla2-admin1@example.com");
  const widget = page.getByTestId("turnstile-widget");
  for (let i = 1; i <= 5; i++) {
    await expect(widget).toHaveCount(0);
    const r = await submit(page, "sai-mat-khau-" + i);
    expect(r.status()).toBe(422);
    expect(((await r.json()) as { captcha_required?: boolean }).captcha_required ?? false).toBe(i >= 5);
  }
  await expect(page.locator('form [role="alert"]').first()).toContainText("hoàn tất xác minh chống spam");
  await expect(widget).toBeVisible();
  const btn = page.getByRole("button", { name: "Đăng nhập" });
  await expect(widget.locator('input[name="cf-turnstile-response"]')).toBeAttached({ timeout: 45_000 });
  await expect(btn).toBeEnabled({ timeout: 45_000 });

  const r6 = await submit(page, "van-sai");
  expect(r6.status()).toBe(422);
  expect(bodies[5]!.captcha_token).toEqual(expect.any(String));
  await expect(btn).toBeEnabled({ timeout: 45_000 }); // widget mount mới -> token mới

  const r7 = await submit(page, "Password123!");
  expect(r7.status()).toBe(200);
  expect(bodies[6]!.captcha_token).toEqual(expect.any(String));
  expect(bodies[0]!).not.toHaveProperty("captcha_token");
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 30_000 });
});
