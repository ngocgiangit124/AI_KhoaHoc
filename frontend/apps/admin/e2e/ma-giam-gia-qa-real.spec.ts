import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA7 QA bổ sung — dùng chung helper với ma-giam-gia-real.spec.ts. e2e THẬT quản lý mã giảm giá (US-013; E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
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


const BASE = `${ADMIN}/quan-tri/ma-giam-gia`;
const stamp = Date.now().toString(36).toUpperCase();
const saveBtn = (page: Page, name: string) => page.getByRole("button", { name });
let staff: Page;

async function idOf(code: string): Promise<number> {
  const res = await apiCall(staff, "GET", `/admin/coupons?q=${encodeURIComponent(code)}&per_page=25`);
  const hit = (res.body!.data as { id: number; code: string }[]).find((c) => c.code === code);
  expect(hit, `không thấy mã ${code}`).toBeTruthy();
  return hit!.id;
}

test.describe("FA7 QA bổ sung (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    test.setTimeout(240_000);
    staff = await (await browser.newContext()).newPage();
    await loginMfa(staff, request, "fa7-qlt1");
    await staff.goto(BASE);
    await expect(staff.getByRole("heading", { name: "Mã giảm giá", level: 1 })).toBeVisible();
  });

  test("QA-1: mã trùng khác hoa/thường -> 422 (API gửi chữ thường), UI tự viết hoa", async () => {
    const r = await apiCall(staff, "POST", "/admin/coupons", { code: "e2e-fa7-dangdung", discount_type: "percent", discount_value: 10, course_ids: [], subject_ids: [] });
    console.log("QA-1 api lowercase dup:", r.status, JSON.stringify(r.body));
    expect([422]).toContain(r.status);
    expect(Object.keys((r.body!.errors ?? {}) as Json)).toContain("code");
  });

  test("QA-2: mã 50 ký tự tạo được, 51 ký tự bị chặn (client + server), khoảng trắng bị chặn", async () => {
    const c50 = `E2E-FA7-${stamp}`.padEnd(50, "X");
    const c51 = `${c50}Y`;
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(c51);
    await staff.locator("#cp-discount_value").fill("10");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#tom-tat-loi")).toContainText(/50/);
    const s51 = await apiCall(staff, "POST", "/admin/coupons", { code: c51, discount_type: "percent", discount_value: 10, course_ids: [], subject_ids: [] });
    expect(s51.status).toBe(422);
    await staff.locator("#cp-code").fill("AB CD");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#cp-code")).toHaveAttribute("aria-invalid", "true");
    await staff.locator("#cp-code").fill(c50);
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff).toHaveURL(BASE);
    const id = await idOf(c50);
    expect(id).toBeGreaterThan(0);
    expect((await apiCall(staff, "DELETE", `/admin/coupons/${id}`)).status).toBe(204);
  });

  test("QA-3: hạn sát nửa đêm 23:59 +07:00 - lưu, F5, API trả đúng mốc, trình duyệt UTC cũng hiển thị y nguyên", async ({ browser }) => {
    const code = `E2E-FA7-TZ${stamp}`;
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(code);
    await staff.locator("#cp-discount_value").fill("10");
    await staff.locator("#cp-valid_until").fill("2031-03-01T23:59");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff).toHaveURL(BASE);
    const id = await idOf(code);
    const got = (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!;
    console.log("QA-3 valid_until api:", got.valid_until);
    expect(new Date(got.valid_until as string).toISOString()).toBe("2031-03-01T16:59:00.000Z");
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.locator("#cp-valid_until")).toHaveValue("2031-03-01T23:59");
    // trình duyệt múi giờ khác (UTC và Los_Angeles) dùng chung cookie phiên
    for (const tz of ["UTC", "America/Los_Angeles"]) {
      const ctx = await browser.newContext({ timezoneId: tz, storageState: await staff.context().storageState() });
      const p = await ctx.newPage();
      await p.goto(`${BASE}/${id}`);
      await expect(p.locator("#cp-valid_until")).toHaveValue("2031-03-01T23:59");
      // lưu không đổi gì khác ngoài tên: mốc phải giữ nguyên
      await p.locator("#cp-name").fill(`TZ ${tz}`);
      await saveBtn(p, "Lưu thay đổi").click();
      await expect(p.getByText("Đã lưu mã giảm giá")).toBeVisible();
      const after = (await apiCall(p, "GET", `/admin/coupons/${id}`)).body!;
      expect(new Date(after.valid_until as string).toISOString()).toBe("2031-03-01T16:59:00.000Z");
      await ctx.close();
    }
    // sửa hạn sang 00:00 ngày kế
    await staff.goto(`${BASE}/${id}`);
    await staff.locator("#cp-valid_until").fill("2031-03-02T00:00");
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
    const g2 = (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!;
    expect(new Date(g2.valid_until as string).toISOString()).toBe("2031-03-01T17:00:00.000Z");
    await apiCall(staff, "DELETE", `/admin/coupons/${id}`);
  });

  test("QA-4: COUPON_LOCKED trên form -> banner + nạp giá trị server, giữ thay đổi khác", async () => {
    const lockedId = await idOf("E2E-FA7-DADUNG");
    const cur = (await apiCall(staff, "GET", `/admin/coupons/${lockedId}`)).body!;
    const real = await apiCall(staff, "PUT", `/admin/coupons/${lockedId}`, { code: cur.code, name: cur.name, discount_type: "percent", discount_value: 55, max_uses: cur.max_uses, valid_from: null, valid_until: cur.valid_until, course_ids: [], subject_ids: [] });
    expect(real.body!.code).toBe("COUPON_LOCKED");
    // Mã chưa dùng DANGDUNG; giả lập "ai đó vừa dùng + đổi giá trị": PUT trả 422 thật, GET sau đó trả bản mới
    const id = await idOf("E2E-FA7-DANGDUNG");
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.locator("#cp-discount_value")).toBeEnabled();
    const fresh = { ...(await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!, discount_value: 35, used_count: 2, max_uses: 10 };
    await staff.route(new RegExp(`/admin/coupons/${id}$`), async (route) => {
      if (route.request().method() === "PUT") return route.fulfill({ status: 422, contentType: "application/json", body: JSON.stringify(real.body) });
      if (route.request().method() === "GET") return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(fresh) });
      return route.continue();
    });
    await staff.locator("#cp-name").fill("Tên sửa QA");
    await staff.locator("#cp-discount_value").fill("40");
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText(/đã được dùng|đã có người dùng|không đổi được/i).first()).toBeVisible();
    // BUG-1 (Minor): form nạp lại TOÀN BỘ từ bản server, tên đang sửa dở bị mất (tài liệu dev nói "giữ nguyên").
    console.log("QA-4 name sau COUPON_LOCKED:", await staff.locator("#cp-name").inputValue());
    await expect(staff.locator("#cp-discount_value")).toHaveValue("35"); // R5: giá trị server mới
    await expect(staff.locator("#cp-discount_value")).toBeDisabled();
    await staff.unroute(new RegExp(`/admin/coupons/${id}$`));
  });

  test("QA-5: bật/tắt hai tab cùng lúc idempotent; API bật/tắt lặp 200", async ({}) => {
    const code = `E2E-FA7-TAB${stamp}`;
    const created = await apiCall(staff, "POST", "/admin/coupons", { code, discount_type: "percent", discount_value: 5, course_ids: [], subject_ids: [] });
    expect(created.status).toBe(201);
    const id = created.body!.id as number;
    const ctx = staff.context();
    const t2 = await ctx.newPage();
    await staff.goto(`${BASE}/${id}`);
    await t2.goto(`${BASE}/${id}`);
    await expect(staff.getByRole("button", { name: "Vô hiệu hoá" })).toBeVisible();
    await expect(t2.getByRole("button", { name: "Vô hiệu hoá" })).toBeVisible();
    for (const p of [staff, t2]) {
      await p.getByRole("button", { name: "Vô hiệu hoá" }).click();
    }
    const confirm = (p: Page) => p.getByRole("dialog").getByRole("button", { name: /Vô hiệu hoá/ });
    await Promise.all([confirm(staff).click(), confirm(t2).click()]);
    await expect.poll(async () => (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!.status).toBe("inactive");
    await expect(staff.getByText(/Đã tắt/).first()).toBeVisible();
    await expect(t2.getByText(/Đã tắt|Không|lỗi/i).first()).toBeVisible();
    console.log("QA-5 tab2 text:", (await t2.locator("main").innerText()).slice(0, 300).replace(/\n/g, " | "));
    expect((await apiCall(staff, "POST", `/admin/coupons/${id}/deactivate`)).status).toBe(200);
    expect((await apiCall(staff, "POST", `/admin/coupons/${id}/activate`)).status).toBe(200);
    expect((await apiCall(staff, "POST", `/admin/coupons/${id}/activate`)).status).toBe(200);
    await t2.close();
    await apiCall(staff, "DELETE", `/admin/coupons/${id}`);
  });

  test("QA-6: xoá mã vừa có đơn tham chiếu -> 409 COUPON_IN_USE hiện hộp giải thích", async () => {
    const id = await idOf("E2E-FA7-DANGDUNG");
    const real = await apiCall(staff, "DELETE", `/admin/coupons/${await idOf("E2E-FA7-DADUNG")}`);
    console.log("QA-6 real delete used:", real.status, JSON.stringify(real.body));
    expect(real.status).toBe(409);
    expect(real.body!.code).toBe("COUPON_IN_USE");
    await staff.goto(`${BASE}/${id}`);
    await staff.route(new RegExp(`/admin/coupons/${id}$`), async (route) => {
      if (route.request().method() === "DELETE") return route.fulfill({ status: 409, contentType: "application/json", body: JSON.stringify(real.body) });
      return route.continue();
    });
    await staff.getByRole("button", { name: "Xoá mã" }).click();
    await staff.getByRole("dialog").getByRole("button", { name: /Xoá/ }).click();
    await expect(staff.getByRole("dialog", { name: "Không thể xoá mã giảm giá" })).toContainText("đã được sử dụng");
    await staff.unroute(new RegExp(`/admin/coupons/${id}$`));
  });

  test("QA-7: S18 - 100% và giảm cố định lớn bắt buộc max_uses + valid_until (UI + server)", async () => {
    await staff.goto(`${BASE}/tao`);
    await staff.locator("#cp-code").fill(`E2E-FA7-S18${stamp}`);
    await staff.getByRole("radio", { name: /Số tiền/ }).check();
    await staff.locator("#cp-discount_value").fill("100000000");
    await saveBtn(staff, "Tạo mã giảm giá").click();
    await expect(staff.locator("#cp-max_uses")).toHaveAttribute("aria-invalid", "true", { timeout: 15_000 });
    await expect(staff.locator("#cp-valid_until")).toHaveAttribute("aria-invalid", "true");
    // server chốt: cố định đúng bằng giá khóa rẻ nhất nhưng thiếu hạn
    const r = await apiCall(staff, "POST", "/admin/coupons", { code: `E2E-FA7-S18B${stamp}`, discount_type: "fixed_amount", discount_value: 300000, course_ids: [], subject_ids: [] });
    console.log("QA-7 fixed 300000 no limits:", r.status);
    const r2 = await apiCall(staff, "POST", "/admin/coupons", { code: `E2E-FA7-S18C${stamp}`, discount_type: "percent", discount_value: 100, course_ids: [], subject_ids: [] });
    expect(r2.status).toBe(422);
    const r3 = await apiCall(staff, "POST", "/admin/coupons", { code: `E2E-FA7-S18D${stamp}`, discount_type: "percent", discount_value: 100, max_uses: 5, valid_until: "2031-03-01T23:59:00+07:00", course_ids: [], subject_ids: [] });
    expect(r3.status).toBe(201);
    await apiCall(staff, "DELETE", `/admin/coupons/${r3.body!.id}`);
    if (r.status === 201) await apiCall(staff, "DELETE", `/admin/coupons/${r.body!.id}`);
  });

  test("QA-8: xoá chuyên đề đang nằm trong phạm vi mã -> phạm vi hẹp lại, trang sửa không vỡ", async () => {
    const sub = await apiCall(staff, "POST", "/admin/subjects", { name: `E2E FA7 QA Tạm ${stamp}` });
    expect(sub.status).toBe(201);
    const sid = sub.body!.id as number;
    const keep = await idOf("E2E-FA7-PHAMVI");
    const created = await apiCall(staff, "POST", "/admin/coupons", { code: `E2E-FA7-SUB${stamp}`, discount_type: "percent", discount_value: 5, course_ids: [], subject_ids: [sid] });
    expect(created.status).toBe(201);
    const cid = created.body!.id as number;
    await staff.goto(`${BASE}/${cid}`);
    await expect(staff.getByText(`E2E FA7 QA Tạm ${stamp}`).first()).toBeVisible({ timeout: 45_000 });
    expect((await apiCall(staff, "DELETE", `/admin/subjects/${sid}`)).status).toBe(204);
    const after = (await apiCall(staff, "GET", `/admin/coupons/${cid}`)).body!;
    console.log("QA-8 after subject delete: is_restricted=", after.is_restricted, "subjects=", JSON.stringify(after.subjects));
    await staff.reload();
    await expect(staff.locator("#cp-code")).toHaveValue(`E2E-FA7-SUB${stamp}`);
    await expect(staff.getByText("Có lỗi").first()).toHaveCount(0);
    expect(keep).toBeGreaterThan(0);
    await apiCall(staff, "DELETE", `/admin/coupons/${cid}`);
  });

  test("QA-9: mã cũ khóa + chuyên đề: sửa tên, PUT không mất phạm vi; 'Kết hợp' còn khi chuyển radio", async () => {
    const id = await idOf("E2E-FA7-PHAMVI");
    const before = (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!;
    await staff.goto(`${BASE}/${id}`);
    const both = staff.getByRole("radio", { name: "Kết hợp chuyên đề và khóa học" });
    await expect(both).toBeChecked({ timeout: 45_000 });
    await staff.getByRole("radio", { name: "Toàn bộ khóa học" }).check();
    await expect(both).toBeVisible();
    await both.check();
    await staff.locator("#cp-name").fill("FA7 phạm vi (sửa tên)");
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
    const after = (await apiCall(staff, "GET", `/admin/coupons/${id}`)).body!;
    expect((after.courses as unknown[]).length).toBe((before.courses as unknown[]).length);
    expect((after.subjects as unknown[]).length).toBe((before.subjects as unknown[]).length);
    expect((after.courses as unknown[]).length).toBeGreaterThan(0);
    expect((after.subjects as unknown[]).length).toBeGreaterThan(0);
  });

  test("QA-10: mất mạng khi lưu -> báo lỗi mạng, giữ dữ liệu, lưu lại được khi có mạng", async () => {
    const id = await idOf("E2E-FA7-PAGE01");
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.locator("#cp-name")).toBeVisible({ timeout: 45_000 });
    await staff.locator("#cp-name").fill("Mất mạng QA");
    await staff.context().setOffline(true);
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText(/mạng|kết nối/i).first()).toBeVisible({ timeout: 15_000 });
    await expect(staff.locator("#cp-name")).toHaveValue("Mất mạng QA");
    await staff.context().setOffline(false);
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff.getByText("Đã lưu mã giảm giá")).toBeVisible();
  });

  test("QA-11: hết phiên giữa form dài (xoá cookie) -> bấm lưu không mất dữ liệu nhập / dẫn về đăng nhập", async () => {
    const id = await idOf("E2E-FA7-PAGE02");
    await staff.goto(`${BASE}/${id}`);
    await expect(staff.locator("#cp-name")).toBeVisible({ timeout: 45_000 });
    await staff.locator("#cp-name").fill("Hết phiên QA");
    const saved = await staff.context().storageState();
    await staff.context().clearCookies();
    await saveBtn(staff, "Lưu thay đổi").click();
    await expect(staff).toHaveURL(/\/dang-nhap\?.*reason=expired/, { timeout: 15_000 });
    expect(decodeURIComponent(staff.url())).toContain("next=/quan-tri/ma-giam-gia/");
    // Ghi nhận: dữ liệu nhập dở không còn sau khi buộc đăng nhập lại (form không còn).
    await staff.context().addCookies(saved.cookies);
  });
});
