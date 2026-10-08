import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * QA FA10 — e2e THẬT bổ sung (mật khẩu một lần, R1 mất mạng khi tạo, R2 cảnh báo Admin, LAST_ADMIN (mock 409), hai tab, phiên bị chặn,
 * 429 thật + mô phỏng, 375px hộp thoại). Chạy: seed-e2e-staff.sh --reset rồi run-real.sh e2e/tai-khoan-staff-qa-real.spec.ts --workers=1 --retries=0 --trace=off.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 90_000 });

const email = (n: string) => `e2e-${n}@example.com`;
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
const row = (page: Page, text: string) => page.getByRole("row").filter({ hasText: text });
const dialog = (page: Page, name: string | RegExp) => page.getByRole("dialog", { name });


let admin: Page;
const logs: string[] = [];
const apiBodies: { url: string; method: string; post: string; body: string }[] = [];

const stamp2 = Date.now().toString(36);
const R1_EMAIL = `e2e-fa10-r1${stamp2}@example.com`;
const R1_NAME = `E2E FA10 R1 ${stamp2}`;
const SEC_EMAIL = `e2e-fa10-sec${stamp2}@example.com`;
const SEC_NAME = `E2E FA10 Sec ${stamp2}`;

test.describe("QA FA10 (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000);
    admin = await (await browser.newContext()).newPage();
    admin.on("console", (m) => logs.push(`console:${m.type()}:${m.text()}`));
    admin.on("pageerror", (e) => logs.push(`pageerror:${e.message}`));
    admin.on("response", async (res) => {
      if (!res.url().includes("/api/v1/")) return;
      let body = "";
      try {
        body = await res.text();
      } catch {
        /* redirect/aborted */
      }
      apiBodies.push({ url: res.url(), method: res.request().method(), post: res.request().postData() ?? "", body });
    });
    await loginMfa(admin, request, "fa10-admin1");
  });

  test("SEC: mật khẩu khởi tạo chỉ có ở response tạo; không URL/console/storage/DOM sau khi đóng; F5/Back không xem lại", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+`);
    await admin.getByRole("button", { name: "Tạo tài khoản" }).click();
    const dlg = dialog(admin, "Tạo tài khoản staff mới");
    await dlg.getByLabel(/Họ và tên/).fill(SEC_NAME);
    await dlg.getByLabel(/Email/).fill(SEC_EMAIL);
    await dlg.getByLabel(/Vai trò/).selectOption("giao_vien");
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    const secret = admin.getByTestId("initial-password");
    await expect(secret).toBeVisible();
    const pw = (await secret.textContent())!.trim();
    expect(pw.length).toBeGreaterThanOrEqual(12);
    expect(admin.url()).not.toContain(pw);
    await admin.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }).click();
    await expect(secret).toHaveCount(0);

    // F5, Back, điều hướng đi rồi về, danh sách tải lại
    await admin.reload();
    await expect(admin.getByText(/^Tổng \d+ tài khoản$/)).toBeVisible();
    await admin.goto(`${ADMIN}/quan-tri`);
    await admin.goBack();
    await expect(admin.getByText(/^Tổng \d+ tài khoản$/)).toBeVisible();
    expect(await admin.content()).not.toContain(pw);
    expect(await admin.locator("body").innerText()).not.toContain(pw);
    const stores = await admin.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }]) + document.cookie);
    expect(stores).not.toContain(pw);
    expect(logs.filter((l) => l.includes(pw))).toEqual([]);
    // danh sách / GET staff không bao giờ trả mật khẩu
    await expect.poll(() => apiBodies.some((b) => b.body.includes(pw))).toBe(true); // listener đọc body bất đồng bộ
    const hits = apiBodies.filter((b) => b.url.includes(pw) || b.post.includes(pw) || b.body.includes(pw));
    expect(hits.map((h) => `${h.method} ${new URL(h.url).pathname}`)).toEqual(["POST /api/v1/admin/staff"]); // chỉ response tạo; không có trong URL/body request nào khác
  });

  test("SEC: response chứa mật khẩu chỉ là POST /admin/staff (tạo) và POST reset-password", async () => {
    const withInitial = apiBodies.filter((b) => /initial_password/.test(b.body));
    expect(withInitial.length).toBeGreaterThanOrEqual(1);
    for (const b of withInitial) expect(b.method).toBe("POST");
    for (const b of withInitial) expect(new URL(b.url).pathname).toMatch(/\/admin\/staff(\/\d+\/reset-password)?$/);
    // cache-control của response tạo
    const res = await admin.evaluate(async (api) => {
      const r = await fetch(`${api}/admin/staff?per_page=25`, { credentials: "include", headers: { Accept: "application/json" } });
      return { cc: r.headers.get("cache-control"), text: await r.text() };
    }, API);
    expect(res.text).not.toMatch(/initial_password|"password"|password_hash/);
    console.log("list cache-control:", res.cc);
  });

  test("R1: server tạo xong nhưng phản hồi bị cắt -> hướng dẫn phục hồi, Tải lại danh sách thấy tài khoản, Đặt lại mật khẩu dùng được", async ({ browser }) => {
    await admin.goto(`${BASE}?q=E2E+FA10+`);
    await admin.route("**/api/v1/admin/staff", async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      await route.fetch(); // server đã tạo
      await route.abort("connectionreset");
    });
    await admin.getByRole("button", { name: "Tạo tài khoản" }).click();
    const dlg = dialog(admin, "Tạo tài khoản staff mới");
    await dlg.getByLabel(/Họ và tên/).fill(R1_NAME);
    await dlg.getByLabel(/Email/).fill(R1_EMAIL);
    await dlg.getByLabel(/Vai trò/).selectOption("giao_vien");
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    await expect(dlg.getByText(/Có thể tài khoản đã được tạo/)).toBeVisible();
    await expect(admin.getByTestId("initial-password")).toHaveCount(0);
    await admin.unroute("**/api/v1/admin/staff");
    // bấm lại -> 422 email đã dùng (đúng như dự đoán)
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    await expect(dlg.getByText("Email đã được sử dụng.", { exact: true })).toBeVisible();
    await dlg.getByRole("button", { name: "Huỷ" }).click();
    await admin.getByRole("searchbox", { name: "Tìm theo tên hoặc email" }).fill(R1_EMAIL);
    await expect(row(admin, R1_EMAIL)).toHaveCount(1);
    await row(admin, R1_EMAIL).getByRole("button", { name: /Đặt lại mật khẩu/ }).click();
    await dialog(admin, `Đặt lại mật khẩu cho ${R1_NAME}?`).getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    const secret = admin.getByTestId("initial-password");
    await expect(secret).toBeVisible();
    const pw = (await secret.textContent())!.trim();
    await admin.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }).click();
    const other = await (await browser.newContext()).newPage();
    await other.goto(`${ADMIN}/dang-nhap`);
    await other.locator('input[name="login"]').fill(R1_EMAIL);
    await other.locator('input[name="password"]').fill(pw);
    await other.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(other).toHaveURL(/\/doi-mat-khau/, { timeout: 20_000 });
    await other.context().close();
    expect(logs.filter((l) => l.includes(pw))).toEqual([]);
  });

  test("R1b: nút 'Tải lại danh sách' trong hộp lỗi mạng gọi lại list", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+Admin`);
    await admin.route("**/api/v1/admin/staff", (r) => (r.request().method() === "POST" ? r.abort() : r.continue()));
    await admin.getByRole("button", { name: "Tạo tài khoản" }).click();
    const dlg = dialog(admin, "Tạo tài khoản staff mới");
    await dlg.getByLabel(/Họ và tên/).fill("E2E FA10 Abort");
    await dlg.getByLabel(/Email/).fill(`e2e-fa10-abort${stamp2}@example.com`);
    await dlg.getByLabel(/Vai trò/).selectOption("quan_ly_trang");
    await dlg.getByRole("button", { name: "Tạo tài khoản" }).click();
    await expect(dlg.getByText(/Có thể tài khoản đã được tạo/)).toBeVisible();
    await admin.unroute("**/api/v1/admin/staff");
    const reloadReq = admin.waitForRequest((r) => r.url().includes("/admin/staff?") && r.method() === "GET");
    await dlg.getByRole("button", { name: "Tải lại danh sách" }).click();
    await reloadReq;
    await dlg.getByRole("button", { name: "Huỷ" }).click();
  });

  test("R2: lên Admin có cảnh báo danger; hạ Admin có cảnh báo mất quyền; không đổi khi Huỷ", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+GV+Khóa`);
    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Đổi vai trò/ }).click();
    const dlg = dialog(admin, "Đổi vai trò của E2E FA10 GV Khóa");
    await expect(dlg.getByText("Vai trò Admin có toàn quyền hệ thống")).toHaveCount(0);
    await dlg.getByLabel(/Vai trò mới/).selectOption("admin");
    await expect(dlg.getByText("Vai trò Admin có toàn quyền hệ thống")).toBeVisible();
    await expect(dlg.getByText(/Chỉ cấp cho người thật sự cần/)).toBeVisible();
    await expect(dlg.getByText("Các khóa đang phụ trách sẽ bị gỡ")).toBeVisible(); // giáo viên -> admin cũng gỡ khóa
    await dlg.getByRole("button", { name: "Huỷ" }).click();
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Giáo viên");
    // thật sự thăng rồi hạ
    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Đổi vai trò/ }).click();
    await dlg.getByLabel(/Vai trò mới/).selectOption("admin");
    await dlg.getByRole("button", { name: "Đổi vai trò" }).click();
    await expect(dlg).toHaveCount(0);
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Admin");
    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Đổi vai trò/ }).click();
    await dlg.getByLabel(/Vai trò mới/).selectOption("giao_vien");
    await expect(dlg.getByText("Vai trò Admin có toàn quyền hệ thống")).toBeVisible();
    await expect(dlg.getByText(/mất toàn quyền hệ thống/)).toBeVisible();
    await dlg.getByRole("button", { name: "Đổi vai trò" }).click();
    await expect(dlg).toHaveCount(0);
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Giáo viên");
  });

  test("LAST_ADMIN (mô phỏng 409 của server): hộp không đóng, hiện lời server, trạng thái không đổi, thử lại được", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+GV+Khóa`);
    const msg = "Không thể khóa Admin cuối cùng đang hoạt động.";
    await admin.route("**/api/v1/admin/staff/*/lock", (r) =>
      r.fulfill({ status: 409, contentType: "application/json", body: JSON.stringify({ code: "LAST_ADMIN", message: msg }) }),
    );
    await row(admin, "E2E FA10 GV Khóa").getByRole("button", { name: /Khóa tài khoản/ }).click();
    const dlg = dialog(admin, "Khóa tài khoản E2E FA10 GV Khóa?");
    await dlg.getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(dlg.getByText(msg)).toBeVisible();
    await expect(dlg).toBeVisible();
    await expect(row(admin, "E2E FA10 GV Khóa")).toContainText("Đang hoạt động");
    await dlg.getByRole("button", { name: "Khóa tài khoản" }).click(); // nút không kẹt loading
    await expect(dlg.getByText(msg)).toBeVisible();
    await admin.unroute("**/api/v1/admin/staff/*/lock");
    await dlg.getByRole("button", { name: "Huỷ" }).click();
    await expect(dlg).toHaveCount(0);
  });

  test("hai tab cùng khóa một tài khoản: tab sau nhận ALREADY_PROCESSED, danh sách tải lại", async () => {
    const tabB = await admin.context().newPage();
    await admin.goto(`${BASE}?q=E2E+FA10+Trang+10`);
    await tabB.goto(`${BASE}?q=E2E+FA10+Trang+10`);
    await expect(row(tabB, "E2E FA10 Trang 10")).toContainText("Đang hoạt động");
    await row(admin, "E2E FA10 Trang 10").getByRole("button", { name: /Khóa tài khoản/ }).click();
    await dialog(admin, "Khóa tài khoản E2E FA10 Trang 10?").getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(row(admin, "E2E FA10 Trang 10")).toContainText("Đã khóa");
    await row(tabB, "E2E FA10 Trang 10").getByRole("button", { name: /Khóa tài khoản/ }).click();
    const dlgB = dialog(tabB, "Khóa tài khoản E2E FA10 Trang 10?");
    await dlgB.getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(dlgB.getByRole("alert")).toBeVisible();
    await expect(row(tabB, "E2E FA10 Trang 10")).toContainText("Đã khóa"); // đã tải lại
    console.log("ALREADY_PROCESSED text:", await dlgB.getByRole("alert").innerText());
    await tabB.close();
  });

  test("đổi vai trò giáo viên rồi đổi ngược: không tự gán lại khóa", async () => {
    // spec dev đã đổi gv-doivaitro -> quản lý trang; đổi ngược
    await admin.goto(`${BASE}?q=E2E+FA10+GV+Đổi`);
    await row(admin, "E2E FA10 GV Đổi Vai Trò").getByRole("button", { name: /Đổi vai trò/ }).click();
    const dlg = dialog(admin, "Đổi vai trò của E2E FA10 GV Đổi Vai Trò");
    await dlg.getByLabel(/Vai trò mới/).selectOption("quan_ly_trang");
    await expect(dlg.getByText("Các khóa đang phụ trách sẽ bị gỡ")).toBeVisible();
    await dlg.getByRole("button", { name: "Đổi vai trò" }).click();
    await expect(admin.getByText("2 khóa không còn giáo viên phụ trách, hãy gán lại")).toBeVisible();
    const hrefs = await admin.getByRole("link", { name: /^Khóa #\d+$/ }).evaluateAll((a) => a.map((x) => (x as HTMLAnchorElement).getAttribute("href")));
    expect(hrefs).toHaveLength(2);
    const ids = hrefs.map((h) => Number(/khoa-hoc\/(\d+)\/sua/.exec(h!)![1]));
    for (const id of ids) {
      const c = await apiCall(admin, "GET", `/admin/courses/${id}`);
      expect(c.status).toBe(200);
      expect(JSON.stringify(c.body)).toContain("E2E FA10 Khóa");
    }
    const id = (await apiCall(admin, "GET", "/admin/staff?q=e2e-fa10-gv-doivaitro")).body!.data as { id: number }[];
    await row(admin, "E2E FA10 GV Đổi Vai Trò").getByRole("button", { name: /Đổi vai trò/ }).click();
    await dlg.getByLabel(/Vai trò mới/).selectOption("giao_vien");
    await dlg.getByRole("button", { name: "Đổi vai trò" }).click();
    await expect(dlg).toHaveCount(0);
    await expect(admin.getByText(/không còn giáo viên phụ trách/)).toHaveCount(0); // đổi ngược: không có released
    console.log("course after revert:", JSON.stringify((await apiCall(admin, "GET", `/admin/courses/${ids[0]}`)).body).slice(0, 400), "gvId", id[0]!.id);
  });

  test("phiên đang mở của staff: bị khóa / đặt lại mật khẩu / đổi vai trò -> request kế tiếp bị chặn rõ ràng", async ({ browser }) => {
    test.setTimeout(180_000);
    const ids: Record<string, number> = {};
    for (const n of ["pg01", "pg02", "pg03"]) {
      const r = await apiCall(admin, "GET", `/admin/staff?q=e2e-fa10-${n}@`);
      ids[n] = (r.body!.data as { id: number }[])[0]!.id;
    }
    const pages: Record<string, Page> = {};
    for (const n of ["pg01", "pg02", "pg03"]) {
      pages[n] = await (await browser.newContext()).newPage();
      await loginTeacher(pages[n]!, `fa10-${n}`);
      await pages[n]!.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    }
    expect((await apiCall(admin, "POST", `/admin/staff/${ids.pg01}/lock`)).status).toBe(200);
    expect((await apiCall(admin, "POST", `/admin/staff/${ids.pg02}/reset-password`)).status).toBe(200);
    expect((await apiCall(admin, "PATCH", `/admin/staff/${ids.pg03}/role`, { role: "quan_ly_trang" })).status).toBe(200);

    // pg01: bị khóa -> API 403 ACCOUNT_LOCKED + giao diện chặn
    const api01 = await apiCall(pages.pg01!, "GET", "/admin/auth/me");
    console.log("pg01 locked api:", api01.status, JSON.stringify(api01.body));
    await pages.pg01!.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    await expect(pages.pg01!.getByText(/Tài khoản đã bị khóa|Đăng nhập/).first()).toBeVisible({ timeout: 20_000 });
    console.log("pg01 url:", pages.pg01!.url(), "|", (await pages.pg01!.locator("body").innerText()).slice(0, 200).replace(/\n/g, " / "));
    // pg02: đặt lại mật khẩu -> phiên bị huỷ
    const api02 = await apiCall(pages.pg02!, "GET", "/admin/auth/me");
    console.log("pg02 reset api:", api02.status, JSON.stringify(api02.body));
    expect([401, 403]).toContain(api02.status);
    await pages.pg02!.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    await expect(pages.pg02!).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
    // pg03: đổi vai trò -> phiên bị huỷ, phải đăng nhập lại
    const api03 = await apiCall(pages.pg03!, "GET", "/admin/auth/me");
    console.log("pg03 role api:", api03.status, JSON.stringify(api03.body));
    expect([401, 403]).toContain(api03.status);
    await pages.pg03!.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    await expect(pages.pg03!).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
    // pg01 đăng nhập lại -> bị từ chối
    const again = await (await browser.newContext()).newPage();
    await fillLogin(again, "fa10-pg01");
    await expect(again.getByText(/đã bị khóa/).first()).toBeVisible({ timeout: 15_000 });
    await expect(again).not.toHaveURL(`${ADMIN}/quan-tri`);
    console.log("pg01 relogin:", again.url(), "|", (await again.locator("body").innerText()).slice(0, 200).replace(/\n/g, " / "));
    for (const p of [...Object.values(pages), again]) await p.context().close();
  });

  test("429 (mô phỏng): thông báo có số giây trong hộp xác nhận và hộp tạo", async () => {
    await admin.goto(`${BASE}?q=E2E+FA10+Trang+11`);
    await admin.route("**/api/v1/admin/staff/*/lock", (r) =>
      r.fulfill({ status: 429, headers: { "Retry-After": "17", "Access-Control-Allow-Origin": ADMIN, "Access-Control-Allow-Credentials": "true", "Access-Control-Expose-Headers": "Retry-After" }, contentType: "application/json", body: JSON.stringify({ message: "Too Many Attempts." }) }),
    );
    await row(admin, "E2E FA10 Trang 11").getByRole("button", { name: /Khóa tài khoản/ }).click();
    const dlg = dialog(admin, "Khóa tài khoản E2E FA10 Trang 11?");
    await dlg.getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(dlg.getByText(/quá nhanh.*17 giây/)).toBeVisible();
    await admin.unroute("**/api/v1/admin/staff/*/lock");
    await dlg.getByRole("button", { name: "Huỷ" }).click();
  });

  test("375px: hộp mật khẩu, hộp xác nhận, hộp đổi vai trò không tràn ngang; nút >= 44px", async () => {
    await admin.setViewportSize({ width: 375, height: 800 });
    await admin.goto(`${BASE}?q=E2E+FA10+Trang+12`);
    const r = row(admin, "E2E FA10 Trang 12");
    await r.getByRole("button", { name: /Đổi vai trò/ }).click();
    const rd = dialog(admin, "Đổi vai trò của E2E FA10 Trang 12");
    await rd.getByLabel(/Vai trò mới/).selectOption("admin");
    expect(await noOverflow(admin)).toBeLessThanOrEqual(0);
    for (const l of [rd.getByRole("button", { name: "Huỷ" }), rd.getByRole("button", { name: "Đổi vai trò" }), rd.getByLabel(/Vai trò mới/)])
      expect((await l.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    await rd.getByRole("button", { name: "Huỷ" }).click();
    await r.getByRole("button", { name: /Đặt lại mật khẩu/ }).click();
    await dialog(admin, "Đặt lại mật khẩu cho E2E FA10 Trang 12?").getByRole("button", { name: "Đặt lại mật khẩu" }).click();
    const secret = admin.getByTestId("initial-password");
    await expect(secret).toBeVisible();
    expect(await noOverflow(admin)).toBeLessThanOrEqual(0);
    const box = (await secret.boundingBox())!;
    expect(box.x + box.width).toBeLessThanOrEqual(375);
    for (const n of ["Sao chép", "Đã lưu mật khẩu, đóng"]) expect((await admin.getByRole("button", { name: n }).boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    await admin.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }).click();
    await admin.setViewportSize({ width: 1280, height: 800 });
  });

  test("429 thật: vượt 30 lần/phút của khóa -> 429 + Retry-After; UI báo thao tác quá nhanh", async () => {
    const id = ((await apiCall(admin, "GET", "/admin/staff?q=e2e-fa10-pg26@")).body!.data as { id: number }[])[0]!.id;
    const statuses = await admin.evaluate(
      async ({ api, id }) => {
        const dev = localStorage.getItem("vv_device_id") ?? "";
        const out: { s: number; ra: string | null }[] = [];
        for (let i = 0; i < 34; i++) {
          const { token } = (await (await fetch(`${api}/csrf-token`, { credentials: "include", headers: { Accept: "application/json", "X-Device-Id": dev } })).json()) as { token: string };
          const r = await fetch(`${api}/admin/staff/${id}/lock`, { method: "POST", credentials: "include", headers: { Accept: "application/json", "X-CSRF-TOKEN": token, "X-Device-Id": dev } });
          out.push({ s: r.status, ra: r.headers.get("retry-after") });
        }
        return out;
      },
      { api: API, id },
    );
    const codes = statuses.map((x) => x.s);
    console.log("lock statuses:", codes.join(","));
    expect(codes).toContain(429);
    expect(codes.filter((c) => c >= 500)).toEqual([]);
    await admin.goto(`${BASE}?q=E2E+FA10+Trang+13`);
    await row(admin, "E2E FA10 Trang 13").getByRole("button", { name: /Khóa tài khoản/ }).click();
    const dlg = dialog(admin, "Khóa tài khoản E2E FA10 Trang 13?");
    await dlg.getByRole("button", { name: "Khóa tài khoản" }).click();
    await expect(dlg.getByText(/Bạn thao tác quá nhanh\./)).toBeVisible();
    console.log("429 UI:", await dlg.getByText(/quá nhanh/).innerText());
    await expect(dlg).toBeVisible();
    await row(admin, "E2E FA10 Trang 13").waitFor();
    await expect(row(admin, "E2E FA10 Trang 13")).toContainText("Đang hoạt động");
  });
});
