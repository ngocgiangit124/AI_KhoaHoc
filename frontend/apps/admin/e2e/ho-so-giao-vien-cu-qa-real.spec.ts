import { existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { expect as baseExpect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA FA11-1 — e2e THẬT bổ sung cho ho-so-giao-vien-cu-real.spec.ts. Chạy (máy host):
 *   e2e/seed-e2e-legacy-profile.sh --reset && e2e/seed-qa-fa11-1.sh && e2e/run-real-uploads.sh e2e/ho-so-giao-vien-cu-qa-real.spec.ts --workers=1 --retries=0
 *   e2e/seed-qa-fa11-1.sh --clean && e2e/seed-e2e-legacy-profile.sh --clean
 * Phiên MFA đăng nhập MỘT lần/người rồi lưu storageState (OTP 1/phút). Miền tĩnh STATIC_URL (localhost:8080) được giả lập bằng `context.route`
 * từ thư mục uploads thật (UPLOADS_DIR), file mất thì 404 (như Nginx).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const PUBLIC = "http://api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const UPLOADS = process.env.UPLOADS_DIR ?? "";
const PASSWORD = "Password123!";
const expect = baseExpect.configure({ timeout: 45_000 });
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !process.env.UPLOADS_DIR, "Cần backend thật + UPLOADS_DIR (run-real-uploads.sh)");
test.describe.configure({ mode: "serial", timeout: 600_000 });

const email = (n: string) => `e2e-${n}@example.com`;
const state = (who: string) => `${tmpdir()}/fa111-${who}.json`;
const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });
/** Ở 375px menu nằm trong ngăn kéo: bấm "Mở menu" (lặp lại tới khi hộp thoại mở, phòng bấm trước khi hydrate). */
async function openNavIfMobile(page: Page) {
  const burger = page.getByRole("button", { name: "Mở menu" });
  const hasBurger = await burger.waitFor({ state: "visible", timeout: 15_000 }).then(() => true, () => false);
  if (!hasBurger) return; // màn rộng: sidebar luôn hiện
  await expect(async () => {
    if (!(await page.getByRole("dialog").isVisible())) await burger.click();
    await expect(page.getByRole("dialog")).toBeVisible({ timeout: 3_000 });
  }).toPass({ timeout: 60_000 });
}
const legacyEntry = (page: Page) => nav(page).getByRole("link", { name: "Hồ sơ giáo viên cũ" });
type Json = Record<string, unknown>;

async function fillLogin(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}
async function latestCode(request: APIRequestContext, to: string, known: Set<string>): Promise<string> {
  let code: string | null = null;
  await expect
    .poll(
      async () => {
        const list = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${to}` } });
        const messages = ((await list.json()) as { messages: { ID: string; Subject: string }[] }).messages;
        const fresh = messages.find((m) => !known.has(m.ID) && /xác thực|mã/i.test(m.Subject));
        if (!fresh) return null;
        const detail = (await (await request.get(`${MAILPIT}/api/v1/message/${fresh.ID}`)).json()) as { Text: string };
        code = /\b(\d{6})\b/.exec(detail.Text)?.[1] ?? null;
        return code;
      },
      { timeout: 60_000, intervals: [1000] },
    )
    .not.toBeNull();
  return code!;
}
async function loginMfa(ctx: BrowserContext, request: APIRequestContext, who: string): Promise<Page> {
  const page = await ctx.newPage();
  const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(who)}` } });
  const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 90_000 });
  const code = await latestCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 90_000 });
  return page;
}
async function emulateStatic(ctx: BrowserContext) {
  await ctx.route(/^http:\/\/localhost:8080\//, async (route) => {
    const name = new URL(route.request().url()).pathname.replace(/^\//, "");
    if (!/^[\w.-]+$/.test(name) || !existsSync(`${UPLOADS}/${name}`)) return route.fulfill({ status: 404, body: "not found", headers: { "access-control-allow-origin": "*" } });
    return route.fulfill({ status: 200, path: `${UPLOADS}/${name}`, contentType: "image/webp", headers: { "access-control-allow-origin": "*" } });
  });
}
const fileOf = (url: string) => `${UPLOADS}/${new URL(url).pathname.replace(/^\//, "")}`;
/** 200 nếu ảnh tải được trong trình duyệt (qua miền tĩnh giả lập), 404 nếu không (CSP chặn fetch nên dùng thẻ img). */
async function staticStatus(page: Page, url: string): Promise<number> {
  return page.evaluate(
    (u) =>
      new Promise<number>((resolve) => {
        const img = new Image();
        img.onload = () => resolve(200);
        img.onerror = () => resolve(404);
        img.src = `${u}?qa=${Date.now()}${Math.random()}`;
      }),
    url,
  );
}
async function apiCall(page: Page, method: string, path: string, body?: unknown) {
  return page.evaluate(
    async ({ api, method, path, body }) => {
      const base = { credentials: "include" as const, headers: { Accept: "application/json", "X-Device-Id": localStorage.getItem("vv_device_id") ?? "" } as Record<string, string> };
      const { token } = (await (await fetch(`${api}/csrf-token`, base)).json()) as { token: string };
      const headers = { ...base.headers, "X-CSRF-TOKEN": token, ...(body ? { "Content-Type": "application/json" } : {}) };
      const res = await fetch(`${api}${path}`, { ...base, method, headers, body: body ? JSON.stringify(body) : undefined });
      const text = await res.text();
      let json: unknown = null;
      try {
        json = JSON.parse(text);
      } catch {
        /* thân rỗng */
      }
      return { status: res.status, body: json as Json | null };
    },
    { api: API, method, path, body },
  );
}
async function openLegacy(browser: Browser, request: APIRequestContext, who: string, viewport?: { width: number; height: number }) {
  await ensureState(browser, request, who);
  const ctx = await browser.newContext({ storageState: state(who), ...(viewport ? { viewport } : {}) });
  await emulateStatic(ctx);
  const page = await ctx.newPage();
  await page.goto(`${ADMIN}/quan-tri`);
  await openNavIfMobile(page);
  await expect(legacyEntry(page)).toBeVisible({ timeout: 90_000 });
  await legacyEntry(page).click();
  await expect(page.getByRole("heading", { name: "Hồ sơ giáo viên cũ", level: 1 })).toBeVisible();
  return { ctx, page };
}
const confirmIn = (page: Page, name: string) => page.getByRole("dialog").getByRole("button", { name });
const publicTeacherIds = async (request: APIRequestContext) =>
  ((await (await request.get(`${PUBLIC}/home/teachers`, { headers: { Accept: "application/json" } })).json()) as { data: { id: number }[] }).data.map((t) => t.id);

/** Đăng nhập MFA một lần/người (lazy) rồi lưu storageState; gặp giới hạn tốc độ đăng nhập (429) thì chờ 70 giây và thử lại. */
const ready = new Set<string>();
async function ensureState(browser: Browser, request: APIRequestContext, who: string) {
  if (ready.has(who)) return;
  for (let attempt = 0; ; attempt++) {
    const ctx = await browser.newContext();
    try {
      await loginMfa(ctx, request, who);
      await ctx.storageState({ path: state(who) });
      ready.add(who);
      return;
    } catch (e) {
      if (attempt >= 3) throw e;
    } finally {
      await ctx.close();
    }
    await new Promise((r) => setTimeout(r, 70_000));
  }
}

test.describe("FA11-1 QA (thật)", () => {
  test("chỉ còn ảnh: chỉ có nút Xoá ảnh; xoá xong file mất, URL cũ 404, menu ẩn", async ({ browser, request }) => {
    const { ctx, page } = await openLegacy(browser, request, "fa11b-qa-av");
    await expect(page.getByRole("button", { name: "Xoá ảnh" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Rút đồng ý" })).toHaveCount(0);
    await expect(page.getByTestId("legacy-consent-off")).toBeVisible();
    const me = await apiCall(page, "GET", "/admin/me/teacher-profile");
    const url = me.body!.avatar_url as string;
    expect(url).toBeTruthy();
    expect(existsSync(fileOf(url))).toBe(true);
    expect(await staticStatus(page, url)).toBe(200);
    await expect(page.getByRole("img", { name: /Ảnh hồ sơ/ })).toBeVisible();

    await page.getByRole("button", { name: "Xoá ảnh" }).click();
    await confirmIn(page, "Xoá ảnh").click();
    await expect(page.getByTestId("legacy-empty")).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);
    expect(existsSync(fileOf(url))).toBe(false);
    expect(await staticStatus(page, url)).toBe(404);
    const after = await apiCall(page, "GET", "/admin/me/teacher-profile");
    expect(after.status).toBe(200);
    expect(after.body!.avatar_url).toBeNull();
    await ctx.close();
  });

  test("chỉ còn đồng ý: chỉ có nút Rút đồng ý; rút xong hết dữ liệu", async ({ browser, request }) => {
    const { ctx, page } = await openLegacy(browser, request, "fa11b-qa-co");
    await expect(page.getByRole("button", { name: "Rút đồng ý" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Xoá ảnh" })).toHaveCount(0);
    await expect(page.getByText("Chưa có ảnh", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    await confirmIn(page, "Rút đồng ý").click();
    await expect(page.getByTestId("legacy-empty")).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);
    await ctx.close();
  });

  test("375px: không cuộn ngang, nút >= 44px; bàn phím trong ConfirmDialog (focus, Tab, Esc không gọi API, Enter xác nhận)", async ({ browser, request }) => {
    const { ctx, page } = await openLegacy(browser, request, "fa11b-qa-fl", { width: 375, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    for (const name of ["Rút đồng ý", "Xoá ảnh"]) {
      const box = await page.getByRole("button", { name }).boundingBox();
      expect(box!.height, `chiều cao nút ${name}`).toBeGreaterThanOrEqual(44);
    }

    // Mở bằng bàn phím, focus vào trong hộp thoại, Tab xoay vòng trong hộp thoại, Esc đóng không gọi API, trả focus.
    const trigger = page.getByRole("button", { name: "Rút đồng ý" });
    await trigger.focus();
    await page.keyboard.press("Enter");
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    expect(await page.evaluate(() => !!document.activeElement?.closest('dialog'))).toBe(true);
    for (let i = 0; i < 4; i++) {
      await page.keyboard.press("Tab");
      // <dialog> modal: phần còn lại của trang inert, Tab chỉ đi trong hộp thoại (hoặc ra giao diện trình duyệt = body), không bao giờ tới nút phía sau.
      expect(await page.evaluate(() => document.activeElement === document.body || !!document.activeElement?.closest('dialog')), `Tab lần ${i + 1} lọt ra nội dung phía sau`).toBe(true);
    }
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    expect(await page.evaluate(() => document.activeElement?.textContent?.trim())).toBe("Rút đồng ý");
    expect((await apiCall(page, "GET", "/admin/me/teacher-profile")).body!.consent).toMatchObject({ given: true });

    // Xác nhận bằng bàn phím: Tab tới nút xác nhận rồi Enter.
    await page.keyboard.press("Enter");
    await expect(dialog).toBeVisible();
    const confirm = confirmIn(page, "Rút đồng ý");
    await confirm.focus();
    await page.keyboard.press("Enter");
    await expect(page.getByTestId("legacy-consent-off")).toBeVisible();
    // Đã rút: vẫn còn ảnh → lối vào còn trong ngăn kéo menu.
    await openNavIfMobile(page);
    await expect(legacyEntry(page)).toBeVisible();
    await ctx.close();
  });

  test("đổi vai trò giữa phiên: phiên cũ bị thu hồi; đăng nhập lại thấy lối vào; rút đồng ý thì hết công khai, URL ảnh cũ 404", async ({ browser, request }) => {
    // Giáo viên công khai (không MFA).
    const tctx = await browser.newContext();
    await emulateStatic(tctx);
    const tpage = await tctx.newPage();
    await fillLogin(tpage, "fa11b-qa-pub");
    await expect(tpage).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 90_000 });
    await expect(nav(tpage).getByRole("link", { name: "Hồ sơ của tôi" })).toBeVisible();
    await expect(legacyEntry(tpage)).toHaveCount(0);
    const mine = await apiCall(tpage, "GET", "/admin/me/teacher-profile");
    const id = (mine.body!.user as { id: number }).id;
    expect(mine.status).toBe(200);
    await expect.poll(() => publicTeacherIds(request), { timeout: 90_000, intervals: [3000] }).toContain(id);

    // Admin đổi vai trò (storageState).
    await ensureState(browser, request, "fa11b-qa-adm");
    const actx = await browser.newContext({ storageState: state("fa11b-qa-adm") });
    const apage = await actx.newPage();
    await apage.goto(`${ADMIN}/quan-tri`);
    const changed = await apiCall(apage, "PATCH", `/admin/staff/${id}/role`, { role: "quan_ly_trang" });
    expect(changed.status, JSON.stringify(changed.body)).toBe(200);

    // Phiên cũ của người này: ghi nhận hành vi (bị thu hồi → về đăng nhập).
    await tpage.reload();
    await expect(tpage).toHaveURL(/\/dang-nhap/, { timeout: 90_000 });
    await tctx.close();

    // Hết là giáo viên: biến khỏi công khai.
    await expect.poll(() => publicTeacherIds(request), { timeout: 90_000, intervals: [3000] }).not.toContain(id);

    // Đăng nhập lại với vai trò mới (QLT, MFA): thấy lối vào, còn ảnh + đồng ý.
    const nctx = await browser.newContext();
    await emulateStatic(nctx);
    const npage = await loginMfa(nctx, request, "fa11b-qa-pub");
    await expect(legacyEntry(npage)).toBeVisible();
    await expect(nav(npage).getByRole("link", { name: "Hồ sơ của tôi" })).toHaveCount(0);
    const before = await apiCall(npage, "GET", "/admin/me/teacher-profile");
    expect(before.status).toBe(200);
    const oldUrl = before.body!.avatar_url as string;
    expect(existsSync(fileOf(oldUrl))).toBe(true);
    expect((before.body!.homepage_status as { reasons: string[] }).reasons).toContain("not_teacher");

    await legacyEntry(npage).click();
    await npage.getByRole("button", { name: "Rút đồng ý" }).click();
    await confirmIn(npage, "Rút đồng ý").click();
    await expect(npage.getByTestId("legacy-consent-off")).toBeVisible();

    // Công khai: không còn họ; URL ảnh cũ không mở được (file cũ bị thay bằng bản sao UUID mới, bản sao cũng không lộ).
    expect(await publicTeacherIds(request)).not.toContain(id);
    expect(existsSync(fileOf(oldUrl))).toBe(false);
    expect(await staticStatus(npage, oldUrl)).toBe(404);
    const after = await apiCall(npage, "GET", "/admin/me/teacher-profile");
    expect((after.body!.consent as { given: boolean }).given).toBe(false);
    const pubText = await (await request.get(`${PUBLIC}/home/teachers`)).text();
    expect(pubText).not.toContain("E2E FA11B QA GV Cong Khai");
    const courses = (await (await request.get(`${PUBLIC}/courses?per_page=100`)).json()) as { data: { slug: string; title: string }[] };
    const course = courses.data.find((c) => c.title === "E2E FA11B QA Khoa");
    if (course) {
      const detail = await (await request.get(`${PUBLIC}/courses/${course.slug}`)).text();
      expect(detail).not.toContain("E2E FA11B QA GV Cong Khai");
    }
    await nctx.close();
    await actx.close();
  });
});
