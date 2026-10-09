import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * QA FA12 + T33-1 - e2e THẬT. Dữ liệu: `seed-e2e-fa8.sh --reset` (admin1, qlt1, gv1 + đơn fa8). `--clean` sau cùng.
 * Chạy --workers=1. Đăng nhập MFA admin ở beforeAll, QLT ở test cuối (cách >= 46s nhờ các test giữa).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật");
test.describe.configure({ mode: "serial", timeout: 240_000 });

const email = (n: string) => `e2e-${n}@example.com`;
type Json = Record<string, unknown>;
const ymd = (d: Date) => d.toISOString().slice(0, 10);
const BASE = `${ADMIN}/quan-tri/nhat-ky`;
const rows = (p: Page) => p.getByRole("table", { name: "Nhật ký thao tác" }).locator("tbody tr");
const ORDER_TYPE = encodeURIComponent("App\\Models\\Order");

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
async function fillLogin(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}
async function loginMfa(page: Page, request: APIRequestContext, who: string) {
  const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(who)}` } });
  const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 30_000 });
  const code = await latestCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 30_000 });
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
      try { json = JSON.parse(text); } catch { /* rỗng */ }
      return { status: res.status, body: json as Json | null };
    },
    { api: API, method, path, body },
  );
}
async function codeOf(page: Page, name: string): Promise<string> {
  const from = ymd(new Date(Date.now() - 80 * 86_400_000));
  const to = ymd(new Date(Date.now() + 86_400_000));
  const res = await apiCall(page, "GET", `/admin/orders?from=${from}&to=${to}&q=${encodeURIComponent(name)}&per_page=25`);
  const data = (res.body?.data ?? []) as Array<{ code: string; student: { name: string } }>;
  const hit = data.find((o) => o.student.name === name);
  expect(hit, `không thấy đơn ${name}`).toBeTruthy();
  return hit!.code;
}

let admin: Page;
let adminId = 0;
let approved = "";
let cancelled = "";
let refunded = "";

test.describe("QA FA12 + T33-1 (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000);
    admin = await (await browser.newContext()).newPage();
    await loginMfa(admin, request, "fa8-admin1");
    const list = await apiCall(admin, "GET", "/admin/staff?q=e2e-fa8&per_page=25");
    adminId = ((list.body?.data ?? []) as Array<{ id: number; email: string }>).find((a) => a.email === "e2e-fa8-admin1@example.com")?.id ?? 0;
    expect(adminId).toBeGreaterThan(0);
    approved = await codeOf(admin, "Fa8 HS Hai");
    cancelled = await codeOf(admin, "Fa8 HS Bốn");
    refunded = await codeOf(admin, "Fa8 HS Mười hai");
    expect((await apiCall(admin, "POST", `/admin/orders/${approved}/approve`, { confirm: true })).status).toBe(200);
    expect((await apiCall(admin, "POST", `/admin/orders/${cancelled}/cancel`, { reason: "Không liên hệ được phụ huynh" })).status).toBe(200);
    expect((await apiCall(admin, "POST", `/admin/orders/${approved}/notes`, { body: "ghi chú QA" })).status).toBe(201);
    expect((await apiCall(admin, "GET", `/admin/orders/${approved}`)).status).toBe(200);
    expect((await apiCall(admin, "POST", `/admin/orders/${refunded}/refund`, { confirm: true })).status).toBe(200);
  });

  test("AC link: Duyệt đơn / Huỷ đơn có link tới /quan-tri/don-hang/{code} và mở đúng đơn", async () => {
    for (const [action, label, code] of [
      ["order.manual_approve", "Duyệt đơn", approved],
      ["order.manual_cancel", "Huỷ đơn", cancelled],
      ["order.refund", "Hoàn tiền", refunded],
      ["order.note_add", "Thêm ghi chú đơn", approved],
      ["order.view_pii", "Xem thông tin cá nhân đơn", approved],
    ] as const) {
      await admin.goto(`${BASE}?action=${action}&actor_id=${adminId}&subject_type=${ORDER_TYPE}`);
      const row = rows(admin).filter({ hasText: label }).filter({ has: admin.locator(`a[href="/quan-tri/don-hang/${code}"]`) }).first();
      await expect(row, `${action} -> ${code}`).toBeVisible({ timeout: 60_000 });
    }
    await admin.goto(`${BASE}?action=order.manual_approve&actor_id=${adminId}&subject_type=${ORDER_TYPE}`);
    await rows(admin).first().locator(`a[href="/quan-tri/don-hang/${approved}"]`).click();
    await expect(admin).toHaveURL(`${ADMIN}/quan-tri/don-hang/${approved}`, { timeout: 60_000 });
    await expect(admin.getByText(approved).first()).toBeVisible({ timeout: 30_000 });
  });

  test("bản ghi không có code (cũ / code lạ) -> không link, không lỗi; HTML trong changes hiện dạng chữ", async () => {
    let dialogs = 0;
    admin.on("dialog", (d) => { dialogs++; void d.dismiss(); });
    await admin.route("**/admin/audit-logs*", async (route) => {
      const res = await route.fetch();
      const json = (await res.json()) as { data: Array<Record<string, unknown>> };
      json.data = json.data.slice(0, 4).map((l, i) => ({
        ...l,
        action: "order.manual_approve",
        subject_type: "App\\Models\\Order",
        subject_id: 900 + i,
        changes: i === 0 ? { status: { from: "pending", to: "paid" } } : i === 1 ? { code: "../../evil?x=<b>" } : i === 2 ? { code: "javascript:alert(1)" } : { note: '<img src=x onerror="window.__xss=1"><script>window.__xss=1</script>', html: "<b>đậm</b>" },
      }));
      await route.fulfill({ response: res, json });
    });
    await admin.goto(`${BASE}?action=order.manual_approve`);
    await expect(rows(admin)).toHaveCount(4, { timeout: 60_000 });
    await expect(admin.locator('a[href*="/quan-tri/don-hang/"]')).toHaveCount(0);
    await expect(rows(admin).first()).toContainText("Đơn hàng #900");
    await rows(admin).nth(3).getByRole("button", { name: /Xem chi tiết/ }).click();
    const dlg = admin.getByRole("dialog");
    await expect(dlg).toBeVisible();
    await expect(dlg).toContainText('<img src=x onerror="window.__xss=1">');
    await expect(dlg).toContainText("<b>đậm</b>");
    expect(await dlg.locator("img, script, b").count()).toBe(0);
    expect(await admin.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
    expect(dialogs).toBe(0);
    await admin.unroute("**/admin/audit-logs*");
  });

  test("URL lạ không vỡ trang và không gửi tham số sai", async () => {
    const reqs: string[] = [];
    admin.on("request", (r) => (r.url().includes("/admin/audit-logs") ? reqs.push(r.url()) : 0));
    for (const q of ["from=2026-02-30", "page=abc", "per_page=7", `subject_type=${encodeURIComponent("<script>alert(1)</script>")}`, "page=0&subject_id=-5&actor_id=x&action=%3Cb%3E", "to=2026-13-01&from=zzz"]) {
      reqs.length = 0;
      await admin.goto(`${BASE}?${q}`);
      await expect(admin.getByRole("heading", { level: 1, name: "Nhật ký thao tác" })).toBeVisible({ timeout: 60_000 });
      await expect(admin.getByRole("table", { name: "Nhật ký thao tác" })).toBeVisible({ timeout: 30_000 });
      expect(reqs.length, q).toBeGreaterThan(0);
      for (const u of reqs) {
        const sp = new URL(u).searchParams;
        expect(sp.get("from") ?? "2026-01-01", q).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        if (sp.has("page")) expect(Number(sp.get("page")), q).toBeGreaterThanOrEqual(1);
        if (sp.has("per_page")) expect([25, 50, 100], q).toContain(Number(sp.get("per_page")));
        expect(sp.get("subject_type") ?? "App\\Models\\X", q).not.toContain("<");
        expect(sp.get("action") ?? "ok", q).not.toContain("<");
        if (sp.has("subject_id")) expect(Number(sp.get("subject_id")), q).toBeGreaterThan(0);
        if (sp.has("actor_id")) expect(Number(sp.get("actor_id")), q).toBeGreaterThan(0);
      }
      await expect(admin.locator('main [role="alert"]')).toHaveCount(0);
    }
    admin.removeAllListeners("request");
  });

  test("lọc người làm + đối tượng, sang trang 2 (nếu có), F5 giữ nguyên", async () => {
    await admin.goto(`${BASE}?actor_id=${adminId}&subject_type=${ORDER_TYPE}&per_page=25`);
    await expect(rows(admin).first()).toBeVisible({ timeout: 60_000 });
    const n = await rows(admin).count();
    expect(n).toBeGreaterThanOrEqual(5);
    for (let i = 0; i < n; i++) await expect(rows(admin).nth(i)).toContainText("E2E FA8 Admin");
    await admin.reload();
    await expect(rows(admin)).toHaveCount(n, { timeout: 30_000 });
    expect(admin.url()).toContain(`actor_id=${adminId}`);
    // Trang 2: không lọc người làm (cửa sổ 7 ngày của dev DB), 25 dòng/trang.
    await admin.goto(`${BASE}?per_page=25`);
    await expect(rows(admin)).toHaveCount(25, { timeout: 60_000 });
    const first1 = await rows(admin).first().innerText();
    await admin.getByRole("navigation", { name: "Phân trang nhật ký" }).getByRole("button", { name: "Trang sau" }).or(admin.getByRole("link", { name: "Trang sau" })).first().click();
    await expect(admin).toHaveURL(/page=2/);
    await expect(admin.getByText(/Trang 2/)).toBeVisible({ timeout: 30_000 });
    const first2 = await rows(admin).first().innerText();
    expect(first2).not.toBe(first1);
    await admin.reload();
    await expect(admin.getByText(/Trang 2/)).toBeVisible({ timeout: 30_000 });
    expect(await rows(admin).first().innerText()).toBe(first2);
    await admin.getByRole("navigation", { name: "Phân trang nhật ký" }).getByText("Trang trước").first().click();
    await expect(admin.getByText(/Trang 1/)).toBeVisible({ timeout: 30_000 });
  });

  test("429 và 422 hiển thị; Thử lại phục hồi", async () => {
    await admin.route("**/admin/audit-logs*", (r) => r.fulfill({ status: 429, headers: { "Retry-After": "30", "content-type": "application/json" }, body: JSON.stringify({ message: "Too Many" }) }));
    await admin.goto(BASE);
    await expect(admin.getByText(/Bạn thao tác quá nhanh/)).toBeVisible({ timeout: 60_000 });
    await admin.unroute("**/admin/audit-logs*");
    await admin.route("**/admin/audit-logs*", (r) => r.fulfill({ status: 422, headers: { "content-type": "application/json" }, body: JSON.stringify({ code: "VALIDATION_ERROR", message: "x", errors: { page: ["Trang không hợp lệ."] } }) }));
    await admin.reload();
    await expect(admin.getByText("Trang không hợp lệ.")).toBeVisible({ timeout: 30_000 });
    await admin.unroute("**/admin/audit-logs*");
    await admin.getByRole("button", { name: "Thử lại" }).click();
    await expect(rows(admin).first()).toBeVisible({ timeout: 30_000 });
  });

  test("backend: page=0/abc/10001 -> 422, 10000 -> 200 qua phiên admin", async () => {
    for (const p of ["0", "abc", "10001"]) expect((await apiCall(admin, "GET", `/admin/audit-logs?page=${p}`)).status, p).toBe(422);
    expect((await apiCall(admin, "GET", "/admin/audit-logs?page=10000")).status).toBe(200);
  });

  test("375px không tràn ngang; 1280px; bàn phím trong hộp chi tiết", async () => {
    await admin.setViewportSize({ width: 375, height: 800 });
    await admin.goto(`${BASE}?action=order.manual_approve&actor_id=${adminId}`);
    await expect(rows(admin).first()).toBeVisible({ timeout: 60_000 });
    expect(await admin.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    await admin.setViewportSize({ width: 1280, height: 800 });
    expect(await admin.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    await expect(admin.getByRole("columnheader", { name: "Đối tượng" })).toBeVisible();
    const btn = rows(admin).first().getByRole("button", { name: /Xem chi tiết/ });
    await btn.focus();
    await admin.keyboard.press("Enter");
    const dlg = admin.getByRole("dialog");
    await expect(dlg).toBeVisible();
    await admin.keyboard.press("Escape");
    await expect(dlg).toBeHidden();
    // mở lại, Tab/Shift+Tab: focus không rơi ra phần trang phía sau
    await btn.focus();
    await admin.keyboard.press("Enter");
    await expect(dlg).toBeVisible();
    for (let i = 0; i < 12; i++) {
      await admin.keyboard.press("Tab");
      expect(await admin.evaluate(() => (!!document.activeElement?.closest("dialog") || document.activeElement === document.body))).toBe(true);
    }
    for (let i = 0; i < 12; i++) {
      await admin.keyboard.press("Shift+Tab");
      expect(await admin.evaluate(() => (!!document.activeElement?.closest("dialog") || document.activeElement === document.body))).toBe(true);
    }
    await admin.keyboard.press("Escape");
    await expect(dlg).toBeHidden();
  });

  // BUG-1 (Minor, a11y): AuditLogScreen gỡ <AuditDetailDialog> khỏi DOM khi đóng nên trình duyệt không trả focus về nút "Chi tiết".
  // Đã sửa (Sửa BUG-1): AuditLogScreen trả focus về nút mở khi đóng.
  test("BUG-1: đóng hộp chi tiết (Esc) trả focus về nút mở", async () => {
    await admin.goto(`${BASE}?action=order.manual_approve&actor_id=${adminId}`);
    const btn = rows(admin).first().getByRole("button", { name: /Xem chi tiết/ });
    await btn.focus();
    await admin.keyboard.press("Enter");
    await expect(admin.getByRole("dialog")).toBeVisible();
    await admin.keyboard.press("Escape");
    await expect(admin.getByRole("dialog")).toBeHidden();
    await expect(btn).toBeFocused({ timeout: 3000 });
  });

  test("giáo viên và quản lý trang mở thẳng route -> không quyền, KHÔNG có request audit-logs / staff", async ({ browser, request }) => {
    const gv = await (await browser.newContext()).newPage();
    const seen: string[] = [];
    gv.on("request", (r) => (/\/admin\/(audit-logs|staff)/.test(r.url()) ? seen.push(r.url()) : 0));
    await fillLogin(gv, "fa8-gv1");
    await expect(gv).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 30_000 });
    await expect(gv.getByRole("navigation", { name: "Menu quản trị" }).getByRole("link", { name: "Nhật ký thao tác" })).toHaveCount(0);
    await gv.goto(BASE);
    await expect(gv.getByTestId("forbidden-view")).toBeVisible({ timeout: 30_000 });
    await gv.waitForTimeout(1500);
    expect(seen).toEqual([]);
    expect((await apiCall(gv, "GET", "/admin/audit-logs")).status).toBe(403);

    const qlt = await (await browser.newContext()).newPage();
    const seen2: string[] = [];
    qlt.on("request", (r) => (/\/admin\/(audit-logs|staff)/.test(r.url()) ? seen2.push(r.url()) : 0));
    await loginMfa(qlt, request, "fa8-qlt1");
    await expect(qlt.getByRole("navigation", { name: "Menu quản trị" }).getByRole("link", { name: "Nhật ký thao tác" })).toHaveCount(0);
    await qlt.goto(`${BASE}?action=order.refund`);
    await expect(qlt.getByTestId("forbidden-view")).toBeVisible({ timeout: 30_000 });
    await qlt.waitForTimeout(1500);
    expect(seen2).toEqual([]);
    expect((await apiCall(qlt, "GET", "/admin/audit-logs")).status).toBe(403);
  });
});
