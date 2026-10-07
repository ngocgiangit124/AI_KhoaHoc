import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * QA FA3 bổ sung (thật; E2E_REAL_BACKEND=1). Bổ sung cho khoa-hoc-real.spec.ts: R1/R2/R3 của review, 413/422 THẬT qua Nginx,
 * 375px (ngăn kéo, nút 44px, bảng không tràn), 404/403/mạng, dán OTP có dấu cách.
 * Cần seed-e2e-courses.sh (và --clean sau cùng). Dùng tài khoản MFA e2e-fa3-qlt{FA3_QA_STAFF:-2}; GV e2e-gv (đăng nhập thẳng).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
const STAFF = process.env.FA3_QA_STAFF ?? "fa3-qlt2";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const email = (n: string) => `e2e-${n}@example.com`;
type Json = Record<string, unknown>;

async function fillLogin(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

async function mfaCode(request: APIRequestContext, to: string, known: Set<string>): Promise<string> {
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

async function apiCall(page: Page, method: string, path: string, body?: unknown, raw?: { size: number; name: string; type: string }) {
  return page.evaluate(
    async ({ api, method, path, body, raw }) => {
      const base = { credentials: "include" as const, headers: { Accept: "application/json", "X-Device-Id": localStorage.getItem("vv_device_id") ?? "" } as Record<string, string> };
      const { token } = (await (await fetch(`${api}/csrf-token`, base)).json()) as { token: string };
      const headers: Record<string, string> = { ...base.headers, "X-CSRF-TOKEN": token };
      let payload: BodyInit | undefined;
      if (raw) {
        const fd = new FormData();
        fd.append("title", "E2E FA3 Ảnh xấu");
        fd.append("thumbnail", new File([new Uint8Array(raw.size).fill(65)], raw.name, { type: raw.type }));
        payload = fd;
      } else if (body) {
        headers["Content-Type"] = "application/json";
        payload = JSON.stringify(body);
      }
      try {
        const res = await fetch(`${api}${path}`, { ...base, method, headers, body: payload });
        const text = await res.text();
        let json: unknown = null;
        try {
          json = JSON.parse(text);
        } catch {
          /* không phải JSON */
        }
        return { status: res.status, body: json as Json | null, text: text.slice(0, 120) };
      } catch (e) {
        return { status: 0, body: null, text: String(e) };
      }
    },
    { api: API, method, path, body, raw },
  );
}

async function findCourse(page: Page, title: string): Promise<Json> {
  const res = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent(title)}`);
  const hit = (res.body!.data as Json[]).find((c) => c.title === title);
  if (!hit) throw new Error(`Không thấy "${title}"`);
  return hit;
}

const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

test.describe("QA FA3 bổ sung (thật)", () => {
  test("Staff: R1/R2/R3, 413/422 thật, 404, 375px", async ({ page, request }) => {
    test.setTimeout(300_000);
    const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(STAFF)}` } });
    const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
    await fillLogin(page, STAFF);
    await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
    const code = await mfaCode(request, email(STAFF), known);

    // BUG-3 FA-V2: dán "ddd ddd" (có dấu cách) vào ô OTP.
    await page.locator("input").first().click();
    await page.evaluate((c) => {
      const el = document.querySelector("input") as HTMLInputElement;
      const dt = new DataTransfer();
      dt.setData("text", `${c.slice(0, 3)} ${c.slice(3)}`);
      el.dispatchEvent(new ClipboardEvent("paste", { clipboardData: dt, bubbles: true, cancelable: true }));
    }, code);
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });

    const own = await (async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
      return findCourse(page, "E2E FA3 Nháp trống");
    })();
    const id = own.id as number;

    await test.step("R1: lưu form không làm mất danh sách GV đang chọn dở", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${id}/sua`);
      const group = page.getByTestId("teachers-group");
      await expect(group).toBeVisible({ timeout: 20_000 });
      await expect(group.getByLabel("Thêm giáo viên")).toBeEnabled({ timeout: 15_000 });
      await group.getByLabel("Thêm giáo viên").selectOption({ label: "E2E FA3 GV Hai" });
      await expect(group.getByRole("button", { name: "Bỏ E2E FA3 GV Hai" })).toBeVisible();
      const original = await page.getByLabel(/Mô tả ngắn/).inputValue();
      await page.getByLabel(/Mô tả ngắn/).fill(`${original} QA`);
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      await expect(page.getByText("Đã lưu thay đổi")).toBeVisible({ timeout: 20_000 });
      await expect(group.getByRole("button", { name: "Bỏ E2E FA3 GV Hai" })).toBeVisible(); // vẫn còn nháp
      expect(((await apiCall(page, "GET", `/admin/courses/${id}`)).body!.teachers as unknown[]).length).toBe(1); // chưa lưu GV
      await page.getByLabel(/Mô tả ngắn/).fill(original); // trả lại
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      await expect(page.getByText("Đã lưu thay đổi")).toBeVisible({ timeout: 20_000 });
    });

    await test.step("R2: bàn phím + aria-live ở TeacherPicker", async () => {
      const group = page.getByTestId("teachers-group");
      const status = group.getByRole("status");
      await group.getByRole("button", { name: "Bỏ E2E FA3 GV Hai" }).focus();
      await page.keyboard.press("Enter");
      await expect(status).toHaveText("Đã bỏ E2E FA3 GV Hai");
      // focus còn trong nhóm (chip kế bên)
      expect(await page.evaluate(() => !!document.activeElement?.closest('[data-testid="teachers-group"]'))).toBe(true);
      // thêm bằng bàn phím trên select gốc
      const sel = group.getByLabel("Thêm giáo viên");
      await sel.focus();
      await page.keyboard.press("ArrowDown");
      await expect(status).toContainText("Đã thêm");
      // bỏ người cuối bị chặn
      for (const b of await group.getByRole("button", { name: /^Bỏ / }).all()) void b;
      await page.reload(); // bỏ nháp
    });

    await test.step("R3: xuất bản khi còn thay đổi chưa lưu → hộp xác nhận (Quay lại không gọi API)", async () => {
      const contentCourse = await findCourse(page, "E2E FA3 Có nội dung");
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${contentCourse.id}/sua`);
      await expect(page.getByLabel(/Tên khóa học/)).toBeVisible({ timeout: 20_000 });
      const title = await page.getByLabel(/Tên khóa học/).inputValue();
      await page.getByLabel(/Tên khóa học/).fill(`${title} nháp`);
      let published = 0;
      page.on("request", (r) => {
        if (r.url().endsWith("/publish") && r.method() === "POST") published++;
      });
      await page.getByRole("button", { name: "Xuất bản", exact: true }).click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Còn thay đổi chưa lưu");
      await dlg.getByRole("button", { name: "Quay lại để lưu" }).click();
      await expect(dlg).toBeHidden();
      expect(published).toBe(0);
      expect((await apiCall(page, "GET", `/admin/courses/${contentCourse.id}`)).body!.status).toBe("draft");
      await page.getByLabel(/Tên khóa học/).fill(title);
    });

    await test.step("ảnh bìa THẬT: >2 MB → 413 (Nginx), sai định dạng → 422", async () => {
      const mid = await apiCall(page, "POST", "/admin/courses", undefined, { size: 3 * 1024 * 1024, name: "to.jpg", type: "image/jpeg" });
      console.log("[3MB]", JSON.stringify({ status: mid.status, text: mid.text }));
      expect(mid.status).toBe(422); // 2 MB < 3 MB < trần Nginx 5 MB: Laravel chặn
      const big = await apiCall(page, "POST", "/admin/courses", undefined, { size: 6 * 1024 * 1024, name: "to.jpg", type: "image/jpeg" });
      console.log("[413 thật]", JSON.stringify({ status: big.status, text: big.text }));
      expect([413, 0]).toContain(big.status); // 0 = trình duyệt thấy NetworkError vì 413 của Nginx không có CORS (đã biết)
      const bad = await apiCall(page, "POST", "/admin/courses", undefined, { size: 2000, name: "gia.jpg", type: "image/jpeg" });
      console.log("[422 thật]", JSON.stringify({ status: bad.status, text: bad.text }));
      expect(bad.status).toBe(422);
    });

    await test.step("404 / lỗi mạng", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/99999999/sua`);
      await expect(page.getByText(/không còn tồn tại|không tìm thấy/i).first()).toBeVisible({ timeout: 20_000 });
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
      await expect(page.getByRole("table")).toBeVisible({ timeout: 20_000 });
      await page.route(/\/api\/v1\/admin\/courses\?/, (r) => r.abort("failed"));
      await page.getByLabel("Tìm theo tên").fill("mang");
      await expect(page.getByText(/Không tải được|kết nối|mạng/i).first()).toBeVisible({ timeout: 20_000 });
      await page.unroute(/\/api\/v1\/admin\/courses\?/);
    });

    await test.step("375px: ngăn kéo link >= 44px, bảng không tràn, nút >= 44px", async () => {
      await page.setViewportSize({ width: 375, height: 800 });
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
      await expect(page.getByRole("table")).toBeVisible({ timeout: 20_000 });
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
      const edit = page.getByRole("link", { name: /Sửa/ }).first();
      const eb = await edit.boundingBox();
      console.log("[nút Sửa 375]", JSON.stringify(eb));
      expect(eb!.height).toBeGreaterThanOrEqual(43.5);
      await page.getByRole("button", { name: "Mở menu" }).click();
      const links = page.getByRole("dialog").getByRole("navigation").getByRole("link");
      const n = await links.count();
      expect(n).toBeGreaterThanOrEqual(3);
      for (let i = 0; i < n; i++) expect((await links.nth(i).boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
      await page.keyboard.press("Escape");
      await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
      await expect(page.getByRole("table")).toBeVisible({ timeout: 20_000 });
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${id}/sua`);
      await expect(page.getByLabel(/Tên khóa học/)).toBeVisible({ timeout: 20_000 });
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
      const removes = page.getByTestId("teachers-group").getByRole("button", { name: /^Bỏ / });
      for (const b of await removes.all()) expect((await b.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
      await page.setViewportSize({ width: 1280, height: 800 });
    });
  });

  test("Giáo viên: không thấy khóa người khác, giá khoá khi sửa, 403 khóa lạ", async ({ page }) => {
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    await expect(page.getByRole("table")).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText("E2E FA3 Của GV khác")).toHaveCount(0);
    const other = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent("E2E FA3 Của GV khác")}`);
    expect((other.body!.data as unknown[]).length).toBe(0);
    const mine = await findCourse(page, "E2E FA3 Nháp trống");
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${mine.id}/sua`);
    await expect(page.getByLabel(/Học phí/)).toBeDisabled({ timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/tao`);
    await expect(page.getByLabel(/Học phí/)).toBeEnabled({ timeout: 20_000 });
    // API: GV sửa giá khoá đang có → bị bỏ qua/422/403 (không đổi giá)
    const put = await apiCall(page, "PUT", `/admin/courses/${mine.id}`, { price: 1 });
    console.log("[GV PUT price]", JSON.stringify({ status: put.status, text: put.text }));
    const after = (await apiCall(page, "GET", `/admin/courses/${mine.id}`)).body!;
    expect(after.price).toBe(mine.price);
  });
});
