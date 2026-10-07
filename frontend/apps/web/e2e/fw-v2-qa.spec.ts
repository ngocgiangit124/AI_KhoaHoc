import { expect, test, type Page } from "@playwright/test";

/**
 * QA FW-V2 + FW2 (bổ sung ngoài danh-muc-real.spec.ts): header đã đăng nhập (desktop + ngăn kéo 375),
 * đăng xuất/đăng nhập lại, lỗi đăng ký miễn phí (mock 403/409/422/429), sheet bộ lọc mobile, ảnh chụp.
 * Backend thật (E2E_REAL_BACKEND=1). Cần: seed-e2e-catalog.sh và user qa-t05-e2e-6.
 * Ảnh chụp ghi vào test-results/shots (SHOTS=1).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const FREE = "e2e-fw2-hinh-hoc-9-mien-phi";
const USER = "qa-t05-e2e-6@example.com";
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

test.describe("Header đã đăng nhập", () => {
  test("desktop: tên + Đăng xuất; đăng xuất -> khách; đăng nhập lại không còn trạng thái cũ", async ({ page }) => {
    await login(page, USER);
    const header = page.getByRole("banner");
    await expect(header.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await expect(header.getByText("Đăng nhập", { exact: true })).toHaveCount(0);
    await page.goto("/khoa-hoc");
    await expect(header.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await header.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(header.getByRole("link", { name: "Đăng nhập" })).toBeVisible({ timeout: 15_000 });
    await expect(header.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    // sau đăng xuất: trang chi tiết là khách (link đăng nhập), F5 vẫn khách
    await page.goto(`/khoa-hoc/${FREE}`);
    await expect(page.locator("#course-cta").getByRole("link", { name: "Đăng ký học miễn phí" })).toBeVisible();
    await page.reload();
    await expect(header.getByRole("button", { name: "Đăng xuất" })).toHaveCount(0);
    // đăng nhập lại
    await login(page, USER);
    await expect(header.getByRole("button", { name: "Đăng xuất" })).toBeVisible({ timeout: 15_000 });
    await page.goto(`/khoa-hoc/${FREE}`);
    await expect(page.locator("#course-cta").getByRole("button", { name: "Đăng ký học miễn phí" })).toBeVisible({ timeout: 15_000 });
  });

  test.describe("375px", () => {
    test.use({ viewport: { width: 375, height: 800 } });
    test("ngăn kéo: tên + Đăng xuất (chạm >= 44px), Esc đóng; đăng xuất ra khách", async ({ page }) => {
      await login(page, USER);
      await page.goto("/khoa-hoc");
      const menu = page.getByRole("button", { name: "Mở menu" });
      await expect(menu).toBeVisible({ timeout: 15_000 });
      const box = await menu.boundingBox();
      expect(box!.width).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeGreaterThanOrEqual(44);
      await menu.click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toBeVisible();
      const out = dlg.getByRole("button", { name: "Đăng xuất" });
      await expect(out).toBeVisible({ timeout: 15_000 });
      expect((await out.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await page.keyboard.press("Escape");
      await expect(dlg).toBeHidden();
      await menu.click();
      await dlg.getByRole("button", { name: "Đăng xuất" }).click();
      await expect(page.getByRole("link", { name: "Đăng nhập" }).first()).toBeVisible({ timeout: 15_000 });
    });

    test("sheet bộ lọc: Esc đóng + trả focus; mọi điều khiển >= 44px; không cuộn ngang", async ({ page }) => {
      await page.goto("/khoa-hoc");
      const trigger = page.getByRole("button", { name: /^Bộ lọc/ });
      await trigger.click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toBeVisible();
      const small = await dlg.locator("button, a, select, input:not([type=checkbox]):not([type=radio])").evaluateAll((els) =>
        els
          .map((e) => ({ t: (e.textContent ?? e.getAttribute("aria-label") ?? e.tagName).trim().slice(0, 30), tag: e.tagName, h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width }))
          .filter((x) => x.h > 0 && x.h < 43.5),
      );
      expect(small, JSON.stringify(small)).toEqual([]);
      const rows = await dlg.locator("input[type=checkbox]").evaluateAll((els) =>
        els.map((e) => {
          const r = (e.closest("label") ?? e).getBoundingClientRect();
          return { id: e.getAttribute("id") ?? e.getAttribute("name"), h: r.height };
        }),
      );
      expect(rows.length).toBeGreaterThan(0);
      expect(rows.filter((x) => x.h < 43.5), JSON.stringify(rows)).toEqual([]);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow).toBeLessThanOrEqual(0);
      await page.keyboard.press("Escape");
      await expect(dlg).toBeHidden();
      await expect(trigger).toBeFocused();
    });
  });
});

test.describe("Đăng ký miễn phí: lỗi từ API (mock)", () => {
  const cases: Array<[string, number, object, RegExp]> = [
    ["409 ENROLLMENT_PENDING -> Đang chờ duyệt", 409, { message: "x", code: "ENROLLMENT_PENDING" }, /Đang chờ duyệt/],
    ["422 COURSE_NOT_FREE -> thông báo", 422, { message: "x", code: "COURSE_NOT_FREE" }, /không còn miễn phí/],
    ["403 khác -> thông báo", 403, { message: "x", code: "FORBIDDEN" }, /không thể đăng ký/],
    ["429 -> thông báo thao tác quá nhanh", 429, { message: "x", code: "TOO_MANY_ATTEMPTS" }, /quá nhanh/],
  ];
  for (const [name, status, body, expected] of cases) {
    test(name, async ({ page }) => {
      await login(page, USER);
      await page.route("**/free-enrollments", (r) => r.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) }));
      await page.goto(`/khoa-hoc/${FREE}`);
      await page.locator("#course-cta").getByRole("button", { name: "Đăng ký học miễn phí" }).click();
      await expect(page.getByText(expected).locator("visible=true").first()).toBeVisible({ timeout: 10_000 });
    });
  }
});

test.describe("Ảnh chụp thực tế", () => {
  test.skip(process.env.SHOTS !== "1", "SHOTS=1 để chụp");
  for (const [tag, w, h] of [["375", 375, 800], ["1280", 1280, 800]] as const) {
    test(`chụp ${tag}`, async ({ browser }) => {
      const ctx = await browser.newContext({ viewport: { width: w, height: h } });
      const page = await ctx.newPage();
      const shot = async (name: string) => page.screenshot({ path: `test-results/shots/${name}-${tag}.png`, fullPage: false });
      await page.goto("/dang-nhap");
      await page.waitForLoadState("networkidle");
      await shot("dang-nhap");
      await page.goto("/");
      await page.waitForLoadState("networkidle");
      await shot("trang-chu");
      await page.goto("/khoa-hoc");
      await page.waitForLoadState("networkidle");
      await shot("danh-muc");
      await page.goto("/khoa-hoc/e2e-fw2-dai-so-9-tra-phi");
      await page.waitForLoadState("networkidle");
      await shot("chi-tiet");
      await ctx.close();
    });
  }
});

test.describe("Đổi liên hệ cần mật khẩu hiện tại (BUG-1)", () => {
  test("thiếu -> lỗi dưới ô; sai -> lỗi field; đúng -> mã mới tới email mới", async ({ page }) => {
    const email = "qa-fwv2-ct-1@example.com"; // seed: chưa xác thực; test đổi email của chính user này nên seed lại trước mỗi lần chạy
    const mailsTo = async (to: string) =>
      ((await (await fetch(`${process.env.E2E_MAILPIT_URL ?? "http://127.0.0.1:8025"}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`)).json()) as { messages?: unknown[] }).messages ?? [];
    await login(page, email);
    await page.goto("/tai-khoan");
    const contact = page.locator("#doi-lien-he");
    const newEmail = `qa-fwv2-ct-moi-${Date.now().toString(36)}@example.com`;
    await contact.getByLabel("Email", { exact: true }).fill(newEmail);
    const save = contact.getByRole("button", { name: "Lưu thay đổi" });
    await save.click();
    await expect(page.getByText(/Vui lòng nhập mật khẩu hiện tại/)).toBeVisible();
    await contact.getByLabel(/^Mật khẩu hiện tại/).fill("sai-mat-khau-1");
    await save.click();
    await expect(page.getByText(/Mật khẩu hiện tại không đúng/)).toBeVisible();
    expect(await mailsTo(newEmail)).toHaveLength(0);
    await contact.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await save.click();
    await expect(page.getByText(/Mã xác thực đã gửi tới email mới\./)).toBeVisible();
    await expect.poll(async () => (await mailsTo(newEmail)).length, { timeout: 15_000 }).toBeGreaterThan(0);
  });
});
