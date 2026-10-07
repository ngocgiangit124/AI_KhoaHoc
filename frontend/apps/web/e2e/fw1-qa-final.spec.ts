import { expect, test, type Browser, type Page } from "@playwright/test";

/**
 * FW1 QA bổ sung (backend thật, bản `next build` + `next start`, Turnstile site key test của Cloudflare):
 * - R14: /dang-nhap?next=/dang-ky sau đăng nhập phải tải cứng để Turnstile không bị CSP chặn.
 * - Nút "Đăng ký" ở header từ trang chi tiết khóa học.
 * - Hộp thoại SESSION_REPLACED không đóng khi bấm Back (điều hướng mềm).
 * - 375px: không cuộn ngang, vùng chạm >= 44px cho nút/liên kết đứng riêng.
 * - Không lộ tài khoản tồn tại ở "Quên mật khẩu"; không open redirect.
 * Seed: e2e/seed-e2e-auth.sh + e2e/seed-e2e-catalog.sh.
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const FREE = "e2e-fw2-hinh-hoc-9-mien-phi";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

function watchCsp(page: Page) {
  const violations: string[] = [];
  page.on("console", (m) => {
    if (/Content Security Policy|violates the following/i.test(m.text())) violations.push(m.text());
  });
  return violations;
}

async function fillLogin(page: Page, email: string) {
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

async function loginFresh(browser: Browser, email: string, viewport = { width: 1280, height: 800 }) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await fillLogin(page, email);
  await expect(page).toHaveURL(/\/$/, { timeout: 20_000 });
  return { ctx, page };
}

test("R14: /dang-nhap?next=/dang-ky -> sau đăng nhập tải cứng, Turnstile hoạt động, không CSP violation", async ({ browser }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const violations = watchCsp(page);
  await page.goto("/dang-nhap?next=/dang-ky");
  await page.waitForLoadState("networkidle");
  await fillLogin(page, "qa-t05-e2e-6@example.com");
  await expect(page).toHaveURL(/\/dang-ky$/, { timeout: 20_000 });
  await expect(page.getByRole("button", { name: "Tạo tài khoản" })).toBeEnabled({ timeout: 25_000 });
  expect(violations).toEqual([]);
  await ctx.close();
});

test('nút "Đăng ký" ở header từ trang chi tiết khóa học: Turnstile tải được, không CSP violation', async ({ page }) => {
  const violations = watchCsp(page);
  await page.goto(`/khoa-hoc/${FREE}`);
  await page.waitForLoadState("networkidle");
  await page.getByRole("banner").getByRole("link", { name: "Đăng ký" }).click();
  await expect(page).toHaveURL(/\/dang-ky$/);
  await expect(page.getByRole("button", { name: "Tạo tài khoản" })).toBeEnabled({ timeout: 25_000 });
  expect(violations).toEqual([]);
});

test("hộp thoại mất phiên không đóng khi bấm Back (điều hướng mềm)", async ({ browser }) => {
  const a = await loginFresh(browser, "qa-t05-e2e-4@example.com");
  await a.page.goto("/khoa-hoc?grade=9");
  await a.page.waitForLoadState("networkidle");
  // điều hướng mềm từ danh mục sang chi tiết khóa
  await a.page.locator(`a[href="/khoa-hoc/${FREE}"]`).first().click();
  await expect(a.page).toHaveURL(new RegExp(`/khoa-hoc/${FREE}$`));
  const b = await loginFresh(browser, "qa-t05-e2e-4@example.com");
  await a.page.reload();
  const dialog = a.page.getByRole("dialog");
  await expect(dialog.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeVisible({ timeout: 15_000 });
  await a.page.goBack();
  await expect(a.page).toHaveURL(/\/khoa-hoc(\?.*)?$/);
  await expect(dialog).toBeVisible();
  await a.page.keyboard.press("Escape");
  await expect(dialog).toBeVisible();
  await a.ctx.close();
  await b.ctx.close();
});

test("open redirect: next ngoài site / scheme lạ bị bỏ, về trang chủ", async ({ browser }) => {
  for (const evil of ["https://evil.example/x", "//evil.example", "/\\evil.example", "javascript:alert(1)", "%2F%2Fevil.example"]) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`/dang-nhap?next=${encodeURIComponent(evil)}`);
    await page.waitForLoadState("networkidle");
    await fillLogin(page, "qa-t05-e2e-3@example.com");
    await expect(page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 20_000 });
    expect(new URL(page.url()).origin, evil).toBe(BASE);
    await ctx.close();
  }
});

test("Quên mật khẩu: email có tài khoản và email không tồn tại cho cùng một thông điệp", async ({ browser }) => {
  const texts: string[] = [];
  for (const email of ["qa-t27-e2e-8@example.com", `khong-ton-tai-${Date.now()}@example.com`]) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto("/quen-mat-khau");
    await page.getByLabel(/Email/).fill(email);
    const btn = page.getByRole("button", { name: "Gửi mã" });
    await expect(btn).toBeEnabled({ timeout: 25_000 });
    await btn.click();
    await expect(page).toHaveURL(/\/quen-mat-khau\/dat-lai$/, { timeout: 20_000 });
    texts.push(((await page.locator("main").innerText()) ?? "").replace(email, "<EMAIL>").replace(/Gửi lại mã sau [\d:]+/, "Gửi lại mã sau N"));
    // email không xuất hiện trên URL
    expect(page.url()).not.toContain("example.com");
    await ctx.close();
  }
  expect(texts[0]).toBe(texts[1]);
});

test.describe("375px", () => {
  test.use({ viewport: { width: 375, height: 800 } });

  for (const path of ["/dang-nhap", "/dang-ky", "/quen-mat-khau"]) {
    test(`${path}: không cuộn ngang; nút/ô nhập/liên kết đứng riêng cao >= 44px`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState("domcontentloaded");
      await page.getByRole("button").first().waitFor();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow).toBeLessThanOrEqual(0);
      const small = await page.evaluate(() => {
        const out: string[] = [];
        const els = document.querySelectorAll<HTMLElement>("main a, main button, main input:not([type=checkbox]):not([type=hidden]), header a, header button");
        els.forEach((el) => {
          const r = el.getBoundingClientRect();
          if (r.width === 0 || r.height === 0) return;
          const cs = getComputedStyle(el);
          if (cs.visibility === "hidden") return;
          // liên kết nằm giữa dòng chữ (inline) được miễn theo WCAG 2.5.8 (inline exception)
          const inline = el.tagName === "A" && cs.display === "inline";
          const logo = el.tagName === "A" && el.closest("header") && /VitaminVui/.test(el.innerText);
          if (r.height < 43.5 && !inline && !logo) out.push(`${el.tagName} "${(el.innerText || el.getAttribute("aria-label") || el.getAttribute("name") || "").trim().slice(0, 40)}" ${Math.round(r.width)}x${Math.round(r.height)}`);
        });
        return out;
      });
      expect(small, `phần tử < 44px ở ${path}`).toEqual([]);
    });
  }
});
