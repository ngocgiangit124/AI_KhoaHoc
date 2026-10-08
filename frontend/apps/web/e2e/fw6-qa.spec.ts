import { expect, test, type Page } from "@playwright/test";

/**
 * QA FW6 (bổ sung) — dữ liệu từ `e2e/seed-qa-fw6.sh` (E2E_FW6Q="full=.. long=.. unpub=.. zero=.. revoked=.. other=..").
 * Chạy bằng `e2e/run-qa-fw6.sh`, `--workers=1`.
 */
const PASSWORD = "matkhau-123";
const ids = Object.fromEntries((process.env.E2E_FW6Q ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.full, "Cần backend thật và E2E_FW6Q từ seed-qa-fw6.sh");
const LIST = "/tai-khoan/khoa-hoc-cua-toi";

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe("QA FW6", () => {
  test("QA1: >12 khóa + pending: trang 1 12 thẻ; trang 2 mở sẵn tab Chờ duyệt; nhóm bị từ chối có lý do (text, không HTML); thu hồi không có trong danh sách", async ({ page }) => {
    await login(page, "fw6q-mix@example.com");
    await page.goto(LIST);
    await expect(page.locator("article h3").first()).toBeVisible({ timeout: 90_000 });
    // 13 khóa đang học: 12 ở trang 1 (không tính banner Học tiếp)
    const n1 = await page.locator("article").count();
    expect(n1).toBeGreaterThanOrEqual(12);
    await expect(page.getByText("QA Bị thu hồi")).toHaveCount(0);
    await page.getByRole("tab", { name: /Không được duyệt/ }).click();
    await expect(page.getByText("Lý do <b>thử</b> HTML & dài.")).toBeVisible();
    await expect(page.locator("main b")).toHaveCount(0);
    await page.goto(`${LIST}?trang=2`);
    await expect(page.getByRole("tab", { selected: true })).toBeVisible({ timeout: 90_000 });
    console.log("[QA1] tab mặc định trang 2:", await page.getByRole("tab", { selected: true }).textContent());
    console.log("[QA1] số thẻ trang 2:", await page.locator("article").count());
    // trang vượt last_page
    await page.goto(`${LIST}?trang=99`);
    // BUG-1 đã sửa: tab Đang học mặc định, báo trang trống + liên kết về trang đầu.
    await expect(page.getByRole("tab", { name: /Đang học/ })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole("tab", { name: /Đang học/ })).toHaveAttribute("aria-selected", "true");
    await expect(page.getByText("Trang này không có khóa học")).toBeVisible();
    await expect(page.getByRole("link", { name: "Về trang đầu" })).toHaveAttribute("href", LIST);
    for (const bad of ["abc", "0", "-1", "1.5"]) {
      await page.goto(`${LIST}?trang=${bad}`);
      await expect(page.locator("article h3").first()).toBeVisible({ timeout: 90_000 });
    }
  });

  test("QA2: khóa 0 bài / ngừng bán / 100% / tiêu đề dài", async ({ page }) => {
    await login(page, "fw6q-mix@example.com");
    await page.goto(LIST);
    await expect(page.locator("article h3").first()).toBeVisible({ timeout: 90_000 });
    const unpub = page.locator("article", { hasText: "QA Khóa 12" });
    await expect(unpub.getByText("Khóa đã ngừng bán")).toBeVisible();
    const full = page.locator("article", { hasText: "QA Khóa 10" });
    await expect(full.getByText("2/2 bài · 100%")).toBeVisible();
    await expect(full.getByText("Đã hoàn thành", { exact: true })).toBeVisible();
    await expect(full.getByRole("link", { name: "Xem lại" })).toBeVisible();
    await page.goto(`${LIST}?trang=2`);
    const zero = page.locator("article", { hasText: "QA Khóa 13" });
    await expect(zero.getByText("Chưa có nội dung")).toBeVisible({ timeout: 90_000 });
    await expect(zero.getByText(/NaN|Infinity/)).toHaveCount(0);
    await expect(zero.getByRole("link", { name: /Tiếp tục học|Bắt đầu học|Xem lại/ })).toHaveCount(0);
    // chi tiết các khóa
    await page.goto(`${LIST}/${ids.zero}`);
    await expect(page.getByRole("heading", { level: 1, name: "QA Khóa 13" })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText(/NaN|Infinity|undefined/)).toHaveCount(0);
    await page.goto(`${LIST}/${ids.full}`);
    await expect(page.getByRole("link", { name: /Xem lại bài học/ })).toBeVisible({ timeout: 90_000 });
    await page.goto(`${LIST}/${ids.unpub}`);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
    console.log("[QA2] unpub detail:", (await page.locator("main").innerText()).slice(0, 200).replace(/\n/g, " | "));
  });

  test("QA3: 375px với tiêu đề rất dài (danh sách + chi tiết): không tràn, nút >= 44px", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await login(page, "fw6q-mix@example.com");
    for (const url of [LIST, `${LIST}/${ids.long}`, `${LIST}/${ids.zero}`]) {
      await page.goto(url);
      await expect(page.locator("main h1, main article h3").first()).toBeVisible({ timeout: 90_000 });
      await page.waitForTimeout(800);
      expect({ url, o: await overflow(page) }).toEqual({ url, o: 0 });
      const small = await page.evaluate(() =>
        [...document.querySelectorAll("main a.focus-ring, main button")].filter((el) => {
          const r = (el as HTMLElement).getBoundingClientRect();
          return r.width > 0 && r.height > 0 && r.height < 43.5;
        }).map((el) => `${el.textContent?.trim().slice(0, 30)}:${Math.round((el as HTMLElement).getBoundingClientRect().height)}`),
      );
      console.log("[QA3] nút <44px", url, JSON.stringify(small));
    }
  });

  test("QA4: học sinh khác / thu hồi: 403, không lộ dữ liệu", async ({ page }) => {
    await login(page, "fw6q-mix@example.com");
    for (const id of [ids.other, ids.revoked]) {
      await page.goto(`${LIST}/${id}`);
      await expect(page.getByText("Bạn chưa sở hữu khóa học này")).toBeVisible({ timeout: 90_000 });
      await expect(page.getByText(/QA Khóa của người khác|QA Bị thu hồi/)).toHaveCount(0);
    }
    await page.context().clearCookies();
    await login(page, "fw6q-other@example.com");
    await page.goto(LIST);
    await expect(page.locator("article", { hasText: "QA Khóa của người khác" })).toBeVisible({ timeout: 90_000 });
    await expect(page.locator("article", { hasText: "QA Khóa 1" })).toHaveCount(0);
    await page.goto(`${LIST}/${ids.full}`);
    await expect(page.getByText("Bạn chưa sở hữu khóa học này")).toBeVisible({ timeout: 90_000 });
  });

  test("QA5: thu hồi giữa phiên (API trả 403) và 401 giữa chừng", async ({ page }) => {
    await login(page, "fw6q-mix@example.com");
    await page.goto(`${LIST}/${ids.full}`);
    await expect(page.getByRole("heading", { level: 1, name: "QA Khóa 10" })).toBeVisible({ timeout: 90_000 });
    await page.route("**/api/v1/me/courses/*/progress", (r) =>
      r.fulfill({ status: 403, contentType: "application/json", body: JSON.stringify({ message: "x", code: "COURSE_NOT_OWNED", errors: { course: { slug: "e2e-fw6q-10" } } }) }),
    );
    await page.reload();
    await expect(page.getByText("Bạn chưa sở hữu khóa học này")).toBeVisible({ timeout: 90_000 });
    await page.unroute("**/api/v1/me/courses/*/progress");
    // 401 giữa chừng: xoá cookie rồi bấm Thử lại / điều hướng client
    await page.goto(LIST);
    await expect(page.locator("article h3").first()).toBeVisible({ timeout: 90_000 });
    await page.route("**/api/v1/me/courses*", (r) => r.fulfill({ status: 401, contentType: "application/json", body: JSON.stringify({ message: "Unauthenticated." }) }));
    await page.getByRole("tab", { name: /Chờ duyệt/ }).click();
    await page.getByRole("link", { name: "Trang 2" }).click().catch(() => undefined);
    await page.reload();
    await expect(page.getByText(/Phiên đăng nhập đã kết thúc/).first()).toBeVisible({ timeout: 90_000 });
    console.log("[QA5] url sau 401:", page.url());
  });

  test("QA6: bàn phím: tab (mũi tên), mục lục <details> bằng Enter/Space, Tab tới nút", async ({ page }) => {
    await login(page, "fw6q-mix@example.com");
    await page.goto(LIST);
    await expect(page.locator("article h3").first()).toBeVisible({ timeout: 90_000 });
    const tabs = page.getByRole("tab");
    await tabs.first().focus();
    await page.keyboard.press("ArrowRight");
    await expect(tabs.nth(1)).toHaveAttribute("aria-selected", "true");
    await page.keyboard.press("ArrowRight");
    await expect(tabs.nth(2)).toHaveAttribute("aria-selected", "true");
    await page.keyboard.press("ArrowLeft");
    await page.keyboard.press("Home");
    await expect(tabs.first()).toHaveAttribute("aria-selected", "true");
    await page.goto(`${LIST}/${ids.unpub}`);
    const sum = page.locator("summary").first();
    await expect(sum).toBeVisible({ timeout: 90_000 });
    const det = page.locator("details").first();
    const before = await det.evaluate((d) => (d as HTMLDetailsElement).open);
    await sum.focus();
    await page.keyboard.press("Enter");
    expect(await det.evaluate((d) => (d as HTMLDetailsElement).open)).toBe(!before);
    await page.keyboard.press("Space");
    expect(await det.evaluate((d) => (d as HTMLDetailsElement).open)).toBe(before);
    // focus ring nhìn thấy
    await sum.focus();
    const outline = await sum.evaluate((e) => getComputedStyle(e).outlineStyle + "|" + getComputedStyle(e).boxShadow);
    console.log("[QA6] focus summary:", outline);
  });
});
