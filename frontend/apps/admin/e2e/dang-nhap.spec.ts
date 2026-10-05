import { expect, test } from "@playwright/test";

const ADMIN_ORIGIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000";
const REAL = process.env.E2E_REAL_BACKEND === "1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";

// Tài khoản seed của backend (DatabaseSeeder, chỉ ở môi trường local), mật khẩu mặc định factory.
const TEACHER = { email: "teacher@vitaminvui.test", password: "password" };
const ADMIN = { email: "admin@vitaminvui.test", password: "password" };

test("trang /dang-nhap hiển thị form đăng nhập quản trị và có CSP nonce", async ({ page }) => {
  const response = await page.goto(`${ADMIN_ORIGIN}/dang-nhap`);
  expect(response).not.toBeNull();

  const csp = response?.headers()["content-security-policy"];
  expect(csp, "response phải có header Content-Security-Policy").toBeTruthy();
  expect(csp).toContain("nonce-");
  expect(csp).toContain("frame-src 'none'");

  await expect(page.getByRole("heading", { name: "Đăng nhập quản trị" })).toBeVisible();
  await expect(page.getByLabel("Email", { exact: false }).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Đăng nhập" })).toBeEnabled();
});

test("trình duyệt lấy được CSRF token từ admin-api (CORS đúng origin)", async ({ page }) => {
  await page.goto(`${ADMIN_ORIGIN}/dang-nhap`);

  const responsePromise = page.waitForResponse((r) => r.url() === `${API}/api/v1/csrf-token`);
  const body = await page.evaluate(async (url) => {
    const res = await fetch(url, { credentials: "include", headers: { Accept: "application/json" } });
    return { status: res.status, json: (await res.json()) as { token?: string } };
  }, `${API}/api/v1/csrf-token`);
  const response = await responsePromise;

  expect(body.status).toBe(200);
  expect(typeof body.json.token).toBe("string");
  expect(response.headers()["access-control-allow-origin"]).toBe(ADMIN_ORIGIN);
  expect(response.headers()["access-control-allow-credentials"]).toBe("true");
});

test.describe("đăng nhập thật (cần backend + seed)", () => {
  test.skip(!REAL, "Chỉ chạy với E2E_REAL_BACKEND=1 (backend Laravel thật + seed local)");

  test("giáo viên đăng nhập → vào /quan-tri, không có mục dành cho Admin, đăng xuất", async ({ page }) => {
    await page.goto(`${ADMIN_ORIGIN}/dang-nhap`);
    await page.locator('input[name="login"]').fill(TEACHER.email);
    await page.locator('input[name="password"]').fill(TEACHER.password);
    await page.getByRole("button", { name: "Đăng nhập" }).click();

    await expect(page).toHaveURL(`${ADMIN_ORIGIN}/quan-tri`, { timeout: 20_000 });
    await expect(page.getByTestId("staff-name")).toContainText("Demo Giáo viên");
    await expect(page.getByRole("navigation", { name: "Menu quản trị" }).getByText("Tài khoản staff")).toHaveCount(0);

    // F5 vẫn giữ phiên.
    await page.reload();
    await expect(page.getByTestId("staff-name")).toBeVisible({ timeout: 20_000 });

    await page.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
  });

  test("sai mật khẩu → thông điệp chung, ở lại trang đăng nhập", async ({ page }) => {
    await page.goto(`${ADMIN_ORIGIN}/dang-nhap`);
    await page.locator('input[name="login"]').fill(TEACHER.email);
    await page.locator('input[name="password"]').fill("sai-mat-khau-123");
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page.getByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeVisible({ timeout: 20_000 });
  });

  test("admin đăng nhập → MFA qua email (Mailpit) → /quan-tri có mục Tài khoản staff", async ({ page, request }) => {
    const startedAt = Date.now() - 5_000;

    await page.goto(`${ADMIN_ORIGIN}/dang-nhap`);
    await page.locator('input[name="login"]').fill(ADMIN.email);
    await page.locator('input[name="password"]').fill(ADMIN.password);
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });

    let code: string | null = null;
    await expect
      .poll(
        async () => {
          const list = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${ADMIN.email}` } });
          const messages = ((await list.json()) as { messages: { ID: string; Created: string }[] }).messages;
          const latest = messages.find((m) => Date.parse(m.Created) >= startedAt);
          if (!latest) return null;
          const detail = (await (await request.get(`${MAILPIT}/api/v1/message/${latest.ID}`)).json()) as { Text: string };
          code = /\b(\d{6})\b/.exec(detail.Text)?.[1] ?? null;
          return code;
        },
        { timeout: 30_000 },
      )
      .not.toBeNull();

    await page.keyboard.type(code!);
    await expect(page).toHaveURL(`${ADMIN_ORIGIN}/quan-tri`, { timeout: 20_000 });
    await expect(page.getByRole("navigation", { name: "Menu quản trị" }).getByText("Tài khoản staff")).toBeVisible();
  });
});
