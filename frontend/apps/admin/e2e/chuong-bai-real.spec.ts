import { closeSync, mkdtempSync, openSync, readFileSync, rmSync, writeSync, ftruncateSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { expect, test, type APIRequestContext, type Locator, type Page, type Request } from "@playwright/test";

/**
 * FA4 — e2e THẬT "Chương & bài": cây kéo-thả (dnd-kit), form bài, tải video TUS lên VideoLab nội bộ (VIDEO_PROVIDER=internal),
 * tiếp tục khi rớt mạng, huỷ, 1 GB, trạng thái xử lý. KHÔNG gọi Bunny.
 * Chạy với E2E_REAL_BACKEND=1 (admin-api.localhost:3001 + :8000, video.localhost:8000, queue `video` đang chạy, MFA đọc từ Mailpit).
 * Seed trước: `frontend/apps/admin/e2e/seed-e2e-curriculum.sh` (--reset để về dữ liệu ban đầu; --clean để xoá hết).
 * Chạy `--workers=1 --retries=0` (mỗi lần thử lại tốn một mã OTP). Dữ liệu thêm trong lúc chạy đều có tiền tố "E2E FA4 ".
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
const STAFF = process.env.FA4_STAFF ?? "fa4-qlt1";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const MB = 1024 * 1024;
const MINI = readFileSync(path.join(__dirname, "fixtures", "mini.mp4"));
const email = (n: string) => `e2e-${n}@example.com`;
type Json = Record<string, unknown>;

/** MP4 hợp lệ lớn hơn: thêm một box `free` (padding) ở cuối nên ffmpeg vẫn đọc bình thường. */
function paddedMp4(extraBytes: number): Buffer {
  const header = Buffer.alloc(8);
  header.writeUInt32BE(extraBytes + 8, 0);
  header.write("free", 4, "ascii");
  return Buffer.concat([MINI, header, Buffer.alloc(extraBytes)]);
}

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
async function apiCall(page: Page, method: string, apiPath: string, body?: unknown) {
  return page.evaluate(
    async ({ api, method, apiPath, body }) => {
      const base = { credentials: "include" as const, headers: { Accept: "application/json", "X-Device-Id": localStorage.getItem("vv_device_id") ?? "" } as Record<string, string> };
      const { token } = (await (await fetch(`${api}/csrf-token`, base)).json()) as { token: string };
      const headers = { ...base.headers, "X-CSRF-TOKEN": token, ...(body ? { "Content-Type": "application/json" } : {}) };
      const res = await fetch(`${api}${apiPath}`, { ...base, method, headers, body: body ? JSON.stringify(body) : undefined });
      const text = await res.text();
      let json: unknown = null;
      try {
        json = JSON.parse(text);
      } catch {
        /* 204 */
      }
      return { status: res.status, body: json as Json | null };
    },
    { api: API, method, apiPath, body },
  );
}

async function findCourse(page: Page, title: string): Promise<number> {
  const res = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent(title)}`);
  const hit = (res.body!.data as Json[]).find((c) => c.title === title);
  if (!hit) throw new Error(`Không thấy khóa "${title}" (đã chạy seed-e2e-curriculum.sh chưa?)`);
  return hit.id as number;
}

type Tree = Array<{ id: number; title: string; lessons: Array<{ id: number; title: string; video_status: string | null; video_source: string; external_provider: string | null; is_preview: boolean }> }>;
async function tree(page: Page, courseId: number): Promise<Tree> {
  const res = await apiCall(page, "GET", `/admin/courses/${courseId}/chapters`);
  expect(res.status).toBe(200);
  return (res.body as { chapters: Tree }).chapters;
}
const shape = (t: Tree) => t.map((c) => [c.title, ...c.lessons.map((l) => l.title)].join(" > "));

const chapter = (page: Page, title: string) => page.getByRole("region", { name: title });
const lessonLink = (page: Page, title: string) => page.getByRole("link", { name: new RegExp(title) });
const grip = (page: Page, title: string) => page.getByRole("button", { name: `Kéo để đổi thứ tự: ${title}` });
const panel = (page: Page) => page.getByTestId("video-panel");
const toastText = (page: Page, text: string) => expect(page.getByText(text).first()).toBeVisible({ timeout: 15_000 });
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

async function openCurriculum(page: Page, courseId: number, lesson?: number) {
  await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${courseId}/sua?tab=chuong-bai${lesson ? `&bai=${lesson}` : ""}`);
  await expect(page.getByRole("navigation", { name: "Phần của khóa học" }).getByRole("link", { name: /^Chương & bài/ })).toHaveAttribute("aria-current", "page", { timeout: 25_000 });
}

/** Kéo bằng chuột thật (dnd-kit cần pointermove nhiều bước vượt ngưỡng 6px). */
async function drag(page: Page, from: Locator, to: Locator) {
  const a = (await from.boundingBox())!;
  const b = (await to.boundingBox())!;
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2 + 10, { steps: 4 });
  await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 20 });
  await page.mouse.up();
}

const isVideoHost = (url: URL) => url.host.startsWith("video.localhost");

/**
 * Một handler duy nhất cho mọi yêu cầu tới host video (không gỡ giữa chừng: gỡ khi còn yêu cầu đang chờ gây "Route is already handled").
 * Điều khiển bằng biến: làm chậm mỗi PATCH, hoặc cắt mạng (abort) ở lần PATCH thứ n.
 */
async function installVideoNet(page: Page) {
  const net = { patchDelayMs: 0, abortPatches: new Set<number>(), patches: 0, seen: [] as Array<{ method: string; offset: string | undefined }> };
  await page.route(isVideoHost, async (route) => {
    const req = route.request();
    if (req.method() === "PATCH" || req.method() === "HEAD") net.seen.push({ method: req.method(), offset: req.headers()["upload-offset"] });
    if (req.method() === "PATCH") {
      net.patches++;
      if (net.abortPatches.has(net.patches)) return route.abort("internetdisconnected");
      if (net.patchDelayMs > 0) await new Promise((r) => setTimeout(r, net.patchDelayMs));
    }
    await route.continue().catch(() => undefined);
  });
  return net;
}

let treeCourse = 0;
let videoCourse = 0;
let otherCourse = 0;
let tmp = "";

test.beforeAll(() => {
  tmp = mkdtempSync(path.join(tmpdir(), "fa4-"));
});
test.afterAll(() => {
  rmSync(tmp, { recursive: true, force: true });
});

test.describe("FA4 chương & bài (thật)", () => {
  test("Giáo viên: cây, thêm chương/bài, kéo-thả chuột, Lên/Xuống, form bài + link ngoài, xoá, URL, 375px", async ({ page }) => {
    test.setTimeout(300_000);
    await fillLogin(page, "fa4-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    treeCourse = await findCourse(page, "E2E FA4 Khóa dựng cây");
    videoCourse = await findCourse(page, "E2E FA4 Khóa video");

    await test.step("giáo viên được gán: mở tab từ màn sửa khóa, thấy cây", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${treeCourse}/sua`);
      await page.getByRole("navigation", { name: "Phần của khóa học" }).getByRole("link", { name: /^Chương & bài/ }).click();
      await expect(page).toHaveURL(/tab=chuong-bai/);
      await expect(chapter(page, "E2E FA4 Chương A")).toBeVisible({ timeout: 20_000 });
      await expect(chapter(page, "E2E FA4 Chương A").getByRole("link")).toHaveCount(3);
      await expect(chapter(page, "E2E FA4 Chương B")).toContainText("Chương chưa có bài học");
      await expect(page.getByText("Chọn một bài để sửa")).toBeVisible();
    });

    await test.step("thêm chương (422 trống ở client, rồi thành công) và thêm bài đầu tiên vào chương rỗng", async () => {
      await page.getByRole("button", { name: "Thêm chương", exact: true }).click();
      const dlg = page.getByRole("dialog");
      await dlg.getByRole("button", { name: "Thêm chương" }).click();
      await expect(dlg.getByText("Tên chương không được để trống.")).toBeVisible();
      await dlg.getByLabel(/Tên chương/).fill("E2E FA4 Chương C");
      await dlg.getByRole("button", { name: "Thêm chương" }).click();
      await expect(chapter(page, "E2E FA4 Chương C")).toBeVisible();
      await chapter(page, "E2E FA4 Chương B").getByRole("button", { name: "Thêm bài đầu tiên" }).click();
      await page.getByRole("dialog").getByLabel(/Tên bài học/).fill("E2E FA4 Bài B1");
      await page.getByRole("dialog").getByRole("button", { name: "Thêm bài" }).click();
      await expect(page).toHaveURL(/bai=\d+/);
      await expect(page.getByRole("heading", { name: "E2E FA4 Bài B1", level: 2 })).toBeVisible();
      expect(shape(await tree(page, treeCourse))).toEqual([
        "E2E FA4 Chương A > E2E FA4 Bài A1 > E2E FA4 Bài A2 > E2E FA4 Bài A3",
        "E2E FA4 Chương B > E2E FA4 Bài B1",
        "E2E FA4 Chương C",
      ]);
    });

    await test.step("kéo-thả bằng chuột: bài A3 lên đầu chương A; lưu thật, F5 vẫn đúng", async () => {
      await drag(page, grip(page, "E2E FA4 Bài A3"), lessonLink(page, "E2E FA4 Bài A1"));
      await toastText(page, "Đã lưu thứ tự");
      expect((await tree(page, treeCourse))[0]!.lessons.map((l) => l.title)).toEqual(["E2E FA4 Bài A3", "E2E FA4 Bài A1", "E2E FA4 Bài A2"]);
      await page.reload();
      await expect(chapter(page, "E2E FA4 Chương A").getByRole("link").first()).toContainText("E2E FA4 Bài A3", { timeout: 20_000 });
    });

    await test.step("kéo bài sang chương khác (thả lên bài B1) và thả vào chương rỗng C", async () => {
      await drag(page, grip(page, "E2E FA4 Bài A2"), lessonLink(page, "E2E FA4 Bài B1"));
      await toastText(page, "Đã lưu thứ tự");
      let t = await tree(page, treeCourse);
      expect(t[1]!.lessons.map((l) => l.title)).toEqual(["E2E FA4 Bài A2", "E2E FA4 Bài B1"]);
      await drag(page, grip(page, "E2E FA4 Bài A1"), page.getByRole("region", { name: "E2E FA4 Chương C" }));
      await expect(chapter(page, "E2E FA4 Chương C").getByRole("link")).toHaveCount(1, { timeout: 15_000 });
      t = await tree(page, treeCourse);
      expect(shape(t)).toEqual(["E2E FA4 Chương A > E2E FA4 Bài A3", "E2E FA4 Chương B > E2E FA4 Bài A2 > E2E FA4 Bài B1", "E2E FA4 Chương C > E2E FA4 Bài A1"]);
    });

    await test.step("kéo chương C lên đầu", async () => {
      await drag(page, grip(page, "E2E FA4 Chương C"), page.getByRole("heading", { name: "E2E FA4 Chương A" }));
      await expect.poll(async () => (await tree(page, treeCourse)).map((c) => c.title)).toEqual(["E2E FA4 Chương C", "E2E FA4 Chương A", "E2E FA4 Chương B"]);
    });

    await test.step("nút Xuống/Lên trong khung sửa bài (đường bàn phím): qua ranh giới chương, khoá ở hai đầu", async () => {
      // Hiện tại: C[A1] · A[A3] · B[A2, B1]
      await lessonLink(page, "E2E FA4 Bài A1").click();
      await expect(page.getByRole("heading", { name: "E2E FA4 Bài A1", level: 2 })).toBeVisible();
      await expect(page.getByRole("button", { name: "Lên" })).toBeDisabled();
      await page.getByRole("button", { name: "Xuống" }).click();
      await toastText(page, "Đã lưu thứ tự");
      let t = await tree(page, treeCourse);
      expect(t[0]!.lessons.map((l) => l.title)).toEqual([]);
      expect(t[1]!.lessons.map((l) => l.title)).toEqual(["E2E FA4 Bài A1", "E2E FA4 Bài A3"]);
      await expect(page.getByRole("button", { name: "Xuống" })).toBeEnabled();
      await page.getByRole("button", { name: "Lên" }).click();
      await expect(chapter(page, "E2E FA4 Chương C").getByRole("link")).toHaveCount(1, { timeout: 15_000 });
      t = await tree(page, treeCourse);
      expect(t[0]!.lessons.map((l) => l.title)).toEqual(["E2E FA4 Bài A1"]);
    });

    await test.step("form bài: tên rỗng, link sai (422 dưới ô), link YouTube hợp lệ, chặn link ngoài khi không cho xem thử", async () => {
      await lessonLink(page, "E2E FA4 Bài A3").click();
      await expect(page.getByRole("heading", { name: "E2E FA4 Bài A3", level: 2 })).toBeVisible();
      await expect(page.getByRole("radio", { name: /Dán link YouTube/ })).toBeDisabled();
      await expect(page.getByText(/Chỉ bài cho xem thử mới được dùng link ngoài/)).toBeVisible();
      await page.getByRole("checkbox", { name: /Cho xem thử/ }).check();
      await page.getByRole("radio", { name: /Dán link YouTube/ }).check();
      await page.getByRole("button", { name: "Lưu bài học" }).click();
      await expect(page.getByText("Hãy dán link YouTube hoặc Vimeo.")).toBeVisible();
      await page.getByLabel(/Link video/).fill("http://evil.example/watch?v=abc");
      await page.getByRole("button", { name: "Lưu bài học" }).click();
      await expect(page.locator("form").getByText(/link|YouTube|Vimeo|https/i).first()).toBeVisible();
      await page.getByLabel(/Link video/).fill("https://www.youtube.com/watch?v=dQw4w9WgXcQ");
      const nameInput = page.getByLabel(/Tên bài học/);
      await nameInput.fill("E2E FA4 Bài A3 (sửa)");
      await page.getByRole("button", { name: "Lưu bài học" }).click();
      await toastText(page, "Đã lưu bài học");
      const lesson = (await tree(page, treeCourse)).flatMap((c) => c.lessons).find((l) => l.title === "E2E FA4 Bài A3 (sửa)")!;
      expect(lesson).toMatchObject({ video_source: "external_link", external_provider: "youtube", is_preview: true });
      await expect(lessonLink(page, "E2E FA4 Bài A3 \\(sửa\\)")).toContainText("YouTube");
    });

    await test.step("F5 giữ đúng bài đang sửa; bỏ xem thử khi đang link ngoài chuyển về tải lên", async () => {
      await page.reload();
      await expect(page.getByRole("heading", { name: "E2E FA4 Bài A3 (sửa)", level: 2 })).toBeVisible({ timeout: 20_000 });
      await expect(page.getByLabel(/Link video/)).toHaveValue("https://www.youtube.com/watch?v=dQw4w9WgXcQ");
      await page.getByRole("checkbox", { name: /Cho xem thử/ }).uncheck();
      await expect(page.getByTestId("video-panel")).toBeVisible();
      await page.getByRole("button", { name: "Lưu bài học" }).click();
      await toastText(page, "Đã lưu bài học");
      expect((await tree(page, treeCourse)).flatMap((c) => c.lessons).find((l) => l.title === "E2E FA4 Bài A3 (sửa)")).toMatchObject({ video_source: "none", is_preview: false });
    });

    await test.step("đổi bài khi form còn thay đổi chưa lưu: hỏi xác nhận", async () => {
      await page.getByLabel(/Tên bài học/).fill("E2E FA4 đang gõ dở");
      await lessonLink(page, "E2E FA4 Bài B1").click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Còn thay đổi chưa lưu");
      await dlg.getByRole("button", { name: "Ở lại để lưu" }).click();
      await expect(page.getByLabel(/Tên bài học/)).toHaveValue("E2E FA4 đang gõ dở");
      await lessonLink(page, "E2E FA4 Bài B1").click();
      await page.getByRole("dialog").getByRole("button", { name: "Bỏ thay đổi" }).click();
      await expect(page.getByRole("heading", { name: "E2E FA4 Bài B1", level: 2 })).toBeVisible();
    });

    await test.step("đổi tên chương; xoá bài và xoá chương có xác nhận", async () => {
      await chapter(page, "E2E FA4 Chương B").getByRole("button", { name: /Sửa tên/ }).click();
      await page.getByRole("dialog").getByLabel(/Tên chương/).fill("E2E FA4 Chương B2");
      await page.getByRole("dialog").getByRole("button", { name: "Lưu tên" }).click();
      await expect(chapter(page, "E2E FA4 Chương B2")).toBeVisible();
      await page.getByRole("button", { name: "Xoá bài" }).click();
      await expect(page.getByRole("dialog")).toContainText("Xoá bài học này?");
      await page.getByRole("dialog").getByRole("button", { name: "Huỷ" }).click();
      await page.getByRole("button", { name: "Xoá bài" }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Xoá bài" }).click();
      await toastText(page, "Đã xoá bài học");
      await expect(page).not.toHaveURL(/bai=/);
      await page.getByRole("button", { name: "Xoá E2E FA4 Chương C" }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Xoá chương" }).click();
      await toastText(page, "Đã xoá chương");
      await expect(chapter(page, "E2E FA4 Chương C")).toHaveCount(0);
      const names = (await tree(page, treeCourse)).map((c) => c.title);
      expect(names).not.toContain("E2E FA4 Chương C");
      expect(names).toContain("E2E FA4 Chương B2");
    });

    await test.step("quyền: khóa của giáo viên khác → 403 (UI và API)", async () => {
      // giáo viên chính xem được danh sách chỉ khóa của mình
      const list = await apiCall(page, "GET", "/admin/courses?per_page=50&q=E2E%20FA4");
      const titles = (list.body!.data as Json[]).map((c) => c.title);
      expect(titles).toEqual(expect.arrayContaining(["E2E FA4 Khóa dựng cây", "E2E FA4 Khóa video"]));
      expect(titles).not.toContain("E2E FA4 Của GV khác");
    });

    await test.step("375px: không tràn ngang, thêm bài ở cuối chương", async () => {
      await page.setViewportSize({ width: 375, height: 800 });
      await openCurriculum(page, treeCourse);
      await expect(chapter(page, "E2E FA4 Chương A")).toBeVisible({ timeout: 20_000 });
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
      await expect(chapter(page, "E2E FA4 Chương A").getByRole("button", { name: "Thêm bài" }).last()).toBeVisible();
      await lessonLink(page, "E2E FA4 Bài A3").click();
      await expect(page.getByRole("heading", { name: /E2E FA4 Bài A3/, level: 2 })).toBeInViewport();
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    });
  });

  test("Giáo viên: tải video — chặn >1 GB/định dạng, tiến độ, xử lý → sẵn sàng, VIDEO_INVALID", async ({ page }) => {
    test.setTimeout(400_000);
    await fillLogin(page, "fa4-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    videoCourse = await findCourse(page, "E2E FA4 Khóa video");
    const t0 = await tree(page, videoCourse);
    const lessons = t0[0]!.lessons;
    const idOf = (title: string) => lessons.find((l) => l.title === title)!.id;
    const net = await installVideoNet(page);
    const uploadRequests: Request[] = [];
    page.on("request", (r) => {
      if (r.url().includes("/video-uploads") || (isVideoHost(new URL(r.url())) && r.method() !== "OPTIONS")) uploadRequests.push(r);
    });

    await test.step("tệp lớn hơn 1 GB bị chặn ngay ở UI, không có yêu cầu nào tới API/TUS", async () => {
      const big = path.join(tmp, "qua-lon.mp4");
      const fd = openSync(big, "w");
      ftruncateSync(fd, 1024 * MB + 1); // tệp thưa: không tốn đĩa
      writeSync(fd, MINI, 0, MINI.length, 0);
      closeSync(fd);
      await openCurriculum(page, videoCourse, idOf("E2E FA4 V1"));
      await expect(panel(page)).toBeVisible({ timeout: 20_000 });
      await page.getByTestId("video-file-input").setInputFiles(big);
      await expect(page.getByRole("alert").filter({ hasText: "Tệp quá lớn" })).toContainText("tối đa 1 GB");
      await page.getByTestId("video-file-input").setInputFiles({ name: "phim.avi", mimeType: "video/x-msvideo", buffer: Buffer.from("x") });
      await expect(page.getByRole("alert").filter({ hasText: "Định dạng không được hỗ trợ" })).toBeVisible();
      expect(uploadRequests).toHaveLength(0);
    });

    await test.step("tải 12 MB: thanh tiến độ %, khoá nút Lưu, rồi 'Đang xử lý' → 'Sẵn sàng' (polling)", async () => {
      net.patchDelayMs = 900;
      await page.getByTestId("video-file-input").setInputFiles({ name: "bai-v1.mp4", mimeType: "video/mp4", buffer: paddedMp4(12 * MB) });
      const bar = page.getByRole("progressbar", { name: /Đang tải lên bai-v1.mp4/ });
      await expect(bar).toBeVisible({ timeout: 20_000 });
      await expect(page.getByRole("button", { name: "Lưu bài học" })).toBeDisabled();
      await expect(page.getByRole("button", { name: "Huỷ tải lên" })).toBeVisible();
      await expect.poll(async () => Number(await bar.getAttribute("aria-valuenow")), { timeout: 20_000 }).toBeGreaterThan(0);
      await expect(lessonLink(page, "E2E FA4 V1")).toContainText(/Đang tải lên|Đang xử lý|Sẵn sàng/);
      net.patchDelayMs = 0;
      await expect(panel(page).getByText(/Đã sẵn sàng · thời lượng/)).toBeVisible({ timeout: 150_000 });
      await expect(lessonLink(page, "E2E FA4 V1")).toContainText("Sẵn sàng");
      await expect(lessonLink(page, "E2E FA4 V1")).toContainText("0:03");
      const v = (await apiCall(page, "GET", `/admin/courses/${videoCourse}/lessons/${idOf("E2E FA4 V1")}/video`)).body!;
      expect(v).toMatchObject({ status: "ready", video_source: "upload", original_filename: "bai-v1.mp4" });
    });

    await test.step("tệp .mp4 không phải video: báo VIDEO_INVALID bằng tiếng Việt, cho chọn tệp khác", async () => {
      await openCurriculum(page, videoCourse, idOf("E2E FA4 V2"));
      await page.getByTestId("video-file-input").setInputFiles({ name: "gia.mp4", mimeType: "video/mp4", buffer: Buffer.from("day khong phai video ".repeat(1000)) });
      await expect(page.getByText(/Định dạng tệp không được hỗ trợ|không phải video hợp lệ/i).first()).toBeVisible({ timeout: 60_000 });
      await expect(page.getByTestId("video-file-input")).toBeAttached();
      await expect(lessonLink(page, "E2E FA4 V2")).toContainText(/Lỗi video|Đang tải lên/);
    });

    await test.step("hết hạn/ trả lời lỗi API khi xin phiên tải: 503 hiện thông báo, không treo", async () => {
      await openCurriculum(page, videoCourse, idOf("E2E FA4 V3"));
      await page.route("**/video-uploads", (route) => route.fulfill({ status: 503, contentType: "application/json", headers: { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" }, body: JSON.stringify({ message: "x", code: "VIDEO_PROVIDER_UNAVAILABLE" }) }));
      await page.getByTestId("video-file-input").setInputFiles({ name: "a.mp4", mimeType: "video/mp4", buffer: MINI });
      await expect(page.getByRole("alert").filter({ hasText: "Tải video lên thất bại" })).toContainText("Dịch vụ video tạm thời không dùng được");
      await page.unroute("**/video-uploads");
      await page.getByRole("button", { name: "Đóng" }).click();
      await expect(page.getByTestId("video-file-input")).toBeAttached();
    });
  });

  test("Giáo viên: rớt mạng giữa chừng thì tiếp tục từ offset cũ; huỷ tải; đổi bài khi đang tải không mất lượt; thay video", async ({ page }) => {
    test.setTimeout(400_000);
    await fillLogin(page, "fa4-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    videoCourse = await findCourse(page, "E2E FA4 Khóa video");
    const lessons = (await tree(page, videoCourse))[0]!.lessons;
    const idOf = (title: string) => lessons.find((l) => l.title === title)!.id;

    const net = await installVideoNet(page);
    await test.step("TUS resume: 2 lần PATCH bị cắt mạng, tus gửi HEAD lấy offset rồi gửi tiếp (không tải lại từ 0)", async () => {
      net.abortPatches = new Set([3, 4]);
      await openCurriculum(page, videoCourse, idOf("E2E FA4 V4"));
      await page.getByTestId("video-file-input").setInputFiles({ name: "resume.mp4", mimeType: "video/mp4", buffer: paddedMp4(20 * MB) });
      await expect(panel(page).getByText(/Đã sẵn sàng · thời lượng/)).toBeVisible({ timeout: 180_000 });
      const seen = net.seen;
      const patchOffsets = seen.filter((s) => s.method === "PATCH").map((s) => Number(s.offset));
      expect(patchOffsets.length).toBeGreaterThanOrEqual(6); // 20 MB / 4 MB = 6 phần + 2 lần thử lại
      expect(patchOffsets[2]).toBeGreaterThan(0);
      // Lần thử lại sau khi bị cắt phải tiếp tục đúng offset, không quay về 0.
      expect(patchOffsets[4]).toBe(patchOffsets[2]);
      expect(patchOffsets.slice(3).every((o) => o > 0)).toBe(true);
      expect(seen.some((s, i) => s.method === "HEAD" && i > 2)).toBe(true);
    });

    await test.step("huỷ tải: dừng, trở về ô chọn tệp; không còn tiến độ", async () => {
      net.patchDelayMs = 1500;
      await openCurriculum(page, videoCourse, idOf("E2E FA4 V5"));
      await page.getByTestId("video-file-input").setInputFiles({ name: "huy.mp4", mimeType: "video/mp4", buffer: paddedMp4(40 * MB) });
      const bar = page.getByRole("progressbar", { name: /Đang tải lên huy.mp4/ });
      await expect(bar).toBeVisible({ timeout: 20_000 });

      // Đổi sang bài khác khi đang tải: lượt tải vẫn chạy, nhãn trên cây vẫn hiện %.
      await lessonLink(page, "E2E FA4 V6").click();
      await expect(page.getByRole("heading", { name: "E2E FA4 V6", level: 2 })).toBeVisible();
      await expect(lessonLink(page, "E2E FA4 V5")).toContainText(/Đang tải lên \d+%/);
      await lessonLink(page, "E2E FA4 V5").click();
      await expect(bar).toBeVisible();

      await page.getByRole("button", { name: "Huỷ tải lên" }).click();
      await expect(bar).toHaveCount(0);
      await expect(page.getByTestId("video-file-input")).toBeAttached();
      // Huỷ có terminate phiên TUS ở server: bài về trạng thái chưa có video.
      await expect(lessonLink(page, "E2E FA4 V5")).toContainText("Chưa có video", { timeout: 20_000 });
      net.patchDelayMs = 0;
    });

    await test.step("chọn lại tệp sau khi huỷ: tải ngay (không cần xác nhận), xong → sẵn sàng", async () => {
      await page.getByTestId("video-file-input").setInputFiles({ name: "lai.mp4", mimeType: "video/mp4", buffer: MINI });
      await expect(panel(page).getByText(/Đã sẵn sàng · thời lượng/)).toBeVisible({ timeout: 150_000 });
      await expect(lessonLink(page, "E2E FA4 V5")).toContainText("Sẵn sàng");
    });

    await test.step("bài đã có video: chọn tệp khác phải xác nhận 'Thay video'; Huỷ thì không tải gì", async () => {
      await lessonLink(page, "E2E FA4 V4").click();
      await expect(page.getByRole("heading", { name: "E2E FA4 V4", level: 2 })).toBeVisible();
      const before = net.patches;
      await page.getByTestId("video-file-input").setInputFiles({ name: "thay.mp4", mimeType: "video/mp4", buffer: MINI });
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Thay video hiện tại?");
      await dlg.getByRole("button", { name: "Huỷ" }).click();
      await expect(dlg).toHaveCount(0);
      expect(net.patches).toBe(before);
      await page.getByTestId("video-file-input").setInputFiles({ name: "thay.mp4", mimeType: "video/mp4", buffer: MINI });
      await page.getByRole("dialog").getByRole("button", { name: "Thay video" }).click();
      await expect(panel(page).getByText(/Đã sẵn sàng.*thay\.mp4/)).toBeVisible({ timeout: 150_000 });
    });

    await test.step("F5 khi đã xong: trạng thái lấy từ server, thấy tên tệp gốc", async () => {
      await page.reload();
      await expect(panel(page).getByText(/thay\.mp4/)).toBeVisible({ timeout: 20_000 });
    });
  });

  test("Quản lý trang (MFA): sửa chương & bài của khóa của giáo viên khác", async ({ page, request }) => {
    test.setTimeout(240_000);
    await loginMfa(page, request, STAFF);
    otherCourse = await findCourse(page, "E2E FA4 Của GV khác");
    await openCurriculum(page, otherCourse);
    await expect(chapter(page, "E2E FA4 Chương X")).toBeVisible({ timeout: 20_000 });
    await lessonLink(page, "E2E FA4 Bài X1").click();
    await page.getByLabel(/Tên bài học/).fill("E2E FA4 Bài X1 (QLT sửa)");
    await page.getByRole("button", { name: "Lưu bài học" }).click();
    await toastText(page, "Đã lưu bài học");
    expect((await tree(page, otherCourse))[0]!.lessons[0]!.title).toBe("E2E FA4 Bài X1 (QLT sửa)");
    // Staff thấy cả ba khóa FA4.
    const list = await apiCall(page, "GET", "/admin/courses?per_page=50&q=E2E%20FA4");
    expect((list.body!.data as Json[]).length).toBeGreaterThanOrEqual(3);
  });

  test("Giáo viên không được gán: UI báo 403, API chương trả 403 (đọc và ghi)", async ({ page }) => {
    test.setTimeout(120_000);
    await fillLogin(page, "fa4-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    // Khóa của gv2 (id lấy qua tài khoản gv2 sẽ tốn một lần đăng nhập; dùng id tăng dần từ khóa video).
    const mine = await findCourse(page, "E2E FA4 Khóa video");
    let foreign = 0;
    for (let id = mine - 5; id <= mine + 5; id++) {
      const res = await apiCall(page, "GET", `/admin/courses/${id}/chapters`);
      if (res.status === 403) foreign = id;
    }
    expect(foreign).toBeGreaterThan(0);
    const ch = await apiCall(page, "POST", `/admin/courses/${foreign}/chapters`, { title: "E2E FA4 không được" });
    expect(ch.status).toBe(403);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${foreign}/sua?tab=chuong-bai`);
    await expect(page.getByTestId("course-forbidden")).toBeVisible({ timeout: 20_000 });
  });
});
