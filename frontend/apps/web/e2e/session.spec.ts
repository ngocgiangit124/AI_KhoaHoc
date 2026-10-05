import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA T05 (US-014, ADR-003) — một phiên học sinh, backend thật (E2E_REAL_BACKEND=1).
 * Mỗi "thiết bị" = 1 browser context riêng (cookie + localStorage `vv_device_id` riêng).
 * Seed trước: `qa-t05-e2e-1..6@example.com` / `matkhau-123` (artisan tinker/factory; không đăng ký qua UI
 * để không chạm limiter đăng ký 30/giờ/IP).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const user = (n: number) => `qa-t05-e2e-${n}@example.com`;
const OVERLAY_TITLE = "Tài khoản vừa đăng nhập ở thiết bị khác";

async function device(browser: Browser, deviceId?: string): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext();
  if (deviceId) {
    await ctx.addInitScript((id) => window.localStorage.setItem("vv_device_id", id), deviceId);
  }
  return { ctx, page: await ctx.newPage() };
}

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu\s*\*?$/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
}

test.describe("T05 một phiên học sinh", () => {
  test("AC1+AC2: A đăng nhập -> B đăng nhập -> A tải lại thấy overlay SESSION_REPLACED không đóng được, B vẫn dùng được", async ({ browser }) => {
    const a = await device(browser);
    const b = await device(browser);
    await login(a.page, user(1));
    await login(b.page, user(1));

    await a.page.reload();
    const overlay = a.page.getByRole("alertdialog");
    await expect(overlay).toBeVisible({ timeout: 15_000 });
    await expect(overlay.getByText(OVERLAY_TITLE)).toBeVisible();
    await expect(overlay.getByRole("link", { name: "Đăng nhập lại" })).toBeVisible();

    // không đóng được: Escape và click ra ngoài không làm biến mất
    await a.page.keyboard.press("Escape");
    await a.page.mouse.click(5, 5);
    await expect(overlay).toBeVisible();

    await b.page.reload();
    await expect(b.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await expect(b.page.getByRole("alertdialog")).toHaveCount(0);

    // "Đăng nhập lại" ở A đưa về form đăng nhập; đăng nhập lại -> B bị thay thế ngược lại
    await overlay.getByRole("link", { name: "Đăng nhập lại" }).click();
    await expect(a.page).toHaveURL(/dang-nhap/);
    await a.ctx.close();
    await b.ctx.close();
  });

  test("A -> B -> A đăng nhập lại (ping-pong): B nhận overlay, A dùng được", async ({ browser }) => {
    const a = await device(browser);
    const b = await device(browser);
    await login(a.page, user(2));
    await login(b.page, user(2));
    await login(a.page, user(2));

    await b.page.reload();
    await expect(b.page.getByRole("alertdialog").getByText(OVERLAY_TITLE)).toBeVisible({ timeout: 15_000 });
    await a.page.reload();
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await a.ctx.close();
    await b.ctx.close();
  });

  test("A -> B -> B đăng xuất -> A tải lại KHÔNG sống lại (không có tên, không Đăng xuất)", async ({ browser }) => {
    const a = await device(browser);
    const b = await device(browser);
    await login(a.page, user(3));
    await login(b.page, user(3));
    await b.page.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(b.page).toHaveURL(/dang-nhap/);

    await a.page.reload();
    await a.page.waitForLoadState("networkidle");
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    // vào trang cần đăng nhập vẫn bị đá về đăng nhập
    await a.page.goto("/xac-thuc-otp");
    await expect(a.page).toHaveURL(/dang-nhap/);
    await a.ctx.close();
    await b.ctx.close();
  });

  test("cùng thiết bị (cùng vv_device_id) đăng nhập lại: bản cũ nhận SESSION_EXPIRED -> thành khách, KHÔNG overlay 'thiết bị khác'; trang cần đăng nhập -> /dang-nhap", async ({ browser }) => {
    const id = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee";
    const a = await device(browser, id);
    const b = await device(browser, id);
    await login(a.page, user(4));
    await login(b.page, user(4));

    // Thiết kế hiện tại (api.ts fetchCurrentUser): /auth/me 401 SESSION_EXPIRED ở trang công khai = khách, không overlay.
    await a.page.reload();
    await expect(a.page.getByRole("link", { name: "Đăng nhập" })).toBeVisible({ timeout: 15_000 });
    await expect(a.page.getByRole("alertdialog")).toHaveCount(0);
    await a.page.goto("/xac-thuc-otp");
    await expect(a.page).toHaveURL(/dang-nhap/, { timeout: 15_000 });
    await expect(a.page.getByRole("alertdialog")).toHaveCount(0);

    await b.page.reload();
    await expect(b.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await a.ctx.close();
    await b.ctx.close();
  });

  test("cùng trình duyệt, tab 2 bấm đăng nhập khi tab 1 còn phiên: không văng, không overlay", async ({ browser }) => {
    const a = await device(browser);
    await login(a.page, user(5));
    const tab2 = await a.ctx.newPage();
    await login(tab2, user(5));

    await a.page.reload();
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await expect(a.page.getByRole("alertdialog")).toHaveCount(0);
    await a.ctx.close();
  });

  test("AC3: đăng xuất rồi đăng nhập lại cùng thiết bị, thiết bị khác không bị ảnh hưởng nếu đang là phiên hiện hành", async ({ browser }) => {
    const a = await device(browser);
    await login(a.page, user(6));
    await a.page.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(a.page).toHaveURL(/dang-nhap/);
    await login(a.page, user(6));
    await a.page.reload();
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await a.ctx.close();
  });

  test("AC5: mất mạng tạm thời (offline) rồi online lại, phiên hiện hành vẫn còn", async ({ browser }) => {
    const a = await device(browser);
    await login(a.page, user(6));
    await a.ctx.setOffline(true);
    await a.page.reload().catch(() => undefined);
    await a.ctx.setOffline(false);
    await a.page.goto("/");
    await expect(a.page.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await expect(a.page.getByRole("alertdialog")).toHaveCount(0);
    await a.ctx.close();
  });
});
