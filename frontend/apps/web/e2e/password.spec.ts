import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA T27 + FW1 (US-015) — backend thật (E2E_REAL_BACKEND=1). Phần "hai thiết bị" gọi API trực tiếp (fetch từ trang, hoặc
 * APIRequestContext cho khách) để kiểm hộp thoại phiên bị huỷ (SESSION_REVOKED); phần "màn hình" đi qua giao diện
 * quên mật khẩu (2 bước) và đổi mật khẩu ở /tai-khoan.
 * Seed trước (đã xác thực email): `qa-t27-e2e-1..3` cho hai thiết bị, `qa-t27-e2e-4..8` cho màn hình, mật khẩu `matkhau-123`.
 * Cần Mailpit ở MAILPIT_URL (mặc định http://localhost:8025) để lấy mã OTP đặt lại.
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const API = process.env.E2E_API_URL ?? "http://api.localhost:8000/api/v1";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "matkhau-123";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const user = (n: number) => `qa-t27-e2e-${n}@example.com`;

async function device(browser: Browser): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext();
  return { ctx, page: await ctx.newPage() };
}

async function login(page: Page, email: string, password = PASSWORD, after: RegExp = /\/$/) {
  // Đã ở trang đăng nhập (có thể kèm ?next=) thì dùng luôn, không điều hướng lại.
  if (!new URL(page.url()).pathname.startsWith("/dang-nhap")) await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(password);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(after);
  await expect(page.getByRole("button", { name: "Đăng xuất" }).first()).toBeVisible({ timeout: 15_000 });
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

    // Tải lại: quyết định PO 2026-10-07 — SESSION_REVOKED hiện hộp thoại báo lý do (không đóng được), KHÔNG phải overlay "thiết bị khác".
    await a.page.reload();
    const dialog = a.page.getByRole("dialog");
    await expect(dialog.getByText("Bạn cần đăng nhập lại")).toBeVisible({ timeout: 15_000 });
    await expect(dialog.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toHaveCount(0);
    await a.page.keyboard.press("Escape");
    await expect(dialog).toBeVisible();
    await dialog.getByRole("link", { name: "Đăng nhập lại" }).click();
    await expect(a.page).toHaveURL(/dang-nhap/);
    await expect(a.page.getByRole("dialog")).toHaveCount(0);

    // Mật khẩu cũ không còn, mật khẩu mới vào được
    await a.page.goto("/dang-nhap");
    await a.page.waitForLoadState("networkidle");
    await a.page.getByLabel("Email hoặc số điện thoại").fill(user(1));
    await a.page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
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
    await expect(b.page.getByRole("dialog")).toHaveCount(0);

    // "Thiết bị khác" mang cookie phiên cũ
    const old = await browser.newContext({ storageState: oldState });
    const op = await old.newPage();
    await op.goto("/");
    await op.waitForLoadState("networkidle");
    await expect(op.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    // Thiết bị cũ mở trang công khai: hộp thoại báo mật khẩu đã đổi (REVOKED), không phải "thiết bị khác".
    await expect(op.getByRole("dialog").getByText("Bạn cần đăng nhập lại")).toBeVisible({ timeout: 15_000 });
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

/** Đi qua giao diện: bước 1 (/quen-mat-khau) -> bước 2 (/quen-mat-khau/dat-lai) -> đăng nhập bằng mật khẩu mới. */
async function openForgot(page: Page, login: string) {
  await page.goto("/quen-mat-khau");
  await page.getByLabel(/^Email hoặc số điện thoại/).fill(login);
  // Turnstile (test key) tự cấp token; nút mở khi có token.
  const send = page.getByRole("button", { name: "Gửi mã" });
  await expect(send).toBeEnabled({ timeout: 20_000 });
  await send.click();
  // Luôn chuyển thẳng sang bước 2 (không lộ tài khoản có tồn tại hay không).
  await expect(page).toHaveURL(/\/quen-mat-khau\/dat-lai$/, { timeout: 20_000 });
}

test.describe("FW1: màn quên mật khẩu (2 bước) và đổi mật khẩu", () => {
  test("US-015 AC1+AC2: quên -> nhận mã qua email -> đặt lại -> /dang-nhap có thông báo -> đăng nhập bằng mật khẩu mới", async ({ page }) => {
    const since = Date.now() - 1000;
    await openForgot(page, user(4));
    // login giữ trong sessionStorage, không có trên URL; phụ đề bước 2 mang thông điệp chung.
    expect(page.url()).not.toContain(encodeURIComponent(user(4)));
    await expect(page.getByText(/Nếu thông tin tồn tại, chúng tôi đã gửi mã/)).toBeVisible();
    await expect(page.getByText(user(4))).toBeVisible();

    const code = await mailCode(user(4), since);
    await page.getByLabel(/Mã xác nhận/).fill(code);
    await page.getByLabel(/^Mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByLabel(/^Nhập lại mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    await expect(page).toHaveURL(/dang-nhap\?trang-thai=dat-lai-xong/);
    await expect(page.getByText("Đặt lại mật khẩu thành công.")).toBeVisible();

    await login(page, user(4), "matkhau-moi-789");
  });

  test("mật khẩu phổ biến -> hiện nguyên lỗi server dưới ô mật khẩu; ô mã vẫn dùng được", async ({ page }) => {
    await openForgot(page, user(5));
    await page.getByLabel(/Mã xác nhận/).fill("000000");
    await page.getByLabel(/^Mật khẩu mới/).fill("matkhau123");
    await page.getByLabel(/^Nhập lại mật khẩu mới/).fill("matkhau123");
    await page.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    await expect(page.getByText(/quá phổ biến/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByLabel(/Mã xác nhận/)).toBeEnabled();
  });

  test("mã sai -> MỌI lỗi mã hiện 'hết hạn', ô mã khoá, mật khẩu đã nhập được giữ; Gửi lại mã (Turnstile ẩn) cấp mã mới, mã mới đặt lại được", async ({ page }) => {
    test.setTimeout(180_000);
    const since = Date.now() - 1000;
    await openForgot(page, user(7));

    await page.getByLabel(/Mã xác nhận/).fill("000000");
    await page.getByLabel(/^Mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByLabel(/^Nhập lại mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    await expect(page.getByText(/đã hết hạn hoặc không còn hiệu lực/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByLabel(/Mã xác nhận/)).toBeDisabled();
    await expect(page.getByLabel(/^Mật khẩu mới/)).toHaveValue("matkhau-moi-789");
    await expect(page.getByRole("button", { name: "Đặt lại mật khẩu" })).toBeDisabled();

    // Gửi lại mã = gọi lại forgot (cần Turnstile ẩn) — chờ hết cooldown 60s từ lần gửi ở bước 1.
    await mailCode(user(7), since); // mã đầu đã tới
    const before = Date.now();
    const resend = page.getByRole("button", { name: "Gửi lại mã", exact: true });
    await expect(resend).toBeEnabled({ timeout: 90_000 });
    await resend.click();
    await expect(page.getByText(/Mã cũ không còn dùng được/)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByLabel(/Mã xác nhận/)).toBeEnabled();
    const code = await mailCode(user(7), before);
    await page.getByLabel(/Mã xác nhận/).fill(code);
    await page.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    await expect(page).toHaveURL(/dang-nhap\?trang-thai=dat-lai-xong/, { timeout: 15_000 });
  });

  test("tài khoản không tồn tại: bước 1 vẫn trả thông điệp chung (không lộ), bước 2 báo mã hết hạn", async ({ page }) => {
    await openForgot(page, `khong-co-${Date.now().toString(36)}@example.com`);
    await page.getByLabel(/Mã xác nhận/).fill("123456");
    await page.getByLabel(/^Mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByLabel(/^Nhập lại mật khẩu mới/).fill("matkhau-moi-789");
    await page.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    await expect(page.getByText(/đã hết hạn hoặc không còn hiệu lực/)).toBeVisible({ timeout: 15_000 });
  });

  test("vào thẳng bước 2 không qua bước 1 -> dẫn về bước 1", async ({ page }) => {
    await page.goto("/quen-mat-khau/dat-lai");
    await expect(page.getByRole("link", { name: "Quên mật khẩu" })).toHaveAttribute("href", "/quen-mat-khau");
  });

  test("US-015 AC4+AC5: Tài khoản -> đổi mật khẩu: sai mật khẩu hiện tại báo lỗi dưới ô; đúng thì giữ phiên, đăng nhập lại được bằng mật khẩu mới", async ({ page }) => {
    await login(page, user(6));
    await page.goto("/tai-khoan");
    await expect(page.getByRole("heading", { name: "Tài khoản", exact: true })).toBeVisible();
    const submit = page.getByRole("button", { name: "Đổi mật khẩu" });

    await page.getByLabel(/^Mật khẩu hiện tại/).last().fill("sai-mat-khau-1");
    await page.getByLabel(/^Mật khẩu mới/).fill("matkhau-moi-456");
    await page.getByLabel(/^Nhập lại mật khẩu mới/).fill("matkhau-moi-456");
    await submit.click();
    await expect(page.getByText("Mật khẩu hiện tại không đúng.")).toBeVisible();

    await page.getByLabel(/^Mật khẩu hiện tại/).last().fill(PASSWORD);
    await submit.click();
    await expect(page.getByText("Đã đổi mật khẩu")).toBeVisible();
    await page.reload();
    await expect(page.getByRole("button", { name: "Đăng xuất" }).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole("dialog")).toHaveCount(0);

    await page.getByRole("button", { name: "Đăng xuất" }).first().click();
    await expect(page).toHaveURL(/dang-nhap/);
    await login(page, user(6), "matkhau-moi-456");
  });

  test("Tài khoản cho khách -> /dang-nhap?next=/tai-khoan; đăng nhập xong quay lại", async ({ page }) => {
    await page.goto("/tai-khoan");
    await expect(page).toHaveURL(/dang-nhap\?next=(%2F|\/)tai-khoan/, { timeout: 15_000 });
    await login(page, user(8), PASSWORD, /tai-khoan/);
  });
});
