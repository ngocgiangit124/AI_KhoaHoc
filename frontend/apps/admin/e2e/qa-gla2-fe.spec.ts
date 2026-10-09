import { expect, test, type Page } from "@playwright/test";
/** Biến gắn vào window bởi Turnstile giả trong spec QA. */
type QaWin = Window & {
  __ts: { callback: (t: string) => void; "expired-callback": () => void };
  __renders: number;
  __soft?: number;
};


/**
 * QA GL-A2-FE (admin) — widget Turnstile ở /dang-nhap quản trị, backend thật. Chạy: `web/e2e/seed-e2e-gla2.sh --reset` rồi
 * `admin/e2e/run-real.sh e2e/qa-gla2-fe.spec.ts`. Ca tài khoản thật: e2e-gla2-admin1@example.com / Password123!.
 */
const ADMIN = "http://admin-api.localhost:3001";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const REAL_USER = "e2e-gla2-admin1@example.com";
const GOOD = "Password123!";
const API = "/api/v1/admin/auth/login";
const CORS = { "Access-Control-Allow-Origin": ADMIN, "Access-Control-Allow-Credentials": "true", "Access-Control-Expose-Headers": "X-Request-Id, Retry-After" };
const rnd = () => `qa-gla2-adm-${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}@example.com`;

async function fakeTurnstile(page: Page) {
  await page.route("https://challenges.cloudflare.com/turnstile/**", (route) =>
    route.fulfill({
      contentType: "application/javascript",
      body: `window.__renders=0;window.turnstile={render:function(el,o){window.__renders++;window.__ts=o;el.innerHTML='<div style="height:65px;width:300px">fake</div>';return 'w'+window.__renders;},remove:function(){}};`,
    }),
  );
}
const give = async (page: Page, tok: string) => {
  await page.waitForFunction(() => (window as unknown as QaWin).__ts, null, { timeout: 15_000 });
  await page.evaluate((t) => (window as unknown as QaWin).__ts.callback(t), tok);
};
async function open(page: Page, email: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.waitForLoadState("networkidle");
  await page.locator('input[name="login"]').fill(email);
}
async function submit(page: Page, pw: string) {
  await page.locator('input[name="password"]').fill(pw);
  const resp = page.waitForResponse((r) => r.url().endsWith(API) && r.request().method() === "POST");
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  return resp;
}
async function failFive(page: Page) {
  for (let i = 1; i <= 5; i++) await submit(page, "sai-" + i);
}
const btn = (page: Page) => page.getByRole("button", { name: "Đăng nhập" });
const alert1 = (page: Page) => page.locator('form [role="alert"]').first();
const bodies = (page: Page) => {
  const arr: Array<Record<string, unknown>> = [];
  page.on("request", (q) => {
    if (q.url().endsWith(API) && q.method() === "POST") arr.push(JSON.parse(q.postData() ?? "{}"));
  });
  return arr;
};

test("enum: tài khoản có / không tồn tại -> UI và phản hồi giống hệt tới lần 5", async ({ browser }) => {
  const run = async (email: string) => {
    const page = await (await browser.newContext()).newPage();
    await open(page, email);
    const out: string[] = [];
    for (let i = 1; i <= 5; i++) {
      const r = await submit(page, "sai-" + i);
      const j = (await r.json()) as Record<string, unknown>;
      delete j.request_id;
      await expect(alert1(page)).toBeVisible();
      out.push(`${r.status()}|${JSON.stringify(j)}|${(await alert1(page).innerText()).replace(/\s+/g, " ")}|widget=${await page.getByTestId("turnstile-widget").count()}`);
    }
    await page.close();
    return out;
  };
  const real = await run(REAL_USER);
  const fake = await run(rnd());
  expect(fake).toEqual(real);
  expect(real[4]).toContain("captcha_required");
  expect(real[3]).not.toContain("widget=1");
});

test("AC thật: >=5 sai, widget Turnstile thật (khoá thử), đúng + token -> /xac-thuc-mfa", async ({ page }) => {
  const b = bodies(page);
  await open(page, REAL_USER);
  const r1 = await submit(page, "van-sai");
  expect(((await r1.json()) as { captcha_required?: boolean }).captcha_required).toBe(true);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(btn(page)).toBeDisabled();
  await expect(page.locator('input[name="cf-turnstile-response"]')).toBeAttached({ timeout: 45_000 });
  await expect(btn(page)).toBeEnabled({ timeout: 45_000 });
  const r = await submit(page, GOOD);
  expect(r.status()).toBe(200);
  expect(b.at(-1)!.captcha_token).toEqual(expect.any(String));
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 30_000 });
});

test("nút khoá tới khi có token, token mới mỗi lần gửi, hết hạn khoá lại, aria, bàn phím, storage", async ({ page }) => {
  await fakeTurnstile(page);
  const b = bodies(page);
  await open(page, rnd());
  await failFive(page);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(btn(page)).toBeDisabled();
  await expect(btn(page)).toHaveAttribute("aria-describedby", "captcha-hint");
  await expect(page.locator("#captcha-hint")).toHaveAttribute("role", "status");
  const renders0 = await page.evaluate(() => (window as unknown as QaWin).__renders as number);
  await give(page, "tok-1");
  await expect(btn(page)).toBeEnabled();
  await page.evaluate(() => (window as unknown as QaWin).__ts["expired-callback"]());
  await expect(btn(page)).toBeDisabled();
  await give(page, "tok-1b");
  await expect(btn(page)).toBeEnabled();
  await submit(page, "van-sai-1");
  expect(b.at(-1)!.captcha_token).toBe("tok-1b");
  await expect(btn(page)).toBeDisabled();
  expect(await page.evaluate(() => (window as unknown as QaWin).__renders as number)).toBeGreaterThan(renders0);
  await give(page, "tok-2");
  await submit(page, "van-sai-2");
  expect(b.at(-1)!.captcha_token).toBe("tok-2");
  expect(b.slice(0, 5).every((x) => !("captcha_token" in x) || x.captcha_token === null)).toBe(true);
  const n = b.length;
  await page.locator('input[name="password"]').fill("x");
  await page.locator('input[name="password"]').press("Enter");
  await page.waitForTimeout(800);
  expect(b.length).toBe(n);
  await give(page, "tok-3");
  await page.locator('input[name="password"]').focus();
  for (let i = 0; i < 4; i++) {
    await page.keyboard.press("Tab");
    if (await btn(page).evaluate((el) => el === document.activeElement)) break;
  }
  await expect(btn(page)).toBeFocused();
  const store = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  expect(store).not.toMatch(/tok-|van-sai/);
});

test("CAPTCHA_INVALID (ép token sai) có thông điệp riêng; token mới thì 422 sai thông tin", async ({ page }) => {
  await fakeTurnstile(page);
  await open(page, rnd());
  await failFive(page);
  await give(page, "tok-a");
  await page.route(`**${API}`, async (route) => {
    const d = JSON.parse(route.request().postData() ?? "{}") as Record<string, unknown>;
    d.captcha_token = "invalid";
    await route.continue({ postData: JSON.stringify(d) });
  });
  const r = await submit(page, GOOD);
  expect(((await r.json()) as { code?: string }).code).toBe("CAPTCHA_INVALID");
  await expect(alert1(page)).toContainText("không hợp lệ hoặc đã hết hạn");
  await expect(btn(page)).toBeDisabled();
  await page.unroute(`**${API}`);
  await give(page, "tok-b");
  const r2 = await submit(page, "van-sai");
  expect(r2.status()).toBe(422);
  await expect(alert1(page)).not.toContainText("không hợp lệ hoặc đã hết hạn");
});

test("429 + Retry-After -> thông điệp thời gian chờ đúng", async ({ page }) => {
  await open(page, rnd());
  for (const [secs, text] of [[45, "45 giây"], [90, "2 phút"], [7300, "3 giờ"]] as const) {
    await page.route(`**${API}`, (route) =>
      route.fulfill({ status: 429, headers: { ...CORS, "Retry-After": String(secs), "content-type": "application/json" }, body: JSON.stringify({ message: "Too many", code: "TOO_MANY_ATTEMPTS" }) }),
    );
    await submit(page, "x-" + secs);
    await expect(alert1(page)).toContainText(`Vui lòng thử lại sau ${text}`);
    await page.unroute(`**${API}`);
  }
});

test("chặn challenges.cloudflare.com (abort) -> 'tải lại trang' role=alert <button>", async ({ page }) => {
  await page.route("https://challenges.cloudflare.com/**", (r) => r.abort());
  await open(page, rnd());
  await failFive(page);
  const alert = page.locator('[role="alert"]', { hasText: "Không tải được bước xác minh" });
  await expect(alert).toBeVisible({ timeout: 20_000 });
  const reload = alert.getByRole("button", { name: "tải lại trang" });
  expect(await reload.evaluate((e) => e.tagName)).toBe("BUTTON");
  await expect(btn(page)).toBeDisabled();
  const [nav] = await Promise.all([page.waitForEvent("load"), reload.click()]);
  expect(nav).toBeTruthy();
});

test("script Cloudflare treo -> chỉ sau ~10s mới hiện 'tải lại trang'", async ({ page }) => {
  await page.route("https://challenges.cloudflare.com/**", () => { /* treo */ });
  await open(page, rnd());
  await failFive(page);
  const alert = page.locator('[role="alert"]', { hasText: "Không tải được bước xác minh" });
  await page.waitForTimeout(6000);
  await expect(alert).toHaveCount(0);
  await expect(alert).toBeVisible({ timeout: 10_000 });
  await expect(alert.getByRole("button", { name: "tải lại trang" })).toBeVisible();
});

test("soft nav: /xac-thuc-mfa (khách) -> router.replace /dang-nhap -> sai tới captcha -> reload ĐÚNG 1 lần, email còn, widget hiện", async ({ page }) => {
  await fakeTurnstile(page);
  const email = rnd();
  await page.goto(`${ADMIN}/xac-thuc-mfa`);
  await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 60_000 });
  await page.waitForLoadState("networkidle");
  await page.evaluate(() => {
    (window as unknown as QaWin).__soft = 1;
    const o = Storage.prototype.setItem;
    Storage.prototype.setItem = function (k: string, v: string) {
      window.name += JSON.stringify([k, v]) + "\n";
      return o.call(this, k, v);
    };
  });
  let loads = 0;
  page.on("load", () => loads++);
  await page.locator('input[name="login"]').fill(email);
  for (let i = 1; i <= 4; i++) await submit(page, "sai-" + i);
  expect(loads).toBe(0);
  expect(await page.evaluate(() => (window as unknown as QaWin).__soft)).toBe(1);
  await page.locator('input[name="password"]').fill("sai-5-bí-mật");
  const reloaded = page.waitForEvent("load");
  await btn(page).click();
  await reloaded;
  await page.waitForTimeout(1500);
  expect(loads).toBe(1);
  expect(await page.evaluate(() => (window as unknown as QaWin).__soft)).toBeUndefined();
  const log = await page.evaluate(() => window.name);
  expect(log.trim().split("\n").map((l) => JSON.parse(l))).toEqual([["vv:gla2-login", email]]);
  await expect(page.locator('input[name="login"]')).toHaveValue(email);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(alert1(page)).toContainText("hoàn tất xác minh");
  expect(await page.evaluate(() => sessionStorage.getItem("vv:gla2-login"))).toBeNull();
  await give(page, "tok-s1");
  await submit(page, "sai-6-bí-mật");
  await page.waitForTimeout(1500);
  expect(loads).toBe(1);
  const store = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  expect(store).not.toMatch(/bí-mật|tok-s1/);
});

test("soft nav hết phiên (AuthGate/SessionWatcher/Logout) là điều hướng cứng: /quan-tri khi chưa đăng nhập về /dang-nhap bằng tải tài liệu mới", async ({ page }) => {
  let docLoads = 0;
  page.on("framenavigated", (f) => { if (f === page.mainFrame()) docLoads++; });
  await page.goto(`${ADMIN}/quan-tri`);
  await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 60_000 });
  await page.waitForLoadState("networkidle");
  const csp = await page.evaluate(async () => (await fetch(location.href)).headers.get("content-security-policy"));
  expect(csp).toMatch(/frame-src[^;]*challenges\.cloudflare\.com/);
  const nav = await page.evaluate(() => new URL((performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming).name).pathname);
  expect(nav).toBe("/dang-nhap"); // tài liệu gốc chính là /dang-nhap => CSP đúng, không stale
  expect(docLoads).toBeGreaterThan(0);
});

test("CSP: chỉ /dang-nhap có challenges.cloudflare.com ở frame-src + connect-src; trang admin khác thì không", async ({ request }) => {
  const get = async (p: string) => (await request.get(`${ADMIN}${p}`, { maxRedirects: 0 })).headers()["content-security-policy"] ?? "";
  const login = await get("/dang-nhap");
  expect(login).toMatch(/frame-src[^;]*challenges\.cloudflare\.com/);
  expect(login).toMatch(/connect-src[^;]*challenges\.cloudflare\.com/);
  // /dang-nhap/ (slash cuối) được Next chuẩn hoá bằng redirect rồi trả CSP đúng của /dang-nhap.
  const slash = (await request.get(`${ADMIN}/dang-nhap/`)).headers()["content-security-policy"] ?? "";
  expect(slash).toMatch(/frame-src[^;]*challenges\.cloudflare\.com/);
  for (const p of ["/xac-thuc-mfa", "/doi-mat-khau", "/quan-tri", "/quan-tri/nhat-ky"]) {
    const c = await get(p);
    expect(c, `CSP của ${p} phải có`).not.toBe("");
    expect(c, p).not.toContain("challenges.cloudflare.com");
    expect(c, p).toContain("frame-src 'none'");
  }
});

test("layout: không tràn ngang ở 375px và 1280px", async ({ browser }) => {
  for (const width of [375, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 800 } });
    const page = await ctx.newPage();
    await fakeTurnstile(page);
    await open(page, rnd());
    await failFive(page);
    await expect(page.getByTestId("turnstile-widget")).toBeVisible();
    const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(over, `tràn ngang ở ${width}px`).toBeLessThanOrEqual(0);
    await page.screenshot({ path: `test-results/qa-gla2-admin-${width}.png`, fullPage: true });
    await ctx.close();
  }
});
