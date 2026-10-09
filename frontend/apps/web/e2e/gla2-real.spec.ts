import { expect, test, type Page } from "@playwright/test";

/**
 * GL-A2-FE — đăng nhập sai nhiều lần thì đòi captcha Turnstile (web), với backend thật (CAPTCHA_DRIVER=fake, site key thử của Cloudflare).
 * Dữ liệu: `e2e/seed-e2e-gla2.sh --reset` trước mỗi lần chạy. Tài khoản gla2-hs-1@example.com.
 */
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const LOGIN = "gla2-hs-1@example.com";
const GOOD = "matkhau-123";

async function submit(page: Page, password: string) {
  await page.getByLabel(/^Mật khẩu/).fill(password);
  const resp = page.waitForResponse((r) => r.url().endsWith("/api/v1/auth/login"));
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  return resp;
}

test("sai 5 lần -> widget Turnstile -> thử lại kèm token mới -> đăng nhập thành công", async ({ page }) => {
  const bodies: Array<Record<string, unknown>> = [];
  page.on("request", (req) => {
    if (req.url().endsWith("/api/v1/auth/login") && req.method() === "POST") bodies.push(JSON.parse(req.postData() ?? "{}"));
  });

  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(LOGIN);
  const widget = page.getByTestId("turnstile-widget");

  for (let i = 1; i <= 5; i++) {
    await expect(widget, `chưa đòi captcha ở lần ${i}`).toHaveCount(0);
    const r = await submit(page, "sai-mat-khau-" + i);
    expect(r.status()).toBe(422);
    expect(((await r.json()) as { captcha_required?: boolean }).captcha_required ?? false).toBe(i >= 5);
  }
  await expect(page.locator('form [role="alert"]')).toContainText("hoàn tất xác minh chống spam");
  await expect(widget).toBeVisible();
  const btn = page.getByRole("button", { name: "Đăng nhập" });
  // Không có token (hoặc đang chờ widget) thì nút khoá; khi widget cấp token thì mở.
  await expect(page.locator('[data-testid="turnstile-widget"] input[name="cf-turnstile-response"]')).toBeAttached({ timeout: 45_000 }); // khoá thử: không có iframe
  await expect(btn).toBeEnabled({ timeout: 45_000 });

  // Lần gửi có captcha nhưng mật khẩu vẫn sai -> 422 sai thông tin, widget được reset (token mới, nút khoá lại rồi mở lại).
  const r6 = await submit(page, "van-sai");
  expect(r6.status()).toBe(422);
  expect(bodies[5]!.captcha_token).toEqual(expect.any(String));
  const firstToken = bodies[5]!.captcha_token;
  await expect(page.locator('form [role="alert"]')).toContainText("không đúng");
  await expect(btn).toBeEnabled({ timeout: 45_000 });

  const r7 = await submit(page, GOOD);
  expect(r7.status()).toBe(200);
  expect(bodies[6]!.captcha_token).toEqual(expect.any(String));
  expect(bodies[0]!).not.toHaveProperty("captcha_token");
  await expect(page).toHaveURL(/\/$/, { timeout: 60_000 });
  expect(firstToken).toBeTruthy();
});

test("token captcha sai -> CAPTCHA_INVALID với thông điệp riêng, rồi token mới thì đăng nhập được", async ({ page }) => {
  // Phiên cũ còn cookie: xoá để thử lại từ đầu (đăng nhập đúng ở test trước đã reset bộ đếm).  await page.context().clearCookies();
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(LOGIN);
  for (let i = 1; i <= 5; i++) await submit(page, "sai-" + i);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(page.getByRole("button", { name: "Đăng nhập" })).toBeEnabled({ timeout: 45_000 });

  // Ép token "invalid" (fake driver từ chối) bằng cách sửa body request.
  await page.route("**/api/v1/auth/login", async (route) => {
    const data = JSON.parse(route.request().postData() ?? "{}") as Record<string, unknown>;
    data.captcha_token = "invalid";
    await route.continue({ postData: JSON.stringify(data) });
  });
  const r = await submit(page, GOOD);
  expect(r.status()).toBe(422);
  expect(((await r.json()) as { code?: string }).code).toBe("CAPTCHA_INVALID");
  await expect(page.locator('form [role="alert"]')).toContainText("không hợp lệ hoặc đã hết hạn");
  await page.unroute("**/api/v1/auth/login");
  await expect(page.getByRole("button", { name: "Đăng nhập" })).toBeEnabled({ timeout: 45_000 });

  const ok = await submit(page, GOOD);
  expect(ok.status()).toBe(200);
});
