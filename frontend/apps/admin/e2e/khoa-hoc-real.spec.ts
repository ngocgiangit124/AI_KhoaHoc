import { deflateSync } from "node:zlib";
import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * FA3 — e2e THẬT màn Khóa học (E2E_REAL_BACKEND=1; admin-api.localhost:3001 + :8000, MFA đọc từ Mailpit, queue chạy).
 * Seed trước (idempotent): `frontend/apps/admin/e2e/seed-e2e-courses.sh`; dọn sau: `seed-e2e-courses.sh --clean` (xoá cả file ảnh).
 * Chạy với `--retries=0` (mỗi lần thử lại tốn một mã OTP). Chỉ 1 lần đăng nhập MFA (QLT); giáo viên đăng nhập thẳng.
 * Dữ liệu thêm trong lúc chạy đều có tiền tố tên "E2E FA3 " nên `--clean` dọn được.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
// Tài khoản staff (Admin/QLT) đăng nhập MFA: đổi bằng FA3_STAFF=admin khi tài khoản mặc định đang bị giới hạn OTP theo giờ.
const STAFF = process.env.FA3_STAFF ?? "qlt";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const email = (n: string) => `e2e-${n}@example.com`;
const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });
const row = (page: Page, name: string) => page.getByRole("row").filter({ hasText: name });
type Json = Record<string, unknown>;
const CORS = { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" };

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
/** PNG hợp lệ w×h một màu (đủ để trình duyệt giải mã và backend mã hoá lại thành WebP). */
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
const IMG_A = { name: "bia-a.png", mimeType: "image/png", buffer: png(64, 36, [200, 30, 30]) };
const IMG_B = { name: "bia-b.png", mimeType: "image/png", buffer: png(64, 36, [30, 30, 200]) };

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
  await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
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
        /* 204 */
      }
      return { status: res.status, body: json as Json | null };
    },
    { api: API, method, path, body },
  );
}

async function findCourse(page: Page, title: string): Promise<Json> {
  const res = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent(title)}`);
  const hit = (res.body!.data as Json[]).find((c) => c.title === title);
  if (!hit) throw new Error(`Không thấy khóa "${title}" (đã chạy seed-e2e-courses.sh chưa?)`);
  return hit;
}

const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const stamp = Date.now();
const CREATED = `E2E FA3 Tạo ${stamp}`;
let otherTeacherCourseId = 0;
let publishedId = 0;
let contentId = 0;
let ownCourseId = 0;
let hiddenSubjectRestore: number | null = null;

test.describe("FA3 khóa học (thật)", () => {
  test.afterEach(async ({ page }) => {
    // Nếu test dừng giữa chừng khi chuyên đề đang bị ẩn tạm → bật lại.
    if (hiddenSubjectRestore !== null && page.url().startsWith(ADMIN)) {
      await apiCall(page, "PATCH", `/admin/subjects/${hiddenSubjectRestore}/status`, { status: "active" }).catch(() => undefined);
      hiddenSubjectRestore = null;
    }
  });

  test("Quản lý trang: lọc/URL, tạo (client + 413 + 422 theo field), sửa + ảnh, gán GV, xuất bản/ngừng bán, thứ tự, xoá, khoá", async ({ page, request }) => {
    test.setTimeout(420_000);
    await loginMfa(page, request, STAFF);
    await expect(page.getByRole("heading", { name: "Khóa học", level: 1 })).toBeVisible();
    await expect(nav(page).getByRole("link", { name: "Khóa học" }).first()).toHaveAttribute("aria-current", "page");
    otherTeacherCourseId = (await findCourse(page, "E2E FA3 Của GV khác")).id as number;
    publishedId = (await findCourse(page, "E2E FA3 Đã xuất bản")).id as number;

    await test.step("lọc/tìm/phân trang trên URL, F5 giữ nguyên", async () => {
      await page.getByLabel("Tìm theo tên").fill("E2E FA3");
      await expect(page).toHaveURL(/q=E2E\+FA3/, { timeout: 10_000 });
      await expect(row(page, "E2E FA3 Nháp trống")).toBeVisible();
      await expect(row(page, "E2E FA3 Đã xuất bản")).toBeVisible();
      await page.getByLabel("Trạng thái", { exact: true }).selectOption("published");
      await expect(page).toHaveURL(/status=published/);
      await expect(row(page, "E2E FA3 Nháp trống")).toHaveCount(0);
      await expect(row(page, "E2E FA3 Đã xuất bản")).toBeVisible();
      await page.getByLabel("Trạng thái", { exact: true }).selectOption("");
      await expect(page).not.toHaveURL(/status=/);
      await page.getByLabel("Lớp", { exact: true }).selectOption("9");
      await expect(page).toHaveURL(/grade_level=9/);
      await expect(row(page, "E2E FA3 Nháp trống")).toHaveCount(0);
      await page.getByLabel("Lớp", { exact: true }).selectOption("");
      await expect(page).not.toHaveURL(/grade_level=/);
      await page.getByLabel("Chuyên đề", { exact: true }).selectOption({ label: "E2E FA3 Hình học" });
      await expect(page).toHaveURL(/subject_id=\d+/);
      await expect(row(page, "E2E FA3 Của GV khác")).toBeVisible();
      await expect(row(page, "E2E FA3 Nháp trống")).toHaveCount(0);
      await page.reload();
      await expect(page.getByLabel("Tìm theo tên")).toHaveValue("E2E FA3");
      await expect(page.getByLabel("Chuyên đề", { exact: true })).not.toHaveValue("");
      await expect(row(page, "E2E FA3 Của GV khác")).toBeVisible();
      await page.getByLabel("Chuyên đề", { exact: true }).selectOption("");
      await expect(page).not.toHaveURL(/subject_id=/);
      await page.getByLabel("Giáo viên", { exact: true }).selectOption({ label: "E2E FA3 GV Hai" });
      await expect(page).toHaveURL(/teacher_id=\d+/);
      await expect(row(page, "E2E FA3 Của GV khác")).toBeVisible();
      await expect(row(page, "E2E FA3 Nháp trống")).toHaveCount(0);
      await page.getByLabel("Giáo viên", { exact: true }).selectOption("");
      await expect(page).not.toHaveURL(/teacher_id=/);
    });

    await test.step("tạo: lỗi client (rỗng, < >, ảnh SVG đổi đuôi, ảnh > 2 MB)", async () => {
      await page.getByRole("link", { name: "Tạo khóa học", exact: true }).click();
      await expect(page).toHaveURL(`${ADMIN}/quan-tri/khoa-hoc/tao`);
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      for (const msg of ["Vui lòng nhập tên khóa học.", "Vui lòng chọn lớp (6–12).", "Vui lòng chọn ít nhất 1 chuyên đề.", "Vui lòng nhập mô tả khóa học.", "Vui lòng nhập học phí.", "Khóa học cần tối thiểu 1 giáo viên phụ trách.", "Vui lòng chọn ảnh bìa."]) {
        await expect(page.getByText(msg)).toBeVisible();
      }
      await page.getByLabel(/Tên khóa học/).fill("E2E FA3 a < b");
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      await expect(page.getByText(/không được chứa ký tự < hoặc >/)).toBeVisible();
      const input = page.getByLabel(/Chọn ảnh/);
      await input.setInputFiles({ name: "x.jpg", mimeType: "image/jpeg", buffer: Buffer.from("<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>") });
      await expect(page.getByText("Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.")).toBeVisible();
      const big = Buffer.concat([png(64, 36, [1, 2, 3]), Buffer.alloc(2 * 1024 * 1024 + 10)]);
      await input.setInputFiles({ name: "big.png", mimeType: "image/png", buffer: big });
      await expect(page.getByText("Ảnh tối đa 2 MB.")).toBeVisible();
      await expect(page.getByAltText("Xem trước ảnh bìa mới")).toHaveCount(0);
    });

    await test.step("tạo: 413 thật của Nginx (thân > 5 MB) và 413 dạng HTML có CORS", async () => {
      await page.getByLabel(/Tên khóa học/).fill(CREATED);
      await page.getByLabel(/^Lớp/).selectOption("7");
      await page.getByTestId("subjects-group").getByRole("checkbox", { name: "E2E FA3 Đại số" }).check();
      await page.getByLabel(/Mô tả ngắn/).fill("Mô tả ngắn E2E");
      await page.getByLabel(/Mô tả chi tiết/).fill("Đoạn một.\n\nĐoạn hai & <ba.");
      await page.getByLabel(/Học phí/).fill("250000");
      await expect(page.getByText("250.000")).toBeVisible();
      await page.getByTestId("teachers-group").getByLabel("Thêm giáo viên").selectOption({ label: "E2E FA3 GV Hai" });
      await page.getByLabel(/Chọn ảnh/).setInputFiles(IMG_A);
      await expect(page.getByAltText("Xem trước ảnh bìa mới")).toBeVisible();

      await page.route("**/api/v1/admin/courses", async (route) => {
        const req = route.request();
        if (req.method() !== "POST") return route.continue();
        await route.continue({ postData: Buffer.concat([req.postDataBuffer()!, Buffer.alloc(6 * 1024 * 1024, 0x20)]) });
      });
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      // Nginx trả 413 không kèm CORS: trình duyệt thấy lỗi mạng (banner kèm gợi ý 2 MB) hoặc 413 → lỗi dưới ô ảnh.
      await expect(page.getByText(/(không quá 2 MB)|(Tệp tải lên quá lớn)/)).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("button", { name: "Tạo khóa học (nháp)" })).toBeEnabled();
      await page.unroute("**/api/v1/admin/courses");

      await page.route("**/api/v1/admin/courses", (route) =>
        route.request().method() === "POST"
          ? route.fulfill({ status: 413, contentType: "text/html", headers: CORS, body: "<html><body><h1>413 Request Entity Too Large</h1></body></html>" })
          : route.continue(),
      );
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      await expect(page.getByText("Tệp tải lên quá lớn. Ảnh bìa tối đa 2 MB.")).toBeVisible();
      await expect(page.getByLabel(/Tên khóa học/)).toHaveValue(CREATED);
      await page.unroute("**/api/v1/admin/courses");
    });

    await test.step("tạo: 422 thật subject_ids.0 (chuyên đề bị ẩn giữa chừng) hiện dưới nhóm chuyên đề; sửa rồi lưu được", async () => {
      const subj = await apiCall(page, "GET", `/admin/subjects?q=${encodeURIComponent("E2E FA3 Đại số")}&per_page=25`);
      hiddenSubjectRestore = (subj.body!.data as { id: number; name: string }[]).find((s) => s.name === "E2E FA3 Đại số")!.id;
      expect((await apiCall(page, "PATCH", `/admin/subjects/${hiddenSubjectRestore}/status`, { status: "hidden" })).status).toBe(200);
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      await expect(page.getByTestId("subjects-group").getByText("Chuyên đề không tồn tại hoặc đang ẩn.")).toBeVisible({ timeout: 15_000 });
      await expect(page.getByLabel(/Tên khóa học/)).toHaveValue(CREATED);
      expect((await apiCall(page, "PATCH", `/admin/subjects/${hiddenSubjectRestore}/status`, { status: "active" })).status).toBe(200);
      hiddenSubjectRestore = null;
      await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
      await expect(page).toHaveURL(/\/quan-tri\/khoa-hoc\/\d+\/sua$/, { timeout: 30_000 });
      ownCourseId = Number(/\/(\d+)\/sua$/.exec(page.url())![1]);
      // (toast "Đã tạo khóa học (bản nháp)" chỉ hiện 4s, dev server biên dịch trang sửa lâu hơn nên không khẳng định ở đây.)
      await expect(page.getByRole("heading", { name: CREATED })).toBeVisible();
      await expect(page.getByText("Nháp", { exact: true })).toBeVisible();
      const created = await apiCall(page, "GET", `/admin/courses/${ownCourseId}`);
      expect(created.body).toMatchObject({ status: "draft", grade_level: 7, price: 250000 });
      expect(created.body!.description).toContain("<p>Đoạn một.</p>");
      expect(created.body!.description).toContain("&amp; &lt;ba");
      expect(created.body!.thumbnail_url).toBeTruthy();
    });

    let firstThumb = "";
    await test.step("sửa: dữ liệu nạp sẵn, đổi tên/giá + ảnh mới (POST _method=PUT), F5 giữ", async () => {
      firstThumb = (await apiCall(page, "GET", `/admin/courses/${ownCourseId}`)).body!.thumbnail_url as string;
      await expect(page.getByLabel(/Tên khóa học/)).toHaveValue(CREATED);
      await expect(page.getByLabel(/Học phí/)).toHaveValue("250000");
      await expect(page.getByLabel(/^Lớp/)).toHaveValue("7");
      await expect(page.getByTestId("subjects-group").getByRole("checkbox", { name: "E2E FA3 Đại số" })).toBeChecked();
      await page.getByLabel(/Tên khóa học/).fill(`${CREATED} (sửa)`);
      await page.getByLabel(/Học phí/).fill("0");
      await expect(page.getByLabel("Khóa học miễn phí")).toBeChecked();
      await page.getByLabel(/Chọn ảnh/).setInputFiles(IMG_B);
      const putReq = page.waitForRequest((r) => r.url().endsWith(`/admin/courses/${ownCourseId}`) && r.method() === "POST");
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      expect((await putReq).headers()["content-type"]).toContain("multipart/form-data"); // POST multipart + _method=PUT (server cập nhật được = _method đã gửi đúng)
      await expect(page.getByText("Đã lưu thay đổi")).toBeVisible({ timeout: 20_000 });
      await expect(page.getByRole("heading", { name: `${CREATED} (sửa)` })).toBeVisible();
      await page.reload();
      await expect(page.getByLabel(/Tên khóa học/)).toHaveValue(`${CREATED} (sửa)`);
      await expect(page.getByLabel(/Học phí/)).toHaveValue("0");
      const after = (await apiCall(page, "GET", `/admin/courses/${ownCourseId}`)).body!;
      expect(after.thumbnail_url).not.toBe(firstThumb);
      // Không có thay đổi → báo, không gọi API.
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      await expect(page.getByText("Chưa có thay đổi nào để lưu.")).toBeVisible();
    });

    await test.step("gán giáo viên: errors.teacher_ids (GV bị khoá mới thêm), giữ GV đã gán nay bị khoá, không gỡ hết", async () => {
      const lockedCourse = await findCourse(page, "E2E FA3 Có GV bị khóa và chuyên đề ẩn");
      const lockedTeacher = (lockedCourse.teachers as { id: number; name: string }[]).find((t) => t.name === "E2E FA3 GV Khoa")!;
      // Trên khóa của mình: thêm một GV hợp lệ nhưng sửa body gửi kèm GV bị khoá → server trả errors.teacher_ids.
      const card = page.getByTestId("teachers-group");
      await expect(card.getByRole("button", { name: "Bỏ E2E FA3 GV Hai" })).toBeVisible();
      await page.route(`**/api/v1/admin/courses/${ownCourseId}/teachers`, async (route) => {
        const body = JSON.parse(route.request().postData() ?? "{}") as { teacher_ids: number[] };
        await route.continue({ postData: JSON.stringify({ teacher_ids: [...body.teacher_ids, lockedTeacher.id] }) });
      });
      await card.getByLabel("Thêm giáo viên").selectOption({ label: "E2E FA3 GV Ba" });
      const rejected = page.waitForResponse((r) => r.url().endsWith(`/admin/courses/${ownCourseId}/teachers`) && r.request().method() === "PUT");
      await page.getByRole("button", { name: "Lưu giáo viên" }).click();
      expect((await rejected).status()).toBe(422);
      await expect(card.getByText("Chỉ được gán tài khoản giáo viên đang hoạt động.")).toBeVisible({ timeout: 15_000 });
      await page.unroute(`**/api/v1/admin/courses/${ownCourseId}/teachers`);
      await page.getByRole("button", { name: "Lưu giáo viên" }).click();
      await expect(page.getByText("Đã cập nhật giáo viên phụ trách")).toBeVisible({ timeout: 15_000 });
      expect(((await apiCall(page, "GET", `/admin/courses/${ownCourseId}`)).body!.teachers as unknown[]).length).toBe(2);
      // Không gỡ hết được: bỏ người thứ hai ổn, bỏ người cuối bị chặn ngay ở client (BR6).
      await card.getByRole("button", { name: "Bỏ E2E FA3 GV Hai" }).click();
      await card.getByRole("button", { name: "Bỏ E2E FA3 GV Ba" }).click();
      await expect(card.getByText("Khóa học cần tối thiểu 1 giáo viên phụ trách.")).toBeVisible();
      await expect(card.getByRole("button", { name: "Bỏ E2E FA3 GV Ba" })).toBeVisible();

      // Khóa có GV bị khoá + chuyên đề ẩn: hiện đánh dấu, lưu tên không gửi lại chuyên đề, thêm GV vẫn giữ người bị khoá.
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${lockedCourse.id}/sua`);
      await expect(page.getByTestId("subjects-group").getByRole("checkbox", { name: /E2E FA3 Đã ẩn/ })).toBeChecked({ timeout: 20_000 });
      await expect(page.getByTestId("teachers-group").getByText(/E2E FA3 GV Khoa/)).toBeVisible();
      await expect(page.getByTestId("teachers-group").getByText("(không còn hoạt động)")).toBeVisible();
      await page.getByLabel(/Tên khóa học/).fill("E2E FA3 Có GV bị khóa và chuyên đề ẩn");
      await page.getByLabel(/Mô tả ngắn/).fill("Đã chạm");
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      await expect(page.getByText("Đã lưu thay đổi")).toBeVisible({ timeout: 20_000 });
      await page.getByTestId("teachers-group").getByLabel("Thêm giáo viên").selectOption({ label: "E2E FA3 GV Hai" });
      await page.getByRole("button", { name: "Lưu giáo viên" }).click();
      await expect(page.getByText("Đã cập nhật giáo viên phụ trách")).toBeVisible({ timeout: 15_000 });
      const ids = ((await apiCall(page, "GET", `/admin/courses/${lockedCourse.id}`)).body!.teachers as { name: string }[]).map((t) => t.name);
      expect(ids).toContain("E2E FA3 GV Khoa");
      expect(ids).toContain("E2E FA3 GV Hai");
    });

    await test.step("xuất bản (ở trang sửa): chưa có chương/bài → thông báo AC3; có nội dung → thành công; ngừng bán/xuất bản lại", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${ownCourseId}/sua`);
      await expect(page.getByRole("heading", { name: `${CREATED} (sửa)` })).toBeVisible({ timeout: 20_000 });
      await page.getByRole("button", { name: "Xuất bản", exact: true }).click();
      const dialog = page.getByRole("dialog");
      await expect(dialog).toContainText("Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.");
      await dialog.getByRole("button", { name: "Đã hiểu" }).click();
      await expect(dialog).toBeHidden();
      await expect(page.getByText("Nháp", { exact: true })).toBeVisible();

      contentId = (await findCourse(page, "E2E FA3 Có nội dung")).id as number;
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${contentId}/sua`);
      await page.getByRole("button", { name: "Xuất bản", exact: true }).click({ timeout: 20_000 });
      await expect(page.getByText("Đã xuất bản khóa học")).toBeVisible({ timeout: 15_000 });
      await expect(page.getByText("Đã xuất bản", { exact: true })).toBeVisible();
      await page.getByRole("button", { name: "Ngừng bán", exact: true }).click();
      await expect(page.getByRole("dialog")).toContainText("học sinh đã mua vẫn giữ quyền truy cập");
      await page.getByRole("dialog").getByRole("button", { name: "Ngừng bán" }).click();
      await expect(page.getByText("Đã ngừng bán khóa học")).toBeVisible({ timeout: 15_000 });
      await expect(page.getByText("Ngừng bán", { exact: true })).toBeVisible();
      await page.getByRole("button", { name: "Xuất bản lại" }).click();
      await expect(page.getByText("Đã xuất bản", { exact: true })).toBeVisible({ timeout: 15_000 });
      // Danh sách phản ánh trạng thái mới (ở 1280px cột "Trạng thái" hiện; dòng phụ dưới md bị CSS ẩn).
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc?q=${encodeURIComponent("E2E FA3 Có nội dung")}`);
      await expect(row(page, "E2E FA3 Có nội dung").getByText("Đã xuất bản", { exact: true }).filter({ visible: true })).toBeVisible({ timeout: 20_000 });
    });

    await test.step("thứ tự nổi bật (ở trang sửa): số sai bị chặn, lưu và giữ sau F5, xoá thứ tự", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${contentId}/sua`);
      const input = page.getByLabel(/Thứ tự nổi bật/);
      await input.fill("-3");
      await page.getByRole("button", { name: "Lưu thứ tự" }).click();
      await expect(page.getByText(/từ 0 đến 1\.000\.000/)).toBeVisible();
      await input.fill("7");
      await page.getByRole("button", { name: "Lưu thứ tự" }).click();
      await expect(page.getByText("Đã lưu thứ tự nổi bật")).toBeVisible({ timeout: 15_000 });
      await page.reload();
      await expect(page.getByLabel(/Thứ tự nổi bật/)).toHaveValue("7", { timeout: 20_000 });
      await page.getByLabel(/Thứ tự nổi bật/).fill("");
      await page.getByRole("button", { name: "Lưu thứ tự" }).click();
      await expect(page.getByText("Đã lưu thứ tự nổi bật").first()).toBeVisible({ timeout: 15_000 });
      await page.reload();
      await expect(page.getByLabel(/Thứ tự nổi bật/)).toHaveValue("", { timeout: 20_000 });
    });

    await test.step("xoá: có học sinh bị khoá nút; 409 thật khi số liệu cũ; hộp thoại gợi ý Ngừng bán", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${publishedId}/sua`);
      await expect(page.getByText(/Không thể xoá vì đã có \d+ học sinh đăng ký/)).toBeVisible({ timeout: 20_000 });
      await expect(page.getByRole("button", { name: "Xoá khóa học" })).toBeDisabled();
      // Giả lập dữ liệu cũ (enrollments_count=0) để bấm được; DELETE là thật → server 409 COURSE_HAS_ENROLLMENTS.
      const detailUrl = new RegExp(`/api/v1/admin/courses/${publishedId}$`);
      await page.route(detailUrl, async (route) => {
        if (route.request().method() !== "GET") return route.continue();
        const res = await route.fetch();
        const json = (await res.json()) as Json;
        json.enrollments_count = 0;
        // Có thể đã gỡ route khi lượt tải lại (sau 409) còn đang chạy → bỏ qua lỗi "already handled".
        await route.fulfill({ response: res, json }).catch(() => undefined);
      });
      await page.reload();
      await expect(page.getByRole("button", { name: "Xoá khóa học" })).toBeEnabled({ timeout: 20_000 });
      await page.getByRole("button", { name: "Xoá khóa học" }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Xoá", exact: true }).click();
      await expect(page.getByRole("dialog")).toContainText("Không thể xoá", { timeout: 15_000 });
      await expect(page.getByRole("dialog")).toContainText("đã có học sinh đăng ký");
      await page.unroute(detailUrl);
      await page.getByRole("dialog").getByRole("button", { name: "Ngừng bán" }).click();
      await expect(page.getByText("Đã ngừng bán khóa học")).toBeVisible({ timeout: 15_000 });
      await expect(page.getByRole("dialog")).toHaveCount(0);
      await expect(page.getByRole("button", { name: "Xuất bản lại" })).toBeVisible({ timeout: 15_000 }); // đã chuyển sang "Ngừng bán"
      // Trả lại trạng thái đã xuất bản cho test giáo viên.
      await page.getByRole("button", { name: "Xuất bản lại" }).click();
      await expect(page.getByText("Đã xuất bản", { exact: true })).toBeVisible({ timeout: 15_000 });
    });

    await test.step("khóa học bị xoá từ nơi khác: lưu ở trang sửa → báo 404, màn 'không tìm thấy'", async () => {
      const gone = await findCourse(page, "E2E FA3 Nháp trống");
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${gone.id}/sua`);
      await expect(page.getByLabel(/Tên khóa học/)).toBeVisible({ timeout: 20_000 });
      expect((await apiCall(page, "DELETE", `/admin/courses/${gone.id}`)).status).toBe(204);
      await page.getByLabel(/Tên khóa học/).fill("E2E FA3 Nháp trống sửa");
      await page.getByRole("button", { name: "Lưu thay đổi" }).click();
      await expect(page.getByTestId("course-not-found")).toBeVisible({ timeout: 15_000 });
    });

    await test.step("xoá ở trang sửa (chưa có học sinh): xác nhận → về danh sách", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${ownCourseId}/sua`);
      await expect(page.getByRole("button", { name: "Xoá khóa học" })).toBeEnabled({ timeout: 20_000 });
      await page.getByRole("button", { name: "Xoá khóa học" }).click();
      await expect(page.getByRole("dialog")).toContainText("Hành động này không thể hoàn tác");
      await page.getByRole("dialog").getByRole("button", { name: "Xoá", exact: true }).click();
      await expect(page).toHaveURL(`${ADMIN}/quan-tri/khoa-hoc`, { timeout: 20_000 });
      expect((await apiCall(page, "GET", `/admin/courses/${ownCourseId}`)).status).toBe(404);
    });

    await test.step("375px: danh sách, tạo, sửa không tràn ngang, ô nhập cao >= 44px", async () => {
      await page.setViewportSize({ width: 375, height: 800 });
      for (const path of ["/quan-tri/khoa-hoc", "/quan-tri/khoa-hoc/tao", `/quan-tri/khoa-hoc/${publishedId}/sua`]) {
        await page.goto(`${ADMIN}${path}`);
        await expect(page.getByRole("heading").first()).toBeVisible({ timeout: 20_000 });
        await expect(page.getByLabel(/Tên khóa học|Tìm theo tên/).first()).toBeVisible();
        expect(await noOverflow(page)).toBeLessThanOrEqual(0);
        const box = await page.getByLabel(/Tên khóa học|Tìm theo tên/).first().boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
      await page.setViewportSize({ width: 1280, height: 800 });
    });

    await test.step("khoá giữa phiên (403 ACCOUNT_LOCKED): phía sau overlay không còn menu/tên/bảng/cảnh báo cũ", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
      await expect(page.getByRole("table")).toBeVisible({ timeout: 20_000 });
      await page.route(/\/api\/v1\/admin\/courses\?/, (route) =>
        route.fulfill({ status: 403, contentType: "application/json", headers: CORS, body: JSON.stringify({ message: "Tài khoản đã bị khóa.", code: "ACCOUNT_LOCKED" }) }),
      );
      await page.getByLabel("Tìm theo tên").fill("khoa");
      await expect(page.getByRole("alertdialog")).toContainText("Tài khoản đã bị khóa", { timeout: 15_000 });
      await expect(nav(page)).toHaveCount(0);
      await expect(page.getByTestId("staff-name")).toHaveCount(0);
      await expect(page.getByRole("table")).toHaveCount(0);
      await expect(page.getByText("Không tải được danh sách khóa học")).toHaveCount(0);
      await expect(page.getByText("Bạn không có quyền thực hiện thao tác này.")).toHaveCount(0);
      await page.unroute(/\/api\/v1\/admin\/courses\?/);
    });
  });

  test("Giáo viên: chỉ thấy khóa được gán, không có nút xuất bản/xoá/thứ tự, 403 khóa lạ, tạo khóa tự gán mình", async ({ page }) => {
    test.setTimeout(240_000);
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc`);
    await expect(page.getByRole("heading", { name: "Khóa học", level: 1 })).toBeVisible();
    await page.getByLabel("Tìm theo tên").fill("E2E FA3");
    await expect(row(page, "E2E FA3 Đã xuất bản")).toBeVisible({ timeout: 15_000 });
    await expect(row(page, "E2E FA3 Của GV khác")).toHaveCount(0);
    await expect(page.getByLabel("Giáo viên", { exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: /Xuất bản|Ngừng bán|Xoá|Đặt thứ tự/ })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Tạo khóa học", exact: true })).toBeVisible();
    // GV cố ép teacher_id/status vào URL: vẫn chỉ thấy khóa của mình (server lọc).
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc?q=${encodeURIComponent("E2E FA3")}&teacher_id=${1}`);
    await expect(row(page, "E2E FA3 Của GV khác")).toHaveCount(0);

    // Khóa lạ → 403; id lạ → 404 thân thiện; id sai định dạng → trang 404.
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${otherTeacherCourseId}/sua`);
    await expect(page.getByTestId("course-forbidden")).toContainText("Bạn không có quyền truy cập khóa học này.", { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/99999999/sua`);
    await expect(page.getByTestId("course-not-found")).toBeVisible({ timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/abc/sua`);
    await expect(page.getByText(/404|không tìm thấy|This page could not be found/i).first()).toBeVisible();

    // Khóa đã xuất bản được gán: lớp chỉ đọc, ẩn giá/gán GV/xuất bản/xoá; sửa tên (JSON PUT) vẫn lưu được.
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${publishedId}/sua`);
    await expect(page.getByLabel(/Tên khóa học/)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByLabel(/^Lớp/)).toBeDisabled();
    await expect(page.getByText(/chỉ Admin\/Quản lý trang đổi được lớp/)).toBeVisible();
    await expect(page.getByLabel(/Học phí/)).toBeDisabled();
    await expect(page.getByTestId("teachers-group")).toHaveCount(0);
    await expect(page.getByRole("button", { name: /Xuất bản|Ngừng bán|^Xoá$/ })).toHaveCount(0);
    await expect(page.getByText("Khóa học sẽ được Admin xem xét và xuất bản.")).toBeVisible();
    await page.getByLabel(/Tên khóa học/).fill("E2E FA3 Đã xuất bản");
    await page.getByLabel(/Mô tả ngắn/).fill(`GV sửa ${stamp}`);
    await page.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByText("Đã lưu thay đổi")).toBeVisible({ timeout: 20_000 });
    const mine = await apiCall(page, "GET", `/admin/courses/${publishedId}`);
    expect(mine.body!.short_description).toBe(`GV sửa ${stamp}`);
    expect(mine.body!.abilities).toMatchObject({ publish: false, delete: false, manage_teachers: false, edit_price: false, edit_grade_level: false });
    // API vẫn là lớp bảo vệ thật: GV gọi thẳng publish/xoá/gán GV → 403.
    expect((await apiCall(page, "POST", `/admin/courses/${publishedId}/unpublish`)).status).toBe(403);
    expect((await apiCall(page, "DELETE", `/admin/courses/${publishedId}`)).status).toBe(403);
    expect((await apiCall(page, "GET", "/admin/teachers")).status).toBe(403);

    // Tạo khóa: không có ô giáo viên, tự được gán.
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/tao`);
    await expect(page.getByLabel(/Tên khóa học/)).toBeVisible();
    await expect(page.getByTestId("teachers-group")).toHaveCount(0);
    await expect(page.getByText(/tự động là giáo viên phụ trách/)).toBeVisible();
    await page.getByLabel(/Tên khóa học/).fill(`E2E FA3 GV tạo ${stamp}`);
    await page.getByLabel(/^Lớp/).selectOption("10");
    await page.getByTestId("subjects-group").getByRole("checkbox", { name: "E2E FA3 Hình học" }).check();
    await page.getByLabel(/Mô tả chi tiết/).fill("Nội dung do giáo viên tạo");
    await page.getByLabel("Khóa học miễn phí").check();
    await page.getByLabel(/Chọn ảnh/).setInputFiles(IMG_A);
    await page.getByRole("button", { name: "Tạo khóa học (nháp)" }).click();
    await expect(page).toHaveURL(/\/quan-tri\/khoa-hoc\/\d+\/sua$/, { timeout: 30_000 });
    const gvCourseId = Number(/\/(\d+)\/sua$/.exec(page.url())![1]);
    await expect(page.getByLabel(/^Lớp/)).toBeEnabled({ timeout: 20_000 }); // chưa xuất bản → còn đổi được lớp
    await expect(page.getByLabel(/Học phí/)).toBeDisabled();
    const created = await apiCall(page, "GET", `/admin/courses/${gvCourseId}`);
    expect((created.body!.teachers as { name: string }[]).map((t) => t.name)).toEqual(["E2E teacher"]);
    expect(created.body!.status).toBe("draft");
  });
});
