import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * QA T28 + FA1 — e2e THẬT với backend Laravel (E2E_REAL_BACKEND=1), web admin ở admin-api.localhost:3001 và API
 * admin-api.localhost:8000 (cùng hostname để cookie SameSite=Strict gửi được).
 * Seed trước (artisan tinker/factory, không flush Redis): e2e-gv/e2e-admin/e2e-qlt/e2e-gv-new/e2e-admin-new/
 * e2e-gv-lock/e2e-gv-idle/e2e-gv-mobile/e2e-hs @example.com, mật khẩu `Password123!`.
 * Mã MFA đọc từ Mailpit; cần queue worker chạy (OtpMail xếp hàng Redis).
 */
const ADMIN = "http://admin-api.localhost:3001";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const email = (n: string) => `e2e-${n}@example.com`;

async function fillLogin(page: Page, who: string, password = PASSWORD) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

async function latestCode(request: APIRequestContext, to: string, since: number): Promise<string> {
  let code: string | null = null;
  await expect
    .poll(
      async () => {
        const list = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${to}` } });
        const messages = ((await list.json()) as { messages: { ID: string; Created: string; Subject: string }[] }).messages;
        const latest = messages.find((m) => Date.parse(m.Created) >= since && /xác thực|mã/i.test(m.Subject));
        if (!latest) return null;
        const detail = (await (await request.get(`${MAILPIT}/api/v1/message/${latest.ID}`)).json()) as { Text: string };
        code = /\b(\d{6})\b/.exec(detail.Text)?.[1] ?? null;
        return code;
      },
      { timeout: 45_000, intervals: [1000] },
    )
    .not.toBeNull();
  return code!;
}

async function loginWithMfa(page: Page, request: APIRequestContext, who: string) {
  const since = Date.now() - 3_000;
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
  const code = await latestCode(request, email(who), since);
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
}

const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });

test.describe("T28/FA1 thật", () => {
  test("AC giáo viên: đăng nhập thẳng, menu không có mục Admin, F5 giữ phiên, 403 view, đăng xuất, email thiết bị mới", async ({ page, request }) => {
    const since = Date.now() - 3_000;
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await expect(page.getByTestId("staff-name")).toContainText("E2E teacher");
    await expect(nav(page).getByText("Tổng quan")).toBeVisible();
    await expect(nav(page).getByText("Tài khoản staff")).toHaveCount(0);
    await expect(nav(page).getByText("Nhật ký thao tác")).toHaveCount(0);
    await expect(nav(page).getByText("Đơn hàng")).toHaveCount(0);

    await page.reload();
    await expect(page.getByTestId("staff-name")).toBeVisible({ timeout: 20_000 });

    // Trang 403
    await page.goto(`${ADMIN}/quan-tri/khong-co-quyen`);
    await expect(page.getByTestId("forbidden-view")).toContainText("Bạn không có quyền truy cập trang này.");

    // Email cảnh báo thiết bị mới (lần đầu của cặp user/UA/device) — worker queue phải chạy.
    await expect
      .poll(
        async () => {
          const r = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email("gv")}` } });
          const msgs = ((await r.json()) as { messages: { Created: string; Subject: string }[] }).messages;
          return msgs.some((m) => Date.parse(m.Created) >= since && /thiết bị mới/i.test(m.Subject));
        },
        { timeout: 30_000, intervals: [1000] },
      )
      .toBe(true);

    await page.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
    // Sau đăng xuất vào khu quản trị bị đá về đăng nhập.
    await page.goto(`${ADMIN}/quan-tri`);
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
  });

  test("Admin: MFA email, sai mã báo lỗi, đúng mã vào, menu đầy đủ", async ({ page, request }) => {
    const since = Date.now() - 3_000;
    await fillLogin(page, "admin");
    await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
    await expect(page.getByRole("heading", { name: "Xác thực 2 lớp" })).toBeVisible();

    // Vào thẳng /quan-tri khi chưa qua MFA bị đưa lại màn MFA.
    await page.goto(`${ADMIN}/quan-tri`);
    await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });

    const code = await latestCode(request, email("admin"), since);
    const wrong = code === "000000" ? "111111" : "000000";
    await page.locator("input").first().click();
    await page.keyboard.type(wrong);
    await expect(page.getByText(/Mã (OTP|xác nhận) không đúng/)).toBeVisible({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/xac-thuc-mfa/);

    // Gửi lại mã đang trong thời gian chờ: nút bị khoá.
    await expect(page.getByRole("button", { name: /Gửi lại/ })).toBeDisabled();

    await page.locator("input").first().click();
    await page.keyboard.type(code);
    // Trước đó test vào thẳng /quan-tri nên `next=/quan-tri` được giữ (không phải DEFAULT_LANDING).
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    for (const label of ["Tài khoản staff", "Nhật ký thao tác", "Đơn hàng", "Mã giảm giá", "Chuyên đề"]) {
      await expect(nav(page).getByText(label)).toBeVisible();
    }
    // Giữ phiên khi F5
    await page.reload();
    await expect(page.getByTestId("staff-name")).toBeVisible({ timeout: 20_000 });
  });

  test("Quản lý trang: MFA, menu có Đơn hàng nhưng không có Tài khoản staff/Nhật ký", async ({ page, request }) => {
    await loginWithMfa(page, request, "qlt");
    await expect(nav(page).getByText("Đơn hàng")).toBeVisible();
    await expect(nav(page).getByText("Tài khoản staff")).toHaveCount(0);
    await expect(nav(page).getByText("Nhật ký thao tác")).toHaveCount(0);
  });

  test("Học sinh đăng nhập cổng quản trị: thông báo sai cổng, ở lại /dang-nhap, không vào được /quan-tri", async ({ page }) => {
    await fillLogin(page, "hs");
    await expect(page.getByText(/trang dành cho bạn/i)).toBeVisible({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/dang-nhap/);
    await page.goto(`${ADMIN}/quan-tri`);
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
  });

  test("Sai mật khẩu: thông điệp chung, không lộ tài khoản có tồn tại", async ({ page }) => {
    await fillLogin(page, "gv", "sai-mat-khau-123");
    await expect(page.getByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeVisible({ timeout: 20_000 });
    await page.locator('input[name="login"]').fill("khong-ton-tai@example.com");
    await page.locator('input[name="password"]').fill("sai-mat-khau-123");
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page.getByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeVisible({ timeout: 20_000 });
  });

  test("Đổi mật khẩu lần đầu (GV): bị giữ ở /doi-mat-khau, lỗi dưới field, đổi xong vào được", async ({ page }) => {
    await fillLogin(page, "gv-new");
    await expect(page).toHaveURL(/\/doi-mat-khau/, { timeout: 20_000 });
    await expect(page.getByRole("heading", { name: "Đổi mật khẩu để tiếp tục" })).toBeVisible();

    await page.goto(`${ADMIN}/quan-tri`);
    await expect(page).toHaveURL(/\/doi-mat-khau/, { timeout: 20_000 });

    // Sai mật khẩu hiện tại
    await page.locator('input[name="current_password"]').fill("khong-dung-123");
    await page.locator('input[name="password"]').fill("MatKhauMoi#2026");
    await page.locator('input[name="password_confirmation"]').fill("MatKhauMoi#2026");
    await page.getByRole("button", { name: /Đặt mật khẩu mới/ }).click();
    await expect(page.getByText(/hiện tại/i).first()).toBeVisible({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/doi-mat-khau/);

    // Đúng
    await page.locator('input[name="current_password"]').fill(PASSWORD);
    await page.getByRole("button", { name: /Đặt mật khẩu mới/ }).click();
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await expect(page.getByTestId("staff-name")).toBeVisible();
  });

  test("Đổi mật khẩu lần đầu (Admin): MFA rồi đổi mật khẩu rồi vào khu quản trị", async ({ page, request }) => {
    const since = Date.now() - 3_000;
    await fillLogin(page, "admin-new");
    await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
    await page.keyboard.type(await latestCode(request, email("admin-new"), since));
    await expect(page).toHaveURL(/\/doi-mat-khau/, { timeout: 20_000 });
    await page.locator('input[name="current_password"]').fill(PASSWORD);
    await page.locator('input[name="password"]').fill("MatKhauMoi#2026");
    await page.locator('input[name="password_confirmation"]').fill("MatKhauMoi#2026");
    await page.getByRole("button", { name: /Đặt mật khẩu mới/ }).click();
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await expect(nav(page).getByText("Tài khoản staff")).toBeVisible();
  });

  test("Idle phía client: mốc hoạt động quá hạn thì đăng xuất và về /dang-nhap kèm thông báo", async ({ page }) => {
    await fillLogin(page, "gv-idle");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.evaluate(() => localStorage.setItem("vv:admin-last-activity", String(Date.now() - 121 * 60_000)));
    await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 30_000 });
    await expect(page.getByText(/không hoạt động/)).toBeVisible();
    // Phiên đã bị đăng xuất phía server (gọi logout): quay lại /quan-tri vẫn bị chặn.
    await page.goto(`${ADMIN}/quan-tri`);
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
  });

  test("Khoá giữa phiên: tải lại thấy màn 'tài khoản bị khóa' (QA khoá user từ ngoài khi test chờ)", async ({ page }) => {
    test.skip(process.env.E2E_LOCK_TEST !== "1", "Chỉ chạy khi QA khoá e2e-gv-lock từ ngoài (E2E_LOCK_TEST=1)");
    test.setTimeout(150_000);
    await fillLogin(page, "gv-lock");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    const locked = page.getByText("Tài khoản của bạn đã bị khóa");
    await expect
      .poll(
        async () => {
          await page.reload({ waitUntil: "networkidle" });
          return await locked.isVisible().catch(() => false);
        },
        { timeout: 120_000, intervals: [4000] },
      )
      .toBe(true);
    // Không phải lỗi chung chung và không lộ dữ liệu quản trị.
    await expect(page.getByTestId("staff-name")).toHaveCount(0);
    await expect(nav(page)).toHaveCount(0);
  });

  test("375px: đăng nhập không tràn ngang, menu hamburger dùng được, nút >= 44px", async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 375, height: 700 } });
    const page = await ctx.newPage();
    await page.goto(`${ADMIN}/dang-nhap`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await fillLogin(page, "gv-mobile");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    await expect(nav(page)).toHaveCount(0);
    const menuBtn = page.getByRole("button", { name: "Menu" });
    const box = await menuBtn.boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(44);
    await menuBtn.click();
    await expect(nav(page).getByText("Tổng quan")).toBeVisible();
    await expect(page.getByRole("button", { name: "Đăng xuất" })).toBeVisible();
    await ctx.close();
  });
});
