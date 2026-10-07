import { existsSync, mkdirSync, statSync } from "node:fs";
import { deflateSync } from "node:zlib";
import { expect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA FA11 (US-020) — e2e THẬT bổ sung cho ho-so-giao-vien-real.spec.ts. Chạy:
 *   e2e/seed-e2e-profiles.sh --reset && e2e/seed-qa-fa11.sh && <run-real với UPLOADS_DIR=/uploads> e2e/ho-so-giao-vien-qa-real.spec.ts --workers=1 --retries=0 && e2e/seed-e2e-profiles.sh --clean
 * `STATIC_URL` (http://localhost:8080) không có máy chủ ở local, nên spec giả lập miền tĩnh bằng `context.route`: phục vụ file thật từ thư mục
 * uploads của backend (gắn chỉ đọc vào container Playwright tại UPLOADS_DIR), file không còn thì 404 (đúng hành vi Nginx).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const PUBLIC = "http://api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const UPLOADS = process.env.UPLOADS_DIR ?? "";
const SHOTS = process.env.QA_SHOTS ?? "";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial", timeout: 120_000 }); // R1b đặt CUỐI cùng vì đang lỗi thật (serial bỏ qua ca sau khi một ca fail)

type Json = Record<string, unknown>;
const email = (n: string) => `e2e-${n}@example.com`;

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
/** PNG w×h: nửa trái màu `left`, nửa phải màu `right`. */
function png(w: number, h: number, left: [number, number, number], right: [number, number, number]): Buffer {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, (_, x) => (x < w / 2 ? left : right)).flat())]);
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk("IHDR", ihdr), chunk("IDAT", deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]);
}
const RED: [number, number, number] = [220, 20, 20];
const BLUE: [number, number, number] = [20, 20, 220];
const WIDE = { name: "rong.png", mimeType: "image/png", buffer: png(600, 300, RED, BLUE) };
const WIDE2 = { name: "rong2.png", mimeType: "image/png", buffer: png(640, 320, [20, 160, 20], [240, 200, 20]) };

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

/** Giả lập miền tĩnh STATIC_URL: file thật từ thư mục uploads của backend; không có file → 404. */
async function emulateStatic(ctx: BrowserContext) {
  await ctx.route(/^http:\/\/localhost:8080\//, async (route) => {
    const name = new URL(route.request().url()).pathname.replace(/^\//, "");
    if (!UPLOADS || !/^[\w.-]+$/.test(name) || !existsSync(`${UPLOADS}/${name}`)) return route.fulfill({ status: 404, body: "not found" });
    return route.fulfill({ status: 200, path: `${UPLOADS}/${name}`, contentType: "image/webp", headers: { "x-robots-tag": "noindex, noimageindex" } });
  });
}
/** Ảnh tải được trong trình duyệt (qua miền tĩnh giả lập) và kích thước tự nhiên. */
async function loadImage(page: Page, url: string): Promise<{ ok: boolean; w: number; h: number }> {
  return page.evaluate(
    (u) =>
      new Promise<{ ok: boolean; w: number; h: number }>((resolve) => {
        const img = new Image();
        img.onload = () => resolve({ ok: true, w: img.naturalWidth, h: img.naturalHeight });
        img.onerror = () => resolve({ ok: false, w: 0, h: 0 });
        img.src = `${u}?qa=${Date.now()}${Math.random()}`; // tránh bộ nhớ đệm ảnh của trình duyệt; Nginx tĩnh bỏ qua query
      }),
    url,
  );
}
const fileOf = (url: string) => `${UPLOADS}/${new URL(url).pathname.replace(/^\//, "")}`;
async function shot(page: Page, name: string) {
  if (!SHOTS) return;
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

const NAMES = {
  gv1: "E2E FA11 GV Một",
  gv2: "E2E FA11 GV Hai",
  gv3: "E2E FA11 GV Ba",
  gv4: "E2E FA11 GV Bốn",
  gv5: "E2E FA11 GV Năm",
  gv6: "E2E FA11 GV Sáu",
  gv7: "E2E FA11 GV Bảy",
  cu: "E2E FA11 Cô Cũ",
} as const;
type Key = keyof typeof NAMES;
const ids = {} as Record<Key, number>;
const teacherRow = (page: Page, key: Key) => page.getByTestId(`teacher-row-${ids[key]}`);
const toggleOf = (page: Page, key: Key) => teacherRow(page, key).getByRole("switch");
const counter = (page: Page) => page.getByTestId("enabled-counter");
const LIST = `${ADMIN}/quan-tri/giao-vien?q=${encodeURIComponent("E2E FA11")}&per_page=50`; // lọc "E2E FA11" gồm mọi người đang bật nên nextOrder đúng

type ListItem = { user: { id: number; name: string }; show_on_homepage: boolean; homepage_order: number | null };
async function listAll(p: Page): Promise<ListItem[]> {
  const res = await apiCall(p, "GET", `/admin/teacher-profiles?q=${encodeURIComponent("E2E FA11")}&per_page=50`);
  expect(res.status).toBe(200);
  return res.body!.data as ListItem[];
}
/** Đưa trạng thái bật/tắt về đúng danh sách (theo thứ tự cho trước, đánh số 1..n), mọi người khác tắt. */
/** apiCall có thử lại khi gặp throttle 429 (nhóm `teacher-profile` 30/phút/user; không phải lỗi ứng dụng). */
async function apiR(p: Page, method: string, path: string, body?: unknown) {
  for (let i = 0; i < 4; i++) {
    const r = await apiCall(p, method, path, body);
    if (r.status !== 429) return r;
    await p.waitForTimeout(20_000);
  }
  return apiCall(p, method, path, body);
}
async function patchRetry(p: Page, path: string, body: unknown) {
  // Throttle `teacher-profile` 30/phút/user: gặp 429 thì chờ rồi thử lại (không phải lỗi ứng dụng).
  for (let i = 0; i < 4; i++) {
    const r = await apiCall(p, "PATCH", path, body);
    if (r.status !== 429) return r;
    await p.waitForTimeout(20_000);
  }
  return apiCall(p, "PATCH", path, body);
}
async function setEnabled(p: Page, want: Key[]) {
  const wantIds = want.map((k) => ids[k]);
  const cur = await listAll(p);
  for (const it of cur) {
    if (it.show_on_homepage && !wantIds.includes(it.user.id)) {
      expect((await patchRetry(p, `/admin/teacher-profiles/${it.user.id}/homepage`, { show_on_homepage: false, homepage_order: null })).status).toBe(200);
    }
  }
  for (const [i, k] of want.entries()) {
    const it = cur.find((c) => c.user.id === ids[k]);
    if (it?.show_on_homepage && it.homepage_order === i + 1) continue;
    // Đã bật rồi thì chỉ đổi thứ tự (người đã đổi vai trò: gửi show_on_homepage:true luôn 422 NOT_TEACHER).
    const body = it?.show_on_homepage ? { homepage_order: i + 1 } : { show_on_homepage: true, homepage_order: i + 1 };
    const r = await patchRetry(p, `/admin/teacher-profiles/${ids[k]}/homepage`, body);
    expect(r.status, `bật ${k}: ${JSON.stringify(r.body)}`).toBe(200);
  }
}
async function publicNames(request: APIRequestContext): Promise<string[]> {
  const res = await request.get(`${PUBLIC}/home/teachers`, { headers: { Accept: "application/json" } });
  expect(res.status()).toBe(200);
  return ((await res.json()) as { data: { name: string }[] }).data.map((t) => t.name).filter((n) => n.startsWith("E2E FA11"));
}
async function publicTeacher(request: APIRequestContext, key: Key) {
  const res = await request.get(`${PUBLIC}/home/teachers`, { headers: { Accept: "application/json" } });
  return ((await res.json()) as { data: { id: number; name: string; avatar_url: string | null; bio: string | null }[] }).data.find((t) => t.id === ids[key]);
}
const uiOrder = async (p: Page) => {
  const items = p.getByRole("region", { name: /Đang bật — thứ tự/ }).getByRole("listitem");
  return (await items.allInnerTexts()).map((t) => t.split("\n").find((l) => l.startsWith("E2E FA11")) ?? t);
};

let staff: Page;
let adm: Page;
let gvState: Awaited<ReturnType<BrowserContext["storageState"]>>;
/** Phiên giáo viên gv1 dùng lại bằng storageState (tránh đăng nhập nhiều lần làm chạm throttle đăng nhập). */
async function gvPage(browser: Browser, opts: Parameters<Browser["newContext"]>[0] = {}) {
  const ctx = await browser.newContext({ ...opts, storageState: gvState });
  await emulateStatic(ctx);
  const page = await ctx.newPage();
  return { ctx, page };
}

test.describe("FA11 QA bổ sung (thật)", () => {
  test.beforeAll(async ({ browser, request }: { browser: Browser; request: APIRequestContext }) => {
    staff = await (await browser.newContext()).newPage();
    await loginMfa(staff, request, "fa11-qlt1");
    adm = await (await browser.newContext()).newPage();
    await loginMfa(adm, request, "fa11-adm1");
    const gvCtx = await browser.newContext();
    const gvLogin = await gvCtx.newPage();
    await loginTeacher(gvLogin, "fa11-gv1");
    gvState = await gvCtx.storageState();
    await gvCtx.close();
    const data = await listAll(staff);
    for (const key of Object.keys(NAMES) as Key[]) {
      const hit = data.find((p) => p.user.name === NAMES[key]);
      if (!hit) throw new Error(`Không thấy "${NAMES[key]}" (đã chạy seed --reset + seed-qa-fa11.sh chưa?)`);
      ids[key] = hit.user.id;
    }
  });
  test.afterAll(async () => {
    await staff?.context().close();
    await adm?.context().close();
  });

  test("Người bị khoá và người đã đổi vai trò vẫn tắt được (UI), không lên API công khai, bật/sửa nội dung người đổi vai trò → 422 NOT_TEACHER", async ({ request }) => {
    // Chạy ĐẦU TIÊN: trạng thái seed (gv2#1, gv4 bị khoá #2, gv5..gv7 #3..#5, cu đã đổi vai trò #6) — cu không bật lại được qua API.
    expect(await publicNames(request)).toEqual([NAMES.gv2, NAMES.gv5, NAMES.gv6, NAMES.gv7]);
    // Người đổi vai trò: sửa nội dung/bật bị từ chối đúng mã, xoá ảnh hộ vẫn được.
    expect((await apiCall(staff, "PATCH", `/admin/teacher-profiles/${ids.cu}`, { bio: "x" })).body?.code).toBe("NOT_TEACHER");
    expect((await apiCall(staff, "PATCH", `/admin/teacher-profiles/${ids.cu}/homepage`, { show_on_homepage: true })).body?.code).toBe("NOT_TEACHER");
    await staff.goto(`${ADMIN}/quan-tri/giao-vien/${ids.cu}`);
    await expect(staff.getByText("Tài khoản này không còn là giáo viên nên chỉ xoá ảnh được.")).toBeVisible();
    await expect(staff.getByLabel("Giới thiệu bản thân")).toBeDisabled();
    await expect(staff.getByLabel("Chuyên môn (một dòng)")).toBeDisabled();
    await staff.getByRole("button", { name: "Xoá ảnh" }).click();
    await staff.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(staff.getByText("Đã lưu hồ sơ")).toBeVisible();
    expect(((await apiCall(staff, "GET", `/admin/teacher-profiles/${ids.cu}`)).body as { avatar_url: string | null }).avatar_url).toBeNull();
    // Tắt cả hai ở danh sách.
    await staff.goto(LIST);
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await expect(teacherRow(staff, "gv4").getByText("Tài khoản bị khoá", { exact: true })).toBeVisible();
    await toggleOf(staff, "gv4").click();
    await expect(counter(staff)).toHaveText("Đang bật 5/6");
    await toggleOf(staff, "cu").click();
    await expect(counter(staff)).toHaveText("Đang bật 4/6");
    await expect(toggleOf(staff, "gv4")).toHaveAttribute("aria-checked", "false");
    await expect(toggleOf(staff, "cu")).toHaveAttribute("aria-checked", "false");
    // Người bị khoá bật lại vẫn lưu được nhưng không hiện ở công khai (AC14).
    await toggleOf(staff, "gv4").click();
    await expect(toggleOf(staff, "gv4")).toHaveAttribute("aria-checked", "true");
    await expect(teacherRow(staff, "gv4").getByText(/Chưa hiện: tài khoản bị khoá/)).toBeVisible();
    expect(await publicNames(request)).toEqual([NAMES.gv2, NAMES.gv5, NAMES.gv6, NAMES.gv7]);
  });

  test("R1: bật 4 người, tắt người #2, bật người mới → người mới ở CUỐI ở màn quản trị và API công khai", async ({ request }) => {
    await staff.goto(LIST);
    await setEnabled(staff, ["gv2", "gv5", "gv6", "gv7"]);
    expect(await publicNames(request)).toEqual([NAMES.gv2, NAMES.gv5, NAMES.gv6, NAMES.gv7]);
    await staff.goto(LIST);
    await expect(counter(staff)).toHaveText("Đang bật 4/6");
    await toggleOf(staff, "gv5").click(); // người #2
    await expect(counter(staff)).toHaveText("Đang bật 3/6");
    expect(await publicNames(request)).toEqual([NAMES.gv2, NAMES.gv6, NAMES.gv7]);
    await toggleOf(staff, "gv3").click(); // người mới
    await expect(counter(staff)).toHaveText("Đang bật 4/6");
    expect(await uiOrder(staff)).toEqual([NAMES.gv2, NAMES.gv6, NAMES.gv7, NAMES.gv3]);
    const orders = (await listAll(staff)).filter((i) => i.show_on_homepage).map((i) => i.homepage_order);
    expect(new Set(orders).size, `thứ tự trùng: ${JSON.stringify(orders)}`).toBe(orders.length);
    expect(await publicNames(request)).toEqual([NAMES.gv2, NAMES.gv6, NAMES.gv7, NAMES.gv3]);
  });

  test("AC21: giáo viên (tab A) và QLT sửa hộ (tab B) sửa hai trường khác nhau — PATCH chỉ trường đổi, không ghi đè", async ({ browser }) => {
    const { page } = await gvPage(browser);
    const bodies: { who: string; body: string }[] = [];
    page.on("request", (r) => r.method() === "PATCH" && /teacher-profile$/.test(r.url()) && bodies.push({ who: "gv", body: r.postData() ?? "" }));
    staff.on("request", (r) => r.method() === "PATCH" && /teacher-profiles\/\d+$/.test(r.url()) && bodies.push({ who: "qlt", body: r.postData() ?? "" }));
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    await staff.goto(`${ADMIN}/quan-tri/giao-vien/${ids.gv1}`);
    await expect(page.getByLabel("Chuyên môn (một dòng)")).toBeVisible();
    await expect(staff.getByLabel("Giới thiệu bản thân")).toBeVisible();
    await page.getByLabel("Chuyên môn (một dòng)").fill("GV viết chuyên môn");
    await staff.getByLabel("Giới thiệu bản thân").fill("QLT viết giới thiệu\nDòng hai");
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ")).toBeVisible();
    await staff.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(staff.getByText("Đã lưu hồ sơ")).toBeVisible();
    const final = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { headline: string; bio: string };
    expect(final.headline).toBe("GV viết chuyên môn");
    expect(final.bio).toBe("QLT viết giới thiệu\nDòng hai");
    expect(bodies.map((b) => `${b.who}:${b.body}`)).toEqual(['gv:{"headline":"GV viết chuyên môn"}', 'qlt:{"bio":"QLT viết giới thiệu\\nDòng hai"}']);
    await expect(staff.getByLabel("Chuyên môn (một dòng)")).toHaveValue("GV viết chuyên môn");
    // Ngược lại: GV sửa bio (tab cũ chưa tải lại) rồi QLT sửa headline.
    await page.getByLabel("Giới thiệu bản thân").fill("GV sửa lại bio");
    await staff.getByLabel("Chuyên môn (một dòng)").fill("QLT sửa lại chuyên môn");
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ").first()).toBeVisible();
    await staff.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect.poll(async () => ((await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { headline: string }).headline).toBe("QLT sửa lại chuyên môn");
    const f2 = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { bio: string };
    expect(f2.bio).toBe("GV sửa lại bio");
    staff.removeAllListeners("request");
  });

  test("409 CONSENT_VERSION_CHANGED giữa chừng: câu mới hiện, bỏ tick, phần còn lại được lưu; sau đó 409 THẬT rồi đồng ý lại thành công", async ({ browser }) => {
    const { page } = await gvPage(browser);
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    const cors = { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" };
    let swapped = false;
    await page.route(/\/admin\/me\/teacher-profile\/consent$/, (route) => {
      swapped = true;
      return route.fulfill({ status: 409, contentType: "application/json", headers: cors, body: JSON.stringify({ message: "Nội dung đồng ý đã thay đổi.", code: "CONSENT_VERSION_CHANGED", context: { current_version: "2099-01" } }) });
    });
    await page.route(/\/admin\/me\/teacher-profile$/, async (route) => {
      if (route.request().method() !== "GET" || !swapped) return route.fallback();
      const res = await route.fetch();
      const json = (await res.json()) as { consent: Json };
      json.consent = { ...json.consent, current_version: "2099-01", current_text: "CÂU ĐỒNG Ý MỚI (QA giả lập)" };
      return route.fulfill({ response: res, json });
    });
    await expect(page.getByLabel(/Tôi đồng ý công khai ảnh/)).toBeVisible();
    await page.getByLabel("Chuyên môn (một dòng)").fill("Lưu cùng lúc với 409");
    await page.getByLabel(/Tôi đồng ý công khai ảnh/).check();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Câu đồng ý đã được cập nhật").first()).toBeVisible();
    await expect(page.getByLabel(/CÂU ĐỒNG Ý MỚI/)).not.toBeChecked();
    expect(((await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { headline: string; consent: { given: boolean } }).headline).toBe("Lưu cùng lúc với 409");
    expect(((await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { consent: { given: boolean } }).consent.given).toBe(false);
    // Bỏ giả lập: giao diện còn giữ phiên bản giả "2099-01" → server thật trả 409 thật → tải lại câu thật → tick lại → lưu được.
    await page.unroute(/\/admin\/me\/teacher-profile\/consent$/);
    await page.unroute(/\/admin\/me\/teacher-profile$/);
    await page.getByLabel(/CÂU ĐỒNG Ý MỚI/).check();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByLabel(/Tôi đồng ý công khai ảnh, họ tên/)).toBeVisible();
    await expect(page.getByLabel(/Tôi đồng ý công khai ảnh, họ tên/)).not.toBeChecked();
    await page.getByLabel(/Tôi đồng ý công khai ảnh, họ tên/).check();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByTestId("consent-given")).toBeVisible();
  });

  for (const width of [320, 375]) {
    test(`Cắt ảnh bằng bàn phím và chạm ở ${width}px (vừa khung, đúng vùng cắt, không cuộn ngang); lưu ảnh thật`, async ({ browser }) => {
      const { ctx, page } = await gvPage(browser, { viewport: { width, height: 700 }, hasTouch: true, isMobile: true });
      await page.goto(`${ADMIN}/quan-tri/ho-so`);
      const input = page.locator('input[name="avatar"]');
      const redFraction = async () =>
        page.evaluate(async () => {
          const src = (document.querySelector('img[alt^="Ảnh thầy/cô"]') as HTMLImageElement).src;
          const img = new Image();
          img.src = src;
          await img.decode();
          const c = document.createElement("canvas");
          c.width = img.naturalWidth;
          c.height = img.naturalHeight;
          const g = c.getContext("2d")!;
          g.drawImage(img, 0, 0);
          const row = g.getImageData(0, Math.floor(c.height / 2), c.width, 1).data;
          let red = 0;
          for (let x = 0; x < c.width; x++) if ((row[x * 4] ?? 0) > (row[x * 4 + 2] ?? 0)) red++;
          return { frac: red / c.width, w: img.naturalWidth, h: img.naturalHeight };
        });
      const cropOnce = async (act: (area: ReturnType<Page["getByTestId"]>) => Promise<void>) => {
        await input.setInputFiles(WIDE);
        const dlg = page.getByRole("dialog", { name: "Cắt ảnh đại diện" });
        await expect(dlg).toBeVisible();
        const area = page.getByTestId("crop-area");
        await expect(area.locator("img")).toBeVisible();
        // Bố cục: khung vuông, nằm trọn trong viewport, hộp thoại và trang không cuộn ngang, nút ≥ 44px.
        const box = (await area.boundingBox())!;
        expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(1);
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(width);
        const d = (await dlg.boundingBox())!;
        expect(d.x).toBeGreaterThanOrEqual(0);
        expect(d.x + d.width).toBeLessThanOrEqual(width + 0.5);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
        for (const name of ["Dùng ảnh này", "Huỷ"]) {
          const b = (await page.getByRole("button", { name, exact: true }).boundingBox())!;
          expect(b.height, `${name} cao`).toBeGreaterThanOrEqual(44);
          expect(b.x + b.width, `${name} trong viewport`).toBeLessThanOrEqual(width);
        }
        await shot(page, `crop-${width}`);
        await act(area);
        await page.getByRole("button", { name: "Dùng ảnh này" }).click();
        await expect(dlg).toHaveCount(0);
        return box;
      };

      // Mặc định (không kéo): vùng cắt chính giữa → ~50% đỏ, vuông, ≤ 800px. Ở màn hẹp khung co lại nên cũng phải đúng.
      const box = await cropOnce(async () => {});
      const base = await redFraction();
      expect(base.w).toBe(base.h);
      expect(base.w).toBeLessThanOrEqual(800);
      expect(Math.abs(base.frac - 0.5), `mặc định ${width}px: ${base.frac}`).toBeLessThan(0.06);
      expect(box.width).toBeLessThanOrEqual(280);

      // Bàn phím: Shift+Phải ×2 đẩy ảnh sang phải → thấy nhiều phần trái (đỏ) hơn; Shift+Trái ×4 → nhiều xanh hơn.
      await cropOnce(async (area) => {
        await area.focus();
        await page.keyboard.press("Shift+ArrowRight");
        await page.keyboard.press("Shift+ArrowRight");
      });
      const kRight = await redFraction();
      expect(kRight.frac, `phím phải ${width}px`).toBeGreaterThan(base.frac + 0.08);
      await cropOnce(async (area) => {
        await area.focus();
        for (let i = 0; i < 4; i++) await page.keyboard.press("Shift+ArrowLeft");
      });
      const kLeft = await redFraction();
      expect(kLeft.frac, `phím trái ${width}px`).toBeLessThan(base.frac - 0.08);

      // Thanh trượt phóng to bằng bàn phím.
      await input.setInputFiles(WIDE);
      const slider = page.getByLabel("Phóng to");
      await slider.focus();
      const z0 = Number(await slider.inputValue());
      await page.keyboard.press("ArrowRight");
      await page.keyboard.press("ArrowRight");
      expect(Number(await slider.inputValue())).toBeGreaterThan(z0);
      await page.getByRole("button", { name: "Huỷ", exact: true }).click();
      await expect(page.getByRole("dialog")).toHaveCount(0);

      // Chạm: kéo ngón tay sang phải 60px (CDP) → nhiều đỏ hơn mặc định; kéo trái 120px → ít đỏ hơn.
      const cdp = await ctx.newCDPSession(page);
      const swipe = async (dx: number) => {
        await cropOnce(async (area) => {
          const b = (await area.boundingBox())!;
          const x = b.x + b.width / 2;
          const y = b.y + b.height / 2;
          await cdp.send("Input.dispatchTouchEvent", { type: "touchStart", touchPoints: [{ x, y }] });
          for (let i = 1; i <= 6; i++) await cdp.send("Input.dispatchTouchEvent", { type: "touchMove", touchPoints: [{ x: x + (dx * i) / 6, y }] });
          await cdp.send("Input.dispatchTouchEvent", { type: "touchEnd", touchPoints: [] });
        });
        return redFraction();
      };
      const tRight = await swipe(60);
      expect(tRight.frac, `chạm phải ${width}px`).toBeGreaterThan(base.frac + 0.08);
      const tLeft = await swipe(-120);
      expect(tLeft.frac, `chạm trái ${width}px`).toBeLessThan(base.frac - 0.08);
      // Trang chính sau khi cắt: không cuộn ngang, xem trước đúng.
      expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

      // Lưu thật: ảnh thành WebP vuông ≤ 800, file có trong kho.
      await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
      await expect(page.getByText("Đã lưu hồ sơ").first()).toBeVisible();
      const saved = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { avatar_url: string };
      expect(saved.avatar_url).toMatch(/\.webp$/);
      if (UPLOADS) expect(existsSync(fileOf(saved.avatar_url))).toBe(true);
      const loaded = await loadImage(page, saved.avatar_url);
      expect(loaded.ok).toBe(true);
      expect(loaded.w).toBe(loaded.h);
      expect(loaded.w).toBeLessThanOrEqual(800);
      await ctx.close();
    });
  }

  test("Ảnh hiển thị THẬT (ô ảnh, xem trước, API công khai); thay ảnh xoá file cũ; rút đồng ý → API công khai hết ảnh/bio và URL ảnh cũ 404", async ({ browser, request }) => {
    const { ctx, page } = await gvPage(browser, { viewport: { width: 1280, height: 900 } });
    await page.goto(`${ADMIN}/quan-tri/ho-so`);
    const before = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { avatar_url: string; consent: { given: boolean } };
    expect(before.consent.given).toBe(true);
    const replaced = before.avatar_url;
    // Thay ảnh bằng ảnh khác: file cũ phải bị xoá khỏi kho (AC2).
    await page.locator('input[name="avatar"]').setInputFiles(WIDE2);
    await page.getByRole("button", { name: "Dùng ảnh này" }).click();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByText("Đã lưu hồ sơ").first()).toBeVisible();
    const cur = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { avatar_url: string };
    expect(cur.avatar_url).not.toBe(replaced);
    if (UPLOADS) {
      expect(existsSync(fileOf(cur.avatar_url))).toBe(true);
      expect(existsSync(fileOf(replaced)), "ảnh cũ phải bị xoá khi thay").toBe(false);
      expect(statSync(fileOf(cur.avatar_url)).size).toBeGreaterThan(100);
    }
    // Ảnh hiển thị thật: cả ô ảnh và thẻ xem trước tải được (naturalWidth > 0), không rơi vào chữ thay thế.
    await page.reload();
    const imgs = page.getByAltText(`Ảnh thầy/cô ${NAMES.gv1}`);
    await expect(imgs.first()).toBeVisible();
    await expect.poll(() => imgs.evaluateAll((els) => els.map((e) => (e as HTMLImageElement).naturalWidth > 0))).toEqual(expect.arrayContaining([true]));
    expect(await imgs.evaluateAll((els) => els.every((e) => (e as HTMLImageElement).naturalWidth > 0))).toBe(true);
    await expect(page.getByText("Không tải được ảnh")).toHaveCount(0);
    await shot(page, "ho-so-co-anh");

    // Bật trang chủ → API công khai có ảnh + bio; GET /courses/{slug} cũng có.
    await setEnabled(staff, ["gv2", "gv1"]);
    const pub = await publicTeacher(request, "gv1");
    expect(pub?.avatar_url).toBe(cur.avatar_url);
    expect(pub?.bio).toBe("GV sửa lại bio");
    const courses = (await (await request.get(`${PUBLIC}/courses?teacher_id=${ids.gv1}`)).json()) as { data: { slug: string }[] };
    const slug = courses.data[0]!.slug;
    const detail = async () => ((await (await request.get(`${PUBLIC}/courses/${slug}`)).json()) as { teachers: { id: number; name: string; bio: string | null; avatar_url: string | null }[] }).teachers.find((t) => t.id === ids.gv1)!;
    expect((await detail()).avatar_url).toBe(cur.avatar_url);
    expect((await detail()).bio).toBe("GV sửa lại bio");
    expect((await loadImage(page, cur.avatar_url)).ok).toBe(true);

    // Rút đồng ý.
    await page.getByRole("button", { name: "Rút đồng ý" }).click();
    await page.getByRole("dialog", { name: "Rút đồng ý công khai?" }).getByRole("button", { name: "Rút đồng ý" }).click();
    await expect(page.getByText("Đã rút đồng ý công khai")).toBeVisible();
    expect(await publicNames(request)).not.toContain(NAMES.gv1);
    const t = await detail();
    expect(t.name).toBe(NAMES.gv1);
    expect(t.avatar_url).toBeNull();
    expect(t.bio).toBeNull();
    // URL ảnh cũ (đã công khai) không còn mở được; hồ sơ nội dung còn nguyên, giáo viên vẫn thấy ảnh (URL mới).
    if (UPLOADS) expect(existsSync(fileOf(cur.avatar_url)), "file ảnh cũ còn trong kho sau khi rút đồng ý").toBe(false);
    expect((await loadImage(page, cur.avatar_url)).ok, "URL ảnh cũ sau khi rút đồng ý").toBe(false);
    const own = (await apiCall(page, "GET", "/admin/me/teacher-profile")).body as { avatar_url: string; bio: string };
    expect(own.avatar_url).not.toBe(cur.avatar_url);
    expect(own.bio).toBe("GV sửa lại bio");
    expect((await loadImage(page, own.avatar_url)).ok).toBe(true);
    await page.reload();
    await expect(page.getByAltText(`Ảnh thầy/cô ${NAMES.gv1}`).first()).toBeVisible();
    expect(await page.getByAltText(`Ảnh thầy/cô ${NAMES.gv1}`).first().evaluate((e) => (e as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
    // Admin cũng thấy ảnh mới ở màn sửa hộ.
    // Đồng ý lại: hiện lại với URL mới (≠ URL cũ).
    await page.getByLabel(/Tôi đồng ý công khai ảnh, họ tên/).check();
    await page.getByRole("button", { name: "Lưu hồ sơ" }).click();
    await expect(page.getByTestId("consent-given")).toBeVisible();
    const again = await publicTeacher(request, "gv1");
    expect(again?.avatar_url).toBe(own.avatar_url);
    expect(again?.avatar_url).not.toBe(cur.avatar_url);
    expect((await loadImage(page, cur.avatar_url)).ok).toBe(false);
    await ctx.close();
  });

  test("Hai admin cùng bật người thứ 7: đúng một người thành công, người kia 409 TEACHER_HOMEPAGE_LIMIT, không bao giờ quá 6", async () => {
    for (let round = 1; round <= 3; round++) {
      await setEnabled(staff, ["gv2", "gv5", "gv6", "gv7", "gv3"]);
      const [a, b] = await Promise.all([
        apiCall(staff, "PATCH", `/admin/teacher-profiles/${ids.gv1}/homepage`, { show_on_homepage: true, homepage_order: 6 }),
        apiCall(adm, "PATCH", `/admin/teacher-profiles/${ids.gv4}/homepage`, { show_on_homepage: true, homepage_order: 6 }),
      ]);
      expect([a.status, b.status].sort(), `vòng ${round}: ${JSON.stringify([a.body, b.body])}`).toEqual([200, 409]);
      const loser = a.status === 409 ? a : b;
      expect(loser.body?.code).toBe("TEACHER_HOMEPAGE_LIMIT");
      const on = (await listAll(staff)).filter((i) => i.show_on_homepage);
      expect(on.length).toBe(6);
    }
    // Giao diện cũ (đang thấy 5/6) bấm bật khi người khác vừa lấp chỗ cuối: báo đúng thông điệp, danh sách tải lại.
    await setEnabled(staff, ["gv2", "gv5", "gv6", "gv7", "gv3"]);
    await staff.goto(LIST);
    await expect(counter(staff)).toHaveText("Đang bật 5/6");
    expect((await apiCall(adm, "PATCH", `/admin/teacher-profiles/${ids.gv1}/homepage`, { show_on_homepage: true, homepage_order: 6 })).status).toBe(200);
    await toggleOf(staff, "gv4").click();
    await expect(staff.getByText("Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước.")).toBeVisible();
    await expect(counter(staff)).toHaveText("Đang bật 6/6");
    await expect(toggleOf(staff, "gv4")).toHaveAttribute("aria-checked", "false");
    await expect(toggleOf(staff, "gv1")).toHaveAttribute("aria-checked", "true");
  });

  test("Admin/QLT không đồng ý thay được: UI khoá + không có nút rút; API consent 404/405/403; PATCH kèm trường consent bị bỏ qua", async ({ browser }) => {
    const { page } = await gvPage(browser);
    await page.goto(`${ADMIN}/quan-tri`);
    for (const who of [staff, adm]) {
      await who.goto(`${ADMIN}/quan-tri/giao-vien/${ids.gv5}`);
      await expect(who.getByTestId("consent-readonly-note")).toContainText("Chỉ giáo viên được đồng ý công khai");
      const box = who.getByLabel(/Tôi đồng ý công khai ảnh/);
      await expect(box).toBeDisabled();
      await expect(box).toBeChecked();
      await expect(who.getByRole("button", { name: /Rút đồng ý/ })).toHaveCount(0);
      await expect(who.getByRole("checkbox")).toHaveCount(1);
      await who.goto(`${ADMIN}/quan-tri/ho-so`);
      await expect(who.getByTestId("forbidden-view")).toBeVisible();
      expect((await apiR(who, "POST", `/admin/teacher-profiles/${ids.gv1}/consent`, { version: "2026-10" })).status).toBeGreaterThanOrEqual(404);
      expect([404, 405]).toContain((await apiR(who, "POST", `/admin/teacher-profiles/${ids.gv5}/consent`, { version: "2026-10" })).status);
      expect([404, 405]).toContain((await apiR(who, "DELETE", `/admin/teacher-profiles/${ids.gv5}/consent`)).status);
      expect((await apiR(who, "POST", "/admin/me/teacher-profile/consent", { version: "2026-10" })).status).toBe(403);
      expect((await apiR(who, "DELETE", "/admin/me/teacher-profile/consent")).status).toBe(403);
      // Mass assignment: các trường đồng ý gửi kèm PATCH nội dung/hiển thị bị bỏ qua.
      const r = await apiR(who, "PATCH", `/admin/teacher-profiles/${ids.gv5}`, { headline: "QA mass", public_consent_at: null, public_consent_withdrawn_at: "2020-01-01 00:00:00", consent: { given: false }, show_on_homepage: false });
      expect([200, 422]).toContain(r.status);
      const after = (await apiR(who, "GET", `/admin/teacher-profiles/${ids.gv5}`)).body as { consent: { given: boolean }; show_on_homepage: boolean };
      expect(after.consent.given).toBe(true);
    }
    // Giáo viên khác cũng không đồng ý/rút thay hay sửa người khác.
    for (const [m, p, b] of [
      ["POST", `/admin/teacher-profiles/${ids.gv5}/consent`, { version: "2026-10" }],
      ["DELETE", `/admin/teacher-profiles/${ids.gv5}/consent`, undefined],
      ["PATCH", `/admin/teacher-profiles/${ids.gv5}`, { bio: "hack" }],
      ["DELETE", `/admin/teacher-profiles/${ids.gv5}/avatar`, undefined],
    ] as const) {
      const r = await apiR(page, m, p, b);
      expect(r.status, `${m} ${p}`).toBeGreaterThanOrEqual(403);
      expect(r.status).toBeLessThanOrEqual(405);
    }
    expect(((await apiR(staff, "GET", `/admin/teacher-profiles/${ids.gv5}`)).body as { consent: { given: boolean }; bio: string }).consent.given).toBe(true);
  });

  test("R1b: bật người mới khi ĐANG LỌC theo tên (bộ lọc không chứa người đang bật) vẫn phải xếp cuối, không chen lên đầu", async () => {
    await staff.goto(LIST);
    await setEnabled(staff, ["gv2", "gv6", "gv7"]);
    await staff.goto(`${ADMIN}/quan-tri/giao-vien?q=${encodeURIComponent("GV Một")}`);
    await expect(teacherRow(staff, "gv1")).toBeVisible();
    await expect(teacherRow(staff, "gv2")).toHaveCount(0);
    await toggleOf(staff, "gv1").click();
    await expect(toggleOf(staff, "gv1")).toHaveAttribute("aria-checked", "true");
    const all = await listAll(staff);
    const mine = all.find((i) => i.user.id === ids.gv1)!;
    const others = all.filter((i) => i.show_on_homepage && i.user.id !== ids.gv1).map((i) => i.homepage_order ?? 0);
    expect(mine.homepage_order, `gv1 order=${mine.homepage_order}, người khác=${JSON.stringify(others)}`).toBeGreaterThan(Math.max(...others));
  });
});
