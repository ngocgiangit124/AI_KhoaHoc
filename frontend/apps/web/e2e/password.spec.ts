import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA T27 (US-015) — hai thiết bị, backend thật (E2E_REAL_BACKEND=1). FW1 chưa có màn quên/đổi mật khẩu
 * nên đổi/đặt lại mật khẩu gọi API trực tiếp (fetch từ trang, hoặc APIRequestContext cho khách); kiểm hành vi
 * overlay/chuyển trang của thiết bị bị huỷ phiên (SESSION_REVOKED).
 * Seed trước: `qa-t27-e2e-1..3@example.com` / `matkhau-123` (tinker/factory).
 * Cần Mailpit ở MAILPIT_URL (mặc định http://localhost:8025) để lấy mã OTP đặt lại.
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const API = process.env.E2E_API_URL ?? "http://api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://localhost:8025";
const PASSWORD = "matkhau-123";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const user = (n: number) => `qa-t27-e2e-${n}@example.com`;

async function device(browser: Browser): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext();
  return { ctx, page: await ctx.newPage() };
}

async function login(page: Page, email: string, password = PASSWORD) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu\s*\*?$/).fill(password);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
}

/** Gọi API từ trang đã đăng nhập (cùng site, cookie + CSRF + device id của chính thiết bị đó). */
async function apiFromPage(page: Page, method: string, path: string, body: unknown) {
  return page.evaluate(
    async ({ api, method, path, body }) => {
      const device = window.localStorage.getItem("vv_device_id") ?? "";
      const csrf = await (await fetch(api + "/csrf-token", { credentials: "include" })).json();
      const res = await fetch(api + path, {
        method,
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": csrf.token,
          "X-Device-Id": device,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      return { status: res.status, json: await res.json().catch(() => null) };
    },
    { api: API, method, path, body },
  );
}

async function mailCode(to: string, notBefore: number): Promise<string> {
  for (let i = 0; i < 30; i++) {
    const search = await (await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`)).json();
    const m = search.messages?.[0];
    if (m && new Date(m.Created).getTime() >= notBefore) {
      const full = await (await fetch(`${MAILPIT}/api/v1/message/${m.ID}`)).json();
      const code = /\b(\d{6})\b/.exec(full.Text ?? "")?.[1];
      if (code) return code;
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error("Không thấy mail OTP");
}

test.describe("T27 mật khẩu: hai thiết bị", () => {
  test("BR3 đặt lại mật khẩu bằng OTP (khách) -> thiết bị đang đăng nhập tải lại thành khách; đăng nhập bằng mật khẩu mới", async ({ browser, playwright }) => {
    const a = await device(browser);
    await login(a.page, user(1));

    const guest = await playwright.request.newContext({ extraHTTPHeaders: { Origin: BASE, Accept: "application/json" } });
    const csrf = await (await guest.get(`${API}/csrf-token`)).json();
    const headers = { "X-CSRF-TOKEN": csrf.token };
    const since = Date.now() - 1000;
    const forgot = await guest.post(`${API}/auth/password/forgot`, { headers, data: { login: user(1), captcha_token: "x" } });
    expect(forgot.status()).toBe(202);
    const code = await mailCode(user(1), since);
    const reset = await guest.post(`${API}/auth/password/reset`, {
      headers,
      data: { login: user(1), code, password: "matkhau-moi-456", password_confirmation: "matkhau-moi-456" },
    });
    expect(reset.status()).toBe(200);

    // Thiết bị A: request kế tiếp nhận 401 SESSION_REVOKED
    const me = await apiFromPage(a.page, "GET", "/auth/me", undefined);
    expect(me.status).toBe(401);
    expect(me.json.code).toBe("SESSION_REVOKED");

    // Tải lại: thành khách (M1 của QA T05: REVOKED không có overlay), không crash, không overlay REPLACED
    await a.page.reload();
    await expect(a.page.getByRole("link", { name: /Đăng nhập/ }).first()).toBeVisible({ timeout: 15_000 });
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    await expect(a.page.getByRole("alertdialog")).toHaveCount(0);

    // Mật khẩu cũ không còn, mật khẩu mới vào được
    await a.page.goto("/dang-nhap");
    await a.page.waitForLoadState("networkidle");
    await a.page.getByLabel("Email hoặc số điện thoại").fill(user(1));
    await a.page.getByLabel(/^Mật khẩu\s*\*?$/).fill(PASSWORD);
    await a.page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(a.page).toHaveURL(/dang-nhap/);
    await login(a.page, user(1), "matkhau-moi-456");
    await a.ctx.close();
    await guest.dispose();
  });

  test("AC4 đổi mật khẩu khi đang đăng nhập: thiết bị đổi vẫn dùng được sau tải lại; cookie cũ (thiết bị sao chép) thành khách", async ({ browser }) => {
    const b = await device(browser);
    await login(b.page, user(2));
    const oldState = await b.ctx.storageState();

    const changed = await apiFromPage(b.page, "PUT", "/auth/password", {
      current_password: PASSWORD,
      password: "matkhau-moi-456",
      password_confirmation: "matkhau-moi-456",
    });
    expect(changed.status).toBe(200);
    expect(changed.json.session_kept).toBe(true);

    await b.page.reload();
    await expect(b.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await expect(b.page.getByRole("alertdialog")).toHaveCount(0);

    // "Thiết bị khác" mang cookie phiên cũ
    const old = await browser.newContext({ storageState: oldState });
    const op = await old.newPage();
    await op.goto("/");
    await op.waitForLoadState("networkidle");
    await expect(op.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    await expect(op.getByRole("alertdialog")).toHaveCount(0);
    const me = await apiFromPage(op, "GET", "/auth/me", undefined);
    expect(me.status).toBe(401);
    expect(me.json.code).toBe("SESSION_REVOKED");

    await old.close();
    await b.ctx.close();
  });

  test("AC5 sai mật khẩu hiện tại: 422 và phiên giữ nguyên", async ({ browser }) => {
    const c = await device(browser);
    await login(c.page, user(3));
    const res = await apiFromPage(c.page, "PUT", "/auth/password", {
      current_password: "sai-mat-khau-1",
      password: "matkhau-moi-456",
      password_confirmation: "matkhau-moi-456",
    });
    expect(res.status).toBe(422);
    expect(res.json.errors.current_password).toBeTruthy();
    await c.page.reload();
    await expect(c.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await c.ctx.close();
  });
});
