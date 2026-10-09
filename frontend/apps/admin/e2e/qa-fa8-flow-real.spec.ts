import { expect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA FA8 (US-022) — LUỒNG TRỌN 2 PHÍA: học sinh (web :3000) <-> quản trị viên (admin :3001), backend thật.
 * Dữ liệu: `seed-e2e-fa8.sh --reset` + học sinh `fa8-qa-*` (buy/cancel/other/race), mã FA8QAA (max 5), FA8QAMAX (max 1).
 * Chạy: --workers=1, tuần tự. Hai phiên quản trị viên: admin1 (A) và qlt1 (Q) để kiểm số đếm giữa 2 phiên.
 */
const ADMIN = "http://admin-api.localhost:3001";
const WEB = "http://api.localhost:3000";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật");
test.describe.configure({ mode: "serial", timeout: 300_000 });

const BASE = `${ADMIN}/quan-tri/don-hang`;
const nav = (p: Page) => p.getByRole("navigation", { name: "Menu quản trị" });
const dialog = (p: Page, name: RegExp) => p.getByRole("dialog", { name });
const email = (n: string) => `e2e-${n}@example.com`;
type Json = Record<string, unknown>;

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
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 30_000 });
  const code = await latestCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 30_000 });
}
async function apiCall(page: Page, method: string, path: string) {
  return page.evaluate(
    async ({ api, method, path }) => {
      const headers = { Accept: "application/json", "X-Device-Id": localStorage.getItem("vv_device_id") ?? "" };
      const res = await fetch(`${api}${path}`, { credentials: "include", method, headers });
      const text = await res.text();
      let json: unknown = null;
      try {
        json = JSON.parse(text);
      } catch {
        /* rỗng */
      }
      return { status: res.status, body: json as Json | null, retry: res.headers.get("retry-after") };
    },
    { api: API, method, path },
  );
}
const navCount = async (page: Page): Promise<number> => {
  const text = (await nav(page).getByRole("link", { name: /Đơn hàng/ }).textContent()) ?? "";
  const m = /(\d+) đơn chờ duyệt/.exec(text);
  return m ? Number(m[1]) : 0;
};
const noHScroll = async (p: Page) => expect(await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

async function studentLogin(ctx: BrowserContext, key: string): Promise<Page> {
  const page = await ctx.newPage();
  await page.goto(`${WEB}/dang-nhap`);
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(`fa8-qa-${key}@example.com`);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
  return page;
}
async function checkout(page: Page, note?: string): Promise<string> {
  await page.goto(`${WEB}/thanh-toan`);
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  if (note) await page.getByLabel(/Ghi chú cho Quản trị viên/).fill(note);
  await page.getByRole("button", { name: "Gửi đơn" }).first().click();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  return new URL(page.url()).pathname.split("/").pop() as string;
}
async function mailBody(request: APIRequestContext, to: string, subject: string, code: string): Promise<string> {
  let text = "";
  await expect
    .poll(
      async () => {
        const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to} subject:"${subject}"`)}`);
        const list = (await res.json()) as { messages?: Array<{ ID: string }> };
        for (const m of list.messages ?? []) {
          const msg = (await (await request.get(`${MAILPIT}/api/v1/message/${m.ID}`)).json()) as { Text?: string; HTML?: string };
          const body = `${msg.Text ?? ""}\n${msg.HTML ?? ""}`;
          if (body.includes(code)) {
            text = body;
            return text;
          }
        }
        return "";
      },
      { timeout: 90_000, message: `chờ thư "${subject}" tới ${to}` },
    )
    .not.toBe("");
  return text;
}
async function approve(page: Page, ref: string) {
  await page.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
  const d = dialog(page, /Duyệt đơn/);
  await d.getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
  await d.getByLabel(/Mã giao dịch/).fill(ref);
  await d.getByRole("button", { name: "Duyệt đơn" }).click();
}

let A: Page;
let Q: Page;
let ctxA: BrowserContext;
let ctxQ: BrowserContext;
let ctxS: BrowserContext; // học sinh
const C: Record<string, string> = {};
let N0 = 0;

test.describe("QA FA8 luồng 2 phía", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(400_000);
    ctxA = await browser.newContext();
    A = await ctxA.newPage();
    await loginMfa(A, request, "fa8-admin1");
    await A.waitForTimeout(50_000); // giới hạn đăng nhập MFA
    ctxQ = await browser.newContext();
    Q = await ctxQ.newPage();
    await loginMfa(Q, request, "fa8-qlt1");
    ctxS = await browser.newContext();
    await Q.goto(BASE);
    await expect(Q.getByRole("heading", { name: "Đơn hàng", level: 1 })).toBeVisible({ timeout: 120_000 });
    await expect.poll(() => navCount(Q), { timeout: 20_000 }).toBeGreaterThanOrEqual(7);
    N0 = await navCount(Q);
  });

  test("L1: học sinh thêm khóa vào giỏ, áp mã, gửi đơn; QTV thấy ở Chờ duyệt, số menu tăng ở phiên khác", async () => {
    const s = await studentLogin(ctxS, "buy");
    await s.goto(`${WEB}/khoa-hoc/e2e-fa8-toan`);
    await s.getByRole("button", { name: "Thêm vào giỏ" }).first().click();
    await expect(s.getByRole("link", { name: "Xem giỏ hàng" }).first()).toBeVisible({ timeout: 60_000 });
    await s.goto(`${WEB}/gio-hang`);
    await expect(s.getByRole("link", { name: "E2E FA8 Khóa Toán" })).toBeVisible({ timeout: 90_000 });
    await s.getByLabel(/Nhập mã/).fill("fa8qaa");
    await s.getByRole("button", { name: "Áp dụng" }).click();
    await expect(s.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
    C.buy = await checkout(s, "QA FA8: gọi sau 18h");
    await expect(s.getByText(/270\.000/).first()).toBeVisible();
    await s.close();

    // Phiên Q chuyển trang -> số tăng 1
    await Q.goto(`${ADMIN}/quan-tri`);
    await expect.poll(() => navCount(Q), { timeout: 30_000 }).toBe(N0 + 1);
    // Tab Chờ duyệt (mặc định) có đơn mới
    await A.goto(`${BASE}?q=Fa8QA`);
    const row = A.getByRole("row").filter({ hasText: "Fa8QA Mua" });
    await expect(row).toBeVisible({ timeout: 30_000 });
    await expect(row).toContainText(C.buy!);
    await expect(row).toContainText(/270\.000/);
    expect(await row.innerText()).toContain("***");
  });

  test("L2: QTV duyệt -> trạng thái, 'Đã duyệt bởi X lúc', coupon ghi nhận; số menu phiên Q giảm", async () => {
    await A.goto(`${BASE}/${C.buy}`);
    await expect(A.getByRole("heading", { level: 1, name: new RegExp(C.buy!) })).toBeVisible({ timeout: 30_000 });
    await expect(A.getByText("QA FA8: gọi sau 18h")).toBeVisible();
    await expect(A.getByText(/FA8QAA/).first()).toBeVisible();
    await approve(A, "FT-QA-FA8-1");
    await expect(A.getByTestId("order-notice")).toContainText("Đã duyệt đơn", { timeout: 20_000 });
    await expect(A.getByText(/Đã duyệt bởi E2E FA8 Admin lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/)).toBeVisible();
    await expect(A.getByText("FT-QA-FA8-1", { exact: true })).toBeVisible();
    await Q.goto(`${ADMIN}/quan-tri`);
    await expect.poll(() => navCount(Q), { timeout: 30_000 }).toBe(N0);
  });

  test("L3: học sinh vào học được khóa, đơn của tôi 'Đã thanh toán', nhận thư 'Đơn đã thanh toán'", async ({ request }) => {
    const s = await studentLogin(ctxS, "buy");
    await s.goto(`${WEB}/tai-khoan/don-hang`);
    const link = s.getByRole("link", { name: new RegExp(C.buy!) });
    await expect(link).toBeVisible({ timeout: 90_000 });
    await expect(link.getByText("Đã thanh toán")).toBeVisible();
    await link.click();
    await expect(s.getByText("Các khóa trong đơn đã mở")).toBeVisible({ timeout: 90_000 });
    await s.goto(`${WEB}/tai-khoan/khoa-hoc-cua-toi`);
    await expect(s.getByText("E2E FA8 Khóa Toán").first()).toBeVisible({ timeout: 90_000 });
    const hrefs = await s.locator("article", { hasText: "E2E FA8 Khóa Toán" }).first().locator("a").evaluateAll((as) => as.map((a) => a.getAttribute("href") ?? ""));
    console.log("[QA] link trong thẻ khóa:", hrefs.join(" | "));
    const cid = hrefs.map((h) => /(\d+)/.exec(h)?.[1]).find(Boolean);
    expect(cid, "id khóa trong liên kết thẻ").toBeTruthy();
    const res = await s.goto(`${WEB}/hoc/${cid}`);
    expect(res?.status()).toBeLessThan(400);
    await s.waitForTimeout(3000);
    expect(s.url()).not.toContain("/dang-nhap");
    await s.goto(`${WEB}/khoa-hoc/e2e-fa8-toan`);
    await expect(s.getByRole("link", { name: /Vào học|Tiếp tục học|Bắt đầu học|Học ngay/ }).first()).toBeVisible({ timeout: 90_000 });
    // giỏ không còn khóa
    await s.goto(`${WEB}/gio-hang`);
    await expect(s.getByRole("link", { name: "E2E FA8 Khóa Toán" })).toHaveCount(0, { timeout: 30_000 });
    const mail = await mailBody(request, "fa8-qa-buy@example.com", "xác nhận thanh toán", C.buy!);
    expect(mail).toContain(C.buy!);
    await s.close();
  });

  test("L4: QTV huỷ kèm lý do -> số menu giảm sau poll (không chuyển trang), học sinh thấy lý do + thư", async ({ request }) => {
    const sc = await studentLogin(ctxS, "cancel");
    C.cancel = await checkout(sc);
    await Q.goto(`${ADMIN}/quan-tri`);
    await expect.poll(() => navCount(Q), { timeout: 30_000 }).toBe(N0 + 1);
    await A.goto(`${BASE}/${C.cancel}`);
    await expect(A.getByText(/FA8QAMAX/).first()).toBeVisible({ timeout: 30_000 });
    await A.getByRole("button", { name: "Huỷ đơn" }).first().click();
    const d = dialog(A, /Huỷ đơn VV/);
    await d.getByLabel(/Lý do gửi học sinh/).fill("QA FA8: không liên hệ được qua SĐT và Zalo");
    await d.getByLabel(/Ghi chú nội bộ/).fill("QA nội bộ: bí mật không gửi HS");
    await d.getByRole("button", { name: "Huỷ đơn" }).click();
    await expect(A.getByTestId("order-notice")).toContainText("Đã huỷ đơn", { timeout: 20_000 });
    // Phiên Q đứng yên trên trang: poll <= 60s (+ dung sai) làm số giảm
    const t0 = Date.now();
    await expect.poll(() => navCount(Q), { timeout: 90_000, intervals: [2000] }).toBe(N0);
    console.log(`[QA] poll badge giảm sau ${Math.round((Date.now() - t0) / 1000)}s`);
    // Học sinh
    await sc.goto(`${WEB}/tai-khoan/don-hang/${C.cancel}`);
    await expect(sc.getByText("Quản trị viên đã huỷ đơn")).toBeVisible({ timeout: 90_000 });
    await expect(sc.getByText(/QA FA8: không liên hệ được qua SĐT và Zalo/)).toBeVisible();
    await expect(sc.getByText(/bí mật không gửi HS/)).toHaveCount(0);
    const mail = await mailBody(request, "fa8-qa-cancel@example.com", "đã bị huỷ", C.cancel!);
    expect(mail).toContain("QA FA8: không liên hệ được qua SĐT và Zalo");
    expect(mail).not.toContain("bí mật không gửi HS");
    await sc.close();
  });

  test("L5: mã FA8QAMAX (max 1) được nhả -> HS khác áp được, đặt đơn, QTV duyệt (đủ lượt)", async () => {
    const so = await studentLogin(ctxS, "other");
    await so.goto(`${WEB}/gio-hang`);
    await expect(so.getByRole("link", { name: "E2E FA8 Khóa Văn" })).toBeVisible({ timeout: 90_000 });
    await so.getByLabel(/Nhập mã/).fill("fa8qamax");
    await so.getByRole("button", { name: "Áp dụng" }).click();
    await expect(so.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
    C.other = await checkout(so);
    await so.close();
    await A.goto(`${BASE}/${C.other}`);
    await expect(A.getByRole("heading", { level: 1, name: new RegExp(C.other!) })).toBeVisible({ timeout: 30_000 });
    await approve(A, "FT-QA-FA8-OTHER");
    await expect(A.getByTestId("order-notice")).toContainText("Đã duyệt đơn", { timeout: 20_000 });
  });

  test("L6: Duyệt muộn đơn đã huỷ mà mã đã hết lượt -> cảnh báo TRƯỚC khi bấm, duyệt xong 'Cần xem lại' ghi lý do mã", async () => {
    await A.goto(`${BASE}/${C.cancel}`);
    await expect(A.getByRole("button", { name: "Duyệt muộn" })).toBeVisible({ timeout: 30_000 });
    await A.getByRole("button", { name: "Duyệt muộn" }).click();
    const d1 = dialog(A, /Duyệt muộn đơn/);
    const box = d1.getByTestId("late-warning-box");
    console.log("[QA] late-warning-box:", (await box.innerText()).replace(/\s+/g, " "));
    await expect(box).toContainText(/FA8QAMAX|mã giảm giá|lượt/i);
    await expect(box).toContainText("QA FA8: không liên hệ được qua SĐT và Zalo");
    await d1.getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    await d1.getByRole("button", { name: "Tiếp tục" }).click();
    await dialog(A, /Xác nhận lần 2/).getByRole("button", { name: "Xác nhận duyệt muộn" }).click();
    await expect(A.getByTestId("order-notice")).toContainText("Đã duyệt muộn", { timeout: 20_000 });
    await expect(A.getByText("Cần xem lại").filter({ visible: true }).first()).toBeVisible();
    console.log("[QA] sau duyệt muộn:", (await A.locator("main").innerText()).replace(/\s+/g, " ").slice(0, 900));
  });

  test("L7: học sinh tự huỷ đúng lúc QTV mở hộp Duyệt -> bấm Duyệt ra 409, thông báo đúng, nút Duyệt muộn hiện", async () => {
    const sr = await studentLogin(ctxS, "race");
    C.race = await checkout(sr);
    await A.goto(`${BASE}/${C.race}`);
    await expect(A.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeVisible({ timeout: 30_000 });
    await A.getByRole("button", { name: "Duyệt: đã nhận tiền" }).click();
    const d = dialog(A, /Duyệt đơn/);
    await d.getByRole("checkbox", { name: /Đã nhận đủ/ }).check();
    // học sinh tự huỷ qua giao diện
    await sr.goto(`${WEB}/tai-khoan/don-hang/${C.race}`);
    await sr.getByRole("button", { name: "Huỷ đơn" }).click();
    await sr.getByRole("alertdialog").or(sr.getByRole("dialog")).getByRole("button", { name: "Huỷ đơn" }).click();
    await expect(sr.getByText("Bạn đã huỷ đơn này")).toBeVisible({ timeout: 60_000 });
    // QTV bấm Duyệt
    await d.getByRole("button", { name: "Duyệt đơn" }).click();
    const notice = A.getByTestId("order-notice");
    await expect(notice).toContainText("Chưa duyệt: đơn vừa đổi trạng thái", { timeout: 20_000 });
    console.log("[QA] 409 notice:", (await notice.innerText()).replace(/\s+/g, " "));
    await expect(notice).toContainText(/Học sinh đã tự huỷ đơn lúc \d{2}:\d{2} \d{2}\/\d{2}\/\d{4}/);
    await expect(notice.getByRole("alert")).toBeVisible();
    await expect(A.getByRole("button", { name: "Duyệt muộn" })).toBeVisible();
    await expect(A.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toHaveCount(0);
    await sr.close();
  });

  for (const w of [375, 1280]) {
    test(`L8: hộp thoại bằng bàn phím ở ${w}px: Tab vòng trong hộp, Esc đóng, focus về nút mở, không cuộn ngang`, async () => {
      await A.setViewportSize({ width: w, height: 800 });
      const list = await apiCall(A, "GET", `/admin/orders?status[]=pending&payment_method=manual&sort=oldest&q=${encodeURIComponent("Fa8 HS Bảy")}`);
      const code = ((list.body?.data ?? []) as Array<{ code: string }>)[0]?.code;
      expect(code, "đơn Fa8 HS Bảy").toBeTruthy();
      await A.goto(`${BASE}/${code}`);
      await expect(A.getByRole("heading", { level: 1, name: new RegExp(code!) })).toBeVisible({ timeout: 30_000 });
      await noHScroll(A);
      for (const [openName, dlgName] of [
        ["Duyệt: đã nhận tiền", /Duyệt đơn/],
        ["Huỷ đơn", /Huỷ đơn VV/],
      ] as const) {
        const trigger = A.getByRole("button", { name: openName }).first();
        await trigger.focus();
        await A.keyboard.press("Enter");
        const dlg = dialog(A, dlgName);
        await expect(dlg).toBeVisible();
        await noHScroll(A);
        const box = (await dlg.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(w + 1);
        for (let i = 0; i < 14; i++) {
          await A.keyboard.press("Tab");
          expect(await A.evaluate(() => document.activeElement === document.body || !!document.activeElement?.closest("dialog")), `focus rời hộp: ${await A.evaluate(() => document.activeElement?.outerHTML.slice(0, 120))}`).toBe(true);
        }
        for (let i = 0; i < 14; i++) {
          await A.keyboard.press("Shift+Tab");
          expect(await A.evaluate(() => document.activeElement === document.body || !!document.activeElement?.closest("dialog")), `focus rời hộp: ${await A.evaluate(() => document.activeElement?.outerHTML.slice(0, 120))}`).toBe(true);
        }
        if (w === 375) {
          for (const b of await dlg.getByRole("button").all()) {
            const bb = await b.boundingBox();
            if (bb) expect(bb.height, `nút ${await b.innerText()}`).toBeGreaterThanOrEqual(44);
          }
        }
        await A.keyboard.press("Escape");
        await expect(dlg).toBeHidden();
        await expect(trigger).toBeFocused();
      }
      await A.setViewportSize({ width: 1280, height: 800 });
    });
  }

  test("L9: ghi chú nội bộ chứa HTML -> 422 hiện dưới ô, giữ nội dung, không thêm ghi chú", async () => {
    const list = await apiCall(A, "GET", `/admin/orders?status[]=pending&payment_method=manual&q=${encodeURIComponent("Fa8 HS Bảy")}`);
    const code = ((list.body?.data ?? []) as Array<{ code: string }>)[0]!.code;
    await A.goto(`${BASE}/${code}`);
    await expect(A.getByRole("list", { name: /Ghi chú nội bộ/ }).getByRole("listitem").first()).toBeVisible({ timeout: 30_000 });
    const before = await A.getByRole("list", { name: /Ghi chú nội bộ/ }).getByRole("listitem").count();
    const html = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    await A.getByLabel("Thêm ghi chú").fill(html);
    await A.getByRole("button", { name: "Lưu ghi chú" }).click();
    const err = A.locator('p[id$="-error"]').filter({ visible: true }).first();
    await expect(err).toBeVisible({ timeout: 20_000 });
    console.log("[QA] lỗi ghi chú HTML:", await err.innerText());
    await expect(A.getByLabel("Thêm ghi chú")).toHaveValue(html);
    await expect(A.getByRole("list", { name: /Ghi chú nội bộ/ }).getByRole("listitem")).toHaveCount(before);
    await expect(A.locator("script:not([src])", { hasText: "alert(1)" })).toHaveCount(0);
  });

  test("L10: tìm email/SĐT 31 lần/phút -> 429 hiện số giây chờ + nút Tải lại", async () => {
    await A.goto(BASE);
    await expect(A.getByRole("heading", { name: "Đơn hàng", level: 1 })).toBeVisible({ timeout: 30_000 });
    let first429 = -1;
    for (let i = 1; i <= 32; i++) {
      const r = await apiCall(A, "GET", `/admin/orders?status[]=pending&payment_method=manual&q=${encodeURIComponent(`qa${i}@example.com`)}`);
      if (r.status === 429 && first429 < 0) first429 = i;
    }
    console.log(`[QA] 429 đầu tiên ở lần ${first429}`);
    expect(first429).toBeGreaterThan(0);
    expect(first429).toBeLessThanOrEqual(31);
    await A.goto(`${BASE}?q=${encodeURIComponent("qa99@example.com")}`);
    const msg = A.getByRole("alert").filter({ hasText: /giây/ }).first();
    await expect(msg).toBeVisible({ timeout: 20_000 });
    console.log("[QA] 429 UI:", (await msg.innerText()).replace(/\s+/g, " "));
    await expect(A.getByRole("button", { name: /Tải lại/ })).toBeVisible();
  });
});
