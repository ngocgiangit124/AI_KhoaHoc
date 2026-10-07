import { deflateSync } from "node:zlib";
import { expect, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";

/**
 * FA11 — e2e THẬT hồ sơ giáo viên công khai (US-020; E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit).
 * Chạy `frontend/apps/admin/e2e/seed-e2e-profiles.sh --reset` TRƯỚC MỖI LẦN chạy (spec ghi vào hồ sơ), `--clean` sau cùng.
 * Chỉ 1 lần đăng nhập MFA (e2e-fa11-qlt1); giáo viên đăng nhập thẳng. Chạy với `--workers=1 --retries=0`.
 * Phủ: AC1, AC2, AC3 (client + 422 giả lập), AC8, AC9, AC10, AC18, AC21, rút đồng ý, người bị khoá/đổi vai trò vẫn tắt được, 375px.
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

function crc32(buf: Buffer): number {
  let c = ~0;
  for (const b of buf) {
    c ^= b;
    for (let k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
  }
  return ~c >>> 0;
}
function chunk(type: string, data: Buffer): Buffer {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}
/** PNG hợp lệ w×h một màu (đủ để trình duyệt giải mã, cắt bằng canvas và backend mã hoá lại thành WebP). */
function png(w: number, h: number, rgb: [number, number, number]): Buffer {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  const rowBytes = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => rgb).flat())]);
  const raw = Buffer.concat(Array.from({ length: h }, () => rowBytes));
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk("IHDR", ihdr), chunk("IDAT", deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]);
}
const IMG = { name: "chan-dung.png", mimeType: "image/png", buffer: png(120, 80, [30, 90, 200]) };

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
const NAMES = {
  gv1: "E2E FA11 GV Một",
  gv2: "E2E FA11 GV Hai",
  gv3: "E2E FA11 GV Ba",
  gv4: "E2E FA11 GV Bốn",
  gv5: "E2E FA11 GV Năm",
  cu: "E2E FA11 Cô Cũ",
} as const;
const ids: Record<keyof typeof NAMES, number> = { gv1: 0, gv2: 0, gv3: 0, gv4: 0, gv5: 0, cu: 0 };
const teacherRow = (page: Page, key: keyof typeof NAMES) => page.getByTestId(`teacher-row-${ids[key]}`);
const toggleOf = (page: Page, key: keyof typeof NAMES) => teacherRow(page, key).getByRole("switch");
const counter = (page: Page) => page.getByTestId("enabled-counter");
const LIST = `${ADMIN}/quan-tri/giao-vien?q=${encodeURIComponent("E2E FA11")}&per_page=50`;

let staff: Page;

test.describe("FA11 hồ sơ giáo viên (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    const ctx = await browser.newContext();
    staff = await ctx.newPage();
    await loginMfa(staff, request, "fa11-qlt1");
    const list = await apiCall(staff, "GET", `/admin/teacher-profiles?q=${encodeURIComponent("E2E FA11")}&per_page=50`);
    expect(list.status).toBe(200);
    const data = list.body!.data as { user: { id: number; name: string } }[];
    for (const key of Object.keys(NAMES) as (keyof typeof NAMES)[]) {
      const hit = data.find((p) => p.user.name === NAMES[key]);
      if (!hit) throw new Error(`Không thấy "${NAMES[key]}" (đã chạy seed-e2e-profiles.sh --reset chưa?)`);
      ids[key] = hit.user.id;
    }
  });
  test.afterAll(async () => {
    await staff?.context().close();
  });

  test("Giáo viên: menu chỉ có 'Hồ sơ của tôi'; màn/API quản lý bị chặn (AC18)", async ({ page }) => {
    await loginTeacher(page, "fa11-gv1");
    await expect(nav(page).getByRole("link", { name: "Hồ sơ của tôi" })).toBeVisible();
    await expect(nav(page).getByRole("link", { name: "Giáo viên trang chủ" })).toHaveCount(0);
    await page.goto(`${ADMIN}/quan-tri/giao-vien`);
    await expect(page.getByTestId("forbidden-view")).toBeVisible();
    expect((await apiCall(page, "GET", "/admin/teacher-profiles")).status).toBe(403);
    // Sửa hồ sơ giáo viên khác hoặc bật trang chủ qua API: 403 FORBIDDEN, không đổi gì.
    const patch = await apiCall(page, "PATCH", `/admin/teacher-profiles/${ids.gv2}`, { bio: "Tấn công" });
    expect(patch.status).toBe(403);
    expect(patch.body?.code).toBe("FORBIDDEN");
    expect((await apiCall(page, "PATCH", `/admin/teacher-profiles/${ids.gv3}/homepage`, { show_on_homepage: true })).status).toBe(403);
  });

  test("Giáo viên: nhập chuyên môn/giới thiệu, đếm ký tự, lỗi 422, trạng thái + lý do (AC1)", async ({ page }) => {
    await loginTeacher(page, "fa11-gv1");
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(page.getByRole("heading", { name: "Hồ sơ của tôi", level: 1 })).toBeVisible();
    await expect(page.getByText("Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui")).toBeVisible();
    await expect(page.getByText("Chưa hiển thị", { exact: true })).toBeVisible();
    await expect(page.getByTestId("not-shown-reasons")).toContainText("chưa đồng ý công khai");
    await expect(page.getByTestId("not-shown-reasons")).toContainText("chưa có ảnh");
    await expect(page.getByTestId("not-shown-reasons")).toContainText("chưa có phần giới thiệu");

    // Client chặn < > và quá dài; server chặn bằng 422 dưới đúng ô (giả lập vì client đã chặn trước).
    await page.getByLabel("Giới thiệu bản thân").fill("<script>alert(1)</script>");
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText(/không chứa < hoặc >/)).toBeVisible();
    await expect(page.getByLabel("Giới thiệu bản thân")).toBeFocused();

    await page.getByLabel("Chuyên môn (một dòng)").fill("Giáo viên Toán THCS");
    await expect(page.getByText("19/120")).toBeVisible();
    await page.getByLabel("Giới thiệu bản thân").fill("Dòng một.\nDòng hai.");
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ")).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Chuyên môn (một dòng)")).toHaveValue("Giáo viên Toán THCS");
    await expect(page.getByLabel("Giới thiệu bản thân")).toHaveValue("Dòng một.\nDòng hai.");
    await expect(page.getByTestId("not-shown-reasons")).not.toContainText("chưa có phần giới thiệu");
    // Thẻ xem trước giữ xuống dòng, hiển thị đúng như chữ.
    await expect(page.getByRole("heading", { name: NAMES.gv1, level: 3 })).toBeVisible();
  });

  test("Giáo viên: ảnh — lỗi client (SVG đổi đuôi, > 2 MB), 422 server, cắt vuông + lưu, xoá (AC2, AC3)", async ({ page }) => {
    await loginTeacher(page, "fa11-gv1");
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    const input = page.locator('input[name="avatar"]');
    await input.setInputFiles({ name: "x.jpg", mimeType: "image/jpeg", buffer: Buffer.from("<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>") });
    await expect(page.getByTestId("avatar-error")).toHaveText("Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.");
    await input.setInputFiles({ name: "big.png", mimeType: "image/png", buffer: Buffer.concat([IMG.buffer, Buffer.alloc(2 * 1024 * 1024 + 10)]) });
    await expect(page.getByTestId("avatar-error")).toHaveText("Ảnh tối đa 2 MB.");

    // 422 từ server (định dạng/giải mã) hiện dưới ô ảnh, không lưu gì.
    await input.setInputFiles(IMG);
    await expect(page.getByRole("dialog", { name: "Cắt ảnh đại diện" })).toBeVisible();
    await page.route(/\/admin\/me\/teacher-profile\/avatar$/, (route) =>
      route.fulfill({
        status: 422,
        contentType: "application/json",
        headers: { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" },
        body: JSON.stringify({ message: "Ảnh không hợp lệ.", errors: { avatar: ["Không giải mã được ảnh. Hãy chọn ảnh JPG, PNG hoặc WebP khác."] } }),
      }),
    );
    await page.getByRole("button", { name: "Dùng ảnh này" }).click();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByTestId("avatar-error")).toHaveText("Không giải mã được ảnh. Hãy chọn ảnh JPG, PNG hoặc WebP khác.");
    await page.unroute(/\/admin\/me\/teacher-profile\/avatar$/);
    expect((await apiCall(page, "GET", "/admin/me/teacher-profile")).body?.avatar_url).toBeNull();

    // Lưu thật: cắt vuông (kéo bằng phím, phóng to), backend mã hoá lại WebP.
    await input.setInputFiles(IMG);
    const area = page.getByTestId("crop-area");
    await area.focus();
    await page.keyboard.press("ArrowLeft");
    await page.getByLabel("Phóng to").fill("1.5");
    await page.getByRole("button", { name: "Dùng ảnh này" }).click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ")).toBeVisible();
    const saved = await apiCall(page, "GET", "/admin/me/teacher-profile");
    expect(String(saved.body?.avatar_url)).toMatch(/\.webp$/);
    // Trong container Playwright miền tĩnh (STATIC_URL) không tới được → ảnh lỗi tải phải hiện chữ thay cho icon vỡ,
    // và vẫn xoá được. Trên trình duyệt thật ảnh hiện bình thường.
    await expect(page.getByAltText(`Ảnh thầy/cô ${NAMES.gv1}`).first().or(page.getByText("Không tải được ảnh"))).toBeVisible();
    await expect(page.getByTestId("not-shown-reasons")).not.toContainText("chưa có ảnh");

    await page.getByRole("button", { name: "Xoá ảnh" }).click();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ")).toBeVisible();
    expect((await apiCall(page, "GET", "/admin/me/teacher-profile")).body?.avatar_url).toBeNull();
    await expect(page.getByTestId("not-shown-reasons")).toContainText("chưa có ảnh");
  });

  test("Giáo viên: đồng ý công khai rồi rút đồng ý có xác nhận; nội dung vẫn giữ", async ({ page }) => {
    await loginTeacher(page, "fa11-gv1");
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await page.getByLabel(/Tôi đồng ý công khai ảnh/).check();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByTestId("consent-given")).toBeVisible();
    const given = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { consent: { given: boolean; version: string; current_version: string } };
    expect(given.consent.given).toBe(true);
    expect(given.consent.version).toBe(given.consent.current_version);

    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    const dialog = page.getByRole("dialog", { name: "Rút đồng ý công khai?" });
    await dialog.getByRole("button", { name: "Huỷ" }).click();
    expect(((await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { consent: { given: boolean } }).consent.given).toBe(true);
    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    await dialog.getByRole("button", { name: "Rút đồng ý" }).click();
    await expect(page.getByText("Đã rút đồng ý công khai")).toBeVisible();
    await expect(page.getByLabel(/Tôi đồng ý công khai ảnh/)).not.toBeChecked();
    const after = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { consent: { given: boolean }; headline: string };
    expect(after.consent.given).toBe(false);
    expect(after.headline).toBe("Giáo viên Toán THCS");
  });

  test("Hai tab sửa hai trường khác nhau không mất dữ liệu (AC21)", async ({ page, context }) => {
    await loginTeacher(page, "fa11-gv1");
    const other = await context.newPage();
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await other.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(page.getByLabel("Chuyên môn (một dòng)")).toBeVisible();
    await expect(other.getByLabel("Giới thiệu bản thân")).toBeVisible();
    await page.getByLabel("Chuyên môn (một dòng)").fill("Sửa ở tab A");
    await other.getByLabel("Giới thiệu bản thân").fill("Sửa ở tab B");
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ")).toBeVisible();
    await other.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(other.getByText("Đã lưu hồ sơ")).toBeVisible();
    const final = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { headline: string; bio: string };
    expect(final.headline).toBe("Sửa ở tab A");
    expect(final.bio).toBe("Sửa ở tab B");
    // Tab B chỉ gửi bio nên ô chuyên môn của nó đã đổi theo dữ liệu mới nhất từ server.
    await expect(other.getByLabel("Chuyên môn (một dòng)")).toHaveValue("Sửa ở tab A");
    await other.close();
  });

  test("QLT: menu, bộ đếm 6/6, người bị khoá/đã đổi vai trò vẫn hiện; không có 'Hồ sơ của tôi'", async () => {
    await staff.goto(LIST);
    await expect(staff.getByRole("heading", { name: "Giáo viên trên trang chủ", level: 1 })).toBeVisible();
    await expect(nav(staff).getByRole("link", { name: "Giáo viên trang chủ" })).toHaveAttribute("aria-current", "page");
    await expect(nav(staff).getByRole("link", { name: "Hồ sơ của tôi" })).toHaveCount(0);
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await expect(teacherRow(staff, "gv2").getByText("Đang hiện")).toBeVisible();
    await expect(teacherRow(staff, "gv4").getByText("Tài khoản bị khoá", { exact: true })).toBeVisible();
    await expect(teacherRow(staff, "gv4").getByText(/Chưa hiện: tài khoản bị khoá/)).toBeVisible();
    await expect(teacherRow(staff, "cu").getByText("Không còn là giáo viên", { exact: true }).first()).toBeVisible();
    // Ô tìm kiếm + bộ lọc "chỉ người đang bật" nằm trên URL.
    await staff.getByLabel("Chỉ người đang bật").click();
    await expect(staff).toHaveURL(/homepage=1/);
    await expect(teacherRow(staff, "gv3")).toHaveCount(0);
    await staff.reload();
    await expect(staff.getByLabel("Chỉ người đang bật")).toBeChecked();
    await staff.getByLabel("Chỉ người đang bật").click();
    await expect(staff).not.toHaveURL(/homepage=1/);
    await staff.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(staff.getByTestId("forbidden-view")).toBeVisible();
    expect((await apiCall(staff, "GET", "/admin/me/teacher-profile")).status).toBe(403);
  });

  test("QLT: bật người thứ 7 bị từ chối đúng thông điệp, trạng thái cũ giữ nguyên (AC10)", async () => {
    await staff.goto(LIST);
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await toggleOf(staff, "gv3").click();
    await expect(staff.getByText("Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước.")).toBeVisible();
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await expect(toggleOf(staff, "gv3")).toHaveAttribute("aria-checked", "false");
  });

  test("QLT: tắt người đã đổi vai trò, bật người chưa đủ điều kiện → 'Chưa hiện: lý do' (AC9)", async () => {
    await staff.goto(LIST);
    await expect(toggleOf(staff, "cu")).toHaveAttribute("aria-checked", "true");
    await toggleOf(staff, "cu").click();
    await expect(counter(staff)).toHaveText("Đang bật 5/6");
    await expect(toggleOf(staff, "cu")).toHaveAttribute("aria-checked", "false");
    // Người không còn là giáo viên đã tắt thì không bật lại được.
    await expect(toggleOf(staff, "cu")).toBeDisabled();

    await toggleOf(staff, "gv3").click();
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await expect(teacherRow(staff, "gv3").getByText(/Chưa hiện: chưa đồng ý công khai, chưa có ảnh, chưa có phần giới thiệu/)).toBeVisible();
    const after = await apiCall(staff, "GET", `/admin/teacher-profiles/${ids.gv3}`);
    expect((after.body as { show_on_homepage: boolean; homepage_status: { visible: boolean } }).show_on_homepage).toBe(true);
    expect((after.body as { homepage_status: { visible: boolean } }).homepage_status.visible).toBe(false);
  });

  test("QLT: đổi thứ tự bằng nút Lên/Xuống (bàn phím dùng được) và thứ tự lưu ở server", async () => {
    // Đang lọc theo tên thì khoá nút thứ tự (tránh đánh số lại thiếu người); mở danh sách không lọc.
    await staff.goto(LIST);
    await expect(staff.getByRole("button", { name: `Đưa ${NAMES.gv5} lên` })).toBeDisabled();
    await expect(staff.getByText(/Đang lọc theo tên nên chưa đổi được thứ tự/)).toBeVisible();
    await staff.goto(`${ADMIN}/quan-tri/giao-vien`);
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    const order = async () => {
      const res = await apiCall(staff, "GET", `/admin/teacher-profiles?q=${encodeURIComponent("E2E FA11")}&homepage=1&per_page=50`);
      return (res.body!.data as { user: { id: number }; homepage_order: number | null }[]).map((p) => p.user.id);
    };
    const before = await order();
    expect(before.indexOf(ids.gv5)).toBeGreaterThan(before.indexOf(ids.gv4));
    const up = staff.getByRole("button", { name: `Đưa ${NAMES.gv5} lên` });
    await up.focus();
    await staff.keyboard.press("Enter");
    await expect
      .poll(async () => {
        const now = await order();
        return now.indexOf(ids.gv5) < now.indexOf(ids.gv4);
      })
      .toBe(true);
    await staff.reload();
    const items = staff.getByRole("region", { name: /Đang bật — thứ tự/ }).getByRole("listitem");
    await expect(items.first()).toContainText(NAMES.gv2);
    await expect(staff.getByRole("button", { name: `Đưa ${NAMES.gv2} lên` })).toBeDisabled();
  });

  test("QLT sửa hộ: ô đồng ý khoá + chữ giải thích, không có API đồng ý thay (AC8); giáo viên thấy 'Chỉnh sửa gần nhất bởi'", async ({ page }) => {
    await staff.goto(`${ADMIN}/quan-tri/giao-vien/${ids.gv1}`);
    await expect(staff.getByRole("heading", { name: `Hồ sơ công khai — ${NAMES.gv1}`, level: 1 })).toBeVisible();
    await expect(staff.getByLabel(/Tôi đồng ý công khai ảnh/)).toBeDisabled();
    await expect(staff.getByTestId("consent-readonly-note")).toContainText("Chỉ giáo viên được đồng ý công khai");
    await expect(staff.getByRole("button", { name: /Rút đồng ý/ })).toHaveCount(0);
    await staff.getByLabel("Chuyên môn (một dòng)").fill("Do QLT sửa hộ");
    await staff.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(staff.getByText("Đã lưu hồ sơ")).toBeVisible();
    // Gọi thẳng API đồng ý thay người khác: không có route (404/405), và đồng ý vẫn chưa có.
    const consent = await apiCall(staff, "POST", `/admin/teacher-profiles/${ids.gv1}/consent`, { version: "2026-10" });
    expect([404, 405]).toContain(consent.status);
    expect(((await apiCall(staff, "GET", `/admin/teacher-profiles/${ids.gv1}`)).body as { consent: { given: boolean } }).consent.given).toBe(false);

    // Hồ sơ không tồn tại → màn không tìm thấy.
    await staff.goto(`${ADMIN}/quan-tri/giao-vien/99999999`);
    await expect(staff.getByTestId("profile-not-found")).toBeVisible();

    await loginTeacher(page, "fa11-gv1");
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(page.getByText(/Chỉnh sửa gần nhất bởi E2E FA11 QLT Một/)).toBeVisible();
    await expect(page.getByLabel("Chuyên môn (một dòng)")).toHaveValue("Do QLT sửa hộ");
  });

  test("375px: không cuộn ngang, nút lưu và công tắc đủ vùng chạm 44px", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await loginTeacher(page, "fa11-gv1");
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await expect(page.getByRole("heading", { name: "Hồ sơ của tôi", level: 1 })).toBeVisible();
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    const save = await page.getByRole("button", { name: "Lưu hồ sơ" }).boundingBox();
    expect(save!.height).toBeGreaterThanOrEqual(44);

    await staff.setViewportSize({ width: 375, height: 800 });
    await staff.goto(LIST);
    await expect(counter(staff)).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
    const sw = await toggleOf(staff, "gv2").boundingBox();
    expect(sw!.height).toBeGreaterThanOrEqual(44);
    const up = await staff.getByRole("button", { name: `Đưa ${NAMES.gv5} lên` }).boundingBox();
    expect(up!.width).toBeGreaterThanOrEqual(44);
    await staff.goto(`${ADMIN}/quan-tri/giao-vien/${ids.gv2}`);
    await expect(staff.getByRole("heading", { level: 1 })).toBeVisible();
    expect(await noOverflow(staff)).toBeLessThanOrEqual(0);
  });
});
