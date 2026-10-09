import { expect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * FA8 (US-022) — e2e THẬT quản trị đơn hàng (E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Dữ liệu: `e2e/seed-e2e-fa8.sh --reset` TRƯỚC MỖI LẦN chạy (spec duyệt/huỷ/hoàn tiền), `--clean` sau cùng.
 * Chỉ thao tác trên đơn của học sinh `fa8-*`. Chạy `--workers=1 --retries=0`. 2 lần đăng nhập MFA (admin1, qlt1); giáo viên đăng nhập thẳng.
 * Phủ: menu + số đơn chờ, tab Chờ duyệt (cũ nhất trước, Sắp hết hạn), lọc/cursor/khoảng ngày, chi tiết (PII đầy đủ, văn bản dạng text,
 * đúng 1 lần GET chi tiết, không lưu storage), Duyệt (tick bắt buộc, chống bấm kép), 2 tab cùng duyệt → 409, đơn vừa bị huỷ → 409 + Duyệt muộn
 * (2 bước), khóa đã xoá → 409, Huỷ (lý do bắt buộc tách ghi chú nội bộ), ghi chú nội bộ, hoàn tiền, 404, giáo viên 403, 375px.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 120_000 });

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
const BASE = `${ADMIN}/quan-tri/don-hang`;
const ymd = (d: Date) => d.toISOString().slice(0, 10);
const row = (page: Page, text: string | RegExp) => page.getByRole("row").filter({ hasText: text });
const dialog = (page: Page, name: RegExp) => page.getByRole("dialog", { name });
const codes: Record<string, string> = {};

/** Mã đơn của học sinh fa8 theo tên (qua API, phiên admin). */
async function codeOf(page: Page, name: string): Promise<string> {
  if (codes[name]) return codes[name];
  const from = ymd(new Date(Date.now() - 80 * 86_400_000));
  const to = ymd(new Date(Date.now() + 86_400_000));
  const res = await apiCall(page, "GET", `/admin/orders?from=${from}&to=${to}&q=${encodeURIComponent(name)}&per_page=25`);
  const data = (res.body?.data ?? []) as Array<{ code: string; student: { name: string } }>;
  const hit = data.find((o) => o.student.name === name);
  expect(hit, `không thấy đơn của ${name}`).toBeTruthy();
  codes[name] = hit!.code;
  return hit!.code;
}

const open = async (page: Page, name: string) => {
  const code = await codeOf(staff, name);
  await page.goto(`${BASE}/${code}`);
  await expect(page.getByRole("heading", { level: 1, name: new RegExp(code) })).toBeVisible({ timeout: 30_000 });
  return code;
};

const navCount = async (page: Page): Promise<number> => {
  const text = (await nav(page).getByRole("link", { name: /Đơn hàng/ }).textContent()) ?? "";
  const m = /(\d+) đơn chờ duyệt/.exec(text);
  return m ? Number(m[1]) : 0;
};

let staff: Page;
let staff2: Page;
let ctx: BrowserContext;

test.describe("FA8 đơn hàng quản trị (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(300_000); // lần đầu dev server biên dịch route (máy yếu)
    ctx = await browser.newContext();
    staff = await ctx.newPage();
    await loginMfa(staff, request, "fa8-admin1");
    staff2 = await ctx.newPage(); // cùng phiên, tab thứ hai (để thử 2 người cùng thao tác)
    await staff.goto(BASE);
    await expect(staff.getByRole("heading", { name: "Đơn hàng", level: 1 })).toBeVisible({ timeout: 120_000 });
  });

  test("menu có 'Đơn hàng' kèm số đơn chờ (câu đọc cho trình đọc màn hình); tab mặc định Chờ duyệt", async () => {
    await staff.goto(`${ADMIN}/quan-tri`);
    const link = nav(staff).getByRole("link", { name: /Đơn hàng/ });
    await expect(link).toBeVisible();
    await expect.poll(() => navCount(staff), { timeout: 15_000 }).toBeGreaterThanOrEqual(7);
    await link.click();
    await expect(staff).toHaveURL(BASE);
    await expect(staff.getByRole("link", { name: /^Chờ duyệt/ })).toHaveAttribute("aria-current", "page");
  });

  test("tab Chờ duyệt: cũ nhất trước, nhãn 'Sắp hết hạn' chỉ ở đơn <12h, email/SĐT đã che, không cần khoảng ngày", async () => {
    await staff.goto(`${BASE}?q=Fa8`);
    await expect(staff.getByLabel(/Từ ngày/)).toHaveCount(0);
    const rows = staff.getByRole("row").filter({ hasText: "Fa8 HS" });
    await expect(rows.first()).toBeVisible({ timeout: 20_000 });
    await expect(rows).toHaveCount(7);
    await expect(rows.first()).toContainText("Fa8 HS Một"); // đơn cũ nhất (69 giờ trước)
    await expect(rows.last()).toContainText("Fa8 HS Bảy");
    await expect(row(staff, "Fa8 HS Một").getByText("Sắp hết hạn").first()).toBeAttached();
    await expect(row(staff, "Fa8 HS Hai").getByText("Sắp hết hạn")).toHaveCount(0);
    const mask = await row(staff, "Fa8 HS Một").first().innerText();
    expect(mask).not.toContain("fa8-hs1@example.com");
    expect(mask).toContain("***");
  });

  test("tìm theo tên trên URL, F5 giữ bộ lọc; lọc rỗng có 'Xoá bộ lọc'", async () => {
    await staff.goto(BASE);
    await staff.getByLabel("Mã đơn hoặc tên học sinh").fill("Fa8 HS Hai");
    await staff.getByRole("button", { name: "Tìm" }).click();
    await expect(staff).toHaveURL(/q=Fa8\+HS\+Hai|q=Fa8%20HS%20Hai/);
    await expect(row(staff, "Fa8 HS Hai")).toBeVisible();
    await staff.reload();
    await expect(row(staff, "Fa8 HS Hai")).toBeVisible();
    await staff.goto(`${BASE}?q=zzzkhongcodon`);
    await expect(staff.getByText("Không tìm thấy đơn phù hợp với bộ lọc")).toBeVisible();
    await staff.getByRole("link", { name: "Xoá bộ lọc" }).click();
    await expect(staff).toHaveURL(BASE);
  });

  test("tab khác: khoảng ngày bắt buộc ≤ 366, phân trang cursor Trước/Sau, tổng, đúng trạng thái", async () => {
    await staff.goto(`${BASE}?tab=tat-ca&q=${encodeURIComponent("Fa8 Bulk")}`);
    await expect(staff.getByLabel(/Từ ngày/)).toBeVisible({ timeout: 20_000 });
    await expect(staff.getByText(/Khoảng 27 đơn khớp bộ lọc/)).toBeVisible();
    const first = (await staff.getByRole("row").nth(1).textContent()) ?? "";
    await staff.getByRole("link", { name: /Trang sau/ }).click();
    await expect(staff).toHaveURL(/cursor=/);
    await expect(staff.getByText(/Khoảng 27 đơn khớp bộ lọc/)).toBeVisible();
    await expect(staff.getByRole("row")).toHaveCount(3); // 2 đơn còn lại + hàng tiêu đề
    await expect(staff.getByRole("link", { name: /Trang sau/ })).toHaveCount(0);
    await staff.reload(); // F5 giữ trang cursor
    await expect(staff.getByRole("row")).toHaveCount(3);
    await staff.getByRole("link", { name: /Trang trước/ }).click();
    await expect(staff.getByRole("row").nth(1)).toHaveText(first.trim());
    // Khoảng ngày quá 366 ngày: báo lỗi, không gọi API.
    let calls = 0;
    staff.on("request", (r) => (/\/admin\/orders\?/.test(r.url()) ? calls++ : 0));
    await staff.goto(`${BASE}?tab=tat-ca&from=2024-01-01&to=2026-01-01`);
    await expect(staff.getByText(/Khoảng ngày tối đa 366 ngày\./).last()).toBeVisible();
    expect(calls).toBe(0);
    staff.removeAllListeners("request");
    // Tab Đã huỷ: 3 đơn của fa8; lọc 'Cần xem lại' → rỗng.
    const from60 = ymd(new Date(Date.now() - 60 * 86_400_000));
    await staff.goto(`${BASE}?tab=da-huy&q=Fa8&from=${from60}`);
    await expect(staff.getByRole("row").filter({ hasText: "Fa8 HS" })).toHaveCount(3, { timeout: 20_000 });
    await expect(row(staff, "Fa8 HS Mười").first()).toContainText(/huỷ/i); // nhãn trạng thái
    await staff.goto(`${BASE}?tab=da-huy&q=Fa8&from=${from60}&review=1`);
    await expect(staff.getByText("Không tìm thấy đơn phù hợp với bộ lọc")).toBeVisible();
    // Tab Chờ duyệt không còn dính lọc ngày/tab cũ.
    await staff.goto(`${BASE}?tab=hoan-tien`);
    await expect(staff.getByRole("link", { name: /^Đã hoàn tiền/ })).toHaveAttribute("aria-current", "page");
  });

  test("chi tiết: thông tin liên hệ đầy đủ, văn bản người dùng là TEXT, đúng 1 lần GET chi tiết, không lưu storage", async () => {
    const code = await codeOf(staff, "Fa8 HS Một");
    const gets: string[] = [];
    staff.on("request", (r) => {
      if (r.method() === "GET" && new RegExp(`/admin/orders/${code}$`).test(r.url().split("?")[0] ?? "")) gets.push(r.url());
    });
    await staff.goto(`${BASE}/${code}`);
    await expect(staff.getByRole("heading", { level: 1, name: new RegExp(code) })).toBeVisible({ timeout: 30_000 });
    await expect(staff.getByText("fa8-hs1@example.com")).toBeVisible();
    await expect(staff.getByText("0980008001")).toBeVisible();
    await expect(staff.getByRole("link", { name: "Gọi" })).toHaveAttribute("href", /^tel:0980008001/);
    await expect(staff.getByRole("link", { name: "Gửi email" })).toHaveAttribute("href", /^mailto:fa8-hs1%40example\.com\?subject=/);
    const note = staff.getByTestId("customer-note");
    await expect(note).toContainText("<b>Gọi sau 18h</b> <img src=x onerror=alert(1)> https://example.com/x");
    await expect(note.locator("b, img, a")).toHaveCount(0);
    await expect(staff.locator('img[src="x"]')).toHaveCount(0);
    await expect(staff.getByText("Có khóa đã ngừng bán").filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText(/Lần xem thông tin liên hệ này đã được ghi vào nhật ký/)).toBeVisible();
    await expect(staff.getByText("Sắp hết hạn").filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText(/Học sinh Fa8 HS Một đặt đơn lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/)).toBeVisible();
    await staff.waitForTimeout(1500);
    expect(gets).toHaveLength(1);
    staff.removeAllListeners("request");
    const stored = await staff.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
    expect(stored).not.toContain(code);
    expect(stored).not.toContain("fa8-hs1");
  });

  test("Duyệt: nút khoá tới khi tick 'Đã nhận đủ', bấm kép chỉ 1 lần, 'Duyệt bởi X lúc …', số đơn chờ trên menu giảm", async () => {
    const code = await open(staff, "Fa8 HS Hai");
    await expect.poll(() => navCount(staff), { timeout: 15_000 }).toBeGreaterThanOrEqual(6);
    const before = await navCount(staff);
    await staff.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    const dlg = dialog(staff, /Duyệt đơn/);
    await expect(dlg.getByText("Số tiền cần nhận")).toBeVisible();
    await expect(dlg.getByText("Tick ô này để bật nút duyệt.")).toBeVisible();
    await expect(dlg.getByRole("button", { name: "Duyệt đơn" })).toBeDisabled();
    await dlg.getByRole("checkbox", { name: /Đã nhận đủ 300\.000/ }).check();
    await dlg.getByLabel(/Mã giao dịch/).fill("FT-E2E-FA8-HAI");
    await dlg.getByLabel(/Ghi chú nội bộ/).fill("Chuyển khoản 10:02");
    let posts = 0;
    staff.on("request", (r) => (r.method() === "POST" && r.url().includes(`/admin/orders/${code}/approve`) ? posts++ : 0));
    await dlg.getByRole("button", { name: "Duyệt đơn" }).dblclick();
    await expect(staff.getByTestId("order-notice")).toContainText("Đã duyệt đơn", { timeout: 20_000 });
    expect(posts).toBe(1);
    staff.removeAllListeners("request");
    await expect(staff.getByTestId("confirmed-by")).toContainText("E2E FA8 Admin");
    await expect(staff.getByTestId("confirmed-by")).toContainText(/lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/);
    await expect(staff.getByText(/Đã duyệt bởi E2E FA8 Admin lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/)).toBeVisible();
    await expect(staff.getByText("FT-E2E-FA8-HAI", { exact: true })).toBeVisible();
    await expect(staff.getByRole("list", { name: /Ghi chú nội bộ/ }).getByText("Chuyển khoản 10:02")).toBeVisible(); // ghi chú nội bộ được thêm
    await expect(staff.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toHaveCount(0);
    await expect.poll(() => navCount(staff), { timeout: 15_000 }).toBe(before - 1);
  });

  test("2 tab cùng duyệt: tab thứ hai nhận 409, tải lại đơn và báo ai đã duyệt", async () => {
    const code = await open(staff, "Fa8 HS Ba");
    await staff2.goto(`${BASE}/${code}`);
    await expect(staff2.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeVisible({ timeout: 30_000 });
    // tab 1 duyệt trước
    await staff.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    await dialog(staff, /Duyệt đơn/).getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await dialog(staff, /Duyệt đơn/).getByRole("button", { name: "Duyệt đơn" }).click();
    await expect(staff.getByTestId("order-notice")).toContainText("Đã duyệt đơn", { timeout: 20_000 });
    // tab 2 (dữ liệu cũ) duyệt sau
    await staff2.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    await dialog(staff2, /Duyệt đơn/).getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await dialog(staff2, /Duyệt đơn/).getByRole("button", { name: "Duyệt đơn" }).click();
    const notice = staff2.getByTestId("order-notice");
    await expect(notice).toContainText("Đơn đã được người khác xử lý", { timeout: 20_000 });
    await expect(notice).toContainText(/E2E FA8 Admin đã duyệt lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/);
    await expect(notice.getByRole("alert")).toBeVisible();
    await expect(staff2.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toHaveCount(0);
    await expect(staff2.getByTestId("confirmed-by")).toContainText("E2E FA8 Admin");
  });

  test("Huỷ: lý do gửi học sinh bắt buộc (lỗi dưới ô + focus), ghi chú nội bộ tách riêng và không lẫn vào lý do", async () => {
    await open(staff, "Fa8 HS Bốn");
    await staff.getByRole("button", { name: "Huỷ đơn" }).first().click();
    const dlg = dialog(staff, /Huỷ đơn VV/);
    await expect(dlg.getByRole("button", { name: "Không huỷ" })).toBeFocused();
    await dlg.getByRole("button", { name: "Huỷ đơn" }).click();
    await expect(dlg.getByText(/ít nhất 5 ký tự/)).toBeVisible();
    await expect(dlg.getByLabel(/Lý do gửi học sinh/)).toBeFocused();
    await dlg.getByRole("button", { name: "Không liên lạc được" }).click();
    await expect(dlg.getByLabel(/Lý do gửi học sinh/)).toHaveValue(/chưa liên lạc được với bạn/);
    await dlg.getByLabel(/Ghi chú nội bộ/).fill("Gọi 3 lần ngày 8/10 và 9/10");
    await dlg.getByRole("button", { name: "Huỷ đơn" }).click();
    await expect(staff.getByTestId("order-notice")).toContainText("Đã huỷ đơn", { timeout: 20_000 });
    await expect(staff.getByText("Huỷ bởi QTV").filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText(/Lý do gửi HS/).filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText(/Lý do gửi học sinh: Quản trị viên đã gọi điện/)).toBeVisible(); // trong lịch sử
    await expect(staff.getByRole("list", { name: /Ghi chú nội bộ/ }).getByText("Gọi 3 lần ngày 8/10 và 9/10")).toBeVisible(); // ghi chú nội bộ riêng
    await expect(staff.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toHaveCount(0);
    await expect(staff.getByRole("button", { name: "Duyệt muộn" })).toBeVisible();
    await expect(staff.getByText(/có thể duyệt muộn tới \d{2}\/\d{2}\/\d{4}/)).toBeVisible();
  });

  test("đơn vừa bị huỷ ở tab khác: duyệt → 409, báo rõ, hiện Duyệt muộn; Duyệt muộn 2 bước thành công, gắn 'Cần xem lại'", async () => {
    const code = await codeOf(staff, "Fa8 HS Năm");
    await staff2.goto(`${BASE}/${code}`);
    await expect(staff2.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeVisible({ timeout: 30_000 });
    // tab 1 huỷ
    await staff.goto(`${BASE}/${code}`);
    await staff.getByRole("button", { name: "Huỷ đơn" }).first().click();
    await dialog(staff, /Huỷ đơn VV/).getByLabel(/Lý do gửi học sinh/).fill("Học sinh muốn đặt lại đơn khác");
    await dialog(staff, /Huỷ đơn VV/).getByRole("button", { name: "Huỷ đơn" }).click();
    await expect(staff.getByTestId("order-notice")).toContainText("Đã huỷ đơn", { timeout: 20_000 });
    // tab 2 duyệt trên dữ liệu cũ
    await staff2.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    await dialog(staff2, /Duyệt đơn/).getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await dialog(staff2, /Duyệt đơn/).getByRole("button", { name: "Duyệt đơn" }).click();
    const notice = staff2.getByTestId("order-notice");
    await expect(notice).toContainText("Chưa duyệt: đơn vừa đổi trạng thái", { timeout: 20_000 });
    await expect(notice).toContainText(/E2E FA8 Admin đã huỷ đơn lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/);
    await expect(notice).toContainText("Duyệt muộn");
    const late = staff2.getByRole("button", { name: "Duyệt muộn" });
    await expect(late).toBeVisible();
    // Duyệt muộn: 2 bước
    await late.click();
    const d1 = dialog(staff2, /Duyệt muộn đơn/);
    await expect(d1.getByTestId("late-warning-box")).toContainText(/Đơn đã huỷ lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/);
    await expect(d1.getByTestId("late-warning-box")).toContainText("Lý do đã gửi học sinh: Học sinh muốn đặt lại đơn khác");
    await expect(d1.getByRole("button", { name: "Tiếp tục" })).toBeDisabled();
    await d1.getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await d1.getByRole("button", { name: "Tiếp tục" }).click();
    const d2 = dialog(staff2, /Xác nhận lần 2/);
    await expect(d2.getByText(/Không hoàn tác được/)).toBeVisible();
    await d2.getByRole("button", { name: "Quay lại" }).click();
    await expect(dialog(staff2, /Duyệt muộn đơn/).getByRole("checkbox", { name: /Đã nhận đủ/ })).toBeChecked(); // giữ dữ liệu khi quay lại
    await d1.getByRole("button", { name: "Tiếp tục" }).click();
    await dialog(staff2, /Xác nhận lần 2/).getByRole("button", { name: "Xác nhận duyệt muộn" }).click();
    await expect(staff2.getByTestId("order-notice")).toContainText("Đã duyệt muộn", { timeout: 20_000 });
    await expect(staff2.getByText("Cần xem lại").filter({ visible: true }).first()).toBeVisible();
    await expect(staff2.getByText(/Duyệt muộn \(đơn đã huỷ trước đó\)/)).toBeVisible();
    await expect(staff2.getByText(/Đã duyệt muộn bởi E2E FA8 Admin lúc/)).toBeVisible();
  });

  test("Duyệt muộn: cảnh báo trước khi bấm (đã sở hữu khóa), quá hạn thì không có nút, đơn mới huỷ thì có hạn", async () => {
    await open(staff, "Fa8 HS Chín");
    await staff.getByRole("button", { name: "Duyệt muộn" }).click();
    const box = dialog(staff, /Duyệt muộn đơn/).getByTestId("late-warning-box");
    await expect(box).toContainText(/đã sở hữu khóa “E2E FA8 Khóa Toán”/);
    await dialog(staff, /Duyệt muộn đơn/).getByRole("button", { name: "Huỷ" }).click();
    await open(staff, "Fa8 HS Mười");
    await expect(staff.getByRole("button", { name: "Duyệt muộn" })).toHaveCount(0);
    await expect(staff.getByText(/Đã quá hạn duyệt muộn \(hạn \d{2}\/\d{2}\/\d{4}\)/)).toBeVisible();
    await open(staff, "Fa8 HS Mười một");
    await expect(staff.getByRole("button", { name: "Duyệt muộn" })).toBeVisible();
    await expect(staff.getByText(/Tự huỷ do hết hạn chờ lúc/)).toBeVisible();
  });

  test("khóa đã xoá: cảnh báo trên trang; duyệt → 409 COURSE_UNAVAILABLE nêu tên khóa, đơn giữ nguyên", async () => {
    await open(staff, "Fa8 HS Sáu");
    await expect(staff.getByText("Có khóa đã bị xoá").filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText("Đã xoá", { exact: true }).filter({ visible: true }).first()).toBeVisible();
    await staff.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    await dialog(staff, /Duyệt đơn/).getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await dialog(staff, /Duyệt đơn/).getByRole("button", { name: "Duyệt đơn" }).click();
    const notice = staff.getByTestId("order-notice");
    await expect(notice).toContainText("Không duyệt được: có khóa đã bị xoá", { timeout: 20_000 });
    await expect(notice).toContainText("E2E FA8 Khóa Sẽ xoá");
    await expect(staff.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeVisible();
    await expect(staff.getByText("Chờ duyệt").filter({ visible: true }).first()).toBeVisible();
  });

  test("ghi chú nội bộ: thêm (mới nhất trên), trống báo lỗi dưới ô, tải lại vẫn còn; bấm kép chỉ 1 ghi chú", async () => {
    const code = await open(staff, "Fa8 HS Bảy");
    await expect(staff.getByText("Đã gọi 9h, hẹn chuyển khoản chiều nay", { exact: true })).toBeVisible();
    await staff.getByRole("button", { name: "Lưu ghi chú" }).click();
    await expect(staff.getByText("Nhập nội dung ghi chú.")).toBeVisible();
    // Server từ chối thẻ HTML trong ghi chú (PlainText): lỗi hiện dưới ô, nội dung được giữ.
    await staff.getByLabel("Thêm ghi chú").fill("<i>Zalo mẹ em</i> 0912345678");
    await staff.getByRole("button", { name: "Lưu ghi chú" }).click();
    await expect(staff.locator('p[id$="-error"]').filter({ visible: true }).first()).toBeVisible({ timeout: 20_000 });
    await expect(staff.getByLabel("Thêm ghi chú")).toHaveValue("<i>Zalo mẹ em</i> 0912345678");
    await staff.getByLabel("Thêm ghi chú").fill("Zalo mẹ em 0912345678");
    await staff.getByRole("button", { name: "Lưu ghi chú" }).dblclick();
    const list = staff.getByRole("list", { name: /Ghi chú nội bộ/ });
    await expect(list.getByRole("listitem").first()).toContainText("Zalo mẹ em 0912345678", { timeout: 20_000 });
    await expect(list.getByRole("listitem")).toHaveCount(2);
    await expect(staff.getByLabel("Thêm ghi chú")).toHaveValue("");
    await staff.goto(`${BASE}/${code}`);
    await expect(staff.getByRole("list", { name: /Ghi chú nội bộ/ }).getByRole("listitem")).toHaveCount(2, { timeout: 20_000 });
  });

  test("Đánh dấu hoàn tiền (đơn đã duyệt): phải tick, gửi xong chuyển 'Đã hoàn tiền'", async () => {
    await open(staff, "Fa8 HS Mười hai");
    await staff.getByRole("button", { name: "Đánh dấu hoàn tiền" }).click();
    const dlg = dialog(staff, /hoàn tiền đơn/);
    await expect(dlg.getByRole("button", { name: "Xác nhận hoàn tiền" })).toBeDisabled();
    await dlg.getByRole("checkbox", { name: /Tôi đã hoàn/ }).check();
    await dlg.getByLabel(/Ghi chú/).fill("Hoàn qua chuyển khoản 9/10");
    await dlg.getByRole("button", { name: "Xác nhận hoàn tiền" }).click();
    await expect(staff.getByTestId("order-notice")).toContainText("Đã đánh dấu hoàn tiền", { timeout: 20_000 });
    await expect(staff.getByText("Đã hoàn tiền").filter({ visible: true }).first()).toBeVisible();
    await expect(staff.getByText("Hoàn qua chuyển khoản 9/10")).toBeVisible();
    await expect(staff.getByRole("button", { name: "Đánh dấu hoàn tiền" })).toHaveCount(0);
  });

  test("404: mã đơn không tồn tại → 'Không tìm thấy đơn hàng'", async () => {
    await staff.goto(`${BASE}/VVKHONGTONTAI99`);
    await expect(staff.getByText("Không tìm thấy đơn hàng")).toBeVisible({ timeout: 20_000 });
    await expect(staff.getByRole("link", { name: "Về danh sách đơn" })).toBeVisible();
  });

  test("a11y bàn phím: hộp thoại giữ focus, Esc đóng và trả focus về nút mở", async () => {
    const bulk = await apiCall(staff, "GET", `/admin/orders?from=${ymd(new Date(Date.now() - 3 * 86_400_000))}&to=${ymd(new Date(Date.now() + 86_400_000))}&q=${encodeURIComponent("Fa8 Bulk")}&per_page=25`);
    const bulkCode = ((bulk.body?.data ?? []) as Array<{ code: string }>)[0]!.code;
    await staff.goto(`${BASE}/${bulkCode}`);
    const trigger = staff.getByRole("button", { name: "Đánh dấu hoàn tiền" });
    await expect(trigger).toBeVisible({ timeout: 30_000 });
    await trigger.focus();
    await staff.keyboard.press("Enter");
    const dlg = dialog(staff, /hoàn tiền đơn/);
    await expect(dlg).toBeVisible();
    await expect(dlg.getByRole("button", { name: "Không hoàn tiền" })).toBeFocused();
    await staff.keyboard.press("Escape");
    await expect(dlg).toBeHidden();
    await expect(trigger).toBeFocused();
  });

  test("375px: danh sách và chi tiết không cuộn ngang; nút hành động đủ lớn", async () => {
    await staff.setViewportSize({ width: 375, height: 800 });
    await staff.goto(`${BASE}?q=Fa8`);
    await expect(staff.getByRole("row").filter({ hasText: "Fa8 HS" }).first()).toBeVisible({ timeout: 20_000 });
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    await staff.goto(`${BASE}?tab=tat-ca&q=${encodeURIComponent("Fa8")}`);
    await expect(staff.getByLabel(/Từ ngày/)).toBeVisible({ timeout: 20_000 });
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    const code = await codeOf(staff, "Fa8 HS Bảy");
    await staff.goto(`${BASE}/${code}`);
    await expect(staff.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeVisible({ timeout: 30_000 });
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    const box = await staff.getByRole("button", { name: "Duyệt: đã nhận tiền" }).boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(44);
    await staff.getByRole("button", { name: "Huỷ đơn" }).first().click();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    await staff.keyboard.press("Escape");
    await staff.setViewportSize({ width: 1280, height: 800 });
  });

  test("giáo viên: không thấy menu Đơn hàng, URL trực tiếp → 403, API trả 403 và không gọi pending-count", async ({ browser }) => {
    const c = await browser.newContext();
    const t = await c.newPage();
    const counted: string[] = [];
    t.on("request", (r) => (r.url().includes("/admin/orders") ? counted.push(r.url()) : 0));
    await fillLogin(t, "fa8-gv1");
    await expect(t).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await expect(nav(t).getByRole("link", { name: "Khóa học" })).toBeVisible();
    await expect(nav(t).getByRole("link", { name: /Đơn hàng/ })).toHaveCount(0);
    await t.goto(BASE);
    await expect(t.getByTestId("forbidden-view")).toBeVisible({ timeout: 20_000 });
    await t.goto(`${BASE}/VVKHONGTONTAI99`);
    await expect(t.getByTestId("forbidden-view")).toBeVisible({ timeout: 20_000 });
    expect(counted).toEqual([]);
    expect((await apiCall(t, "GET", "/admin/orders/pending-count")).status).toBe(403);
    expect((await apiCall(t, "GET", "/admin/orders?status[]=pending")).status).toBe(403);
    await c.close();
  });

  test("Quản lý trang: thấy menu Đơn hàng và danh sách đơn chờ", async ({ browser, request }) => {
    const c = await browser.newContext();
    const q = await c.newPage();
    await loginMfa(q, request, "fa8-qlt1");
    await expect(nav(q).getByRole("link", { name: /Đơn hàng/ })).toBeVisible();
    await q.goto(`${BASE}?q=Fa8`);
    await expect(q.getByRole("row").filter({ hasText: "Fa8 HS" }).first()).toBeVisible({ timeout: 30_000 });
    await c.close();
  });
});
