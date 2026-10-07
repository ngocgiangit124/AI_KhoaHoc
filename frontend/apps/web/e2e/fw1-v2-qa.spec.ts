import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * FW1 trên design v2 (backend thật, E2E_REAL_BACKEND=1): màn chặn (trang + hộp thoại ở chi tiết khóa), thông báo ở trang
 * đăng nhập, nền ô ly của trang khách, hộp thoại phiên kết thúc, và ảnh chụp 375/1280 vào test-results/shots-fw1.
 * Seed: `e2e/seed-e2e-auth.sh` (otp-e2e-*, qa-t05-e2e-*) và `e2e/seed-e2e-catalog.sh` (fw2-hs-ok, fw2-hs-unv, khóa miễn phí).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const FREE = "e2e-fw2-hinh-hoc-9-mien-phi";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 20_000 });
}

async function device(browser: Browser, viewport = { width: 1280, height: 800 }): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext({ viewport });
  return { ctx, page: await ctx.newPage() };
}

test.describe("nền ô ly và khung", () => {
  test("mọi trang khách có nền bg-oly-page; trang đăng nhập dùng Sheet, không tràn ngang ở 375", async ({ browser }) => {
    const { ctx, page } = await device(browser, { width: 375, height: 800 });
    for (const path of ["/", "/khoa-hoc", "/dang-nhap", "/dang-ky", "/quen-mat-khau", "/dieu-khoan"]) {
      await page.goto(path);
      await page.waitForLoadState("domcontentloaded");
      await expect(page.locator("main#noi-dung"), path).toHaveClass(/bg-oly-page/);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, path).toBeLessThanOrEqual(0);
    }
    await ctx.close();
  });

  test("liên kết điều khoản/chính sách ở form đăng ký có đích thật (không 404)", async ({ page, request }) => {
    for (const path of ["/dieu-khoan", "/chinh-sach-du-lieu"]) expect((await request.get(path)).status(), path).toBe(200);
    await page.goto("/dang-ky");
    await expect(page.getByRole("link", { name: "Điều khoản sử dụng" })).toHaveAttribute("href", "/dieu-khoan");
    await expect(page.getByRole("link", { name: "Chính sách xử lý dữ liệu cá nhân" })).toHaveAttribute("href", "/chinh-sach-du-lieu");
  });
});

test.describe("R1: CSP Turnstile khi đi bằng liên kết (điều hướng cứng)", () => {
  /** Theo dõi vi phạm CSP trong console; link tới route có Turnstile phải tải lại tài liệu để nhận CSP đúng. */
  async function followFromLogin(page: Page, click: () => Promise<void>, url: RegExp, button: string) {
    const violations: string[] = [];
    page.on("console", (m) => {
      if (/Content Security Policy|violates the following/i.test(m.text())) violations.push(m.text());
    });
    await page.goto("/dang-nhap");
    await page.waitForLoadState("networkidle");
    await click();
    await expect(page).toHaveURL(url);
    // Turnstile (test key) cấp token => nút mở khoá; nếu iframe bị CSP chặn thì nút khoá vĩnh viễn.
    await expect(page.getByRole("button", { name: button })).toBeEnabled({ timeout: 25_000 });
    expect(violations).toEqual([]);
  }

  test('"Quên mật khẩu?" từ /dang-nhap', async ({ page }) => {
    await followFromLogin(page, () => page.getByRole("link", { name: "Quên mật khẩu?" }).click(), /\/quen-mat-khau$/, "Gửi mã");
  });
  test('"Đăng ký ngay" từ /dang-nhap', async ({ page }) => {
    await followFromLogin(page, () => page.getByRole("link", { name: "Đăng ký ngay" }).click(), /\/dang-ky$/, "Tạo tài khoản");
  });
  test('nút "Đăng ký" ở header', async ({ page }) => {
    await followFromLogin(page, () => page.getByRole("banner").getByRole("link", { name: "Đăng ký" }).click(), /\/dang-ky$/, "Tạo tài khoản");
  });
});

test.describe("thông báo ở trang đăng nhập", () => {
  test("?trang-thai=het-phien / dat-lai-xong hiện Alert; giá trị lạ bị bỏ qua", async ({ page }) => {
    await page.goto("/dang-nhap?trang-thai=het-phien");
    await expect(page.getByText("Phiên đăng nhập đã hết hạn.")).toBeVisible();
    await page.goto("/dang-nhap?trang-thai=dat-lai-xong");
    await expect(page.getByText("Đặt lại mật khẩu thành công.")).toBeVisible();
    await page.goto("/dang-nhap?trang-thai=<script>");
    await expect(page.getByText(/Phiên đăng nhập đã hết hạn|Đặt lại mật khẩu thành công/)).toHaveCount(0);
  });
});

test.describe("màn chặn", () => {
  test("trang /can-xac-thuc: khách -> đăng nhập; chưa xác thực -> màn chặn + Gửi mã xác nhận sang /xac-thuc-otp; đã xác thực -> danh mục", async ({ browser }) => {
    const guest = await device(browser);
    await guest.page.goto("/can-xac-thuc");
    await expect(guest.page).toHaveURL(/dang-nhap\?next=(%2F|\/)can-xac-thuc/, { timeout: 15_000 });
    await guest.ctx.close();

    const unv = await device(browser);
    await login(unv.page, "fw2-hs-unv@example.com");
    await unv.page.goto("/can-xac-thuc");
    await expect(unv.page.getByRole("heading", { name: "Xác thực email để tiếp tục" })).toBeVisible({ timeout: 15_000 });
    await expect(unv.page.getByText(/f\*+@example\.com/)).toBeVisible();
    await unv.page.getByRole("button", { name: "Gửi mã xác nhận" }).click();
    await expect(unv.page).toHaveURL(/xac-thuc-otp/, { timeout: 15_000 });
    await unv.ctx.close();

    const ok = await device(browser);
    await login(ok.page, "fw2-hs-ok@example.com");
    await ok.page.goto("/can-xac-thuc");
    await expect(ok.page).toHaveURL(/\/khoa-hoc$/, { timeout: 15_000 });
    await ok.ctx.close();
  });

  test("/cho-phu-huynh: học sinh không thuộc diện chờ phụ huynh -> về danh mục (không có gì để chặn)", async ({ browser }) => {
    const ok = await device(browser);
    await login(ok.page, "fw2-hs-ok@example.com");
    await ok.page.goto("/cho-phu-huynh");
    await expect(ok.page).toHaveURL(/\/khoa-hoc$/, { timeout: 15_000 });
    await ok.ctx.close();
  });

  test("chi tiết khóa miễn phí, chưa xác thực: bấm Đăng ký học -> hộp thoại mở TẠI CHỖ (không rời trang), nút Đóng trả về trang", async ({ browser }) => {
    const { ctx, page } = await device(browser);
    await login(page, "fw2-hs-unv@example.com");
    await page.goto(`/khoa-hoc/${FREE}`);
    await page.locator("#course-cta").getByRole("button", { name: "Đăng ký học miễn phí" }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog.getByText("Xác thực email để tiếp tục")).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveURL(new RegExp(`/khoa-hoc/${FREE}$`));
    await expect(dialog.getByRole("link", { name: "Tôi đã có mã" })).toHaveAttribute("href", "/xac-thuc-otp");
    await dialog.getByRole("button", { name: "Đóng" }).click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`/khoa-hoc/${FREE}$`));
    await ctx.close();
  });
});

test.describe("hộp thoại phiên kết thúc trên trang công khai", () => {
  test("SESSION_REPLACED: không đóng được (Esc, bấm nền), Đăng nhập lại giữ ?next, tự đóng khi sang trang đăng nhập", async ({ browser }) => {
    const a = await device(browser);
    const b = await device(browser);
    await login(a.page, "qa-t05-e2e-5@example.com");
    await login(b.page, "qa-t05-e2e-5@example.com");
    await a.page.goto("/khoa-hoc?grade=9");
    const dialog = a.page.getByRole("dialog");
    await expect(dialog.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeVisible({ timeout: 15_000 });
    await expect(dialog.getByRole("link", { name: "Đặt lại mật khẩu" })).toHaveAttribute("href", "/quen-mat-khau");
    await a.page.keyboard.press("Escape");
    await a.page.mouse.click(5, 5);
    await expect(dialog).toBeVisible();
    await dialog.getByRole("link", { name: "Đăng nhập lại" }).click();
    await expect(a.page).toHaveURL(/dang-nhap\?next=%2Fkhoa-hoc%3Fgrade%3D9/);
    await expect(a.page.getByRole("dialog")).toHaveCount(0);
    await a.ctx.close();
    await b.ctx.close();
  });
});

/** Ảnh chụp cho docs/design/mockups/v2/thuc-te/web (SHOTS=1 không cần: luôn chụp, rẻ). */
for (const [tag, w, h] of [["375", 375, 800], ["1280", 1280, 800]] as const) {
  test(`chụp ${tag}`, async ({ browser }) => {
    test.setTimeout(180_000);
    const shot = (page: Page, name: string, fullPage = false) => page.screenshot({ path: `test-results/shots-fw1/${name}-${tag}.png`, fullPage });
    const guest = await device(browser, { width: w, height: h });
    const gp = guest.page;
    for (const [name, path, ready] of [
      ["dang-nhap", "/dang-nhap", "Đăng nhập"],
      ["dang-nhap-het-phien", "/dang-nhap?trang-thai=het-phien", "Đăng nhập"],
      ["dang-ky", "/dang-ky", "Tạo tài khoản học sinh"],
      ["quen-mat-khau", "/quen-mat-khau", "Quên mật khẩu"],
    ] as const) {
      await gp.goto(path);
      await expect(gp.getByRole("heading", { name: ready, level: 1 })).toBeVisible();
      await gp.waitForTimeout(800);
      await shot(gp, name, name === "dang-ky");
    }
    // Đăng ký < 18: khối phụ huynh + hộp tóm tắt lỗi
    await gp.goto("/dang-ky");
    await gp.getByLabel("Ngày sinh").fill("2012-05-05");
    const create = gp.getByRole("button", { name: "Tạo tài khoản" });
    await expect(create).toBeEnabled({ timeout: 20_000 }); // chờ Turnstile cấp token
    await create.click();
    await gp.waitForTimeout(600);
    await shot(gp, "dang-ky-loi", true);
    await guest.ctx.close();

    const { ctx, page } = await device(browser, { width: w, height: h });
    await login(page, "otp-e2e-12@example.com");
    await page.goto("/xac-thuc-otp");
    await expect(page.getByLabel(/Mã xác nhận/)).toBeVisible({ timeout: 15_000 });
    await shot(page, "xac-thuc-otp");
    await page.getByLabel(/Mã xác nhận/).fill("000000");
    await expect(page.getByText(/Mã OTP không đúng|quá nhiều lần|không còn hiệu lực|hết hạn/).first()).toBeVisible({ timeout: 15_000 });
    await shot(page, "xac-thuc-otp-loi");
    await page.goto("/can-xac-thuc");
    await expect(page.getByRole("heading", { name: "Xác thực email để tiếp tục" })).toBeVisible({ timeout: 15_000 });
    await shot(page, "can-xac-thuc");
    await page.goto("/tai-khoan");
    await expect(page.getByRole("heading", { name: "Tài khoản", exact: true })).toBeVisible({ timeout: 15_000 });
    await shot(page, "tai-khoan", true);
    await ctx.close();

    const unv = await device(browser, { width: w, height: h });
    await login(unv.page, "fw2-hs-unv@example.com");
    await unv.page.goto(`/khoa-hoc/${FREE}`);
    // Mobile: hành động nằm ở thanh dính đáy, desktop: ở khối #course-cta — bấm nút đang hiển thị.
    await unv.page.locator("button:visible", { hasText: "Đăng ký học miễn phí" }).first().click();
    await expect(unv.page.getByRole("dialog").getByText("Xác thực email để tiếp tục")).toBeVisible({ timeout: 15_000 });
    await unv.page.waitForTimeout(500);
    await shot(unv.page, "chan-hop-thoai");
    await unv.ctx.close();
  });
}
