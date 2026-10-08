import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * QA FA5 vòng 2 (laravel-qa): sắp xếp câu (SLN7) trên backend thật — bàn phím + thông báo trình đọc màn hình, chuột ở danh sách dài,
 * 375px với 199 câu, khoá khi đang lưu thứ tự (R6), hai tab lệch danh sách, copy-on-write sau khi đổi thứ tự (+ Back chỉ hỏi một lần),
 * học sinh: lượt mới theo thứ tự mới, lượt đang dở không đổi.
 * Seed: `seed-e2e-quiz.sh --reset`, rồi publish khóa + ghi danh e2e-fa5-hs@example.com (xem docs/qa/FA5.md "QA vòng 2"). `--workers=1 --retries=0`.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const STUDENT_API = "http://api.localhost:8000/api/v1";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

type Json = Record<string, unknown>;
interface Q { id: number; position: number; content: string }
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
async function loginGv(page: Page) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill("e2e-fa5-gv@example.com");
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
}
const detail = async (page: Page, c: number, q: number) => (await apiCall(page, "GET", `/admin/courses/${c}/quizzes/${q}`)).body as unknown as { questions: Q[] };
const questionsOf = async (page: Page, c: number, q: number) => (await detail(page, c, q)).questions;
const mk = (text: string) => ({ content: text, explanation: null, options: [0, 1, 2, 3].map((i) => ({ content: `${i}`, is_correct: i === 0 })) });
const contiguous = (qs: Q[]) => qs.map((q) => q.position);
const list = (page: Page) => page.getByTestId("question-list");
const items = (page: Page) => list(page).getByRole("listitem");
const toastText = (page: Page, text: string) => expect(page.getByText(text).first()).toBeVisible({ timeout: 15_000 });
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const live = (page: Page) => page.locator('[aria-live="assertive"]').first();
const dialog = (page: Page) => page.getByRole("dialog");
/** Ghi lại mọi thông báo của vùng aria-live (dnd-kit thay chữ liên tục: thông báo "nhấc" bị "đang ở trên" ghi đè ngay). */
const trackLive = (page: Page) =>
  page.evaluate(() => {
    const w = window as unknown as { __live: string[] };
    w.__live = [];
    const el = document.querySelector('[aria-live="assertive"]')!;
    new MutationObserver(() => {
      const t = el.textContent ?? "";
      if (t && w.__live[w.__live.length - 1] !== t) w.__live.push(t);
    }).observe(el, { childList: true, characterData: true, subtree: true });
  });
const liveLog = (page: Page) => page.evaluate(() => (window as unknown as { __live: string[] }).__live);

let course = 0;
let quizA = 0;
let quizEmpty = 0;
let quizFull = 0;

test.describe("QA FA5 vòng 2 (thật)", () => {
  test("chuẩn bị: id khóa/quiz; thêm 3 câu vào quiz trống", async ({ page }) => {
    test.setTimeout(90_000);
    await loginGv(page);
    const l = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent("E2E FA5 Khóa soạn quiz")}`);
    course = ((l.body!.data as Json[]).find((c) => c.title === "E2E FA5 Khóa soạn quiz")!.id) as number;
    const qs = (await apiCall(page, "GET", `/admin/courses/${course}/quizzes`)).body!.data as Array<{ id: number; title: string }>;
    quizA = qs.find((q) => q.title === "E2E FA5 Quiz có lượt làm")!.id;
    quizEmpty = qs.find((q) => q.title === "E2E FA5 Quiz trống")!.id;
    quizFull = qs.find((q) => q.title === "E2E FA5 Quiz gần đầy")!.id;
    for (const q of await questionsOf(page, course, quizEmpty)) await apiCall(page, "DELETE", `/admin/courses/${course}/quizzes/${quizEmpty}/questions/${q.id}`);
    for (const n of [1, 2, 3]) expect((await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk(`QA2 câu ${n}`))).status).toBe(201);
    expect((await questionsOf(page, course, quizEmpty)).map((q) => q.content)).toEqual(["QA2 câu 1", "QA2 câu 2", "QA2 câu 3"]);
  });

  test("bàn phím: cách -> mũi tên -> cách đổi thứ tự; thông báo trình đọc màn hình; Esc huỷ không đổi gì", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(items(page)).toHaveCount(3, { timeout: 25_000 });
    // Hướng dẫn cho trình đọc màn hình (aria-describedby của tay nắm).
    const grip = (n: number) => page.getByRole("button", { name: `Kéo để đổi thứ tự: câu ${n}`, exact: true });
    const descId = await grip(1).getAttribute("aria-describedby");
    expect(descId).toBeTruthy();
    await expect(page.locator(`#${descId}`)).toContainText("Nhấn phím cách để nhấc");

    await test.step("Esc huỷ", async () => {
      await trackLive(page);
      await grip(1).focus();
      await page.keyboard.press("Space");
      await page.waitForTimeout(400);
      await page.keyboard.press("ArrowDown");
      await page.waitForTimeout(400);
      await expect(live(page)).toHaveText("Đang ở trên vị trí 2/3.");
      await page.keyboard.press("Escape");
      await expect(live(page)).toHaveText("Đã huỷ kéo câu.");
      const log = await liveLog(page);
      console.log(`QA2 thông báo SR (Esc): ${JSON.stringify(log)}`);
      // Ghi nhận: thông báo "Đã nhấc câu" bị dnd-kit ghi đè ngay bởi "Đang ở trên vị trí" (cùng một lần cập nhật).
      expect(log.join(" ")).toMatch(/vị trí 1\/3/);
      await page.waitForTimeout(800);
      expect((await questionsOf(page, course, quizEmpty)).map((q) => q.content)).toEqual(["QA2 câu 1", "QA2 câu 2", "QA2 câu 3"]);
    });

    await test.step("thả: câu 1 xuống vị trí 2", async () => {
      await trackLive(page);
      await grip(1).focus();
      await page.keyboard.press("Space");
      await page.waitForTimeout(400);
      await page.keyboard.press("ArrowDown");
      await page.waitForTimeout(400);
      await page.keyboard.press("Space");
      await page.waitForTimeout(400);
      await page.waitForTimeout(500);
      console.log(`QA2 thông báo SR (thả): ${JSON.stringify(await liveLog(page))}`);
      expect.soft(await live(page).textContent(), "BUG-3 thông báo thả sai vị trí").toBe("Đã thả câu 1 tại vị trí 2/3.");
      await toastText(page, "Đã lưu thứ tự câu");
      const qs = await questionsOf(page, course, quizEmpty);
      expect(qs.map((q) => q.content)).toEqual(["QA2 câu 2", "QA2 câu 1", "QA2 câu 3"]);
      expect(contiguous(qs)).toEqual([1, 2, 3]);
      await expect(items(page).nth(1)).toContainText("QA2 câu 1");
      // Ghi nhận vị trí tiêu điểm sau khi lưu (nút bị khoá khi đang lưu có thể làm mất tiêu điểm bàn phím).
      const focused = await page.evaluate(() => document.activeElement?.getAttribute("aria-label") ?? document.activeElement?.tagName);
      console.log(`QA2 tiêu điểm sau thả bằng phím: ${focused}`);
    });

    await test.step("kéo xuống rồi bấm mũi tên lên (đi một vòng) vẫn đúng", async () => {
      await grip(3).focus();
      await page.keyboard.press("Space");
      await page.waitForTimeout(400);
      await page.keyboard.press("ArrowUp");
      await page.waitForTimeout(400);
      await page.keyboard.press("ArrowUp");
      await page.waitForTimeout(400);
      await page.keyboard.press("Space");
      await page.waitForTimeout(400);
      await toastText(page, "Đã lưu thứ tự câu");
      const qs = await questionsOf(page, course, quizEmpty);
      expect(qs.map((q) => q.content)).toEqual(["QA2 câu 3", "QA2 câu 2", "QA2 câu 1"]);
      expect(contiguous(qs)).toEqual([1, 2, 3]);
    });
  });

  test("nút Lên/Xuống: ở rìa bị vô hiệu; nhấn liên tiếp nhanh không gửi chồng, chỉ một toast", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(items(page)).toHaveCount(3, { timeout: 25_000 });
    await expect(page.getByRole("button", { name: "Chuyển câu 1 lên" })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Chuyển câu 3 xuống" })).toBeDisabled();
    let puts = 0;
    page.on("request", (r) => r.method() === "PUT" && /\/questions\/order$/.test(r.url()) && puts++);
    // Hai lần bấm trong cùng một tick: lần hai phải bị chặn bởi khoá.
    await page.evaluate(() => {
      const b = document.querySelector<HTMLButtonElement>('button[aria-label="Chuyển câu 1 xuống"]')!;
      b.click();
      b.click();
    });
    await toastText(page, "Đã lưu thứ tự câu");
    await page.waitForTimeout(1000);
    expect(puts).toBe(1);
    // Bấm lại liên tiếp 4 lần (sau mỗi lần chờ nút mở khoá): đếm toast trong 3 giây.
    for (let i = 0; i < 3; i++) {
      await expect(page.getByRole("button", { name: "Chuyển câu 2 xuống" })).toBeEnabled({ timeout: 10_000 });
      await page.getByRole("button", { name: "Chuyển câu 2 xuống" }).click();
      await expect(page.getByRole("button", { name: "Chuyển câu 2 lên" })).toBeEnabled({ timeout: 10_000 });
      await page.getByRole("button", { name: "Chuyển câu 2 lên" }).click();
    }
    await expect(page.getByRole("button", { name: "Chuyển câu 2 lên" })).toBeEnabled({ timeout: 10_000 });
    const toasts = await page.getByText("Đã lưu thứ tự câu").count();
    console.log(`QA2 số toast "Đã lưu thứ tự câu" sau 6 lần bấm nhanh: ${toasts}; PUT: ${puts}`);
    expect(toasts).toBeLessThanOrEqual(2);
    const qs = await questionsOf(page, course, quizEmpty);
    expect(contiguous(qs)).toEqual([1, 2, 3]);
    expect(new Set(qs.map((q) => q.content)).size).toBe(3);
  });

  test("danh sách dài (199 câu): kéo chuột thật; 375px không tràn, mọi nút >= 44px; Xuống ở giữa; position 1..n liền mạch", async ({ page }) => {
    test.setTimeout(240_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizFull}`);
    await expect(items(page)).toHaveCount(199, { timeout: 40_000 });
    const before = await questionsOf(page, course, quizFull);
    expect(before).toHaveLength(199);

    await test.step("desktop: kéo câu 3 lên đầu bằng chuột", async () => {
      await page.getByRole("button", { name: "Kéo để đổi thứ tự: câu 3", exact: true }).scrollIntoViewIfNeeded();
      const grip = page.getByRole("button", { name: "Kéo để đổi thứ tự: câu 3", exact: true });
      const first = items(page).first();
      const a = (await grip.boundingBox())!;
      const b = (await first.boundingBox())!;
      await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
      await page.mouse.down();
      await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2 - 10, { steps: 4 });
      await page.mouse.move(b.x + b.width / 2, b.y + 10, { steps: 25 });
      await page.mouse.up();
      await expect.poll(async () => (await questionsOf(page, course, quizFull))[0]!.id, { timeout: 20_000 }).toBe(before[2]!.id);
      const qs = await questionsOf(page, course, quizFull);
      expect(qs).toHaveLength(199);
      expect(contiguous(qs)).toEqual(Array.from({ length: 199 }, (_, i) => i + 1));
      expect(qs.map((q) => q.id).slice(0, 3)).toEqual([before[2]!.id, before[0]!.id, before[1]!.id]);
    });

    await test.step("375px", async () => {
      await page.setViewportSize({ width: 375, height: 800 });
      await page.reload();
      await expect(items(page)).toHaveCount(199, { timeout: 40_000 });
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
      const small = await page.evaluate(() => {
        const bad: string[] = [];
        document.querySelectorAll<HTMLElement>('[data-testid="question-list"] button, [data-testid="question-list"] a').forEach((el) => {
          const r = el.getBoundingClientRect();
          if (r.width < 43.5 || r.height < 43.5) bad.push(`${el.getAttribute("aria-label") ?? el.textContent}:${Math.round(r.width)}x${Math.round(r.height)}`);
        });
        return bad;
      });
      expect(small.slice(0, 5)).toEqual([]);
      // Chuyển câu 100 xuống (giữa danh sách) bằng nút ở màn hình nhỏ.
      const mid = page.getByRole("button", { name: "Chuyển câu 100 xuống", exact: true });
      await mid.scrollIntoViewIfNeeded();
      const cur = await questionsOf(page, course, quizFull);
      const t0 = Date.now();
      await mid.click();
      await toastText(page, "Đã lưu thứ tự câu");
      console.log(`QA2 PUT order 199 câu + cập nhật UI: ${Date.now() - t0}ms`);
      const qs = await questionsOf(page, course, quizFull);
      expect(contiguous(qs)).toEqual(Array.from({ length: 199 }, (_, i) => i + 1));
      expect(qs[99]!.id).toBe(cur[100]!.id);
      expect(qs[100]!.id).toBe(cur[99]!.id);
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    });
  });

  test("R6: PUT order chậm -> Sửa/Thêm câu hỏi bị khoá, nút bị khoá; xong thì danh sách khớp server", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(items(page)).toHaveCount(3, { timeout: 25_000 });
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    await page.route(/\/questions\/order$/, async (route) => {
      await gate;
      await route.continue();
    });
    await page.getByRole("button", { name: "Chuyển câu 1 xuống" }).click();
    await expect(page.getByRole("button", { name: "Thêm câu hỏi" })).toBeDisabled();
    await expect(page.getByRole("link", { name: /^Sửa/ })).toHaveCount(0);
    await expect(list(page)).toHaveAttribute("aria-busy", "true");
    await expect(page.getByRole("button", { name: /^Chuyển câu \d (lên|xuống)$/ }).first()).toBeDisabled();
    // Cố ép vào trang soạn bằng bàn phím trên phần tử "Sửa" giả (span aria-disabled): không điều hướng.
    const url = page.url();
    await page.locator('span[aria-disabled="true"]').first().click({ force: true });
    expect(page.url()).toBe(url);
    release();
    await expect(page.getByRole("link", { name: /^Sửa/ })).toHaveCount(3, { timeout: 15_000 });
    await page.unroute(/\/questions\/order$/);
    const qs = await questionsOf(page, course, quizEmpty);
    const ui = await items(page).allInnerTexts();
    qs.forEach((q, i) => expect(ui[i]).toContain(q.content));
    // Mở khoá: "Thêm câu hỏi" là liên kết dùng được.
    await expect(page.getByRole("link", { name: "Thêm câu hỏi" })).toBeVisible();
  });

  test("hai tab: tab A thêm + xoá câu, tab B kéo thả -> báo đã đổi, tải lại, không mất câu; tab B làm tiếp được", async ({ browser }) => {
    test.setTimeout(180_000);
    const ctxA = await browser.newContext();
    const ctxB = await browser.newContext();
    const a = await ctxA.newPage();
    const b = await ctxB.newPage();
    try {
      await loginGv(a);
      await loginGv(b);
      await b.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
      await expect(items(b)).toHaveCount(3, { timeout: 25_000 });
      const start = await questionsOf(a, course, quizEmpty);
      // Tab A: thêm 1 câu mới và xoá câu đầu.
      expect((await apiCall(a, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk("QA2 câu thêm từ tab A"))).status).toBe(201);
      expect((await apiCall(a, "DELETE", `/admin/courses/${course}/quizzes/${quizEmpty}/questions/${start[0]!.id}`)).status).toBe(204);
      const server = await questionsOf(a, course, quizEmpty);
      expect(server).toHaveLength(3);
      expect(contiguous(server)).toEqual([1, 2, 3]);
      // Tab B (danh sách cũ) kéo thả.
      await b.getByRole("button", { name: "Chuyển câu 2 xuống" }).click();
      await expect(b.getByText(/Đã tải lại danh sách mới/)).toBeVisible({ timeout: 15_000 });
      await expect(items(b)).toHaveCount(3, { timeout: 15_000 });
      // Danh sách tải lại là bất đồng bộ sau thông báo: chờ tới khi khớp server (không khớp trong 15s = lỗi thật).
      await expect
        .poll(async () => (await items(b).allInnerTexts()).map((t, i) => t.includes(server[i]!.content)).every(Boolean), { timeout: 15_000 })
        .toBe(true);
      const ui = await items(b).allInnerTexts();
      expect(ui.join("\n")).toContain("QA2 câu thêm từ tab A");
      expect(ui.join("\n")).not.toContain(start[0]!.content);
      // Không có thay đổi nào ở server sau lần bị từ chối.
      expect((await questionsOf(a, course, quizEmpty)).map((q) => q.id)).toEqual(server.map((q) => q.id));
      // Tab B kéo thả lại sau khi tải lại: thành công.
      await b.getByRole("button", { name: "Chuyển câu 1 xuống" }).click();
      await toastText(b, "Đã lưu thứ tự câu");
      const after = await questionsOf(a, course, quizEmpty);
      expect(after.map((q) => q.id)).toEqual([server[1]!.id, server[0]!.id, server[2]!.id]);
      expect(contiguous(after)).toEqual([1, 2, 3]);
    } finally {
      await ctxA.close();
      await ctxB.close();
    }
  });

  test("đổi thứ tự rồi sửa câu đã có lượt làm: id mới đúng vị trí; Back chỉ hỏi một lần", async ({ page }) => {
    test.setTimeout(180_000);
    await loginGv(page);
    const [q1, q2] = await questionsOf(page, course, quizA);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}`);
    await expect(items(page)).toHaveCount(2, { timeout: 25_000 });
    await page.getByRole("button", { name: "Chuyển câu 1 xuống" }).click();
    await toastText(page, "Đã lưu thứ tự câu");
    expect((await questionsOf(page, course, quizA)).map((q) => q.id)).toEqual([q2!.id, q1!.id]);

    // q1 (đã có lượt nộp) đang ở vị trí 2.
    await page.getByRole("link", { name: "Sửa câu 2" }).click();
    await expect(page).toHaveURL(new RegExp(`cau=${q1!.id}$`));
    const content = page.getByRole("textbox", { name: /Nội dung câu hỏi/ });
    await expect(content).toHaveValue(/Câu cũ/, { timeout: 15_000 });
    await content.fill("QA2 bản mới sau đổi thứ tự");
    await page.getByRole("button", { name: "Lưu câu hỏi" }).click();
    await expect(page.getByText("Đã lưu thành bản mới của câu hỏi")).toBeVisible({ timeout: 15_000 });
    const after = await questionsOf(page, course, quizA);
    expect(after).toHaveLength(2);
    expect(after[0]!.id).toBe(q2!.id);
    expect(after[1]!.id).not.toBe(q1!.id);
    expect(after[1]!.content).toBe("QA2 bản mới sau đổi thứ tự");
    expect(contiguous(after)).toEqual([1, 2]);
    expect((await apiCall(page, "GET", `/admin/courses/${course}/quizzes/${quizA}/questions/${q1!.id}`)).status).toBe(404);
    await expect(page).toHaveURL(new RegExp(`cau=${after[1]!.id}$`));

    // Sửa dở tiếp rồi Back: hỏi đúng một lần mỗi lần bấm Back.
    await content.fill("QA2 đang sửa dở");
    await page.goBack();
    await expect(dialog(page)).toContainText("Còn thay đổi chưa lưu");
    await expect(dialog(page)).toHaveCount(1);
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(dialog(page)).toHaveCount(0);
    await expect(content).toHaveValue("QA2 đang sửa dở");
    await page.waitForTimeout(600);
    await expect(dialog(page)).toHaveCount(0); // không bật lại hộp thứ hai
    await page.goBack();
    await expect(dialog(page)).toContainText("Còn thay đổi chưa lưu");
    await dialog(page).getByRole("button", { name: "Bỏ thay đổi" }).click();
    await expect(dialog(page)).toHaveCount(0, { timeout: 5000 });
    await page.waitForTimeout(800);
    await expect(dialog(page)).toHaveCount(0); // Bỏ thay đổi: đi luôn, không hỏi lần hai
    console.log(`QA2 URL sau khi Bỏ thay đổi bằng Back: ${page.url()}`);
    await expect(page.locator("main")).toBeVisible();
    // Dữ liệu chưa lưu không lọt vào server.
    expect((await questionsOf(page, course, quizA))[1]!.content).toBe("QA2 bản mới sau đổi thứ tự");
  });
});

/* ---------- Học sinh (API thật) ---------- */
async function studentLogin(request: APIRequestContext) {
  const h = { Accept: "application/json", Origin: "http://api.localhost:3000", Referer: "http://api.localhost:3000/", "X-Device-Id": "qa2-fa5-device-0001" };
  const { token } = (await (await request.get(`${STUDENT_API}/csrf-token`, { headers: h })).json()) as { token: string };
  const res = await request.post(`${STUDENT_API}/auth/login`, { headers: { ...h, "X-CSRF-TOKEN": token, "Content-Type": "application/json" }, data: { login: "e2e-fa5-hs@example.com", password: PASSWORD } });
  expect([200, 201, 204], `login HS: ${res.status()} ${await res.text()}`).toContain(res.status());
  return async (method: "GET" | "POST", p: string) => {
    const t = ((await (await request.get(`${STUDENT_API}/csrf-token`, { headers: h })).json()) as { token: string }).token;
    const r = await request.fetch(`${STUDENT_API}${p}`, { method, headers: { ...h, "X-CSRF-TOKEN": t } });
    return { status: r.status(), body: (await r.json().catch(() => null)) as Json | null };
  };
}
const attemptOrder = (b: Json | null): number[] => {
  const qs = ((b?.questions ?? (b?.data as Json | undefined)?.questions) as Array<{ id: number }> | undefined) ?? [];
  return qs.map((q) => q.id);
};

test.describe("QA FA5 vòng 2: học sinh thấy thứ tự mới", () => {
  test("lượt đang dở không đổi; lượt mới sau khi nộp theo thứ tự mới", async ({ page, request }) => {
    test.setTimeout(180_000);
    await loginGv(page);
    // Đưa quiz trống về 2 câu cố định để dễ so.
    for (const q of await questionsOf(page, course, quizEmpty)) await apiCall(page, "DELETE", `/admin/courses/${course}/quizzes/${quizEmpty}/questions/${q.id}`);
    for (const n of [1, 2, 3]) await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk(`HS câu ${n}`));
    const [s1, s2, s3] = await questionsOf(page, course, quizEmpty);

    const hs = await studentLogin(request);
    const started = await hs("POST", `/learn/quizzes/${quizEmpty}/attempts`);
    expect([200, 201], JSON.stringify(started.body)).toContain(started.status);
    const attemptId = (started.body!.id ?? (started.body!.data as Json).id) as number;
    expect(attemptOrder(started.body)).toEqual([s1!.id, s2!.id, s3!.id]);

    // Admin đổi thứ tự bằng UI: câu 3 lên đầu (hai lần Lên).
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(items(page)).toHaveCount(3, { timeout: 25_000 });
    await page.getByRole("button", { name: "Chuyển câu 3 lên" }).click();
    await toastText(page, "Đã lưu thứ tự câu");
    await expect(page.getByRole("button", { name: "Chuyển câu 2 lên" })).toBeEnabled({ timeout: 10_000 });
    await page.getByRole("button", { name: "Chuyển câu 2 lên" }).click();
    await expect.poll(async () => (await questionsOf(page, course, quizEmpty)).map((q) => q.id), { timeout: 15_000 }).toEqual([s3!.id, s1!.id, s2!.id]);

    // Lượt đang dở: không đổi, cả khi đọc lại lẫn khi "bắt đầu" lại (trả lượt đang làm).
    const reread = await hs("GET", `/learn/quiz-attempts/${attemptId}`);
    expect(reread.status).toBe(200);
    expect(attemptOrder(reread.body)).toEqual([s1!.id, s2!.id, s3!.id]);
    const again = await hs("POST", `/learn/quizzes/${quizEmpty}/attempts`);
    expect(attemptOrder(again.body)).toEqual([s1!.id, s2!.id, s3!.id]);

    // Nộp rồi làm lượt mới: thứ tự mới.
    const sub = await hs("POST", `/learn/quiz-attempts/${attemptId}/submit`);
    expect([200, 201, 204]).toContain(sub.status);
    const fresh = await hs("POST", `/learn/quizzes/${quizEmpty}/attempts`);
    expect([200, 201], JSON.stringify(fresh.body)).toContain(fresh.status);
    expect(attemptOrder(fresh.body)).toEqual([s3!.id, s1!.id, s2!.id]);
  });
});
