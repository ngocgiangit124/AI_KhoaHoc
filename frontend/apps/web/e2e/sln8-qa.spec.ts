import { expect, test, type Page } from "@playwright/test";

const ids = Object.fromEntries((process.env.E2E_SLN8 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần E2E_SLN8");

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill("matkhau-123");
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
}

test("QA AC: chưa học bài nào -> Tiếp tục học vào đúng bài đầu", async ({ page }) => {
  await login(page, "sln8-hs@example.com");
  await page.goto("/khoa-hoc/e2e-sln8-khoa");
  await page.getByRole("link", { name: "Tiếp tục học" }).first().click();
  await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l1}$`));
  await expect(page.getByRole("heading", { level: 1, name: "Bài SLN8 một" })).toBeVisible();
  await page.goto(`/hoc/${ids.course}`);
  await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l1}$`));
});

test("QA 375px: không tràn ngang, nút >=44px, thanh dính đáy, Tab+Enter có focus ring", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 667 });
  await login(page, "sln8-hs@example.com");
  await page.goto("/khoa-hoc/e2e-sln8-khoa");
  await expect(page.getByRole("link", { name: "Tiếp tục học" }).first()).toBeVisible();
  const sw = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(sw[0]).toBeLessThanOrEqual(sw[1] ?? 0);
  const links = page.getByRole("link", { name: "Tiếp tục học" });
  const n = await links.count();
  let visibleInViewport = 0;
  for (let i = 0; i < n; i++) {
    const l = links.nth(i);
    if (!(await l.isVisible())) continue;
    const b = (await l.boundingBox())!;
    console.log("cta", i, JSON.stringify(b));
    expect(b.height).toBeGreaterThanOrEqual(44);
    if (b.y >= 0 && b.y + b.height <= 667) visibleInViewport++;
  }
  expect(visibleInViewport).toBeGreaterThan(0);
  await page.evaluate(() => window.scrollTo(0, 0));
  const lesson = page.getByRole("link", { name: /Bài SLN8 hai/ }).first();
  await lesson.scrollIntoViewIfNeeded();
  const lb = (await lesson.boundingBox())!;
  console.log("lesson", JSON.stringify(lb));
  expect(lb.height).toBeGreaterThanOrEqual(44);
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  let focused = false;
  for (let i = 0; i < 80 && !focused; i++) {
    await page.keyboard.press("Tab");
    focused = await lesson.evaluate((el) => el === document.activeElement);
  }
  expect(focused).toBe(true);
  const ring = await lesson.evaluate((el) => { const s = getComputedStyle(el); return { o: s.outlineStyle + " " + s.outlineWidth, b: s.boxShadow }; });
  console.log("ring", JSON.stringify(ring));
  expect(ring.o.startsWith("none") && ring.b === "none").toBe(false);
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l2}$`));
});

for (const who of ["guest", "sln8-qa-x@example.com"]) {
  test(`QA: ${who} mở thẳng URL bài bị chặn, không thấy video`, async ({ page }) => {
    if (who !== "guest") await login(page, who);
    const statuses: string[] = [];
    page.on("response", (r) => { if (/playback|\.m3u8|\/learn\//.test(r.url())) statuses.push(r.status() + " " + r.url()); });
    await page.goto(`/hoc/${ids.course}/bai/${ids.l1}`);
    await page.waitForTimeout(5000);
    console.log(who, "url=", page.url(), "responses=", JSON.stringify(statuses));
    await expect(page.locator("video")).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 1, name: "Bài SLN8 một" })).toHaveCount(0);
    for (const s of statuses) expect(s.startsWith("200")).toBe(false);
    // chi tiết khóa không hiện link /hoc cho người chưa ghi danh
    await page.goto("/khoa-hoc/e2e-sln8-khoa");
    await expect(page.getByRole("link", { name: "Tiếp tục học" })).toHaveCount(0);
    await expect(page.locator('a[href^="/hoc/"]')).toHaveCount(0);
  });
}
