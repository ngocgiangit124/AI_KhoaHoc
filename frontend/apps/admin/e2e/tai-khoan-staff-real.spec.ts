import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA10 — e2e THẬT quản lý tài khoản staff (US-016; E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Chạy `frontend/apps/admin/e2e/seed-e2e-staff.sh --reset` TRƯỚC MỖI LẦN chạy (spec khóa/đổi vai trò/tạo tài khoản), `--clean` sau cùng.
 * 1 lần đăng nhập MFA (e2e-fa10-admin1) + 1 lần MFA quản lý trang cho 403; giáo viên đăng nhập thẳng. Chạy với `--workers=1 --retries=0 --trace=off`.
 * Phủ: menu + danh sách + lọc vai trò/trạng thái/tìm/phân trang trên URL, dòng của mình không có nút, tạo (kiểm sơ bộ, 422 email trùng
 * dưới ô + hộp tóm tắt, chặn bấm kép, mật khẩu hiện một lần không đóng bằng Esc), khóa/mở khóa, đặt lại mật khẩu (đăng nhập bằng mật khẩu mới
 * bị buộc đổi), đổi vai trò giáo viên (released_course_ids + liên kết khóa), server chặn tự khóa, lỗi 500/mạng, QLT/GV 403, 375px.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 90_000 });

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

async function loginTeacher(page: Page, who: string) {
  await fillLogin(page, who);
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
const BASE = `${ADMIN}/quan-tri/tai-khoan`;
const stamp = Date.now().toString(36);
const NEW_EMAIL = `e2e-fa10-new${stamp}@example.com`;
const NEW_NAME = `E2E FA10 Mới ${stamp}`;
const row = (page: Page, text: string) => page.getByRole("row").filter({ hasText: text });
const dialog = (page: Page, name: string | RegExp) => page.getByRole("dialog", { name });

let admin: Page;
let selfId = 0;

test.describe("FA10 tài khoản staff (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000); // lần đầu dev server biên dịch route (máy yếu)
    admin = await (await browser.newContext()).newPage();
    await loginMfa(admin, request, "fa10-admin1");
    await admin.goto(BASE);
    await expect(admin.getByRole("heading", { name: "Tài khoản staff", level: 1 })).toBeVisible();
    const me = await apiCall(admin, "GET", "/admin/staff?q=e2e-fa10-admin1&per_page=25");
    selfId = (me.body!.data as { id: number; is_self: boolean }[]).find((a) => a.is_self)!.id;
  });

  test("menu có 'Tài khoản staff'; danh sách hiện vai trò/trạng thái bằng chữ; dòng của mình không có nút nguy hiểm", async () => {
    await admin.goto(`${ADMIN}/quan-tri`);
    await nav(admin).getByRole("link", { name: "Tài khoản staff" }).click();
    await expect(admin).toHaveURL(BASE);
    await admin.getByRole("searchbox", { name: "Tìm theo tên hoặc email" }).fill("E2E FA10 Admin");
    await expect(admin).toHaveURL(/q=E2E\+FA10\+Admin|q=E2E%20FA10%20Admin/);
    const self = row(admin, "E2E FA10 Admin Một");
    await expect(self).toBeVisible();
    await expect(self).toContainText("(bạn)");
    await expect(self).toContainText("không thể tự khóa");
    await expect(self.getByRole("button")).toHaveCount(0);
    await admin.goto(`${BASE}?q=E2E+FA10+GV`);
    const gv = row(admin, "E2E FA10 GV Khóa");
    await expect(gv).toContainText("Giáo viên");
    await expect(gv).toContainText("Đang hoạt động");
    await expect(row(admin, "E2E FA10 GV Đã Khóa")).toContainText("Đã khóa");
  });

  test("lọc vai trò/trạng thái nằm trên URL, F5 giữ nguyên", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+`);
    await admin.getByLabel("Vai trò", { exact: true }).selectOption("giao_vien");
    await admin.getByLabel("Trạng thái", { exact: true }).selectOption("locked");
    await expect(admin).toHaveURL(/role=giao_vien/);
    await expect(admin).toHaveURL(/status=locked/);
    await expect(row(admin, "E2E FA10 GV Đã Khóa")).toBeVisible();
    await expect(row(admin, "E2E FA10 GV Khóa")).toHaveCount(0);
    await admin.reload();
    await expect(admin.getByLabel("Trạng thái", { exact: true })).toHaveValue("locked");
    await expect(row(admin, "E2E FA10 GV Đã Khóa")).toBeVisible();
    await admin.goto(`${BASE}?q=zzzkhongco`);
    await expect(admin.getByText("Không có tài khoản nào khớp bộ lọc")).toBeVisible();
  });

  test("phân trang 25/trang trên URL", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+Trang`);
    await expect(row(admin, "E2E FA10 Trang 26")).toBeVisible(); // mới tạo nhất đứng đầu
    await expect(admin.getByRole("row").filter({ hasText: "E2E FA10 Trang" })).toHaveCount(25);
    await admin.getByRole("link", { name: "Trang 2" }).click();
    await expect(admin).toHaveURL(/page=2/);
    await expect(admin.getByRole("row").filter({ hasText: "E2E FA10 Trang" })).toHaveCount(1);
    await expect(row(admin, "E2E FA10 Trang 01")).toBeVisible();
  });

  test("tạo: kiểm sơ bộ, email trùng → 422 dưới ô + tóm tắt, rồi tạo được; mật khẩu hiện một lần, Esc không đóng", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+`);
    await admin.getByRole("button", { name: "Tạo tài khoản" }).click();
    const dlg = dialog(admin, "Tạo tài khoản staff mới");
    await expect(dlg).toBeVisible();
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    await expect(dlg.getByText(/còn 3 chỗ cần sửa/)).toBeVisible();
    await expect(dlg.getByRole("option", { name: /Học sinh/ })).toHaveCount(0);

    await dlg.getByLabel(/Họ và tên/).fill(NEW_NAME);
    await dlg.getByLabel(/Email/).fill("e2e-fa10-qlt1@example.com"); // trùng email có sẵn
    await dlg.getByLabel(/Vai trò/).selectOption("giao_vien");
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    await expect(dlg.getByText("Email đã được sử dụng.", { exact: true })).toBeVisible();
    await expect(dlg.getByText(/còn 1 chỗ cần sửa/)).toBeVisible();
    await expect(dlg.getByLabel(/Họ và tên/)).toHaveValue(NEW_NAME);

    await dlg.getByLabel(/Email/).fill(NEW_EMAIL);
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).dblclick(); // chặn bấm kép
    const secret = admin.getByTestId("initial-password");
    await expect(secret).toBeVisible();
    expect((await secret.textContent())!.length).toBeGreaterThanOrEqual(12);
    await expect(admin.getByText(/chỉ hiển thị 1 lần/)).toBeVisible();
    await admin.keyboard.press("Escape");
    await expect(secret).toBeVisible();
    await admin.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }).click();
    await expect(secret).toHaveCount(0);
    await admin.getByRole("searchbox", { name: "Tìm theo tên hoặc email" }).fill(NEW_EMAIL);
    await expect(row(admin, NEW_EMAIL)).toHaveCount(1);
    await expect(row(admin, NEW_EMAIL)).toContainText("Giáo viên");
  });

  test("khóa rồi mở khóa; mở khóa tài khoản đã khóa sẵn", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+GV`);
    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Khóa tài khoản/ }).click();
    const dlg = dialog(admin, "Khóa tài khoản E2E FA10 GV Khóa?");
    await expect(dlg).toContainText("bị từ chối truy cập ngay");
    await dlg.getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(dlg).toHaveCount(0);
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Đã khóa");
    await expect(row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Mở khóa/ })).toBeVisible();

    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Mở khóa/ }).click();
    await dialog(admin, "Mở khóa tài khoản E2E FA10 GV Khóa?").getByRole("button", { name: "Mở khóa" }).click();
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Đang hoạt động");

    await row(admin, "E2E FA10 GV Đã Khóa").getByRole("button", { name: /Mở khóa/ }).click();
    await dialog(admin, "Mở khóa tài khoản E2E FA10 GV Đã Khóa?").getByRole("button", { name: "Mở khóa" }).click();
    await expect(row(admin, "E2E FA10 GV Đã Khóa")).toContainText("Đang hoạt động");
  });

  test("đặt lại mật khẩu: hiện mật khẩu mới một lần; đăng nhập bằng nó bị buộc đổi mật khẩu", async ({ browser }) => {
    await admin.goto(`${BASE}?q=${encodeURIComponent(NEW_EMAIL)}`);
    await row(admin, NEW_EMAIL).getByRole("button", { name: /Đặt lại mật khẩu/ }).click();
    const dlg = dialog(admin, `Đặt lại mật khẩu cho ${NEW_NAME}?`);
    await expect(dlg).toContainText("huỷ phiên đăng nhập");
    await dlg.getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    const secret = admin.getByTestId("initial-password");
    await expect(secret).toBeVisible();
    const password = (await secret.textContent())!.trim();
    await admin.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }).click();

    const other = await (await browser.newContext()).newPage();
    await other.goto(`${ADMIN}/dang-nhap`);
    await other.locator('input[name="login"]').fill(NEW_EMAIL);
    await other.locator('input[name="password"]').fill(password);
    await other.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(other).toHaveURL(/\/doi-mat-khau/, { timeout: 20_000 });
    await other.context().close();
  });

  test("đổi vai trò giáo viên: cảnh báo trước, sau đó 'N khóa không còn giáo viên' kèm liên kết khóa", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+GV+Đổi`);
    await row(admin, "E2E FA10 GV Đổi Vai Trò").getByRole("button", { name: /Đổi vai trò/ }).click();
    const dlg = dialog(admin, "Đổi vai trò của E2E FA10 GV Đổi Vai Trò");
    await dlg.getByLabel(/Vai trò mới/).selectOption("quan_ly_trang");
    await expect(dlg.getByText("Các khóa đang phụ trách sẽ bị gỡ")).toBeVisible();
    await dlg.getByRole("button", { name: "Đổi vai trò" }).click();
    await expect(dlg).toHaveCount(0);
    await expect(admin.getByText("2 khóa không còn giáo viên phụ trách, hãy gán lại")).toBeVisible();
    await expect(row(admin, "E2E FA10 GV Đổi Vai Trò")).toContainText("Quản lý trang");
    const link = admin.getByRole("link", { name: /^Khóa #\d+$/ }).first();
    await expect(link).toHaveAttribute("href", /\/quan-tri\/khoa-hoc\/\d+\/sua$/);
    await link.click();
    await expect(admin).toHaveURL(/\/quan-tri\/khoa-hoc\/\d+\/sua/);
  });

  test("server chặn tự khóa / tự đổi vai trò (409), trạng thái không đổi", async () => {
    const lock = await apiCall(admin, "POST", `/admin/staff/${selfId}/lock`);
    expect(lock.status).toBe(409);
    expect(lock.body!.code).toBe("CANNOT_MODIFY_SELF");
    const role = await apiCall(admin, "PATCH", `/admin/staff/${selfId}/role`, { role: "giao_vien" });
    expect(role.status).toBe(409);
    const me = await apiCall(admin, "GET", `/admin/staff/${selfId}`);
    expect(me.body).toMatchObject({ role: "admin", status: "active" });
  });

  test("lỗi tải 500 và mất mạng: thông báo rõ + Thử lại", async () => {
    await admin.route("**/api/v1/admin/staff?**", (r) => r.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ message: "Lỗi máy chủ." }) }));
    await admin.goto(`${BASE}?q=E2E+FA10+Admin`);
    await expect(admin.getByText("Không tải được danh sách tài khoản")).toBeVisible();
    await admin.unroute("**/api/v1/admin/staff?**");
    await admin.route("**/api/v1/admin/staff?**", (r) => r.abort());
    await admin.getByRole("button", { name: "Thử lại" }).click();
    await expect(admin.getByText(/Không thể kết nối tới máy chủ/)).toBeVisible();
    await admin.unroute("**/api/v1/admin/staff?**");
    await admin.getByRole("button", { name: "Thử lại" }).click();
    await expect(admin.getByText(/^Tổng \d+ tài khoản$/)).toBeVisible();
  });

  test("375px: không tràn ngang, nút thao tác và ô lọc cao ≥ 44px", async () => {
    await admin.setViewportSize({ width: 375, height: 800 });
    await admin.goto(`${BASE}?q=E2E+FA10+GV`);
    await expect(row(admin, "E2E FA10 GV Khóa")).toBeVisible();
    expect(await noOverflow(admin)).toBeLessThanOrEqual(0);
    for (const loc of [
      admin.getByRole("button", { name: "Tạo tài khoản" }),
      admin.getByLabel("Vai trò", { exact: true }),
      admin.getByLabel("Trạng thái", { exact: true }),
      row(admin, "E2E FA10 GV Khóa").getByRole("button").first(),
      row(admin, "E2E FA10 GV Khóa").getByRole("button").last(),
    ]) {
      expect((await loc.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    }
    await admin.getByRole("button", { name: "Tạo tài khoản" }).click();
    expect(await noOverflow(admin)).toBeLessThanOrEqual(0);
    await dialog(admin, "Tạo tài khoản staff mới").getByRole("button", { name: "Huỷ" }).click();
    await admin.setViewportSize({ width: 1280, height: 800 });
  });

  test("giáo viên: không thấy menu, vào thẳng ra 403, API 403", async ({ browser }) => {
    const gv = await (await browser.newContext()).newPage();
    await loginTeacher(gv, "fa10-gv-khoa");
    await expect(nav(gv).getByRole("link", { name: "Mã giảm giá" })).toHaveCount(0);
    await expect(nav(gv).getByText("Tài khoản staff")).toHaveCount(0);
    await gv.goto(BASE);
    await expect(gv.getByTestId("forbidden-view")).toBeVisible();
    await expect(gv.getByText("Bạn không có quyền truy cập trang này.")).toBeVisible();
    const res = await apiCall(gv, "GET", "/admin/staff");
    expect(res.status).toBe(403);
    await gv.context().close();
  });

  test("quản lý trang: không thấy menu, vào thẳng ra 403", async ({ browser, request }) => {
    const qlt = await (await browser.newContext()).newPage();
    await loginMfa(qlt, request, "fa10-qlt1");
    await expect(nav(qlt).getByText("Tài khoản staff")).toHaveCount(0);
    await qlt.goto(BASE);
    await expect(qlt.getByTestId("forbidden-view")).toBeVisible();
    expect((await apiCall(qlt, "GET", "/admin/staff")).status).toBe(403);
    await qlt.context().close();
  });
});
