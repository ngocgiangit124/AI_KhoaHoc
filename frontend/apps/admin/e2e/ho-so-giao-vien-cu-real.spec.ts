import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA11-1 — e2e THẬT: người đã đổi vai trò (không còn giao_vien) tự rút đồng ý / xoá ảnh hồ sơ (E2E_REAL_BACKEND=1).
 * Chạy `seed-e2e-legacy-profile.sh --reset` TRƯỚC MỖI LẦN chạy, `--clean` sau cùng; `--workers=1 --retries=0`.
 * Phủ: lối vào chỉ khi còn dữ liệu, xác nhận trước khi gỡ, khoá sửa/đồng ý lại (API 403), menu ẩn sau khi gỡ hết, giáo viên không đổi.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

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


const legacyEntry = (page: Page) => nav(page).getByRole("link", { name: "Hồ sơ giáo viên cũ" });

test.describe("FA11-1 hồ sơ giáo viên cũ (thật)", () => {
  test("người không có hồ sơ: không có lối vào, vào thẳng URL nhận không có quyền", async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    const page = await (await browser.newContext()).newPage();
    await loginMfa(page, request, "fa11b-qlt-c");
    await expect(nav(page)).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);
    await expect(nav(page).getByRole("link", { name: "Hồ sơ của tôi" })).toHaveCount(0);
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(page.getByText(/không có quyền/i).first()).toBeVisible();
  });

  test("giáo viên giữ 'Hồ sơ của tôi', không có mục 'cũ'", async ({ page }) => {
    await loginTeacher(page, "fa11b-gv");
    await expect(nav(page).getByRole("link", { name: "Hồ sơ của tôi" })).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);
  });

  test("đã đổi vai trò: xem trạng thái, rút đồng ý và xoá ảnh qua xác nhận, menu ẩn khi hết dữ liệu", async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    const page = await (await browser.newContext()).newPage();
    await loginMfa(page, request, "fa11b-qlt-a");
    await expect(legacyEntry(page)).toBeVisible();
    await legacyEntry(page).click();
    await expect(page.getByRole("heading", { name: "Hồ sơ giáo viên cũ", level: 1 })).toBeVisible();
    // Không có ô sửa / đồng ý lại.
    await expect(page.locator("textarea, input[type=text], input[type=checkbox]")).toHaveCount(0);
    await expect(page.getByTestId("legacy-consent-given")).toBeVisible();

    // Server vẫn chặn sửa nội dung / đồng ý lại.
    expect((await apiCall(page, "PATCH", "/admin/me/teacher-profile", { bio: "x" })).status).toBe(403);
    expect((await apiCall(page, "POST", "/admin/me/teacher-profile/consent", { version: "2026-10" })).status).toBe(403);

    // Rút đồng ý: huỷ không gọi, xác nhận mới gỡ.
    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    await page.getByRole("dialog").getByRole("button", { name: /Huỷ|Hủy/ }).click();
    await expect(page.getByTestId("legacy-consent-given")).toBeVisible();
    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Rút đồng ý" }).click();
    await expect(page.getByTestId("legacy-consent-off")).toBeVisible();
    await expect(legacyEntry(page)).toBeVisible(); // còn ảnh

    // Xoá ảnh qua xác nhận.
    await page.getByRole("button", { name: "Xoá ảnh" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Xoá ảnh" }).click();
    await expect(page.getByTestId("legacy-empty")).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);

    // Tải lại: API vẫn 200 nhưng không còn dữ liệu → menu vẫn ẩn.
    await page.reload();
    await expect(nav(page)).toBeVisible();
    await expect(legacyEntry(page)).toHaveCount(0);
    const me = await apiCall(page, "GET", "/admin/me/teacher-profile");
    expect(me.status).toBe(200);
    expect(me.body!.avatar_url).toBeNull();
  });
});
