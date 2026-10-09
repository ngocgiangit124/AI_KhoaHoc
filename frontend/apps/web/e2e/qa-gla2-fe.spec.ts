import { expect, test, type Page } from "@playwright/test";
/** Biến gắn vào window bởi Turnstile giả trong spec QA. */
type QaWin = Window & {
  __ts: { callback: (t: string) => void; "expired-callback": () => void };
  __renders: number;
  __soft?: number;
};


/**
 * QA GL-A2-FE (web) — widget Turnstile ở /dang-nhap với backend thật. Chạy: `seed-e2e-gla2.sh --reset` rồi
 * `run-gla2-real.sh --config playwright.qa-gla2.config.ts`. Danh tính "không tồn tại" cũng được backend đếm y hệt (contract §1.6)
 * nên hầu hết ca dùng email ngẫu nhiên; chỉ ca tài khoản thật dùng gla2-hs-1@example.com.
 */
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const REAL_USER = "gla2-hs-1@example.com";
const GOOD = "matkhau-123";
const API = "/api/v1/auth/login";
// Giả phản hồi cross-origin như backend thật (cors.php expose Retry-After).
const CORS = { "Access-Control-Allow-Origin": "http://api.localhost:3000", "Access-Control-Allow-Credentials": "true", "Access-Control-Expose-Headers": "X-Request-Id, Retry-After" };
const rnd = () => `qa-gla2-${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}@example.com`;

/** Giả Turnstile có điều khiển được (thay script Cloudflare) để quan sát khoá nút, token, hết hạn. */
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
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
}
async function submit(page: Page, pw: string) {
  await page.getByLabel(/^Mật khẩu/).fill(pw);
  const resp = page.waitForResponse((r) => r.url().endsWith(API) && r.request().method() === "POST");
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  return resp;
}
async function failFive(page: Page) {
  for (let i = 1; i <= 5; i++) await submit(page, "sai-" + i);
}
const btn = (page: Page) => page.getByRole("button", { name: "Đăng nhập" });
const bodies = (page: Page) => {
  const arr: Array<Record<string, unknown>> = [];
  page.on("request", (q) => {
    if (q.url().endsWith(API) && q.method() === "POST") arr.push(JSON.parse(q.postData() ?? "{}"));
  });
  return arr;
};

test("AC enum: tài khoản có / không tồn tại -> UI và phản hồi giống hệt từ lần 1 tới lần 5", async ({ browser }) => {
  const run = async (email: string) => {
    const page = await (await browser.newContext()).newPage();
    await open(page, email);
    const out: string[] = [];
    for (let i = 1; i <= 5; i++) {
      const r = await submit(page, "sai-" + i);
      const j = (await r.json()) as Record<string, unknown>;
      delete j.request_id;
      await expect(page.locator('form [role="alert"]').first()).toBeVisible();
      out.push(`${r.status()}|${JSON.stringify(j)}|${(await page.locator('form [role="alert"]').first().innerText()).replace(/\s+/g, " ")}|widget=${await page.getByTestId("turnstile-widget").count()}`);
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

test("AC1/AC4 (khoá thật tài khoản): đã >=5 sai, widget thật, đúng mật khẩu + token -> vào trang chủ", async ({ page }) => {
  const b = bodies(page);
  await open(page, REAL_USER);
  const r1 = await submit(page, "van-sai");
  expect(((await r1.json()) as { captcha_required?: boolean }).captcha_required).toBe(true);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(page.locator('input[name="cf-turnstile-response"]')).toBeAttached({ timeout: 45_000 });
  await expect(btn(page)).toBeEnabled({ timeout: 45_000 });
  const r = await submit(page, GOOD);
  expect(r.status()).toBe(200);
  expect(b.at(-1)!.captcha_token).toEqual(expect.any(String));
  await expect(page).toHaveURL(/\/$/, { timeout: 60_000 });
});

test("AC2: nút khoá tới khi có token, token mới mỗi lần gửi, hết hạn thì khoá lại, aria", async ({ page }) => {
  await fakeTurnstile(page);
  const b = bodies(page);
  await open(page, rnd());
  await failFive(page);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(btn(page)).toBeDisabled();
  await expect(btn(page)).toHaveAttribute("aria-describedby", "captcha-hint");
  await expect(page.locator("#captcha-hint")).toHaveAttribute("role", "status");
  await expect(page.locator("#captcha-hint")).toContainText("hoàn tất xác minh");
  const renders0 = await page.evaluate(() => (window as unknown as QaWin).__renders as number);

  await give(page, "tok-1");
  await expect(btn(page)).toBeEnabled();
  await expect(page.locator("#captcha-hint")).toHaveText("");
  // hết hạn -> khoá lại; cấp lại -> mở
  await page.evaluate(() => (window as unknown as QaWin).__ts["expired-callback"]());
  await expect(btn(page)).toBeDisabled();
  await give(page, "tok-1b");
  await expect(btn(page)).toBeEnabled();

  await submit(page, "van-sai-1");
  expect(b.at(-1)!.captcha_token).toBe("tok-1b");
  await expect(btn(page)).toBeDisabled(); // widget mount mới, chưa token
  expect(await page.evaluate(() => (window as unknown as QaWin).__renders as number)).toBeGreaterThan(renders0);
  await give(page, "tok-2");
  await submit(page, "van-sai-2");
  expect(b.at(-1)!.captcha_token).toBe("tok-2");
  expect(b.slice(0, 5).every((x) => !("captcha_token" in x) || x.captcha_token === null)).toBe(true);
  // Enter khi nút khoá không gửi request
  await expect(btn(page)).toBeDisabled();
  const n = b.length;
  await page.getByLabel(/^Mật khẩu/).fill("x");
  await page.getByLabel(/^Mật khẩu/).press("Enter");
  await page.waitForTimeout(800);
  expect(b.length).toBe(n);
  // bàn phím: sau khi có token, Tab từ mật khẩu tới nút
  await give(page, "tok-3");
  await page.getByLabel(/^Mật khẩu/).focus();
  for (let i = 0; i < 4; i++) {
    await page.keyboard.press("Tab");
    if (await btn(page).evaluate((el) => el === document.activeElement)) break;
  }
  await expect(btn(page)).toBeFocused();
  // token không nằm trong storage
  const store = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  expect(store).not.toMatch(/tok-|van-sai/);
});

test("AC3: CAPTCHA_INVALID (ép token sai) có thông điệp riêng, widget mount mới, token mới rồi 422 sai thông tin", async ({ page }) => {
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
  const alert = page.locator('form [role="alert"]').first();
  await expect(alert).toContainText("không hợp lệ hoặc đã hết hạn");
  await expect(alert).not.toContainText("không đúng");
  await expect(btn(page)).toBeDisabled();
  await page.unroute(`**${API}`);
  await give(page, "tok-b");
  const r2 = await submit(page, "van-sai");
  expect(r2.status()).toBe(422);
  await expect(alert).toContainText("không đúng");
});

test("429 + Retry-After -> thông điệp thời gian chờ đúng", async ({ page }) => {
  await open(page, rnd());
  for (const [secs, text] of [[45, "45 giây"], [90, "2 phút"], [7300, "3 giờ"]] as const) {
    await page.route(`**${API}`, (route) =>
      route.fulfill({ status: 429, headers: { ...CORS, "Retry-After": String(secs), "content-type": "application/json" }, body: JSON.stringify({ message: "Too many", code: "TOO_MANY_ATTEMPTS" }) }),
    );
    await submit(page, "x-" + secs);
    await expect(page.locator('form [role="alert"]').first()).toContainText(`Vui lòng thử lại sau ${text}`);
    await page.unroute(`**${API}`);
  }
  // không header
  await page.route(`**${API}`, (route) => route.fulfill({ status: 429, headers: CORS, contentType: "application/json", body: JSON.stringify({ message: "x", code: "TOO_MANY_ATTEMPTS" }) }));
  await submit(page, "x");
  await expect(page.locator('form [role="alert"]').first()).toContainText("thử lại sau ít phút");
});

test("chặn challenges.cloudflare.com -> sau ~10s nút 'tải lại trang' (role=alert, <button>)", async ({ page }) => {
  await page.route("https://challenges.cloudflare.com/**", (r) => r.abort());
  await open(page, rnd());
  await failFive(page);
  const t0 = Date.now();
  const alert = page.locator('[role="alert"]', { hasText: "Không tải được bước xác minh" });
  await expect(alert).toBeVisible({ timeout: 20_000 });
  console.log(`[QA] alert hiện sau ${Date.now() - t0} ms`);
  const reload = alert.getByRole("button", { name: "tải lại trang" });
  await expect(reload).toBeVisible();
  expect(await reload.evaluate((e) => e.tagName)).toBe("BUTTON");
  await expect(btn(page)).toBeDisabled();
  const [nav] = await Promise.all([page.waitForEvent("load"), reload.click()]);
  expect(nav).toBeTruthy();
});

test("script Cloudflare treo (không lỗi, không callback) -> sau ~10s mới hiện 'tải lại trang'", async ({ page }) => {
  await page.route("https://challenges.cloudflare.com/**", () => { /* không trả lời */ });
  await open(page, rnd());
  await failFive(page);
  const alert = page.locator('[role="alert"]', { hasText: "Không tải được bước xác minh" });
  await expect(page.getByTestId("turnstile-widget")).toBeAttached();
  await page.waitForTimeout(6000);
  await expect(alert).toHaveCount(0); // chưa tới 10 giây
  const t0 = Date.now();
  await expect(alert).toBeVisible({ timeout: 10_000 });
  console.log(`[QA] alert (script treo) hiện ~${6000 + Date.now() - t0} ms sau lần gửi thứ 5`);
  await expect(alert.getByRole("button", { name: "tải lại trang" })).toBeVisible();
});

test("soft nav: /gio-hang (khách, RequireUser) -> /dang-nhap mềm -> sai tới captcha -> reload ĐÚNG 1 lần, email còn, widget hiện", async ({ page }) => {
  await fakeTurnstile(page);
  const email = rnd();
  await page.goto("/gio-hang");
  await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 60_000 });
  await page.waitForLoadState("networkidle");
  await page.evaluate(() => ((window as unknown as QaWin).__soft = 1)); // dấu: còn nguyên nếu chưa reload
  let loads = 0;
  page.on("load", () => loads++);
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  const posts: string[] = [];
  page.on("request", (q) => { if (q.url().endsWith(API) && q.method() === "POST") posts.push(q.postData() ?? ""); });
  for (let i = 1; i <= 4; i++) await submit(page, "sai-" + i);
  expect(loads).toBe(0);
  expect(await page.evaluate(() => (window as unknown as QaWin).__soft)).toBe(1);
  // lần 5: backend trả captcha_required -> reload
  await page.getByLabel(/^Mật khẩu/).fill("sai-5-bí-mật");
  const reloaded = page.waitForEvent("load");
  await btn(page).click();
  await reloaded;
  await page.waitForTimeout(1500);
  expect(loads).toBe(1);
  expect(await page.evaluate(() => (window as unknown as QaWin).__soft)).toBeUndefined();
  await expect(page.getByLabel("Email hoặc số điện thoại")).toHaveValue(email);
  await expect(page.getByTestId("turnstile-widget")).toBeVisible();
  await expect(page.locator('form [role="alert"]').first()).toContainText("hoàn tất xác minh");
  expect(await page.evaluate(() => sessionStorage.getItem("vv:gla2-login"))).toBeNull(); // cờ đã xoá
  // không reload lần 2 khi gửi tiếp
  await give(page, "tok-s1");
  await submit(page, "sai-6-bí-mật");
  await page.waitForTimeout(1500);
  expect(loads).toBe(1);
  const store = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  expect(store).not.toMatch(/bí-mật|tok-s1/);
  expect(await page.evaluate(() => performance.getEntriesByType("navigation").length)).toBe(1);
});

test("soft nav: giá trị lưu vào sessionStorage trước reload chỉ là định danh (không mật khẩu/token)", async ({ page }) => {
  await fakeTurnstile(page);
  await page.goto("/gio-hang");
  await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 60_000 });
  await page.waitForLoadState("networkidle");
  // Ghi lại mọi lần setItem vào window.name (sống sót qua reload) để đọc sau.
  await page.evaluate(() => {
    const o = Storage.prototype.setItem;
    Storage.prototype.setItem = function (k: string, v: string) {
      window.name += JSON.stringify([k, v]) + "\n";
      return o.call(this, k, v);
    };
  });
  const email = rnd();
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  const reloaded = page.waitForEvent("load");
  for (let i = 1; i <= 5; i++) await submit(page, "mat-khau-bi-mat-" + i).catch(() => {});
  await reloaded;
  const log = await page.evaluate(() => window.name);
  expect(log.trim().split("\n").map((l) => JSON.parse(l))).toEqual([["vv:gla2-login", email]]);
  expect(log).not.toContain("bi-mat");
});

test("layout: không tràn ngang ở 375px và 1280px (có widget + cảnh báo)", async ({ browser }) => {
  for (const width of [375, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 800 } });
    const page = await ctx.newPage();
    await fakeTurnstile(page);
    await open(page, rnd());
    await failFive(page);
    await expect(page.getByTestId("turnstile-widget")).toBeVisible();
    const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(over, `tràn ngang ở ${width}px`).toBeLessThanOrEqual(0);
    await page.screenshot({ path: `test-results/qa-gla2-web-${width}.png`, fullPage: true });
    await ctx.close();
  }
});

test("CSP web: /dang-nhap có challenges.cloudflare.com ở frame-src + connect-src", async ({ request }) => {
  const csp = (await request.get("/dang-nhap")).headers()["content-security-policy"] ?? "";
  expect(csp).toMatch(/frame-src[^;]*challenges\.cloudflare\.com/);
  expect(csp).toMatch(/connect-src[^;]*challenges\.cloudflare\.com/);
});
