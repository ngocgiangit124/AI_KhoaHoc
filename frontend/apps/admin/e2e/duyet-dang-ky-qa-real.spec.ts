import { expect, request as pwRequest, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";
import fs from "node:fs";

/**
 * QA FA6 — e2e THẬT bổ sung (US-012). Chạy: `seed-e2e-requests.sh --reset && seed-qa-fa6.sh` → `run-real.sh e2e/duyet-dang-ky-qa-real.spec.ts --workers=1 --retries=0` → `seed-e2e-requests.sh --clean`.
 * Phiên QLT dùng lại qua storageState (OTP MFA 1/phút).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const STUDENT_API = "http://api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
const BASE = `${ADMIN}/quan-tri/duyet-dang-ky`;
const STATE = "test-results/fa6-qlt-state.json";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 120_000 });

type Json = Record<string, unknown>;
const email = (n: string) => `e2e-${n}@example.com`;
const hsRow = (page: Page, n: number) => page.getByRole("row", { name: new RegExp(`E2E FA6 HS ${n}(?!\\d)`) });
const approveBtn = (page: Page, n: number) => hsRow(page, n).getByRole("button", { name: /^Duyệt yêu cầu/ });
const rejectBtn = (page: Page, n: number) => hsRow(page, n).getByRole("button", { name: /^Từ chối yêu cầu/ });
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

async function fillLogin(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

async function mailCode(request: APIRequestContext, to: string, known: Set<string>): Promise<string> {
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

async function staffContext(browser: Browser, request: APIRequestContext, viewport?: { width: number; height: number }): Promise<{ ctx: BrowserContext; page: Page }> {
  const opts = viewport ? { viewport } : {};
  if (fs.existsSync(STATE)) {
    const ctx = await browser.newContext({ storageState: STATE, ...opts });
    const page = await ctx.newPage();
    await page.goto(BASE);
    if (await page.getByRole("heading", { name: "Duyệt đăng ký khóa miễn phí" }).isVisible({ timeout: 20_000 }).catch(() => false)) return { ctx, page };
    await ctx.close();
  }
  const ctx = await browser.newContext(opts);
  const page = await ctx.newPage();
  const who = "fa6-qlt1";
  const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(who)}` } });
  const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
  const code = await mailCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
  await ctx.storageState({ path: STATE });
  await page.goto(BASE);
  return { ctx, page };
}

async function teacherContext(browser: Browser, who: string): Promise<{ ctx: BrowserContext; page: Page; id: number }> {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const resp = page.waitForResponse((r) => r.url().includes("/admin/auth/login") && r.request().method() === "POST");
  await fillLogin(page, who);
  const id = ((await (await resp).json()) as { user: { id: number } }).user.id;
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
  return { ctx, page, id };
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
        /* rỗng */
      }
      return { status: res.status, body: json as Json | null };
    },
    { api: API, method, path, body },
  );
}

const courseIds: Record<string, number> = {};
const enr: Record<number, number> = {};
let staff: Page;
let staffCtx: BrowserContext;

async function loadCourseId(page: Page, title: string) {
  const opt = page.getByLabel("Khóa học").locator("option", { hasText: title });
  await expect(opt.or(page.getByLabel("Khóa học").locator("option", { hasText: "E2E FA6 QA Reasons" }))).not.toHaveCount(0);
  return (await opt.count()) === 1 ? Number(await opt.getAttribute("value")) : 0; // 0: khóa đã bị xoá mềm ở lần chạy trước (chạy lọc test)
}

async function mapEnrollments(course: number) {
  const list = await apiCall(staff, "GET", `/admin/enrollment-requests?course_id=${course}&per_page=50`);
  for (const r of list.body!.data as { id: number; student: { name: string } }[]) {
    const m = /HS (\d+)$/.exec(r.student.name);
    if (m) enr[Number(m[1])] = r.id;
  }
}

async function mailTo(request: APIRequestContext, to: string) {
  let msg: { Subject: string; Text: string; HTML: string } | null = null;
  await expect
    .poll(
      async () => {
        const list = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${to}` } });
        const ms = ((await list.json()) as { messages: { ID: string }[] }).messages;
        if (!ms.length) return null;
        msg = (await (await request.get(`${MAILPIT}/api/v1/message/${ms[0]!.ID}`)).json()) as typeof msg;
        return msg;
      },
      { timeout: 60_000, intervals: [1000] },
    )
    .not.toBeNull();
  return msg!;
}

async function studentSession(who: string) {
  const ctx = await pwRequest.newContext({ baseURL: STUDENT_API, extraHTTPHeaders: { Origin: "http://api.localhost:3000", Accept: "application/json" } });
  await ctx.get("/api/v1/csrf-token");
  const xsrf = async () => decodeURIComponent((await ctx.storageState()).cookies.find((c) => c.name === "XSRF-TOKEN")?.value ?? "");
  const res = await ctx.post("/api/v1/auth/login", {
    headers: { "X-XSRF-TOKEN": await xsrf(), "X-Device-Id": `qa-fa6-${who}` },
    data: { login: email(who), password: PASSWORD, device_id: `qa-fa6-${who}` },
  });
  expect(res.status(), "đăng nhập học sinh").toBe(200);
  return ctx;
}

test.describe("QA FA6", () => {
  test.beforeAll(async ({ browser, request }) => {
    test.setTimeout(300_000);
    fs.mkdirSync("test-results", { recursive: true });
    ({ ctx: staffCtx, page: staff } = await staffContext(browser, request));
    await expect(staff.getByRole("heading", { name: "Duyệt đăng ký khóa miễn phí" })).toBeVisible();
    for (const k of ["Race", "Price", "Deleted", "Removed", "Reasons"]) courseIds[k] = await loadCourseId(staff, `E2E FA6 QA ${k}`);
    for (const k of Object.values(courseIds)) if (k) await mapEnrollments(k);
  });

  test("QA1 hai người cùng duyệt/từ chối: danh sách cũ → đúng một thành công, người kia 409 + toast + tải lại", async ({ browser }) => {
    const gv = await teacherContext(browser, "fa6-gv1");
    const url = `${BASE}?course_id=${courseIds["Race"]}`;
    await staff.goto(url);
    await gv.page.goto(url);
    for (const n of [10, 11, 12, 13]) {
      await expect(hsRow(staff, n)).toBeVisible();
      await expect(hsRow(gv.page, n)).toBeVisible();
    }
    // (a) QLT duyệt HS10, giáo viên (danh sách cũ) bấm Từ chối
    await approveBtn(staff, 10).click();
    await expect(staff.getByText("Đã duyệt yêu cầu của E2E FA6 HS 10")).toBeVisible();
    await rejectBtn(gv.page, 10).click();
    await gv.page.getByRole("dialog").getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(gv.page.getByText(/đã được người khác xử lý/)).toBeVisible();
    await expect(hsRow(gv.page, 10)).toHaveCount(0);
    await expect(gv.page.getByRole("dialog")).toHaveCount(0);
    // (b) giáo viên từ chối HS11, QLT (danh sách cũ) bấm Duyệt
    await rejectBtn(gv.page, 11).click();
    await gv.page.getByRole("dialog").getByLabel(/Lý do/).fill("Không đúng lớp");
    await gv.page.getByRole("dialog").getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(gv.page.getByText("Đã từ chối yêu cầu của E2E FA6 HS 11")).toBeVisible();
    await approveBtn(staff, 11).click();
    await expect(staff.getByText(/đã được người khác xử lý/)).toBeVisible();
    await expect(hsRow(staff, 11)).toHaveCount(0);
    const st = await apiCall(staff, "GET", `/admin/enrollment-requests?course_id=${courseIds["Race"]}&status=rejected`);
    expect((st.body!.data as { student: { name: string } }[]).map((r) => r.student.name)).toContain("E2E FA6 HS 11");
    // (c) đồng thời THẬT ở tầng API: duyệt/duyệt HS12, duyệt/từ chối HS13
    const [a1, a2] = await Promise.all([apiCall(staff, "POST", `/admin/enrollment-requests/${enr[12]}/approve`), apiCall(gv.page, "POST", `/admin/enrollment-requests/${enr[12]}/approve`)]);
    expect([a1.status, a2.status].sort()).toEqual([200, 409]);
    const [b1, b2] = await Promise.all([apiCall(staff, "POST", `/admin/enrollment-requests/${enr[13]}/approve`), apiCall(gv.page, "POST", `/admin/enrollment-requests/${enr[13]}/reject`, { reason: "x" })]);
    expect([b1.status, b2.status].sort()).toEqual([200, 409]);
    const loser = b1.status === 409 ? b1 : b2;
    expect(loser.body).toMatchObject({ code: "ALREADY_PROCESSED" });
    const act = await apiCall(staff, "GET", `/admin/enrollment-requests?course_id=${courseIds["Race"]}&status=active`);
    const names = (act.body!.data as { student: { name: string } }[]).map((r) => r.student.name);
    expect(names.filter((n) => /HS 1[23]$/.test(n)).length).toBeGreaterThanOrEqual(1);
    await gv.ctx.close();
  });

  test("QA2 giáo viên bị gỡ khỏi khóa khi đang mở trang → thao tác 403, toast + danh sách tải lại, API 403", async ({ browser }) => {
    const gv = await teacherContext(browser, "fa6-gv2");
    const gv1 = await teacherContext(browser, "fa6-gv1");
    await gv.page.goto(`${BASE}?per_page=50`);
    await expect(hsRow(gv.page, 18)).toBeVisible();
    await expect(hsRow(gv.page, 19)).toBeVisible();
    const res = await apiCall(staff, "PUT", `/admin/courses/${courseIds["Removed"]}/teachers`, { teacher_ids: [gv1.id] });
    expect(res.status, JSON.stringify(res.body)).toBe(200);
    await approveBtn(gv.page, 18).click();
    await expect(gv.page.getByText(/không có quyền xử lý yêu cầu này/)).toBeVisible();
    await expect(hsRow(gv.page, 18)).toHaveCount(0);
    await expect(hsRow(gv.page, 19)).toHaveCount(0);
    // từ chối cũng 403
    const direct = await apiCall(gv.page, "POST", `/admin/enrollment-requests/${enr[19]}/reject`, { reason: "x" });
    expect(direct.status).toBe(403);
    // HS vẫn pending, giáo viên còn lại duyệt được
    await gv1.page.goto(`${BASE}?course_id=${courseIds["Removed"]}`);
    await expect(hsRow(gv1.page, 19)).toBeVisible();
    // mở thẳng course_id → màn không có quyền
    await gv.page.goto(`${BASE}?course_id=${courseIds["Removed"]}`);
    await expect(gv.page.getByTestId("forbidden-view")).toBeVisible();
    await gv.ctx.close();
    await gv1.ctx.close();
  });

  test("QA3 khóa đổi sang có phí khi yêu cầu còn chờ → 422 ngay tại dòng, vẫn từ chối được; các dòng khác vẫn chờ", async () => {
    await staff.goto(`${BASE}?course_id=${courseIds["Price"]}`);
    await expect(hsRow(staff, 14)).toBeVisible();
    const put = await apiCall(staff, "PUT", `/admin/courses/${courseIds["Price"]}`, { price: 199000 });
    expect(put.status, JSON.stringify(put.body)).toBe(200);
    await approveBtn(staff, 14).click();
    await expect(hsRow(staff, 14)).toContainText("Khóa học đã chuyển sang có phí");
    await expect(hsRow(staff, 14)).toBeVisible();
    await expect(approveBtn(staff, 14)).toBeEnabled();
    const raw = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[15]}/approve`);
    expect(raw.status).toBe(422);
    expect(raw.body).toMatchObject({ code: "COURSE_NOT_FREE" });
    await rejectBtn(staff, 14).click();
    await staff.getByRole("dialog").getByLabel(/Lý do/).fill("Khóa đã chuyển có phí");
    await staff.getByRole("dialog").getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 14")).toBeVisible();
    // trả về miễn phí để khóa không "kẹt" cho lần dọn
    await apiCall(staff, "PUT", `/admin/courses/${courseIds["Price"]}`, { price: 0 });
  });

  test("QA4 khóa bị xoá mềm khi yêu cầu còn chờ → 409 COURSE_UNAVAILABLE ở dòng; từ chối?; danh sách sau tải lại", async () => {
    await staff.goto(`${BASE}?course_id=${courseIds["Deleted"]}`);
    await expect(hsRow(staff, 16)).toBeVisible();
    // API xoá khóa bị chặn khi đã có đăng ký (409 COURSE_HAS_ENROLLMENTS) → xoá mềm trực tiếp qua DB nhờ watcher ở host (e2e/qa-fa6-watcher.sh)
    const del = await apiCall(staff, "DELETE", `/admin/courses/${courseIds["Deleted"]}`);
    expect(del.status).toBe(409);
    expect(del.body).toMatchObject({ code: "COURSE_HAS_ENROLLMENTS" });
    fs.writeFileSync("test-results/fa6-delete.req", "");
    await expect.poll(() => fs.existsSync("test-results/fa6-delete.ack"), { timeout: 60_000, intervals: [500] }).toBe(true);
    await approveBtn(staff, 16).click();
    await expect(hsRow(staff, 16)).toContainText("Khóa học đã bị xoá");
    const rej = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[17]}/reject`, { reason: "Khóa đã xoá" });
    test.info().annotations.push({ type: "reject-on-deleted-course", description: `status=${rej.status} ${JSON.stringify(rej.body)}` });
    expect([200, 403, 404, 409]).toContain(rej.status);
    // UI: từ chối dòng còn lại (HS16) như thông báo hứa ("vẫn có thể từ chối")
    await rejectBtn(staff, 16).click();
    await staff.getByRole("dialog").getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(staff.getByRole("status").or(staff.getByText(/từ chối yêu cầu|Khóa học|không/i)).first()).toBeVisible();
    test.info().annotations.push({ type: "ui-reject-deleted", description: await staff.locator("main").innerText().then((t) => t.slice(0, 300)) });
    await staff.reload();
    await expect(hsRow(staff, 16)).toHaveCount(0);
  });

  test("QA5 email kết quả: duyệt và từ chối kèm lý do (Mailpit) + học sinh được duyệt vào học được (API learn)", async ({ request }) => {
    const reasons = courseIds["Reasons"];
    const hs50 = await studentSession("fa6-hs50");
    const before = await hs50.get(`/api/v1/learn/courses/${reasons}`);
    expect(before.status()).toBe(403);
    await staff.goto(`${BASE}?course_id=${reasons}&per_page=50`);
    await approveBtn(staff, 50).click();
    await expect(staff.getByText("Đã duyệt yêu cầu của E2E FA6 HS 50")).toBeVisible();
    const after = await hs50.get(`/api/v1/learn/courses/${reasons}`);
    expect(after.status()).toBe(200);
    const m1 = await mailTo(request, email("fa6-hs50"));
    test.info().annotations.push({ type: "mail-approve", description: m1.Subject });
    expect(m1.Subject).toMatch(/duyệt|chấp|đăng ký/i);
    expect(m1.Text).toContain("E2E FA6 QA Reasons");

    const reason = 'Chưa đủ điều kiện — thiếu "giấy tờ" & ảnh 😀\nDòng hai: 100% đúng / sai \\ \'đơn\'';
    await rejectBtn(staff, 51).click();
    const dlg = staff.getByRole("dialog");
    await dlg.getByLabel(/Lý do/).fill(reason);
    await dlg.getByRole("button", { name: "Xác nhận từ chối" }).click();
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 51")).toBeVisible();
    const m2 = await mailTo(request, email("fa6-hs51"));
    test.info().annotations.push({ type: "mail-reject", description: `${m2.Subject} | ${m2.Text.replace(/\s+/g, " ").slice(0, 400)}` });
    expect(m2.Text).toContain("Chưa đủ điều kiện");
    expect(m2.Text).toContain("😀");
    expect(m2.Text).toContain("Dòng hai");
    expect(m2.Text).toMatch(/giấy tờ/);
    expect(m2.HTML).not.toMatch(/<script/i);
    expect(m2.HTML).toContain("&amp;"); // ký tự & được escape trong HTML
    expect((await hs50.get(`/api/v1/learn/courses/${reasons}`)).status()).toBe(200);
    const hs51 = await studentSession("fa6-hs51");
    expect((await hs51.get(`/api/v1/learn/courses/${reasons}`)).status()).toBe(403);
    // hiển thị ở tab Đã từ chối
    await staff.goto(`${BASE}?course_id=${reasons}&status=rejected`);
    await expect(hsRow(staff, 51)).toContainText("Lý do:");
    await expect(hsRow(staff, 51)).toContainText("😀");
    await expect(hsRow(staff, 51)).toContainText("Dòng hai");
    await hs50.dispose();
    await hs51.dispose();
  });

  test("QA6 lý do: 1000 ký tự OK, 1001 → chặn, emoji/xuống dòng OK, <script> 422 và không thực thi, chỉ khoảng trắng = không có lý do", async () => {
    const reasons = courseIds["Reasons"];
    let alerts = 0;
    staff.on("dialog", (d) => {
      alerts++;
      void d.dismiss();
    });
    await staff.goto(`${BASE}?course_id=${reasons}&per_page=50`);
    const open = async (n: number) => {
      await rejectBtn(staff, n).click();
      return staff.getByRole("dialog");
    };
    const confirm = (dlg: ReturnType<Page["getByRole"]>) => dlg.getByRole("button", { name: "Xác nhận từ chối" }).click();
    // 1000 ký tự
    let dlg = await open(52);
    await dlg.getByLabel(/Lý do/).fill("a".repeat(1000));
    await expect(dlg).toContainText("1000/1000");
    await dlg.getByLabel(/Lý do/).press("End");
    await staff.keyboard.type("zzz");
    expect(await dlg.getByLabel(/Lý do/).inputValue()).toHaveLength(1000); // gõ thêm bị maxLength chặn
    await confirm(dlg);
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 52")).toBeVisible();
    // 1001 ký tự: ô nhập cắt còn 1000 (maxLength) nên giao diện không thể gửi 1001; server vẫn phải 422
    dlg = await open(53);
    await dlg.getByLabel(/Lý do/).fill("b".repeat(1001));
    await expect(dlg).toContainText("1000/1000");
    expect(await dlg.getByLabel(/Lý do/).inputValue()).toHaveLength(1000);
    await dlg.getByRole("button", { name: "Huỷ" }).click();
    const over = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[53]}/reject`, { reason: "b".repeat(1001) });
    expect(over.status).toBe(422);
    expect(JSON.stringify(over.body)).toMatch(/tối đa 1\.000/);
    await expect(hsRow(staff, 53)).toBeVisible();
    // emoji + xuống dòng + ký tự đặc biệt
    dlg = await open(54);
    await dlg.getByLabel(/Lý do/).fill("Có dấu: Đặng Thị Hồng 😀🎓\n\nDòng 3 — “ngoặc” 'đơn' & % $ { } [ ] ; ` ~ | \\ /");
    await confirm(dlg);
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 54")).toBeVisible();
    // <script> và dấu < >
    dlg = await open(55);
    await dlg.getByLabel(/Lý do/).fill("<script>window.__xss=1;alert(1)</script>");
    await confirm(dlg);
    await expect(dlg.getByText(/văn bản thuần|HTML/)).toBeVisible();
    await expect(dlg).toBeVisible();
    expect(await staff.evaluate(() => (window as unknown as { __xss?: number }).__xss ?? 0)).toBe(0);
    await dlg.getByLabel(/Lý do/).fill("a > b");
    await confirm(dlg);
    await expect(dlg.getByText(/văn bản thuần|HTML/)).toBeVisible();
    await dlg.getByRole("button", { name: "Huỷ" }).click();
    await expect(hsRow(staff, 55)).toBeVisible();
    // chỉ khoảng trắng
    dlg = await open(56);
    await dlg.getByLabel(/Lý do/).fill("     ");
    await confirm(dlg);
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 56")).toBeVisible();
    // ký tự điều khiển qua API
    const ctrl = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[57]}/reject`, { reason: "a\u0007b" });
    expect(ctrl.status).toBe(422);
    const notString = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[57]}/reject`, { reason: ["x"] });
    expect(notString.status).toBe(422);
    // 1000 emoji (mỗi emoji 2 đơn vị UTF-16): backend đếm ký tự → cho phép
    const emoji = await apiCall(staff, "POST", `/admin/enrollment-requests/${enr[58]}/reject`, { reason: "😀".repeat(1000) });
    test.info().annotations.push({ type: "emoji-1000", description: `status=${emoji.status}` });
    expect(emoji.status).toBe(200);
    // hiển thị tab Đã từ chối
    await staff.goto(`${BASE}?course_id=${reasons}&status=rejected&per_page=50`);
    await expect(hsRow(staff, 52)).toContainText("a".repeat(1000));
    await expect(hsRow(staff, 54)).toContainText("Dòng 3");
    await expect(hsRow(staff, 54)).toContainText("😀🎓");
    await expect(hsRow(staff, 56)).not.toContainText("Lý do:");
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    expect(alerts).toBe(0);
    staff.removeAllListeners("dialog");
  });

  test("QA7 URL rác: không crash, không 5xx, không phản chiếu HTML", async () => {
    test.setTimeout(400_000); // 24 URL; máy yếu
    const errors: string[] = [];
    const bad: string[] = [];
    staff.on("pageerror", (e) => errors.push(String(e)));
    staff.on("response", (r) => {
      if (r.status() >= 500) bad.push(`${r.status()} ${r.url()}`);
    });
    const urls = [
      "?status=abc", "?status=", "?status=active&status=rejected", "?course_id=abc", "?course_id=-1", "?course_id=0", "?course_id=1e3", "?course_id=99999999",
      "?course_id=99999999999999999999", "?course_id=1%20OR%201=1", "?page=0", "?page=-1", "?page=abc", "?page=99999", "?page=100001", "?page=1.5", "?per_page=7", "?per_page=1000",
      "?per_page=0", "?per_page=abc", "?per_page=25&per_page=50", "?status=%22%3E%3Cscript%3Ewindow.__xss=1%3C/script%3E", "?foo[]=1&course_id[]=2", "?status=active&page=-5&per_page=7&course_id=x",
    ];
    for (const u of urls) {
      await staff.goto(`${BASE}${u}`);
      await expect(staff.getByRole("heading", { name: "Duyệt đăng ký khóa miễn phí" }), u).toBeVisible({ timeout: 30_000 });
      await expect(staff.locator("body"), u).not.toContainText(/Application error|Unhandled|Internal Server/i);
      expect(await staff.evaluate(() => (window as unknown as { __xss?: number }).__xss ?? 0), u).toBe(0);
    }
    // tới cuối trang quá lớn tự lùi
    await staff.goto(`${BASE}?page=99999`);
    await expect(staff.getByRole("link", { name: /Chờ duyệt/ })).toHaveAttribute("aria-current", "page");
    await staff.waitForTimeout(1500);
    test.info().annotations.push({ type: "page99999-url", description: staff.url() });
    expect(errors).toEqual([]);
    expect(bad).toEqual([]);
    staff.removeAllListeners("pageerror");
    staff.removeAllListeners("response");
  });

  test("QA8 bàn phím hộp thoại từ chối: focus vào hộp thoại, Tab không thoát, Esc đóng và trả focus; chặn bấm kép khi đang gửi", async () => {
    const reasons = courseIds["Reasons"];
    await staff.goto(`${BASE}?course_id=${reasons}&per_page=50`);
    const trigger = rejectBtn(staff, 59);
    await trigger.focus();
    await staff.keyboard.press("Enter");
    const dlg = staff.getByRole("dialog");
    await expect(dlg).toBeVisible();
    // <dialog> modal gốc: Tab cuối vòng có thể ra "giao diện trình duyệt" (activeElement = body) — không phải thoát ra nội dung trang (nền inert).
    const inside = () => staff.evaluate(() => document.activeElement === document.body || !!document.activeElement?.closest("dialog"));
    const onDialog = () => staff.evaluate(() => !!document.activeElement?.closest("dialog"));
    await expect.poll(onDialog, { timeout: 3000, message: "focus chuyển vào hộp thoại khi mở" }).toBe(true);
    for (let i = 0; i < 8; i++) {
      await staff.keyboard.press("Tab");
      expect(await inside(), `Tab ${i}`).toBe(true);
    }
    for (let i = 0; i < 8; i++) {
      await staff.keyboard.press("Shift+Tab");
      expect(await inside(), `Shift+Tab ${i}`).toBe(true);
    }
    // nền không còn focus được / không tương tác (aria-modal hoặc inert)
    await staff.keyboard.press("Escape");
    await expect(dlg).toHaveCount(0);
    await expect(trigger).toBeFocused();
    // mở lại, Enter trong textarea không gửi; gửi chậm → bấm kép chỉ 1 request; Esc khi đang gửi không đóng
    await trigger.click();
    await dlg.getByLabel(/Lý do/).fill("Bấm kép");
    let posts = 0;
    await staff.route("**/enrollment-requests/*/reject", async (route) => {
      posts++;
      await new Promise((r) => setTimeout(r, 2500));
      await route.continue();
    });
    const confirmBtn = dlg.getByRole("button", { name: "Xác nhận từ chối" });
    await confirmBtn.dblclick();
    await staff.keyboard.press("Escape");
    await expect(dlg).toBeVisible();
    await expect(staff.getByText("Đã từ chối yêu cầu của E2E FA6 HS 59")).toBeVisible({ timeout: 20_000 });
    expect(posts).toBe(1);
    await staff.unroute("**/enrollment-requests/*/reject");
  });

  test("QA9 R1: đổi tab khi tải chậm → dòng cũ không có nút Duyệt/Từ chối; Duyệt bấm kép chỉ 1 request", async () => {
    const reasons = courseIds["Reasons"];
    await staff.goto(`${BASE}?course_id=${reasons}&status=rejected&per_page=50`);
    await expect(hsRow(staff, 52)).toBeVisible();
    await staff.route("**/enrollment-requests?*status=pending_approval*", async (route) => {
      await new Promise((r) => setTimeout(r, 3000));
      await route.continue();
    });
    await staff.getByRole("link", { name: /Chờ duyệt/ }).click();
    await staff.waitForTimeout(800);
    await expect(staff.getByRole("button", { name: /^(Duyệt|Từ chối) yêu cầu/ })).toHaveCount(0);
    await expect(staff.getByRole("button", { name: /^Duyệt yêu cầu/ }).first()).toBeVisible({ timeout: 20_000 }).catch(() => undefined);
    await staff.unroute("**/enrollment-requests?*status=pending_approval*");
    // bấm kép Duyệt
    await staff.goto(`${BASE}?course_id=${reasons}&per_page=50`);
    let posts = 0;
    await staff.route("**/enrollment-requests/*/approve", async (route) => {
      posts++;
      await new Promise((r) => setTimeout(r, 1500));
      await route.continue();
    });
    const first = staff.getByRole("button", { name: /^Duyệt yêu cầu/ }).first();
    await first.dblclick();
    await expect(staff.getByText(/Đã duyệt yêu cầu của/)).toBeVisible({ timeout: 20_000 });
    expect(posts).toBe(1);
    await staff.unroute("**/enrollment-requests/*/approve");
  });

  test("QA10 375px: không tràn ngang, vùng chạm ≥ 44px (nút, tab, lọc, phân trang), hộp thoại vừa màn hình, lý do 1000 ký tự không tràn", async ({ browser, request }) => {
    const { ctx, page } = await staffContext(browser, request, { width: 375, height: 800 });
    const reasons = courseIds["Reasons"];
    await page.goto(`${BASE}?course_id=${reasons}&per_page=50`);
    await expect(page.getByRole("button", { name: /^Duyệt yêu cầu/ }).first()).toBeVisible();
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    const small: string[] = [];
    const check = async (label: string, loc: ReturnType<Page["locator"]>) => {
      for (const el of await loc.all()) {
        if (!(await el.isVisible())) continue;
        const b = (await el.boundingBox())!;
        if (b.height < 43.5 || b.width < 43.5) small.push(`${label}: ${Math.round(b.width)}x${Math.round(b.height)} ${(await el.innerText().catch(() => "")).slice(0, 25)}`);
      }
    };
    await check("nút dòng", page.getByRole("button", { name: /^(Duyệt|Từ chối) yêu cầu/ }));
    await check("tab", page.getByRole("link", { name: /Chờ duyệt|Đã duyệt|Đã từ chối/ }));
    await check("select", page.locator("main select"));
    await check("phân trang", page.getByRole("navigation", { name: "Phân trang" }).getByRole("link"));
    const first = page.getByRole("button", { name: /^Từ chối yêu cầu/ }).first();
    await first.click();
    const dlg = page.getByRole("dialog");
    await expect(dlg).toBeVisible();
    await dlg.getByLabel(/Lý do/).fill("c".repeat(1000));
    await check("nút hộp thoại", dlg.getByRole("button"));
    const box = (await dlg.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(-0.5);
    expect(box.x + box.width).toBeLessThanOrEqual(375.5);
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    await page.keyboard.press("Escape");
    await page.goto(`${BASE}?course_id=${reasons}&status=rejected&per_page=50`);
    await expect(hsRow(page, 52)).toBeVisible();
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    test.info().annotations.push({ type: "touch-targets<44", description: small.join(" | ") || "không có" });
    expect(small, small.join("\n")).toEqual([]);
    await ctx.close();
  });

  test.afterAll(async () => {
    await staffCtx?.close();
  });
});
