import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";

/**
 * FA4 — e2e QA bổ sung (chạy sau chuong-bai-real.spec.ts; tài khoản e2e-fa4-*, KHÔNG dùng tài khoản học sinh của FW4).
 * Các luồng reviewer lưu ý: điều hướng SPA khi đang tải, xoá bài đang tải, kéo-thả đúng lúc poll, hai tab (CURRICULUM_MISMATCH),
 * mất mạng > 40 s, 409 có tiến độ, GV bị gỡ khỏi khóa giữa phiên (403), 375px (vùng chạm >= 44px).
 * Cần `seed-e2e-curriculum.sh --reset` trước và trình theo dõi tín hiệu (e2e/.qa-signal) chạy ở host để thao tác DB (tiến độ học, gán GV).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const MB = 1024 * 1024;
const MINI = readFileSync(path.join(__dirname, "fixtures", "mini.mp4"));
type Json = Record<string, unknown>;

function paddedMp4(extraBytes: number): Buffer {
  const header = Buffer.alloc(8);
  header.writeUInt32BE(extraBytes + 8, 0);
  header.write("free", 4, "ascii");
  return Buffer.concat([MINI, header, Buffer.alloc(extraBytes)]);
}

async function login(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(`e2e-${who}@example.com`);
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
}

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
  if (!hit) throw new Error(`Không thấy khóa "${title}"`);
  return hit.id as number;
}

type Tree = Array<{ id: number; title: string; lessons: Array<{ id: number; title: string; video_status: string | null }> }>;
async function tree(page: Page, courseId: number): Promise<Tree> {
  const res = await apiCall(page, "GET", `/admin/courses/${courseId}/chapters`);
  expect(res.status).toBe(200);
  return (res.body as { chapters: Tree }).chapters;
}
const lessonTitles = (t: Tree, chapterTitle: string) => t.find((c) => c.title === chapterTitle)!.lessons.map((l) => l.title);

const chapter = (page: Page, title: string) => page.getByRole("region", { name: title, exact: true });
const lessonLink = (page: Page, title: string) => page.getByRole("link", { name: new RegExp(title) });
const grip = (page: Page, title: string) => page.getByRole("button", { name: `Kéo để đổi thứ tự: ${title}` });
const panel = (page: Page) => page.getByTestId("video-panel");
const toastText = (page: Page, text: string | RegExp) => expect(page.getByText(text).first()).toBeVisible({ timeout: 15_000 });
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

async function openCurriculum(page: Page, courseId: number, lesson?: number) {
  await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${courseId}/sua?tab=chuong-bai${lesson ? `&bai=${lesson}` : ""}`);
  await expect(page.getByRole("navigation", { name: "Phần của khóa học" }).getByRole("link", { name: /^Chương & bài/ })).toHaveAttribute("aria-current", "page", { timeout: 25_000 });
}

async function drag(page: Page, from: Locator, to: Locator) {
  await from.scrollIntoViewIfNeeded();
  const a = (await from.boundingBox())!;
  const b = (await to.boundingBox())!;
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2 + 10, { steps: 4 });
  await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 20 });
  await page.mouse.up();
}

const isVideoHost = (url: URL) => url.host.startsWith("video.localhost");

/** Điều khiển mạng tới host video: chậm mỗi PATCH, chặn hoàn toàn (mất mạng), ghi lại PATCH/HEAD. */
async function installVideoNet(page: Page) {
  const net = { patchDelayMs: 0, blocked: false, patches: 0, seen: [] as Array<{ method: string; offset: string | undefined }> };
  await page.route(isVideoHost, async (route) => {
    const req = route.request();
    if (req.method() === "OPTIONS") return route.continue().catch(() => undefined);
    if (net.blocked) return route.abort("internetdisconnected");
    if (req.method() === "PATCH" || req.method() === "HEAD") net.seen.push({ method: req.method(), offset: req.headers()["upload-offset"] });
    if (req.method() === "PATCH") {
      net.patches++;
      if (net.patchDelayMs > 0) await new Promise((r) => setTimeout(r, net.patchDelayMs));
    }
    await route.continue().catch(() => undefined);
  });
  return net;
}

/** Nhờ trình theo dõi ở host thao tác DB (tiến độ học, gán giáo viên). */
const SIGNAL_DIR = path.join(__dirname, ".qa-signal");
let sigN = 0;
async function signal(action: string, arg = "") {
  mkdirSync(SIGNAL_DIR, { recursive: true });
  const n = `${Date.now()}-${sigN++}`;
  writeFileSync(path.join(SIGNAL_DIR, `req-${n}`), `${action} ${arg}\n`);
  await expect.poll(() => existsSync(path.join(SIGNAL_DIR, `done-${n}`)), { timeout: 60_000, intervals: [500] }).toBe(true);
}

async function loadIds(page: Page) {
  videoCourse = await findCourse(page, "E2E FA4 Khóa video");
  for (const c of await tree(page, videoCourse)) for (const l of c.lessons) { const m = /^E2E FA4 QA (Q\d|P\d)$/.exec(l.title); if (m) lessonId[m[1]!] = l.id; }
}

function bigFile(name: string, extra: number): string {
  const dir = mkdtempSync(path.join(tmpdir(), "fa4qa-"));
  const file = path.join(dir, name);
  writeFileSync(file, paddedMp4(extra));
  return file;
}

const QC = "E2E FA4 QA Chương";
const QP = "E2E FA4 QA Chương P";
let videoCourse = 0;
let treeCourse = 0;
let qcId = 0;
const lessonId: Record<string, number> = {};

test.describe("FA4 QA bổ sung (thật)", () => {
  test("chuẩn bị: tạo chương QA + 6 bài + chương P (2 bài) qua API", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, "fa4-gv");
    videoCourse = await findCourse(page, "E2E FA4 Khóa video");
    const ch = await apiCall(page, "POST", `/admin/courses/${videoCourse}/chapters`, { title: QC });
    expect(ch.status).toBe(201);
    qcId = ((ch.body as Json).data ? ((ch.body as Json).data as Json).id : (ch.body as Json).id) as number;
    for (const n of ["Q1", "Q2", "Q3", "Q4", "Q5", "Q6"]) {
      const r = await apiCall(page, "POST", `/admin/courses/${videoCourse}/chapters/${qcId}/lessons`, { title: `E2E FA4 QA ${n}` });
      expect(r.status).toBe(201);
      lessonId[n] = (((r.body as Json).data as Json | undefined) ?? (r.body as Json)).id as number;
    }
    const cp = await apiCall(page, "POST", `/admin/courses/${videoCourse}/chapters`, { title: QP });
    const qpId = (((cp.body as Json).data as Json | undefined) ?? (cp.body as Json)).id as number;
    for (const n of ["P1", "P2"]) {
      const r = await apiCall(page, "POST", `/admin/courses/${videoCourse}/chapters/${qpId}/lessons`, { title: `E2E FA4 QA ${n}` });
      lessonId[n] = (((r.body as Json).data as Json | undefined) ?? (r.body as Json)).id as number;
    }
    expect(Object.keys(lessonId)).toHaveLength(8);
  });

  test("QA-1: đang tải mà đi menu khác rồi Back: lượt tải còn sống, xong tới 'Sẵn sàng'", async ({ page }) => {
    test.setTimeout(300_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    const net = await installVideoNet(page);
    net.patchDelayMs = 1200;
    await openCurriculum(page, videoCourse, lessonId.Q1);
    await page.getByTestId("video-file-input").setInputFiles({ name: "dimenu.mp4", mimeType: "video/mp4", buffer: paddedMp4(24 * MB) });
    const bar = page.getByRole("progressbar", { name: /Đang tải lên dimenu.mp4/ });
    await expect(bar).toBeVisible({ timeout: 20_000 });
    await expect.poll(() => net.patches, { timeout: 20_000 }).toBeGreaterThan(0);

    // Đi sang menu khác bằng điều hướng trong ứng dụng (không tải lại trang).
    await page.evaluate(() => ((window as unknown as { __spa: boolean }).__spa = true));
    await page.getByRole("link", { name: "Khóa học" }).first().click();
    await expect(page).toHaveURL(/\/quan-tri\/khoa-hoc$/, { timeout: 20_000 });
    expect(await page.evaluate(() => (window as unknown as { __spa?: boolean }).__spa)).toBe(true); // vẫn là điều hướng SPA
    const atLeave = net.patches;
    await expect.poll(() => net.patches, { timeout: 30_000, message: "lượt tải phải tiếp tục khi đang ở menu khác" }).toBeGreaterThan(atLeave);

    await page.goBack();
    await expect(page).toHaveURL(/tab=chuong-bai/, { timeout: 20_000 });
    await expect(lessonLink(page, "E2E FA4 QA Q1")).toContainText(/Đang tải lên \d+%|Đang xử lý|Sẵn sàng/, { timeout: 20_000 });
    net.patchDelayMs = 0;
    await lessonLink(page, "E2E FA4 QA Q1").click();
    await expect(panel(page).getByText(/Đã sẵn sàng/)).toBeVisible({ timeout: 180_000 });
    await expect(lessonLink(page, "E2E FA4 QA Q1")).toContainText("Sẵn sàng");
    const t = await tree(page, videoCourse);
    expect(t.find((c) => c.title === QC)!.lessons[0]!.video_status).toBe("ready");
  });

  test("QA-2: xoá bài đang tải thì lượt tải dừng, không còn PATCH", async ({ page }) => {
    test.setTimeout(240_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    const net = await installVideoNet(page);
    net.patchDelayMs = 1500;
    await openCurriculum(page, videoCourse, lessonId.Q2);
    await page.getByTestId("video-file-input").setInputFiles(bigFile("xoa.mp4", 60 * MB));
    await expect(page.getByRole("progressbar", { name: /Đang tải lên xoa.mp4/ })).toBeVisible({ timeout: 20_000 });
    await expect.poll(() => net.patches, { timeout: 20_000 }).toBeGreaterThan(1);
    await page.getByRole("button", { name: "Xoá bài" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Xoá bài" }).click();
    await toastText(page, "Đã xoá bài học");
    const afterDelete = net.patches;
    await page.waitForTimeout(8_000); // > 4 chu kỳ PATCH chậm
    expect(net.patches - afterDelete, "PATCH còn gửi sau khi xoá bài").toBeLessThanOrEqual(1); // tối đa 1 chunk đang bay
    const settled = net.patches;
    await page.waitForTimeout(5_000);
    expect(net.patches).toBe(settled);
    await expect(lessonLink(page, "E2E FA4 QA Q2")).toHaveCount(0);
    expect(lessonTitles(await tree(page, videoCourse), QC)).not.toContain("E2E FA4 QA Q2");
  });

  test("QA-3: kéo-thả đúng lúc poll: GET cũ về sau PUT không ghi đè thứ tự", async ({ page }) => {
    test.setTimeout(240_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    await page.setViewportSize({ width: 1280, height: 1600 });
    const net = await installVideoNet(page);
    net.patchDelayMs = 0;
    // Giữ lại GET chapters (poll) và trả bản cũ sau khi PUT xong.
    const hold = { on: false, heldCount: 0, release: () => {} };
    const gate = new Promise<void>((r) => (hold.release = r));
    await page.route(/\/admin\/courses\/\d+\/chapters(\?.*)?$/, async (route) => {
      if (route.request().method() !== "GET" || !hold.on) return route.continue();
      hold.on = false; // chỉ giữ đúng một GET (poll đầu tiên); các GET sau (tree(), poll tiếp) đi thẳng
      const stale = await route.fetch();
      hold.heldCount++;
      await gate;
      await route.fulfill({ response: stale }).catch(() => undefined);
    });
    const chId = (await tree(page, videoCourse)).find((c) => c.title === QC)!.id;
    const fresh = await apiCall(page, "POST", `/admin/courses/${videoCourse}/chapters/${chId}/lessons`, { title: `E2E FA4 QA R${Date.now() % 100000}` });
    const freshId = (((fresh.body as Json).data as Json | undefined) ?? (fresh.body as Json)).id as number;
    await openCurriculum(page, videoCourse, freshId);
    await expect(chapter(page, QC)).toBeVisible({ timeout: 20_000 });
    const before = lessonTitles(await tree(page, videoCourse), QC);
    hold.on = true;
    await page.getByTestId("video-file-input").setInputFiles({ name: "poll.mp4", mimeType: "video/mp4", buffer: MINI });
    // Bài tải xong → cây bắt đầu poll (3 s); chờ GET đầu tiên bị giữ.
    await expect.poll(() => hold.heldCount, { timeout: 40_000, message: "poll phải bắn GET chapters" }).toBeGreaterThan(0);
    // Kéo Q6 lên trên Q3 trong lúc GET cũ đang bay.
    const putDone = page.waitForResponse((r) => r.url().includes("/curriculum/order") && r.request().method() === "PUT");
    await drag(page, grip(page, "E2E FA4 QA Q6"), lessonLink(page, "E2E FA4 QA Q3"));
    expect((await putDone).status()).toBe(200);
    const after = lessonTitles(await tree(page, videoCourse), QC);
    expect(after).not.toEqual(before);
    hold.release(); // trả bản cũ về SAU khi PUT thành công
    await page.waitForTimeout(1_500);
    const ui = await chapter(page, QC).getByRole("link").allInnerTexts();
    const uiTitles = ui.map((u) => u.split("\n")[0]);
    expect(uiTitles, "UI phải đúng thứ tự server vừa lưu, không bị GET cũ ghi đè").toEqual(after);
  });

  test("QA-4: hai tab cùng sửa: CURRICULUM_MISMATCH tải lại cây", async ({ page, context }) => {
    test.setTimeout(180_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    await page.setViewportSize({ width: 1280, height: 1600 });
    await openCurriculum(page, videoCourse, lessonId.Q4);
    await expect(chapter(page, QC)).toBeVisible({ timeout: 20_000 });
    // Đóng băng tab 1 ở bản cây cũ (mọi GET chapters trả bản cũ) để mô phỏng "tab 1 chưa biết tab 2 vừa sửa".
    const tag = String(Date.now() % 100000);
    const frozen = { on: false, body: "" };
    await page.route(/\/admin\/courses\/\d+\/chapters(\?.*)?$/, async (route) => {
      if (route.request().method() !== "GET") return route.continue();
      const real = await route.fetch();
      if (!frozen.on) frozen.body = await real.text();
      await route.fulfill({ response: real, body: frozen.on ? frozen.body : undefined });
    });
    await page.reload();
    await expect(chapter(page, QC)).toBeVisible({ timeout: 20_000 });
    frozen.on = true;
    page.on("request", (r) => {
      if (r.method() === "PUT" && r.url().includes("/curriculum/order")) frozen.on = false; // PUT bị từ chối thì lần tải lại sau đó phải thấy bản mới
    });
    const tab2 = await context.newPage();
    await tab2.goto(`${ADMIN}/quan-tri`);
    const add = await apiCall(tab2, "POST", `/admin/courses/${videoCourse}/chapters/${(await tree(tab2, videoCourse)).find((c) => c.title === QC)!.id}/lessons`, { title: `E2E FA4 QA Tab2 ${tag}` });
    expect(add.status).toBe(201);
    await expect(lessonLink(page, `E2E FA4 QA Tab2 ${tag}`)).toHaveCount(0); // tab 1 chưa biết
    await drag(page, grip(page, "E2E FA4 QA Q5"), lessonLink(page, "E2E FA4 QA Q4"));
    await toastText(page, /vừa được thay đổi ở nơi khác/);
    await expect(lessonLink(page, `E2E FA4 QA Tab2 ${tag}`)).toBeVisible({ timeout: 15_000 }); // cây đã tải lại
    // Kéo lại sau khi đã tải lại thì thành công.
    await drag(page, grip(page, "E2E FA4 QA Q5"), lessonLink(page, "E2E FA4 QA Q4"));
    await toastText(page, "Đã lưu thứ tự");
    expect(lessonTitles(await tree(tab2, videoCourse), QC).indexOf("E2E FA4 QA Q5")).toBeLessThan(lessonTitles(await tree(tab2, videoCourse), QC).indexOf("E2E FA4 QA Q4"));
    await tab2.close();
  });

  test("QA-5: mất mạng > 40 s rồi có lại: 'đang dừng' rồi tự tiếp tục", async ({ page }) => {
    test.setTimeout(420_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    const net = await installVideoNet(page);
    net.patchDelayMs = 800;
    await openCurriculum(page, videoCourse, lessonId.Q4);
    await page.getByTestId("video-file-input").setInputFiles({ name: "matmang.mp4", mimeType: "video/mp4", buffer: paddedMp4(32 * MB) });
    await expect(page.getByRole("progressbar", { name: /Đang tải lên matmang.mp4/ })).toBeVisible({ timeout: 20_000 });
    await expect.poll(() => net.patches, { timeout: 20_000 }).toBeGreaterThan(1);
    net.blocked = true;
    const t0 = Date.now();
    await expect(page.getByText("Tải lên đang dừng")).toBeVisible({ timeout: 120_000 });
    expect(Date.now() - t0, "phải thử lại một lúc trước khi báo dừng").toBeGreaterThan(20_000);
    await expect(page.getByRole("button", { name: "Tải tiếp" })).toBeVisible();
    await expect(page.getByRole("progressbar")).toHaveAttribute("aria-valuetext", /đang dừng/);
    const sentBefore = Number(await page.getByRole("progressbar").getAttribute("aria-valuenow"));
    await page.waitForTimeout(5_000); // vẫn dừng, không tự bắn
    await expect(page.getByText("Tải lên đang dừng")).toBeVisible();
    // Có mạng lại: trình duyệt báo `online`.
    net.blocked = false;
    net.patchDelayMs = 0;
    const patchesBefore = net.patches;
    await page.evaluate(() => window.dispatchEvent(new Event("online")));
    await expect(panel(page).getByText(/Đã sẵn sàng/)).toBeVisible({ timeout: 180_000 });
    expect(net.patches).toBeGreaterThan(patchesBefore);
    const resumeHead = net.seen.filter((s) => s.method === "HEAD");
    expect(resumeHead.length).toBeGreaterThan(0);
    const firstAfter = net.seen.slice(net.seen.length - 1 - (net.patches - patchesBefore)).find((s) => s.method === "PATCH");
    expect(Number(firstAfter?.offset ?? 0), "tiếp tục từ offset cũ, không về 0").toBeGreaterThan(0);
    expect(sentBefore).toBeGreaterThan(0);
  });

  test("QA-6: xoá bài/chương đã có tiến độ học trả 409 với thông điệp tiếng Việt; bài không tiến độ vẫn xoá được", async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    await signal("progress", String(lessonId.P1));
    await openCurriculum(page, videoCourse, lessonId.P1);
    await expect(page.getByRole("heading", { name: "E2E FA4 QA P1", level: 2 })).toBeVisible({ timeout: 20_000 });
    await page.getByRole("button", { name: "Xoá bài" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Xoá bài" }).click();
    await toastText(page, "Không thể xoá bài vì đã có học sinh học bài này.");
    await expect(lessonLink(page, "E2E FA4 QA P1")).toBeVisible();
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: `Xoá ${QP}` }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Xoá chương" }).click();
    await toastText(page, "Không thể xoá chương vì đã có học sinh học bài trong chương này.");
    await expect(chapter(page, QP)).toBeVisible();
    const t = await tree(page, videoCourse);
    expect(lessonTitles(t, QP)).toEqual(["E2E FA4 QA P1", "E2E FA4 QA P2"]);
    // Bài chưa có tiến độ: xoá được.
    await lessonLink(page, "E2E FA4 QA P2").click();
    await expect(page.getByRole("heading", { name: "E2E FA4 QA P2", level: 2 })).toBeVisible();
    await page.getByRole("button", { name: "Xoá bài" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Xoá bài" }).click();
    await toastText(page, "Đã xoá bài học");
    expect(lessonTitles(await tree(page, videoCourse), QP)).toEqual(["E2E FA4 QA P1"]);
  });

  test("QA-7: 375px không tràn ngang, vùng chạm >= 44px (đo vùng bấm thật)", async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    await page.setViewportSize({ width: 375, height: 800 });
    await openCurriculum(page, videoCourse, lessonId.Q3);
    await expect(chapter(page, QC)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole("heading", { name: /E2E FA4 QA Q3/, level: 2 })).toBeVisible();
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);

    const small = await page.evaluate(() => {
      const out: string[] = [];
      const root = document.querySelector("main") ?? document.body;
      const els = root.querySelectorAll<HTMLElement>("button, a[href], input:not([type=hidden]):not([type=file]), select, textarea, [role=radio], [role=checkbox]");
      els.forEach((el) => {
        el.scrollIntoView({ block: "center" });
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return;
        const cs = getComputedStyle(el);
        if (cs.visibility === "hidden") return;
        // Vùng bấm thật: kiểm điểm cách mép tới khi đủ 44px quanh tâm có trả về chính phần tử (hoặc con/cha label) không.
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        const hit = (x: number, y: number) => {
          const t = document.elementFromPoint(x, y);
          return !!t && (el === t || el.contains(t) || t.contains(el) && t.tagName === "LABEL");
        };
        const edgeX = Math.max(r.width / 2, 21.5);
        const edgeY = Math.max(r.height / 2, 21.5);
        const ok = hit(cx - edgeX + 1, cy) && hit(cx + edgeX - 1, cy) && hit(cx, cy - edgeY + 1) && hit(cx, cy + edgeY - 1);
        const name = (el.getAttribute("aria-label") ?? el.textContent ?? el.getAttribute("name") ?? el.tagName).trim().slice(0, 40);
        if (!ok && (r.top > innerHeight || r.bottom < 0)) return; // ngoài khung nhìn: không đo được
        if (!ok || (r.height < 44 && !ok)) out.push(`${el.tagName.toLowerCase()} "${name}" ${Math.round(r.width)}x${Math.round(r.height)} class="${el.className.toString().slice(0, 90)}" in="${el.closest("section")?.getAttribute("aria-labelledby") ?? el.closest("nav,aside,header")?.tagName ?? "-"}"`);
      });
      return out;
    });
    test.info().annotations.push({ type: "vung-cham-nho", description: small.join("\n") });
    console.log("VÙNG CHẠM < 44px (375px):\n" + (small.join("\n") || "(không có)"));
    // Tay cầm kéo và nút biểu tượng (phần R4 dev đã sửa) phải đạt 44px; các nút khác ghi nhận ở báo cáo QA (BUG-2).
    const hard = small.filter((l) => /Kéo để đổi thứ tự|Sửa tên|"Xoá E2E|"Đóng/.test(l));
    expect(hard, hard.join("\n")).toEqual([]);
  });

  test("QA-7b (BUG-1): nút 'Thêm bài' ở đầu chương phải ẩn ở 375px (hiện đang lộ, cao 36px, trùng nút ở cuối chương)", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    await page.setViewportSize({ width: 375, height: 800 });
    await openCurriculum(page, videoCourse);
    await expect(chapter(page, QC)).toBeVisible({ timeout: 20_000 });
    const visible = await chapter(page, QC).getByRole("button", { name: "Thêm bài" }).evaluateAll((els) => els.filter((e) => e.getBoundingClientRect().height < 44).length);
    expect(visible).toBe(0);
  });

  test("QA-8: giáo viên bị gỡ khỏi khóa giữa phiên: thao tác 403 có thông báo, F5 ra màn 403", async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, "fa4-gv");
    await loadIds(page);
    treeCourse = await findCourse(page, "E2E FA4 Khóa dựng cây");
    await openCurriculum(page, treeCourse);
    await expect(chapter(page, "E2E FA4 Chương A")).toBeVisible({ timeout: 20_000 });
    await signal("unassign", String(treeCourse));
    try {
      // Thao tác ghi sau khi bị gỡ: thêm chương.
      await page.getByRole("button", { name: "Thêm chương", exact: true }).click();
      await page.getByRole("dialog").getByLabel(/Tên chương/).fill("E2E FA4 sau khi bị gỡ");
      await page.getByRole("dialog").getByRole("button", { name: "Thêm chương" }).click();
      await expect(page.getByText(/không có quyền sửa nội dung khóa học này/).first()).toBeVisible({ timeout: 15_000 });
      await expect(chapter(page, "E2E FA4 sau khi bị gỡ")).toHaveCount(0);
      // Kéo thả sau khi bị gỡ: hoàn lại (không để cây lệch với server) + thông báo.
      await page.keyboard.press("Escape");
      await drag(page, grip(page, "E2E FA4 Bài A3"), lessonLink(page, "E2E FA4 Bài A1"));
      await expect(page.getByText(/không có quyền sửa nội dung khóa học này/).first()).toBeVisible({ timeout: 15_000 });
      const links = await chapter(page, "E2E FA4 Chương A").getByRole("link").allInnerTexts();
      expect(links[0]).toContain("E2E FA4 Bài A1");
      await page.reload();
      await expect(page.getByTestId("course-forbidden").or(page.getByTestId("curriculum-forbidden")).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await signal("assign", String(treeCourse));
    }
  });

  test("dọn: xoá dữ liệu QA trong DB (tiến độ học giả)", async () => {
    await signal("cleanup");
  });
});
