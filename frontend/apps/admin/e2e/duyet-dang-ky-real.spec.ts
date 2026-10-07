import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA6 — e2e THẬT duyệt đăng ký khóa miễn phí (US-012; E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Chạy `frontend/apps/admin/e2e/seed-e2e-requests.sh --reset` TRƯỚC MỖI LẦN chạy (spec duyệt/từ chối), `--clean` sau cùng.
 * 1 lần đăng nhập MFA (e2e-fa6-qlt1); giáo viên đăng nhập thẳng. Chạy với `--workers=1 --retries=0`.
 * Phủ: danh sách mặc định chờ duyệt + cũ nhất trước + email che, lọc khóa trên URL, duyệt, từ chối kèm lý do, 422 lý do, 409 đã xử lý,
 * phân trang 25/trang trên URL (F5 giữ), giáo viên chỉ thấy khóa mình (+403 khi mở thẳng khóa khác), duyệt bởi giáo viên, 375px.
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
const hsRow = (page: Page, n: number) => page.getByRole("row", { name: new RegExp(`E2E FA6 HS ${n}(?!\\d)`) });
const BASE = `${ADMIN}/quan-tri/duyet-dang-ky`;
const ids = { a: 0, b: 0, many: 0 };
const enrollmentIds: Record<number, number> = {};

let staff: Page;
let teacher: Page;

async function courseId(page: Page, title: string): Promise<number> {
  const opt = page.getByLabel("Khóa học").locator("option", { hasText: title });
  await expect(opt).toHaveCount(1);
  return Number(await opt.getAttribute("value"));
}

test.describe("FA6 duyệt đăng ký (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000); // lần đầu dev server biên dịch route (máy yếu)
    staff = await (await browser.newContext()).newPage();
    await loginMfa(staff, request, "fa6-qlt1");
    await staff.goto(BASE);
    await expect(staff.getByRole("heading", { name: "Duyệt đăng ký khóa miễn phí" })).toBeVisible();
    ids.a = await courseId(staff, "E2E FA6 Khóa A");
    ids.b = await courseId(staff, "E2E FA6 Khóa B");
    ids.many = await courseId(staff, "E2E FA6 Khóa Nhiều");
    const list = await apiCall(staff, "GET", `/admin/enrollment-requests?course_id=${ids.a}&per_page=50`);
    for (const r of (list.body!.data as { id: number; student: { name: string } }[])) {
      const m = /HS (\d+)$/.exec(r.student.name);
      if (m) enrollmentIds[Number(m[1])] = r.id;
    }
  });

  test("danh sách mặc định là Chờ duyệt, lọc khóa ghi lên URL, cũ nhất trước, email đã che", async () => {
    await staff.getByLabel("Khóa học").selectOption(String(ids.a));
    await expect(staff).toHaveURL(new RegExp(`course_id=${ids.a}`));
    await expect(staff.getByRole("link", { name: /Chờ duyệt/ })).toHaveAttribute("aria-current", "page");
    await expect(hsRow(staff, 1)).toBeVisible();
    const names = await staff.getByRole("row").filter({ hasText: "E2E FA6 HS" }).allInnerTexts();
    expect(names.map((t) => /HS (\d+)/.exec(t)![1])).toEqual(["1", "2", "3", "4", "9"]);
    const text = await staff.locator("main").innerText();
    expect(text).not.toContain("e2e-fa6-hs1@example.com");
    expect(text).toMatch(/\w\*\*\*@example\.com/);
    await staff.reload();
    await expect(hsRow(staff, 1)).toBeVisible();
  });

  test("duyệt HS1: dòng biến mất khỏi Chờ duyệt, có ở Đã duyệt", async () => {
    await staff.goto(`${BASE}?course_id=${ids.a}`);
    await hsRow(staff, 1).getByRole("button", { name: /^Duyệt yêu cầu/ }).click();
    await expect(staff.getByText("Đã duyệt yêu cầu của E2E FA6 HS 1")).toBeVisible();
    await expect(hsRow(staff, 1)).toHaveCount(0);
    await staff.getByRole("link", { name: /Đã duyệt/ }).click();
    await expect(staff).toHaveURL(/status=active/);
    await expect(hsRow(staff, 1)).toBeVisible();
    await expect(hsRow(staff, 8)).toBeVisible();
  });

  test("từ chối HS2 kèm lý do; HTML trong lý do bị 422 ngay dưới ô và hộp thoại vẫn mở", async () => {
    await staff.goto(`${BASE}?course_id=${ids.a}`);
    await hsRow(staff, 2).getByRole("button", { name: /^Từ chối yêu cầu/ }).click();
    const dialog = staff.getByRole("dialog");
    await dialog.getByLabel(/Lý do/).fill("<b>x</b>");
    await dialog.getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(dialog.getByText(/HTML|thẻ|ký tự/i).first()).toBeVisible();
    await expect(dialog).toBeVisible();
    await dialog.getByLabel(/Lý do/).fill("Thiếu thông tin lớp học");
    await dialog.getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 2")).toBeVisible();
    await expect(hsRow(staff, 2)).toHaveCount(0);
    await staff.getByRole("link", { name: /Đã từ chối/ }).click();
    await expect(hsRow(staff, 2)).toContainText("Lý do: Thiếu thông tin lớp học");
    await expect(hsRow(staff, 7)).toContainText("Lý do: Chưa đủ điều kiện");
    await expect(hsRow(staff, 2).getByRole("button")).toHaveCount(0);
  });

  test("409: người khác đã xử lý HS3 trước → báo, dòng biến mất sau khi tải lại", async () => {
    await staff.goto(`${BASE}?course_id=${ids.a}`);
    await expect(hsRow(staff, 3)).toBeVisible();
    const other = await apiCall(staff, "POST", `/admin/enrollment-requests/${enrollmentIds[3]}/approve`);
    expect(other.status).toBe(200);
    await hsRow(staff, 3).getByRole("button", { name: /^Duyệt yêu cầu/ }).click();
    await expect(staff.getByText(/người khác xử lý/)).toBeVisible();
    await expect(hsRow(staff, 3)).toHaveCount(0);
  });

  test("phân trang 25/trang trên URL, F5 giữ trang", async () => {
    await staff.goto(`${BASE}?course_id=${ids.many}`);
    await expect(staff.getByRole("row").filter({ hasText: "E2E FA6 HS" })).toHaveCount(25);
    await staff.getByRole("navigation", { name: "Phân trang" }).getByRole("link", { name: /2/ }).first().click();
    await expect(staff).toHaveURL(/page=2/);
    await expect(staff.getByRole("row").filter({ hasText: "E2E FA6 HS" })).toHaveCount(2);
    await staff.reload();
    await expect(staff.getByRole("row").filter({ hasText: "E2E FA6 HS" })).toHaveCount(2);
  });

  test("giáo viên chỉ thấy khóa mình; mở thẳng khóa khác → không có quyền; giáo viên duyệt được", async ({ browser }) => {
    teacher = await (await browser.newContext()).newPage();
    await loginTeacher(teacher, "fa6-gv1");
    await expect(nav(teacher).getByRole("link", { name: "Duyệt đăng ký" })).toBeVisible();
    await teacher.goto(`${BASE}?per_page=50`);
    await expect(hsRow(teacher, 4)).toBeVisible();
    await expect(hsRow(teacher, 5)).toHaveCount(0);
    await expect(teacher.getByLabel("Khóa học").locator("option", { hasText: "E2E FA6 Khóa B" })).toHaveCount(0);
    await teacher.goto(`${BASE}?course_id=${ids.b}`);
    await expect(teacher.getByTestId("forbidden-view")).toBeVisible();
    await teacher.goto(`${BASE}?course_id=${ids.a}`);
    await hsRow(teacher, 4).getByRole("button", { name: /^Duyệt yêu cầu/ }).click();
    await expect(teacher.getByText("Đã duyệt yêu cầu của E2E FA6 HS 4")).toBeVisible();
    await expect(hsRow(teacher, 4)).toHaveCount(0);
    const other = await apiCall(staff, "GET", `/admin/enrollment-requests?course_id=${ids.b}`);
    const otherId = (other.body!.data as { id: number }[])[0]!.id;
    const direct = await apiCall(teacher, "POST", `/admin/enrollment-requests/${otherId}/approve`);
    expect(direct.status).toBe(403);
  });

  test("375px: không tràn ngang, nút thao tác cao ≥ 44px", async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 375, height: 800 } });
    const page = await ctx.newPage();
    await loginTeacher(page, "fa6-gv1");
    await page.goto(`${BASE}?course_id=${ids.a}`);
    await expect(hsRow(page, 9)).toBeVisible();
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    for (const b of await hsRow(page, 9).getByRole("button").all()) {
      expect((await b.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    }
    await ctx.close();
  });
});
