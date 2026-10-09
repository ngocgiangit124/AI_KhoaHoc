import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA12 (US-016) - e2e THẬT màn Nhật ký thao tác (E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Dữ liệu: `e2e/seed-e2e-fa12.sh --reset` TRƯỚC MỖI LẦN chạy, `--clean` sau cùng. Chạy `--workers=1 --retries=0 --trace=off`.
 * 2 lần đăng nhập MFA (e2e-fa12-admin1, e2e-fa12-qlt1) cách nhau >= 46 giây (throttle MFA).
 * Tự tạo log thật: khóa/mở khóa e2e-fa12-gv1 13 lần (26 dòng `user.lock`/`user.unlock`) rồi lọc theo người làm + đối tượng.
 * Phủ: menu -> route, mặc định 7 ngày, bảng (giờ VN, người làm + vai trò, nhãn hành động, đối tượng), lọc trên URL (người làm, loại + id
 * đối tượng, mã hành động nhập tay), Trang sau/Trang trước + F5, hộp chi tiết (key-value, Esc), chỉ đọc (PUT/DELETE bị từ chối, không có
 * nút sửa/xoá), khoảng ngày ngược, 375px không tràn ngang, quản lý trang bị 403.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 180_000 });

const email = (n: string) => `e2e-${n}@example.com`;
const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });
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
      { timeout: 45_000, intervals: [1000] },
    )
    .not.toBeNull();
  return code!;
}

async function loginMfa(page: Page, request: APIRequestContext, who: string) {
  const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(who)}` } });
  const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
  const code = await latestCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
}

/** Gọi API thật bằng phiên của trang (cookie + CSRF). */
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

const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const BASE = `${ADMIN}/quan-tri/nhat-ky`;
const rows = (page: Page) => page.getByRole("table", { name: "Nhật ký thao tác" }).locator("tbody tr");
const LOCK_PAIRS = 13;

let admin: Page;
let adminId = 0;
let gvId = 0;
const USER_TYPE = encodeURIComponent("App\\Models\\User");

test.describe("FA12 nhật ký thao tác (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000); // lần đầu dev server biên dịch route (máy yếu)
    admin = await (await browser.newContext()).newPage();
    await loginMfa(admin, request, "fa12-admin1");
    const list = await apiCall(admin, "GET", "/admin/staff?q=e2e-fa12&per_page=25");
    const data = (list.body?.data ?? []) as Array<{ id: number; email: string }>;
    adminId = data.find((a) => a.email === "e2e-fa12-admin1@example.com")?.id ?? 0;
    gvId = data.find((a) => a.email === "e2e-fa12-gv1@example.com")?.id ?? 0;
    expect(adminId).toBeGreaterThan(0);
    expect(gvId).toBeGreaterThan(0);
    // Tạo log thật: khóa / mở khóa giáo viên (throttle 30/phút -> 26 lời gọi).
    for (let i = 0; i < LOCK_PAIRS; i++) {
      expect((await apiCall(admin, "POST", `/admin/staff/${gvId}/lock`)).status).toBe(200);
      expect((await apiCall(admin, "POST", `/admin/staff/${gvId}/unlock`)).status).toBe(200);
    }
  });

  test("menu -> Nhật ký thao tác; mặc định 7 ngày; bảng có giờ VN, người làm, nhãn hành động; không có nút sửa/xoá", async () => {
    await admin.goto(`${ADMIN}/quan-tri`);
    await nav(admin).getByRole("link", { name: "Nhật ký thao tác" }).click();
    await expect(admin).toHaveURL(BASE, { timeout: 60_000 });
    await expect(admin.getByRole("heading", { level: 1, name: "Nhật ký thao tác" })).toBeVisible();
    await expect(admin.getByText("Chỉ đọc", { exact: true })).toBeVisible();
    const from = await admin.getByLabel("Từ ngày").inputValue();
    const to = await admin.getByLabel("Đến ngày").inputValue();
    expect((Date.parse(to) - Date.parse(from)) / 86_400_000).toBe(6);
    await expect(rows(admin)).toHaveCount(25, { timeout: 20_000 });
    await expect(rows(admin).first()).toContainText(/\d{2}\/\d{2}\/\d{4}\s*\d{2}:\d{2}:\d{2}/);
    await expect(rows(admin).first()).toContainText("E2E FA12 Admin");
    await expect(admin.getByRole("button", { name: /^(sửa|xoá|xóa)/i })).toHaveCount(0);
    await expect(admin.getByRole("link", { name: /^(sửa|xoá|xóa)/i })).toHaveCount(0);
  });

  test("lọc theo người làm + loại + mã đối tượng trên URL; phân trang Trang sau / Trang trước; F5 giữ nguyên", async () => {
    await admin.goto(BASE);
    await expect(admin.getByLabel("Người làm")).toHaveJSProperty("tagName", "SELECT", { timeout: 20_000 }); // danh sách staff đã tải
    await admin.getByLabel("Người làm").selectOption(String(adminId));
    await admin.getByLabel("Loại đối tượng").selectOption("App\\Models\\User");
    await admin.getByLabel("Mã số đối tượng").fill(String(gvId));
    await admin.getByRole("button", { name: "Lọc", exact: true }).click();
    await expect(admin).toHaveURL(new RegExp(`actor_id=${adminId}.*subject_id=${gvId}`));
    await expect(rows(admin)).toHaveCount(25, { timeout: 20_000 });
    await expect(rows(admin).first()).toContainText(/Mở khoá tài khoản|Khoá tài khoản/);
    const nav2 = admin.getByRole("navigation", { name: "Phân trang nhật ký" });
    await expect(nav2.getByRole("link", { name: /Trang trước/ })).toHaveCount(0);
    await nav2.getByRole("link", { name: /Trang sau/ }).click();
    await expect(admin).toHaveURL(/page=2/);
    await expect(rows(admin).first()).toContainText(/khoá tài khoản/i, { timeout: 20_000 });
    await expect(rows(admin)).toHaveCount(1); // 26 dòng: trang 2 còn đúng 1 dòng
    await expect(admin.getByRole("navigation", { name: "Phân trang nhật ký" }).getByRole("link", { name: /Trang sau/ })).toHaveCount(0); // không còn links.next
    await admin.reload();
    await expect(admin).toHaveURL(/page=2/);
    await expect(rows(admin).first()).toBeVisible({ timeout: 20_000 });
    await nav2.getByRole("link", { name: /Trang trước/ }).click();
    await expect(rows(admin)).toHaveCount(25, { timeout: 20_000 });
    // 50 dòng/trang: hết trong một trang
    await admin.getByLabel("Số dòng/trang").selectOption("50");
    await expect(admin).toHaveURL(/per_page=50/);
    await expect(rows(admin)).toHaveCount(26, { timeout: 20_000 });
  });

  test("lọc theo mã hành động nhập tay; hộp chi tiết hiện key-value và đóng bằng Esc", async () => {
    await admin.goto(`${BASE}?actor_id=${adminId}&subject_type=${USER_TYPE}&subject_id=${gvId}`);
    await expect(rows(admin).first()).toBeVisible({ timeout: 20_000 });
    await admin.getByLabel("Hoặc nhập mã hành động").fill("user.lock");
    await admin.getByRole("button", { name: "Lọc", exact: true }).click();
    await expect(admin).toHaveURL(/action=user\.lock/);
    await expect(rows(admin)).toHaveCount(LOCK_PAIRS, { timeout: 20_000 });
    for (const r of await rows(admin).all()) await expect(r).toContainText("Khoá tài khoản");
    await rows(admin).first().getByRole("button", { name: /Xem chi tiết/ }).click();
    const dlg = admin.getByRole("dialog");
    await expect(dlg).toBeVisible();
    await expect(dlg.getByText("E2E FA12 Admin")).toBeVisible();
    await expect(dlg.getByText("Tài khoản #" + gvId)).toBeVisible();
    const changes = dlg.getByTestId("audit-changes");
    await expect(changes).toContainText("status");
    await expect(changes).toContainText("locked");
    await admin.keyboard.press("Escape");
    await expect(dlg).toBeHidden();
    // Mã không có bản ghi: trạng thái rỗng do lọc + Xoá bộ lọc
    await admin.goto(`${BASE}?action=khong.ton.tai`);
    await expect(admin.getByText("Không có nhật ký khớp bộ lọc")).toBeVisible({ timeout: 20_000 });
    await admin.getByRole("link", { name: "Xoá bộ lọc" }).click();
    await expect(admin).toHaveURL(BASE);
  });

  test("khoảng ngày ngược báo lỗi, không đổi URL; chỉ đọc: PUT/DELETE bị từ chối; không lưu dữ liệu vào storage", async () => {
    await admin.goto(BASE);
    await expect(rows(admin).first()).toBeVisible({ timeout: 20_000 });
    await admin.getByLabel("Từ ngày").fill("2026-10-09");
    await admin.getByLabel("Đến ngày").fill("2026-10-01");
    await admin.getByRole("button", { name: "Lọc", exact: true }).click();
    await expect(admin.getByRole("alert").filter({ hasText: /trước hoặc bằng/ })).toBeVisible();
    await expect(admin).toHaveURL(BASE);
    for (const method of ["PUT", "PATCH", "DELETE"]) {
      const res = await apiCall(admin, method, "/admin/audit-logs/1");
      expect([404, 405]).toContain(res.status);
    }
    const stored = await admin.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
    expect(stored).not.toMatch(/audit|nhat-ky|user\.lock/i);
  });

  test("375px: không tràn ngang, nút Chi tiết >= 44px, vẫn mở được hộp chi tiết", async () => {
    await admin.setViewportSize({ width: 375, height: 800 });
    await admin.goto(BASE);
    await expect(rows(admin).first()).toBeVisible({ timeout: 20_000 });
    expect(await noOverflow(admin)).toBeLessThanOrEqual(0);
    const btn = rows(admin).first().getByRole("button", { name: /Xem chi tiết/ });
    expect((await btn.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    await btn.click();
    await expect(admin.getByRole("dialog")).toBeVisible();
    await admin.keyboard.press("Escape");
    await admin.setViewportSize({ width: 1280, height: 800 });
  });

  test("API trực tiếp: filter action khớp đúng, per_page lạ -> 422 (hiện lời server)", async () => {
    const ok = await apiCall(admin, "GET", `/admin/audit-logs?action=user.lock&actor_id=${adminId}&per_page=25`);
    expect(ok.status).toBe(200);
    const bad = await apiCall(admin, "GET", "/admin/audit-logs?per_page=7");
    expect(bad.status).toBe(422);
  });

  test("Quản lý trang: menu không có Nhật ký; mở thẳng route -> trang không có quyền; API 403", async ({ browser, request }) => {
    test.setTimeout(240_000);
    await admin.waitForTimeout(46_000); // throttle MFA giữa 2 lần đăng nhập
    const qlt = await (await browser.newContext()).newPage();
    await loginMfa(qlt, request, "fa12-qlt1");
    await expect(nav(qlt).getByText("Nhật ký thao tác")).toHaveCount(0);
    await qlt.goto(BASE);
    await expect(qlt.getByTestId("forbidden-view")).toBeVisible({ timeout: 30_000 });
    const res = await apiCall(qlt, "GET", "/admin/audit-logs");
    expect(res.status).toBe(403);
  });
});
