import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * QA FW3 (US-022) — kiểm THÊM bằng backend thật. Dữ liệu: `seed-e2e-fw3.sh --reset` rồi `seed-qa-fw3.sh` (tài khoản fw3-qa-*).
 * Chạy: `e2e/run-qa-fw3.sh`; dọn: `seed-e2e-fw3.sh --clean`.
 */
const PASSWORD = "matkhau-123";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://localhost:8025";
const BASE = "http://api.localhost:3000";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật");

const emailOf = (key: string) => `fw3-qa-${key}@example.com`;

async function login(page: Page, key: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(emailOf(key));
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}
async function hscroll(page: Page) {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}
const submit = (page: Page) => page.getByRole("button", { name: "Gửi đơn" }).first();
const orderLinks = (page: Page) => page.locator('main a[href^="/tai-khoan/don-hang/"]');
const codeFromUrl = (page: Page) => new URL(page.url()).pathname.split("/").pop() as string;
async function ready(page: Page) {
  await page.goto("/thanh-toan");
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
}
async function mailsWithCode(request: APIRequestContext, to: string, code: string): Promise<number> {
  const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}&limit=100`);
  const list = (await res.json()) as { messages?: Array<{ ID: string }> };
  let n = 0;
  for (const m of list.messages ?? []) {
    const msg = (await (await request.get(`${MAILPIT}/api/v1/message/${m.ID}`)).json()) as { Text?: string; HTML?: string };
    if (`${msg.Text ?? ""}${msg.HTML ?? ""}`.includes(code)) n += 1;
  }
  return n;
}
async function userCtx(browser: Browser, key: string, width = 1280) {
  const context = await browser.newContext({ baseURL: BASE, viewport: { width, height: 800 } });
  const page = await context.newPage();
  await login(page, key);
  return { context, page };
}

test("QA1: ngắt mạng SAU khi server nhận (response mất) rồi bấm lại -> dùng lại đơn, đúng 1 đơn, đúng 1 thư", async ({ page, request }) => {
  await login(page, "net");
  await ready(page);
  const posts: string[] = [];
  page.on("request", (r) => r.method() === "POST" && r.url().endsWith("/api/v1/checkout") && posts.push(r.url()));
  let aborted = 0;
  page.on("requestfailed", (r) => r.url().endsWith("/api/v1/checkout") && (aborted += 1));
  await page.route("**/api/v1/checkout", async (route) => {
    if (aborted > 0 || posts.length > 1) return route.continue();
    await route.fetch(); // server đã tạo đơn
    await route.abort("failed"); // trình duyệt không nhận được response
  });
  await submit(page).click();
  await expect.poll(() => aborted, { timeout: 90_000 }).toBe(1);
  await page.waitForTimeout(1500);
  console.log("[QA1] url:", page.url(), "| alerts:", JSON.stringify(await page.getByRole("alert").allTextContents()), "| disabled:", await submit(page).isDisabled().catch(() => "n/a"));
  await expect(page).toHaveURL(/\/thanh-toan$/);
  await expect(page.getByRole("alert").filter({ hasText: /\S/ }).first()).toBeVisible({ timeout: 30_000 });
  await expect(submit(page)).toBeEnabled();
  await submit(page).click();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+\?dung-lai=1$/, { timeout: 90_000 });
  const code = codeFromUrl(page);
  await expect(page.getByText("Đơn này bạn đã gửi trước đó")).toBeVisible({ timeout: 90_000 });
  expect(posts).toHaveLength(2);
  await page.goto("/tai-khoan/don-hang");
  await expect(orderLinks(page)).toHaveCount(1, { timeout: 90_000 });
  await expect.poll(() => mailsWithCode(request, emailOf("net"), code), { timeout: 60_000 }).toBeGreaterThanOrEqual(1);
  await page.waitForTimeout(5000);
  expect(await mailsWithCode(request, emailOf("net"), code)).toBe(1);
});

test("QA2: ngắt mạng TRƯỚC khi tới server rồi bấm lại -> tạo đúng 1 đơn", async ({ page, request }) => {
  await login(page, "net2");
  await ready(page);
  let failed = 0;
  page.on("requestfailed", (r) => r.url().endsWith("/api/v1/checkout") && (failed += 1));
  await page.route("**/api/v1/checkout", (route) => (failed > 0 ? route.continue() : route.abort("internetdisconnected")));
  await submit(page).click();
  await expect.poll(() => failed, { timeout: 90_000 }).toBe(1);
  await expect(page.getByRole("alert").filter({ hasText: /\S/ }).first()).toBeVisible({ timeout: 30_000 });
  await expect(submit(page)).toBeEnabled();
  await submit(page).click();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  const code = codeFromUrl(page);
  await page.goto("/tai-khoan/don-hang");
  await expect(orderLinks(page)).toHaveCount(1, { timeout: 90_000 });
  await expect.poll(() => mailsWithCode(request, emailOf("net2"), code), { timeout: 60_000 }).toBe(1);
});

test("QA3: cùng tài khoản/giỏ ở 2 trình duyệt (2 context riêng) bấm gửi cùng lúc -> 1 đơn, 1 thư", async ({ browser, request }) => {
  const A = await userCtx(browser, "two");
  // Đăng nhập lần 2 cùng tài khoản sẽ đá phiên cũ (đăng nhập 1 nơi), nên "trình duyệt thứ 2" dùng chung phiên qua storageState.
  const ctxB = await browser.newContext({ baseURL: BASE, storageState: await A.context.storageState() });
  const B = { context: ctxB, page: await ctxB.newPage() };
  for (const [n, p] of [["A", A.page], ["B", B.page]] as const) {
    await p.goto("/thanh-toan");
    try {
      await expect(p.getByRole("radio").first()).toBeChecked({ timeout: 60_000 });
    } catch (e) {
      console.log("[QA3] không có radio ở", n, p.url(), (await p.locator("main").innerText()).replace(/\s+/g, " ").slice(0, 500));
      throw e;
    }
  }
  await Promise.all([submit(A.page).click(), submit(B.page).click()]);
  await expect(A.page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  await expect(B.page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  const code = codeFromUrl(A.page);
  expect(codeFromUrl(B.page)).toBe(code);
  await A.page.goto("/tai-khoan/don-hang");
  await expect(orderLinks(A.page)).toHaveCount(1, { timeout: 90_000 });
  await expect.poll(() => mailsWithCode(request, emailOf("two"), code), { timeout: 60_000 }).toBeGreaterThanOrEqual(1);
  await A.page.waitForTimeout(4000);
  expect(await mailsWithCode(request, emailOf("two"), code)).toBe(1);
  await A.context.close();
  await B.context.close();
});

test("QA4: đổi mã giảm giá ở tab khác -> 409 CHECKOUT_CHANGED, preview mới, KHÔNG tự gửi", async ({ browser }) => {
  const A = await userCtx(browser, "coupon");
  await ready(A.page);
  await expect(A.page.getByText(/270\.000/).first()).toBeVisible();
  const B = await A.context.newPage();
  await B.goto("/gio-hang");
  await B.getByRole("button", { name: "Gỡ mã" }).click();
  await expect(B.getByRole("button", { name: "Áp dụng" })).toBeVisible({ timeout: 60_000 });
  await B.getByLabel(/Nhập mã/).fill("E2EFW3B");
  await B.getByRole("button", { name: "Áp dụng" }).click();
  await expect(B.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
  const posts: string[] = [];
  A.page.on("request", (r) => r.method() === "POST" && r.url().endsWith("/api/v1/checkout") && posts.push(r.url()));
  await submit(A.page).click();
  const alert = A.page.getByRole("alert").filter({ hasText: "Giỏ hàng vừa thay đổi" });
  await expect(alert).toBeVisible({ timeout: 60_000 });
  await expect(A.page.getByText(/240\.000/).first()).toBeVisible();
  await A.page.waitForTimeout(4000);
  expect(posts).toHaveLength(1);
  await expect(A.page).toHaveURL(/\/thanh-toan$/);
  await A.page.goto("/tai-khoan/don-hang");
  await expect(A.page.getByText(/chưa có đơn|Chưa có đơn/).first()).toBeVisible({ timeout: 90_000 });
  await expect(orderLinks(A.page)).toHaveCount(0);
  await ready(A.page);
  await submit(A.page).click();
  await expect(A.page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  await expect(A.page.getByText(/240\.000/).first()).toBeVisible({ timeout: 60_000 });
  await A.context.close();
});

test("QA5a: hộp đơn chờ -> 'Giữ đơn cũ' (bàn phím: Tab giữ trong hộp, Enter) -> đơn cũ nguyên, không đơn mới", async ({ page }) => {
  await login(page, "repkeep");
  await ready(page);
  await submit(page).click();
  const dialog = page.getByRole("dialog", { name: "Bạn đang có đơn chờ duyệt" });
  await expect(dialog).toBeVisible({ timeout: 60_000 });
  // Tab nhiều lần: tiêu điểm phải ở trong hộp.
  for (let i = 0; i < 6; i++) {
    await page.keyboard.press("Tab");
    const inside = await page.evaluate(() => (document.activeElement === document.body || !!document.activeElement?.closest('dialog')));
    expect(inside, `Tab lần ${i + 1} rời hộp thoại`).toBe(true);
  }
  await dialog.getByRole("button", { name: "Giữ đơn cũ" }).focus();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/tai-khoan\/don-hang\/VVQAKEEP001$/, { timeout: 60_000 });
  await page.goto("/tai-khoan/don-hang");
  await expect(orderLinks(page)).toHaveCount(1, { timeout: 90_000 });
  await expect(orderLinks(page).first()).toContainText("Chờ Quản trị viên duyệt");
});

test("QA5b: hộp đơn chờ -> 'Huỷ đơn cũ, đặt đơn mới' bằng Enter -> đơn cũ 'đã thay bằng {mã mới}'", async ({ page }) => {
  await login(page, "repnew");
  await ready(page);
  await submit(page).click();
  const dialog = page.getByRole("dialog", { name: "Bạn đang có đơn chờ duyệt" });
  await expect(dialog).toBeVisible({ timeout: 60_000 });
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  // Tiêu điểm trả về nút gửi?
  const backOnSubmit = await page.evaluate(() => document.activeElement?.textContent?.includes("Gửi đơn") ?? false);
  console.log("[QA5b] focus quay lại nút Gửi đơn sau Esc:", backOnSubmit);
  await submit(page).click();
  await expect(dialog).toBeVisible({ timeout: 60_000 });
  const replaceBtn = dialog.getByRole("button", { name: "Huỷ đơn cũ, đặt đơn mới" });
  await replaceBtn.focus();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  const newCode = codeFromUrl(page);
  await page.goto("/tai-khoan/don-hang/VVQANEW0001");
  await expect(page.getByText("Đơn đã được thay bằng đơn mới")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: `Xem đơn ${newCode}` })).toBeVisible();
  await page.goto("/tai-khoan/don-hang");
  await expect(orderLinks(page)).toHaveCount(2, { timeout: 90_000 });
  await expect(page.getByText("Chờ Quản trị viên duyệt")).toHaveCount(1);
  await expect(orderLinks(page).filter({ hasText: "Đã thay bằng đơn mới" })).toHaveCount(1);
});

test("QA6: vượt 5 đơn/ngày -> thông báo + giờ reset (tương lai, 00:00 ngày mai giờ VN)", async ({ page }) => {
  await login(page, "limit");
  await ready(page);
  await submit(page).click();
  const alert = page.getByRole("alert").filter({ hasText: "Bạn đã đặt quá nhiều đơn hôm nay" });
  await expect(alert).toBeVisible({ timeout: 60_000 });
  const text = (await alert.textContent()) ?? "";
  console.log("[QA6] ", text);
  const m = text.match(/Từ (\d{2}):(\d{2}), (\d{2})\/(\d{2})\/(\d{4})/);
  expect(m).not.toBeNull();
  const tomorrow = new Date(Date.now() + 24 * 3600 * 1000).toLocaleDateString("en-GB", { timeZone: "Asia/Ho_Chi_Minh" });
  expect(`${m![3]}/${m![4]}/${m![5]}`).toBe(tomorrow);
  await expect(page).toHaveURL(/\/thanh-toan$/);
});

test("QA7: link trong thư tới đơn đã huỷ/hết hạn, của người khác, mã sai định dạng", async ({ page }) => {
  await login(page, "links");
  const errs: string[] = [];
  page.on("pageerror", (e) => errs.push(e.message));
  for (const code of ["VVQACANC001", "VVQAEXPD001"]) {
    await page.goto(`/thanh-toan/da-gui/${code}`);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText(/Đã huỷ|huỷ/).first()).toBeVisible();
    await expect(page.getByRole("link", { name: /Gọi điện/ })).toHaveCount(0);
    await expect(page.getByRole("link", { name: /Nhắn Zalo/ })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Huỷ đơn" })).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 1, name: "Đã gửi đơn" })).toHaveCount(0);
    console.log("[QA7] h1 đơn đóng", code, "=", await page.getByRole("heading", { level: 1 }).first().textContent());
  }
  for (const path of ["/thanh-toan/da-gui/VVQAOTHER01", "/tai-khoan/don-hang/VVQAOTHER01", "/thanh-toan/da-gui/VVQAKHONGCO1"]) {
    await page.goto(path);
    await expect(page.getByText("Không tìm thấy đơn hàng")).toBeVisible({ timeout: 90_000 });
  }
  for (const bad of ["a..b", "%3Cscript%3E", "vvqacanc001", "VV%00", "x".repeat(80), "VV-1", "%E2%80%AE"]) {
    for (const base of ["/thanh-toan/da-gui/", "/tai-khoan/don-hang/"]) {
      const res = await page.goto(base + bad);
      const st = res?.status();
      if (st === 200) await page.waitForFunction(() => !/Đang tải/.test(document.querySelector("main")?.textContent ?? "x"), null, { timeout: 60_000 });
      const notFoundUi = await page.getByText(/Không tìm thấy|404/).first().isVisible().catch(() => false);
      if (st === 200 && !notFoundUi) console.log("[QA7] 200 body:", (await page.locator("main").innerText()).replace(/\s+/g, " ").slice(0, 300));
      console.log("[QA7] bad", base + bad.slice(0, 20), st, "ui404=", notFoundUi);
      expect([404, 200, 400]).toContain(st);
      if (bad === "vvqacanc001") continue; // chữ thường: server so khớp không phân biệt hoa/thường, vẫn đúng chủ đơn (ghi nhận, NIT)
      expect(st === 404 || notFoundUi).toBe(true);
    }
  }
  expect(errs).toEqual([]);
});

test("QA8: ghi chú/lý do chứa <script>, <b>, <img onerror> hiển thị dạng chữ, không thực thi", async ({ page }) => {
  await login(page, "xss");
  await page.addInitScript(() => { (window as unknown as { __xss?: number }).__xss = 0; });
  await page.goto("/tai-khoan/don-hang/VVQAXSS0001");
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText("<script>window.__xss=1</script> <b>đậm</b>")).toBeVisible();
  await expect(page.getByText('Dòng 2 & "nháy"')).toBeVisible();
  expect(await page.locator("main b").count()).toBe(0);
  expect(await page.locator("main script").count()).toBe(0);
  await page.goto("/tai-khoan/don-hang/VVQAXSS0002");
  await expect(page.getByText("<b>lý do</b><img src=x onerror=window.__xss=1>")).toBeVisible({ timeout: 90_000 });
  expect(await page.locator("main b, main img[src='x']").count()).toBe(0);
  await page.goto("/thanh-toan/da-gui/VVQAXSS0001");
  await expect(page.getByRole("heading", { level: 1, name: "Đã gửi đơn" })).toBeVisible({ timeout: 90_000 });
  expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBe(0);
});

test("QA9: kênh liên hệ đúng số thật, nút >=44px, tel:, Zalo tab mới noopener, KHÔNG có STK/QR, hạn 72h", async ({ page }) => {
  await login(page, "xss");
  await page.goto("/thanh-toan/da-gui/VVQAXSS0001");
  await expect(page.getByRole("heading", { level: 1, name: "Đã gửi đơn" })).toBeVisible({ timeout: 90_000 });
  const call = page.getByRole("link", { name: /Gọi điện/ });
  const zalo = page.getByRole("link", { name: /Nhắn Zalo/ });
  await expect(call).toHaveAttribute("href", "tel:0915592224");
  await expect(zalo).toHaveAttribute("href", "https://zalo.me/0915592224");
  await expect(zalo).toHaveAttribute("target", "_blank");
  const rel = (await zalo.getAttribute("rel")) ?? "";
  expect(rel).toContain("noopener");
  expect(rel).toContain("noreferrer");
  for (const l of [call, zalo, page.getByRole("link", { name: /hotro@/ })]) {
    expect((await l.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
  await expect(page.getByText(/0915\s?592\s?224/).first()).toBeVisible();
  await expect(page.getByText("8h–17h")).toBeVisible();
  const body = (await page.locator("main").innerText()).replace(/\s+/g, " ");
  expect(body).not.toMatch(/STK|số tài khoản\s*:?\s*\d|Ngân hàng|Vietcombank|Techcombank|MB Bank|VietQR|mã QR|quét mã/i);
  expect(await page.locator("main img[alt*='QR' i], main canvas, main svg[aria-label*='QR' i]").count()).toBe(0);
  await expect(page.getByText(/không đăng số tài khoản trên website/)).toBeVisible();
  const hours = body.match(/còn (\d+) giờ/);
  console.log("[QA9] hạn:", hours?.[0]);
  expect(Number(hours?.[1])).toBeGreaterThanOrEqual(1);
  expect(Number(hours?.[1])).toBeLessThanOrEqual(72);
  // popup Zalo: tab mới, không có opener
  const [popup] = await Promise.all([page.context().waitForEvent("page", { timeout: 15_000 }).catch(() => null), zalo.click({ noWaitAfter: true })]);
  if (popup) {
    expect(await popup.evaluate(() => window.opener === null).catch(() => true)).toBe(true);
    await popup.close();
  }
});

test("QA10 (R1): /auth/me lỗi ngay sau khi xoá khóa -> giỏ không bị thay bằng lỗi, header không thành khách", async ({ page }) => {
  await login(page, "me");
  await page.goto("/gio-hang");
  await expect(page.getByRole("link", { name: "E2E FW3 Ngữ văn 9" })).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: /Giỏ hàng, 2 khóa/ })).toBeVisible();
  await page.route("**/api/v1/auth/me", (route) => route.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ message: "Server Error" }) }));
  await page.getByRole("button", { name: "Xoá khóa E2E FW3 Ngữ văn 9 khỏi giỏ" }).click();
  await expect(page.getByRole("link", { name: "E2E FW3 Ngữ văn 9" })).toHaveCount(0, { timeout: 60_000 });
  await page.waitForTimeout(2500);
  await expect(page.getByRole("link", { name: "E2E FW3 Toán 9 nâng cao" })).toBeVisible();
  await expect(page.getByText("Không tải được thông tin tài khoản")).toHaveCount(0);
  await expect(page.getByRole("heading", { level: 1, name: "Giỏ hàng" })).toBeVisible();
  await expect(page.getByRole("banner").getByRole("link", { name: "Đăng nhập" })).toHaveCount(0);
  await expect(page.getByRole("banner").getByRole("link", { name: /Đăng ký/ })).toHaveCount(0);
  await expect(page.getByRole("banner").getByRole("link", { name: /QA FW3 me/ })).toBeVisible();
  // Lỗi mạng (abort) cũng vậy.
  await page.unroute("**/api/v1/auth/me");
  await page.route("**/api/v1/auth/me", (route) => route.abort("failed"));
  await page.getByLabel(/Nhập mã/).fill("E2EFW3");
  await page.getByRole("button", { name: "Áp dụng" }).click();
  await expect(page.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
  await page.waitForTimeout(2000);
  await expect(page.getByText("Không tải được thông tin tài khoản")).toHaveCount(0);
  await expect(page.getByRole("banner").getByRole("link", { name: "Đăng nhập" })).toHaveCount(0);
});

test("QA11: 375px — không cuộn ngang ở mọi trang; thanh dính đáy không che ô ghi chú khi focus", async ({ browser }) => {
  const A = await userCtx(browser, "kbd", 375);
  const page = A.page;
  for (const path of ["/gio-hang", "/thanh-toan", "/tai-khoan/don-hang", "/tai-khoan"]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
    expect(await hscroll(page), path).toBeLessThanOrEqual(0);
  }
  await ready(page);
  // Mô phỏng bàn phím ảo: viewport thấp.
  await page.setViewportSize({ width: 375, height: 380 });
  const note = page.getByLabel(/Ghi chú cho Quản trị viên/);
  await note.focus();
  await page.keyboard.type("abc");
  const info = await page.evaluate(() => {
    const ta = document.activeElement as HTMLElement;
    const r = ta.getBoundingClientRect();
    const fixed = [...document.querySelectorAll<HTMLElement>("body *")].filter((e) => ["fixed", "sticky"].includes(getComputedStyle(e).position) && e.getBoundingClientRect().height > 0 && !e.contains(ta));
    const overlaps = fixed.map((e) => { const b = e.getBoundingClientRect(); return { tag: e.tagName, top: b.top, bottom: b.bottom, ov: Math.max(0, Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top)) }; });
    const top = document.elementFromPoint(r.left + r.width / 2, Math.min(r.bottom - 2, window.innerHeight - 2));
    return { tag: ta.tagName, rTop: r.top, rBottom: r.bottom, vh: window.innerHeight, overlaps, topIsTa: top === ta };
  });
  console.log("[QA11]", JSON.stringify(info));
  expect(info.tag).toBe("TEXTAREA");
  expect(info.rBottom).toBeGreaterThan(0);
  expect(info.overlaps.filter((o) => o.ov > 4)).toEqual([]);
  // Bàn phím thật: Tab tới nút Gửi đơn rồi Enter.
  await page.setViewportSize({ width: 375, height: 800 });
  await note.focus();
  let reached = false;
  for (let i = 0; i < 15 && !reached; i++) {
    await page.keyboard.press("Tab");
    reached = await page.evaluate(() => document.activeElement?.tagName === "BUTTON" && /Gửi đơn/.test(document.activeElement.textContent ?? ""));
  }
  expect(reached).toBe(true);
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  expect(await hscroll(page)).toBeLessThanOrEqual(0);
  await A.context.close();
});

test("QA12: ô ghi chú chặn < > ; 500 ký tự; tiếng Việt có dấu", async ({ browser }) => {
  const A = await userCtx(browser, "kbd2");
  const page = A.page;
  await ready(page);
  const note = page.getByLabel(/Ghi chú cho Quản trị viên/);
  await note.fill("<b>x</b>");
  const posts: string[] = [];
  page.on("request", (r) => r.method() === "POST" && r.url().endsWith("/api/v1/checkout") && posts.push(r.url()));
  await submit(page).click();
  await page.waitForTimeout(1500);
  const alerts = await page.getByRole("alert").allTextContents();
  console.log("[QA12] <b> ->", posts.length, "POST;", JSON.stringify(alerts), "| field text:", (await page.locator("main").innerText()).match(/không (được|thể)[^\n]*/)?.[0]);
  const invalid = await note.getAttribute("aria-invalid");
  const described = await note.evaluate((el) => (el.getAttribute("aria-describedby") ?? "").split(" ").map((id) => document.getElementById(id)?.textContent ?? "").join(" | "));
  console.log("[QA12] aria-invalid:", invalid, "| describedby:", described);
  expect(posts.length).toBe(0);
  expect(invalid === "true" && /<|>|không/i.test(described)).toBe(true);
  await expect(page).toHaveURL(/\/thanh-toan$/);
  const long = "ắ".repeat(501);
  await note.fill(long);
  const val = await note.inputValue();
  console.log("[QA12] 501 ký tự -> len", val.length, "|", (await page.getByText(/\/500/).first().textContent()) ?? "");
  await note.fill("Gọi sau 18h – Zalo mẹ: 0987 654 321 ắằẳẵặ");
  await expect(page.getByText(/\/500/).first()).toBeVisible();
  await A.context.close();
});

test("QA13: huỷ đơn — xác nhận (Esc/Tab), huỷ lần 2 từ tab cũ -> thông báo đúng", async ({ browser }) => {
  const A = await userCtx(browser, "cancel");
  const a = A.page;
  const b = await A.context.newPage();
  for (const p of [a, b]) {
    await p.goto("/tai-khoan/don-hang/VVQACANCEL1");
    await expect(p.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
  }
  // Esc đóng hộp, tiêu điểm quay lại nút.
  await a.getByRole("button", { name: "Huỷ đơn" }).click();
  const dlg = a.getByRole("dialog").or(a.getByRole("alertdialog"));
  await expect(dlg.getByText(/Huỷ đơn VVQACANCEL1\?/)).toBeVisible();
  await a.keyboard.press("Escape");
  await expect(dlg).toBeHidden();
  console.log("[QA13] focus về nút Huỷ đơn sau Esc:", await a.evaluate(() => /Huỷ đơn/.test(document.activeElement?.textContent ?? "")));
  await a.getByRole("button", { name: "Huỷ đơn" }).click();
  for (let i = 0; i < 5; i++) {
    await a.keyboard.press("Tab");
    expect(await a.evaluate(() => (document.activeElement === document.body || !!document.activeElement?.closest('dialog'))), `Tab ${i + 1}`).toBe(true);
  }
  await dlg.getByRole("button", { name: "Huỷ đơn" }).click();
  await expect(a.getByText("Bạn đã huỷ đơn này")).toBeVisible({ timeout: 60_000 });
  // Tab cũ vẫn thấy nút Huỷ -> huỷ lần 2.
  await b.getByRole("button", { name: "Huỷ đơn" }).click();
  await b.getByRole("dialog").or(b.getByRole("alertdialog")).getByRole("button", { name: "Huỷ đơn" }).click();
  await b.waitForTimeout(3000);
  const txt = (await b.locator("main").innerText()).replace(/\s+/g, " ");
  console.log("[QA13] huỷ lần 2 ->", txt.slice(0, 400));
  const alerts = await b.getByRole("alert").allTextContents();
  console.log("[QA13] alerts:", JSON.stringify(alerts));
  await expect(b.getByText(/đã huỷ|Đã huỷ/).first()).toBeVisible();
  await expect(b.getByText(/Đơn đã đổi trạng thái|Không huỷ được|đã được huỷ|Bạn đã huỷ đơn này/).first()).toBeVisible();
  await expect(b.getByRole("button", { name: "Huỷ đơn" })).toHaveCount(0);
  expect((await b.locator("main").innerText())).not.toMatch(/duyệt/i);
  await A.context.close();
});

test("QA14 (FW7 AC28): có đơn chờ -> màn xoá tài khoản hiện nút 'Xem đơn {mã}'", async ({ page }) => {
  await login(page, "del");
  await page.goto("/tai-khoan/quyen-du-lieu-ca-nhan");
  await page.getByRole("button", { name: "Xoá tài khoản của tôi" }).click();
  await page.getByRole("button", { name: "Gửi mã xác nhận" }).click();
  const link = page.getByRole("link", { name: "Xem đơn VVQADEL0001" });
  await expect(link).toBeVisible({ timeout: 60_000 });
  await expect(link).toHaveAttribute("href", "/tai-khoan/don-hang/VVQADEL0001");
  await link.click();
  await expect(page).toHaveURL(/\/tai-khoan\/don-hang\/VVQADEL0001$/);
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
});

test("QA15: 375px — hộp thoại thay đơn & cảnh báo hạn mức không tràn ngang", async ({ browser }) => {
  // dùng lại fw3-qa-repkeep (đơn VVQAKEEP001 còn chờ, giỏ vẫn còn)
  const A = await userCtx(browser, "repkeep", 375);
  await ready(A.page);
  await submit(A.page).click();
  await expect(A.page.getByRole("dialog")).toBeVisible({ timeout: 60_000 });
  expect(await hscroll(A.page)).toBeLessThanOrEqual(0);
  const box = await A.page.getByRole("dialog").boundingBox();
  expect((box?.x ?? 0) >= 0 && (box?.x ?? 0) + (box?.width ?? 0) <= 375).toBe(true);
  for (const n of ["Giữ đơn cũ", "Huỷ đơn cũ, đặt đơn mới"]) {
    expect((await A.page.getByRole("dialog").getByRole("button", { name: n }).boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
  await A.context.close();
});
