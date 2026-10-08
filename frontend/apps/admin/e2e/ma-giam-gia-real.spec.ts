import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA7 — e2e THẬT quản lý mã giảm giá (US-013; E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Chạy `frontend/apps/admin/e2e/seed-e2e-coupons.sh --reset` TRƯỚC MỖI LẦN chạy (spec tạo/sửa/xoá mã), `--clean` sau cùng.
 * 1 lần đăng nhập MFA (e2e-fa7-qlt1); giáo viên đăng nhập thẳng. Chạy với `--workers=1 --retries=0`.
 * Phủ: menu + danh sách + lọc trạng thái/tìm/phân trang trên URL, tạo (422 trùng mã dưới ô + hộp tóm tắt, % > 100, S18, chặn bấm kép),
 * phạm vi khóa/chuyên đề, sửa mã đã dùng (khoá trường), COUPON_LOCKED/S18 từ server, bật/tắt, xoá (+ không xoá được mã đã dùng),
 * xác nhận rời trang, 404, giáo viên 403, 375px.
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
const BASE = `${ADMIN}/quan-tri/ma-giam-gia`;
const stamp = Date.now().toString(36).toUpperCase();
const NEW_CODE = `E2E-FA7-NEW${stamp}`;
const FREE_CODE = `E2E-FA7-FREE${stamp}`;
const SCOPE_CODE = `E2E-FA7-SC${stamp}`;
const row = (page: Page, code: string) => page.getByRole("row").filter({ hasText: code });
const codeLink = (page: Page, code: string) => page.getByRole("link", { name: code, exact: true });
const saveBtn = (page: Page, name: string) => page.getByRole("button", { name });

let staff: Page;
let teacher: Page;
const couponIds: Record<string, number> = {};

async function idOf(code: string): Promise<number> {
  if (couponIds[code]) return couponIds[code];
  const res = await apiCall(staff, "GET", `/admin/coupons?q=${encodeURIComponent(code)}&per_page=25`);
  const hit = (res.body!.data as { id: number; code: string }[]).find((c) => c.code === code);
  expect(hit, `không thấy mã ${code}`).toBeTruthy();
  couponIds[code] = hit!.id;
  return hit!.id;
}

test.describe("FA7 mã giảm giá (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000); // lần đầu dev server biên dịch route (máy yếu)
    staff = await (await browser.newContext()).newPage();
    await loginMfa(staff, request, "fa7-qlt1");
    await staff.goto(BASE);
    await expect(staff.getByRole("heading", { name: "Mã giảm giá", level: 1 })).toBeVisible();
  });

  test("menu có 'Mã giảm giá'; danh sách hiện mã seed với giảm/trạng thái/lượt dùng", async () => {
    await staff.goto(`${ADMIN}/quan-tri`);
    await nav(staff).getByRole("link", { name: "Mã giảm giá" }).click();
    await expect(staff).toHaveURL(BASE);
    await staff.getByRole("searchbox", { name: "Tìm theo mã hoặc tên" }).fill("E2E-FA7-DADUNG");
    await expect(staff).toHaveURL(/q=E2E-FA7-DADUNG/);
    const r = row(staff, "E2E-FA7-DADUNG");
    await expect(r).toBeVisible();
    await expect(r).toContainText("30%");
    await expect(r).toContainText("Đang hoạt động");
    await expect(r.getByRole("progressbar")).toHaveAttribute("aria-valuetext", "3/10");
  });

  test("AC6: lọc trạng thái nằm trên URL, F5 giữ nguyên", async () => {
    await staff.goto(`${BASE}?q=E2E-FA7-`);
    await expect(staff.getByRole("row").filter({ hasText: "E2E-FA7-PAGE" }).first()).toBeVisible();
    await staff.getByRole("link", { name: "Hết lượt" }).click();
    await expect(staff).toHaveURL(/state=exhausted/);
    await expect(row(staff, "E2E-FA7-HETLUOT")).toBeVisible();
    await expect(row(staff, "E2E-FA7-HETLUOT")).toContainText("Hết lượt");
    await expect(row(staff, "E2E-FA7-PAGE01")).toHaveCount(0);
    await staff.reload();
    await expect(row(staff, "E2E-FA7-HETLUOT")).toBeVisible();
    for (const [tab, code, label] of [
      ["Hết hạn", "E2E-FA7-HETHAN", "Hết hạn"],
      ["Sắp diễn ra", "E2E-FA7-SAPTOI", "Sắp diễn ra"],
      ["Đã tắt", "E2E-FA7-DATAT", "Đã tắt"],
    ] as const) {
      await staff.getByRole("link", { name: tab, exact: true }).click();
      await expect(row(staff, code)).toContainText(label);
    }
    await staff.goto(`${BASE}?q=E2E-FA7-ZZZ-KHONG-CO`);
    await expect(staff.getByText("Không có mã nào khớp bộ lọc")).toBeVisible();
  });

  test("phân trang 25/trang trên URL (F5 giữ)", async () => {
    await staff.goto(`${BASE}?q=E2E-FA7-PAGE`);
    await expect(staff.getByRole("row").filter({ hasText: "E2E-FA7-PAGE" })).toHaveCount(25);
    await staff.getByRole("link", { name: "Trang 2", exact: true }).click();
    await expect(staff).toHaveURL(/page=2/);
    await expect(staff.getByRole("row").filter({ hasText: "E2E-FA7-PAGE" })).toHaveCount(1);
    await staff.reload();
    await expect(staff.getByRole("row").filter({ hasText: "E2E-FA7-PAGE" })).toHaveCount(1);
  });

  test("tạo: lỗi client (rỗng, % > 100) không gọi API; trùng mã → 422 dưới ô mã + hộp tóm tắt, giữ dữ liệu", async () => {
    const posts: string[] = [];
    staff.on("request", (r) => r.method() === "POST" && r.url().endsWith("/admin/coupons") && posts.push(r.url()));
    await staff.goto(`${BASE}/tao`);
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.getByText(/Chưa lưu được — còn 2 chỗ cần sửa/)).toBeVisible();
    await expect(staff.locator("#cp-code")).toHaveAttribute("aria-invalid", "true");
    await staff.locator("#cp-code").fill("e2e-fa7-dangdung");
    await expect(staff.locator("#cp-code")).toHaveValue("E2E-FA7-DANGDUNG");
    await staff.locator("#cp-discount_value").fill("101");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.getByText("Giá trị giảm không được vượt quá 100%.").first()).toBeVisible();
    expect(posts).toHaveLength(0);
    await staff.locator("#cp-discount_value").fill("15");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#cp-code")).toHaveAttribute("aria-invalid", "true");
    await expect(staff.locator("#tom-tat-loi")).toContainText("Mã giảm giá đã tồn tại");
    await expect(staff.locator("#cp-code")).toHaveValue("E2E-FA7-DANGDUNG");
    await expect(staff.locator("#cp-discount_value")).toHaveValue("15");
    expect(posts).toHaveLength(1);
    staff.removeAllListeners("request");
  });

  test("AC1: tạo mã thành công (bấm kép chỉ 1 request) → về danh sách, mã active", async () => {
    const posts: string[] = [];
    staff.on("request", (r) => r.method() === "POST" && r.url().endsWith("/admin/coupons") && posts.push(r.url()));
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(NEW_CODE.toLowerCase());
    await staff.locator("#cp-name").fill("FA7 mã mới");
    await staff.locator("#cp-discount_value").fill("15");
    await staff.locator("#cp-max_uses").fill("5");
    await staff.locator("#cp-valid_until").fill("2031-12-31T23:59");
    await saveBtn(staff, "Tạo mã giảm giá").dblclick();
    await expect(staff).toHaveURL(BASE);
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
    expect(posts).toHaveLength(1);
    staff.removeAllListeners("request");
    await staff.goto(`${BASE}?q=${NEW_CODE}`);
    await expect(row(staff, NEW_CODE)).toContainText("15%");
    await expect(row(staff, NEW_CODE)).toContainText("Đang hoạt động");
    await expect(row(staff, NEW_CODE)).toContainText("31/12/2031");
    await expect(row(staff, NEW_CODE).getByRole("progressbar")).toHaveAttribute("aria-valuetext", "0/5");
  });

  test("S18: giảm 100% đòi giới hạn lượt + hạn dùng (client), rồi tạo được khi đủ", async () => {
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(FREE_CODE);
    await staff.locator("#cp-discount_value").fill("100");
    await expect(staff.getByText("Mã giảm hết giá trị đơn")).toBeVisible();
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#tom-tat-loi")).toContainText("bắt buộc có giới hạn lượt dùng và ngày hết hạn");
    await expect(staff.locator("#cp-max_uses")).toHaveAttribute("aria-invalid", "true");
    await expect(staff.locator("#cp-valid_until")).toHaveAttribute("aria-invalid", "true");
    await staff.locator("#cp-max_uses").fill("3");
    await staff.locator("#cp-valid_until").fill("2031-06-30T12:00");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff).toHaveURL(BASE);
  });

  test("AC5: ngày kết thúc trước bắt đầu bị chặn ở client", async () => {
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(`E2E-FA7-DATE${stamp}`);
    await staff.locator("#cp-discount_value").fill("10");
    await staff.locator("#cp-valid_from").fill("2031-02-01T00:00");
    await staff.locator("#cp-valid_until").fill("2031-01-01T00:00");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#tom-tat-loi")).toContainText("Ngày kết thúc phải sau ngày bắt đầu.");
  });

  test("AC8: phạm vi theo khóa + chuyên đề (kể cả chuyên đề ẩn): lưu rồi chi tiết hiện đúng danh sách", async () => {
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(SCOPE_CODE);
    await staff.locator("#cp-discount_value").fill("10");
    await staff.getByRole("radio", { name: "Theo chuyên đề cụ thể" }).check();
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#tom-tat-loi")).toContainText("Chọn ít nhất 1 chuyên đề");
    await staff.getByRole("checkbox", { name: "E2E FA7 Chuyên đề" }).check();
    await expect(staff.getByText("(đang ẩn)").first()).toBeVisible();
    await staff.getByRole("checkbox", { name: /E2E FA7 Ẩn/ }).check();
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff).toHaveURL(BASE);
    await staff.goto(`${BASE}?q=${SCOPE_CODE}`);
    await expect(row(staff, SCOPE_CODE)).toContainText("2 chuyên đề");
    await codeLink(staff, SCOPE_CODE).click();
    await expect(staff.getByRole("heading", { name: "Phạm vi đang lưu" })).toBeVisible({ timeout: 45_000 }); // lần đầu dev server biên dịch route [id]
    const aside = staff.locator("section", { has: staff.getByRole("heading", { name: "Phạm vi đang lưu" }) });
    await expect(aside).toContainText("E2E FA7 Chuyên đề");
    await expect(aside).toContainText("E2E FA7 Ẩn");
    // Đổi sang theo khóa: tìm khóa, chọn Khóa A, lưu; phạm vi chỉ còn 1 khóa.
    await staff.getByRole("radio", { name: "Theo khóa học cụ thể" }).check();
    await staff.getByRole("searchbox", { name: "Tìm khóa học theo tên" }).fill("E2E FA7 Khóa A");
    await staff.getByRole("checkbox", { name: "E2E FA7 Khóa A" }).check();
    await expect(staff.getByRole("button", { name: "Bỏ khóa E2E FA7 Khóa A" })).toBeVisible();
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
    await expect(aside).toContainText("Khóa học (1)");
    await expect(aside).not.toContainText("Chuyên đề (");
  });

  test("sửa mã đã dùng: mã/loại/giá trị chỉ đọc; đổi tên + hạn lưu được; max_uses < đã dùng bị chặn", async () => {
    const id = await idOf("E2E-FA7-DADUNG");
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.locator("#cp-code")).toBeDisabled();
    await expect(staff.locator("#cp-discount_value")).toBeDisabled();
    await expect(staff.getByRole("radio", { name: "Phần trăm (%)" })).toBeDisabled();
    await expect(staff.getByText(/không đổi được mã, loại hoặc giá trị giảm/)).toBeVisible();
    await expect(staff.getByRole("button", { name: "Xoá mã" })).toBeDisabled();
    await expect(staff.getByRole("progressbar", { name: "Lượt đã dùng" })).toHaveAttribute("aria-valuetext", "3/10");
    await staff.locator("#cp-max_uses").fill("2");
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.locator("#tom-tat-loi")).toContainText("Không được nhỏ hơn số lượt đã dùng (3)");
    await staff.locator("#cp-max_uses").fill("12");
    await staff.locator("#cp-name").fill("FA7 đã dùng (sửa)");
    await staff.locator("#cp-valid_until").fill("2031-03-01T08:30");
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
    await staff.reload();
    await expect(staff.locator("#cp-name")).toHaveValue("FA7 đã dùng (sửa)");
    await expect(staff.locator("#cp-max_uses")).toHaveValue("12");
    await expect(staff.locator("#cp-valid_until")).toHaveValue("2031-03-01T08:30");
    await expect(staff.getByRole("progressbar", { name: "Lượt đã dùng" })).toHaveAttribute("aria-valuetext", "3/12");
  });

  test("hợp đồng thật: đổi giá trị mã đã dùng → 422 COUPON_LOCKED; fixed lớn thiếu giới hạn → 422 trên max_uses/valid_until", async () => {
    const id = await idOf("E2E-FA7-DADUNG");
    const cur = (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!;
    const put = await apiCall(staff, "PUT", `/admin/coupons/${id}`, {
      code: cur.code,
      name: cur.name,
      discount_type: "percent",
      discount_value: 50,
      max_uses: cur.max_uses,
      valid_from: null,
      valid_until: cur.valid_until,
      course_ids: [],
      subject_ids: [],
    });
    expect(put.status).toBe(422);
    expect(put.body!.code).toBe("COUPON_LOCKED");
    expect((put.body!.errors as { fields: string[] }).fields).toContain("discount_value");
    const post = await apiCall(staff, "POST", "/admin/coupons", { code: `E2E-FA7-BIG${stamp}`, discount_type: "fixed_amount", discount_value: 100000000, course_ids: [], subject_ids: [] });
    expect(post.status).toBe(422);
    expect(Object.keys(post.body!.errors as Record<string, unknown>)).toEqual(expect.arrayContaining(["max_uses", "valid_until"]));
  });

  test("AC3: vô hiệu hoá có xác nhận → 'Đã tắt'; bật lại → 'Đang hoạt động'", async () => {
    const id = await idOf(NEW_CODE);
    await staff.goto(`${BASE}/${id}`);
    await staff.getByRole("button", { name: "Vô hiệu hoá" }).click();
    await expect(staff.getByText(`Vô hiệu hoá mã ${NEW_CODE}?`)).toBeVisible();
    await staff.getByRole("dialog").getByRole("button", { name: "Vô hiệu hoá" }).click();
    await expect(staff.getByText("Đã vô hiệu hoá mã giảm giá")).toBeVisible();
    await expect(staff.getByRole("heading", { level: 1 }).locator("xpath=..").getByText("Đã tắt")).toBeVisible();
    await staff.goto(`${BASE}?state=inactive&q=${NEW_CODE}`);
    await expect(row(staff, NEW_CODE)).toContainText("Đã tắt");
    await codeLink(staff, NEW_CODE).click();
    await staff.getByRole("button", { name: "Bật lại mã" }).click();
    await staff.getByRole("dialog").getByRole("button", { name: "Bật lại" }).click();
    await expect(staff.getByText("Đã bật lại mã giảm giá")).toBeVisible();
    await expect(staff.getByRole("heading", { level: 1 }).locator("xpath=..").getByText("Đang hoạt động")).toBeVisible();
  });

  test("xác nhận rời trang khi form còn thay đổi chưa lưu (Ở lại / Bỏ thay đổi)", async () => {
    const id = await idOf(NEW_CODE);
    await staff.goto(`${BASE}/${id}`);
    await staff.locator("#cp-name").fill("Đổi tên chưa lưu");
    await nav(staff).getByRole("link", { name: "Khóa học" }).click();
    await expect(staff.getByText("Còn thay đổi chưa lưu")).toBeVisible();
    await staff.getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(staff).toHaveURL(`${BASE}/${id}`);
    await expect(staff.locator("#cp-name")).toHaveValue("Đổi tên chưa lưu");
    await nav(staff).getByRole("link", { name: "Khóa học" }).click();
    await staff.getByRole("button", { name: "Bỏ thay đổi" }).click();
    await expect(staff).toHaveURL(/\/quan-tri\/khoa-hoc/);
  });

  test("xoá: mã đã dùng không xoá được; mã chưa dùng xoá sau xác nhận, mở lại → không tìm thấy", async () => {
    const delUsed = await apiCall(staff, "DELETE", `/admin/coupons/${await idOf("E2E-FA7-DADUNG")}`);
    expect(delUsed.status).toBe(409);
    expect(delUsed.body!.code).toBe("COUPON_IN_USE");
    const id = await idOf(FREE_CODE);
    await staff.goto(`${BASE}/${id}`);
    await staff.getByRole("button", { name: "Xoá mã" }).click();
    await staff.getByRole("dialog").getByRole("button", { name: "Xoá mã" }).click();
    await expect(staff).toHaveURL(BASE);
    await expect(staff.getByText("Đã xoá mã giảm giá")).toBeVisible();
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.getByTestId("coupon-not-found")).toBeVisible();
  });

  test("id không hợp lệ → 404 của Next; id không tồn tại → 'Không tìm thấy mã giảm giá'", async () => {
    await staff.goto(`${BASE}/abc`);
    await expect(staff.getByText(/không tìm thấy|404/i).first()).toBeVisible();
    await staff.goto(`${BASE}/99999999`);
    await expect(staff.getByText("Không tìm thấy mã giảm giá")).toBeVisible();
  });

  test("giáo viên: không có mục menu; mở thẳng /ma-giam-gia và /tao → trang không có quyền", async ({ browser }) => {
    teacher = await (await browser.newContext()).newPage();
    await loginTeacher(teacher, "fa7-gv1");
    await expect(nav(teacher).getByRole("link", { name: "Mã giảm giá" })).toHaveCount(0);
    for (const path of ["", "/tao", "/1"]) {
      await teacher.goto(`${BASE}${path}`);
      await expect(teacher.getByTestId("forbidden-view")).toBeVisible();
    }
    const api = await apiCall(teacher, "GET", "/admin/coupons");
    expect(api.status).toBe(403);
  });

  test("375px: danh sách và form không tràn ngang, nút ≥ 44px", async () => {
    await staff.setViewportSize({ width: 375, height: 800 });
    await staff.goto(`${BASE}?q=E2E-FA7-DANGDUNG`);
    await expect(row(staff, "E2E-FA7-DANGDUNG")).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    const edit = staff.getByRole("link", { name: "Sửa mã E2E-FA7-DANGDUNG" });
    expect((await edit.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    await staff.goto(`${BASE}/tao`);
    await expect(staff.locator("#cp-code")).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    for (const sel of ["#cp-code", "#cp-discount_value", "#cp-valid_until", "#cp-max_uses"]) {
      expect((await staff.locator(sel).boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    }
    expect((await saveBtn(staff, "Tạo mã giảm giá").boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    await staff.getByRole("radio", { name: "Theo khóa học cụ thể" }).check();
    await expect(staff.getByRole("searchbox", { name: "Tìm khóa học theo tên" })).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    await staff.goto(`${BASE}/${await idOf("E2E-FA7-DADUNG")}`);
    await expect(staff.locator("#cp-code")).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
  });
});
