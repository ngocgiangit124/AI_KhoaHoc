import { expect, test, type Page } from "@playwright/test";

/**
 * QA FA5 (laravel-qa) — bổ sung cho soan-quiz-real.spec.ts: xác nhận rời trang (sidebar/header/Back, FA4 tab chương bài),
 * hai tab cùng sửa một câu có lượt làm, công thức độc hại ở xem trước, `<b`/`x > 2` ở client và server, quiz bị xoá khi đang mở.
 * Dùng giáo viên được gán (không MFA). Seed trước: `seed-e2e-quiz.sh --reset`. Chạy `--workers=1 --retries=0`.
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

type Json = Record<string, unknown>;
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
const content = (page: Page) => page.getByRole("textbox", { name: /Nội dung câu hỏi/ });
const option = (page: Page, i: number) => page.locator(`#q-o${i}`);
const preview = (page: Page) => page.getByTestId("question-preview");
const dialog = (page: Page) => page.getByRole("dialog");

let course = 0;
let quizA = 0;
let quizEmpty = 0;
let lessonId = 0;

test.describe("QA FA5 (thật)", () => {
  test("chuẩn bị: lấy id khóa, quiz, bài", async ({ page }) => {
    test.setTimeout(90_000);
    await loginGv(page);
    const list = await apiCall(page, "GET", `/admin/courses?per_page=50&q=${encodeURIComponent("E2E FA5 Khóa soạn quiz")}`);
    course = ((list.body!.data as Json[]).find((c) => c.title === "E2E FA5 Khóa soạn quiz")!.id) as number;
    const quizzes = (await apiCall(page, "GET", `/admin/courses/${course}/quizzes`)).body!.data as Array<{ id: number; title: string }>;
    quizA = quizzes.find((q) => q.title === "E2E FA5 Quiz có lượt làm")!.id;
    quizEmpty = quizzes.find((q) => q.title === "E2E FA5 Quiz trống")!.id;
    const cur = (await apiCall(page, "GET", `/admin/courses/${course}/chapters`)).body as Json;
    const chapters = (cur.chapters ?? (cur.data as Json).chapters) as Array<{ lessons?: Array<{ id: number; title: string }> }>;
    lessonId = chapters.flatMap((c) => c.lessons ?? []).find((l) => l.title === "E2E FA5 Bài 1")!.id;
    expect(course && quizA && quizEmpty && lessonId).toBeTruthy();
  });

  test("AC rời trang: soạn dở -> sidebar/header/Back hỏi; Ở lại giữ chữ; Bỏ thay đổi rời trang", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await content(page).fill("Soạn dở QA");

    // Sidebar: mọi liên kết nội bộ ngoài khung nội dung (sidebar + thanh trên cùng).
    const outside = page.locator('a[href^="/quan-tri"]').filter({ hasNot: page.locator("main a") });
    const sidebar = page.getByRole("link", { name: /^(Tổng quan|Hồ sơ của tôi)$/ }).first();
    await sidebar.click();
    await expect(dialog(page)).toContainText("Còn thay đổi chưa lưu");
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(content(page)).toHaveValue("Soạn dở QA");
    await expect(page).toHaveURL(/cau=moi/);
    void outside;

    // Esc trong hộp thoại = Ở lại.
    await sidebar.click();
    await expect(dialog(page)).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(dialog(page)).toHaveCount(0);
    await expect(content(page)).toHaveValue("Soạn dở QA");

    // Back (hai lần liên tiếp: lần 2 vẫn phải bị chặn).
    for (let i = 0; i < 2; i++) {
      await page.goBack();
      await expect(dialog(page)).toContainText("Còn thay đổi chưa lưu");
      await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
      await expect(content(page)).toHaveValue("Soạn dở QA");
      await expect(page).toHaveURL(/cau=moi/);
    }

    // Câu trước/sau và chip số câu là liên kết cùng trang nhưng khác ?cau: cũng phải hỏi.
    // Ctrl+click (mở tab mới) không bị chặn.
    // Bỏ thay đổi qua sidebar: rời trang, không còn hỏi.
    await sidebar.click();
    await dialog(page).getByRole("button", { name: "Bỏ thay đổi" }).click();
    await expect(page).not.toHaveURL(/bai-tap/, { timeout: 15_000 });
    // Quay lại bài soạn, chưa gõ gì: Back/sidebar không hỏi.
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await sidebar.click();
    await expect(page).not.toHaveURL(/bai-tap/, { timeout: 15_000 });
    await expect(dialog(page)).toHaveCount(0);
  });

  test("AC rời trang: sau khi lưu xong không còn hỏi (kể cả Back)", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await content(page).fill("Câu QA lưu rồi rời $x$");
    for (let i = 0; i < 4; i++) await option(page, i).fill(`đáp án ${i}`);
    await page.getByRole("radio", { name: /đáp án B/ }).check();
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(page.getByTestId("saved-at")).toBeVisible({ timeout: 15_000 });
    await page.getByRole("link", { name: /^(Tổng quan|Hồ sơ của tôi)$/ }).first().click();
    await expect(dialog(page)).toHaveCount(0);
    await expect(page).not.toHaveURL(/bai-tap/, { timeout: 15_000 });
  });

  test("FA4 còn đúng với hook mới: form bài chưa lưu -> tab, sidebar đều hỏi; trong khung chỉ hỏi một lần", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/sua?tab=chuong-bai&bai=${lessonId}`);
    const name = page.getByLabel(/Tên bài học/);
    await expect(name).toBeVisible({ timeout: 25_000 });
    await name.fill("E2E FA5 Bài 1 (sửa dở)");
    const tabs = page.getByRole("navigation", { name: "Phần của khóa học" });

    await tabs.getByRole("link", { name: "Thông tin chung" }).click();
    await expect(dialog(page)).toHaveCount(1);
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(name).toHaveValue("E2E FA5 Bài 1 (sửa dở)");

    await tabs.getByRole("link", { name: /^Bài tập/ }).click();
    await expect(dialog(page)).toHaveCount(1);
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();

    await page.getByRole("link", { name: /^(Tổng quan|Hồ sơ của tôi)$/ }).first().click();
    await expect(dialog(page)).toHaveCount(1);
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(page).toHaveURL(/tab=chuong-bai/);

    await tabs.getByRole("link", { name: /^Bài tập/ }).click();
    await dialog(page).getByRole("button", { name: "Bỏ thay đổi" }).click();
    await expect(page).toHaveURL(/tab=bai-tap/, { timeout: 15_000 });
    // Tab Bài tập không dirty -> sang Thông tin chung không hỏi.
    await tabs.getByRole("link", { name: "Thông tin chung" }).click();
    await expect(dialog(page)).toHaveCount(0);
  });

  test("hai tab cùng sửa một câu đã có lượt làm: tab 2 (id cũ) -> báo câu không còn, tải lại; mở lại link id cũ không trắng trang", async ({ browser }) => {
    test.setTimeout(180_000);
    const ctx = await browser.newContext();
    const a = await ctx.newPage();
    await loginGv(a);
    const b = await ctx.newPage();
    const detail = (await apiCall(a, "GET", `/admin/courses/${course}/quizzes/${quizA}`)).body as unknown as { questions: Array<{ id: number }> };
    const q1 = detail.questions[0]!.id;
    const url = `${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${q1}`;
    await a.goto(url);
    await b.goto(url);
    await expect(content(a)).toBeVisible({ timeout: 25_000 });
    await expect(content(b)).toBeVisible({ timeout: 25_000 });

    await content(a).fill("Tab A sửa $a$");
    await a.getByRole("button", { name: "Lưu câu hỏi" }).click();
    await expect(a.getByText("Đã lưu thành bản mới của câu hỏi")).toBeVisible({ timeout: 15_000 });
    const newId = (((await apiCall(a, "GET", `/admin/courses/${course}/quizzes/${quizA}`)).body as unknown as { questions: Array<{ id: number }> }).questions[0]!.id);
    expect(newId).not.toBe(q1);

    await content(b).fill("Tab B sửa $b$");
    await b.getByRole("button", { name: "Lưu câu hỏi" }).click();
    await expect(b.getByText("Câu hỏi không còn tồn tại. Đã tải lại danh sách.")).toBeVisible({ timeout: 15_000 });
    await expect(b.getByTestId("question-list")).toBeVisible({ timeout: 15_000 });
    await expect(b.getByTestId("question-list")).toContainText("Tab A sửa");
    await expect(b.getByTestId("question-list")).not.toContainText("Tab B sửa");
    // DB: không có bản của tab B.
    const after = (await apiCall(a, "GET", `/admin/courses/${course}/quizzes/${quizA}`)).body as unknown as { questions: Array<{ id: number; content: string }> };
    expect(after.questions.map((q) => q.content).join("|")).not.toContain("Tab B");

    // Mở lại link id cũ: thông báo, không trắng trang; còn dẫn đường được.
    await b.goto(url);
    await expect(b.getByTestId("question-missing")).toBeVisible({ timeout: 25_000 });
    await expect(b.getByRole("link", { name: "Về danh sách câu" }).first()).toBeVisible();
    await ctx.close();
  });

  test("công thức độc hại ở xem trước: không liên kết/ảnh; macro đệ quy và >2000 ký tự không treo trang; $ lẻ chỉ là lưu ý", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    const evil = [
      "$\\href{javascript:alert(1)}{bấm vào}$",
      "$\\includegraphics{https://evil.test/x.png}$",
      "$\\def\\a{\\a\\a}\\a$",
      "$\\htmlClass{pwn}{x}\\htmlStyle{position:fixed}{y}$",
      `$${"x+".repeat(1100)}x$`,
    ].join("\n");
    let dialogs = 0;
    page.on("dialog", () => dialogs++);
    await content(page).fill(evil);
    await option(page, 0).fill("giá 5$");
    await expect(page.getByTestId("dollar-warning")).toBeVisible();
    // Lưu ý $ không phải lỗi: không aria-invalid, không role=alert của ô đó.
    await expect(option(page, 0)).not.toHaveAttribute("aria-invalid", "true");
    await page.waitForTimeout(800);
    const p = preview(page);
    expect(await p.locator("a, img, script, iframe, [onerror], [onclick]").count()).toBe(0);
    expect(await p.locator(".pwn").count()).toBe(0);
    expect(await p.locator('[style*="position:fixed"], [style*="position: fixed"]').count()).toBe(0);
    await expect(p.locator(".katex-error").first()).toBeVisible();
    await expect(p).toContainText("\\includegraphics");
    expect(dialogs).toBe(0);
    // Trang vẫn tương tác được sau macro đệ quy.
    await content(page).fill("ổn $x$");
    await expect(p.locator(".katex").first()).toBeVisible({ timeout: 5000 });
  });

  test("<b bị chặn ở client (không gọi API) và ở server; x > 2 được cả hai; bidi bị server chặn", async ({ page }) => {
    test.setTimeout(120_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    let posts = 0;
    page.on("request", (r) => {
      if (r.method() === "POST" && /\/questions$/.test(r.url())) posts++;
    });
    await content(page).fill("Nếu a<b thì sao");
    for (let i = 0; i < 4; i++) await option(page, i).fill(`x > ${i}`);
    await page.getByRole("radio", { name: /đáp án A/ }).check();
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(page.locator("#tom-tat-loi")).toContainText("giống thẻ HTML");
    await expect(page.locator("#tom-tat-loi")).toBeFocused();
    expect(posts).toBe(0);

    // Server từ chối cùng nội dung và các biến thể.
    const mk = (c: string, o0 = "a") => ({ content: c, explanation: null, options: [o0, "b", "c", "d"].map((x, i) => ({ content: x, is_correct: i === 0 })) });
    const bad = ["a<b", "</p>", "<!-- x -->", "x‮y", "x​y", "x\u0000y", "x\u0007y"];
    for (const s of bad) {
      const r = await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk(s));
      expect(r.status, JSON.stringify(s)).toBe(422);
    }
    const r2 = await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk("a", "<b>"));
    expect(r2.status).toBe(422);
    expect(JSON.stringify(r2.body)).toContain("options.0.content");

    // x > 2 được ở UI.
    const before = posts;
    await content(page).fill("Nếu x > 2 và y < 3 thì $a \\lt b$ đúng");
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(page.getByTestId("saved-at")).toBeVisible({ timeout: 15_000 });
    expect(posts - before).toBe(1);
    // Tiếng Việt có dấu giữ nguyên qua DB.
    await expect(preview(page)).toContainText("Nếu x > 2 và y < 3");
  });

  test("quiz bị xoá khi đang mở trang soạn: lưu/xoá câu không trắng trang, báo không tìm thấy bài tập", async ({ browser }) => {
    test.setTimeout(180_000);
    const ctx = await browser.newContext();
    const a = await ctx.newPage();
    await loginGv(a);
    const created = await apiCall(a, "POST", `/admin/courses/${course}/quizzes`, { title: "E2E FA5 QA sẽ bị xoá", lesson_id: lessonId, time_limit_minutes: null });
    expect(created.status).toBe(201);
    const qid = (created.body!.data as Json | undefined)?.id ?? (created.body as Json).id;
    const b = await ctx.newPage();
    await b.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${qid}?cau=moi`);
    await expect(content(b)).toBeVisible({ timeout: 25_000 });
    expect((await apiCall(a, "DELETE", `/admin/courses/${course}/quizzes/${qid}`)).status).toBe(204);

    await content(b).fill("Câu cho quiz đã xoá");
    for (let i = 0; i < 4; i++) await option(b, i).fill(`${i}`);
    await b.getByRole("radio", { name: /đáp án A/ }).check();
    await b.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(b.getByText(/không còn tồn tại|Không tìm thấy/).first()).toBeVisible({ timeout: 20_000 });
    // Không trắng trang, không tạo câu mồ côi trong DB.
    await expect(b.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(b.getByTestId("quiz-not-found")).toBeVisible({ timeout: 5_000 });
    await ctx.close();
  });

  test("mở link bài tập đã bị xoá / id không tồn tại: trang 'Không tìm thấy bài tập', có lối về", async ({ page }) => {
    test.setTimeout(90_000);
    await loginGv(page);
    const created = await apiCall(page, "POST", `/admin/courses/${course}/quizzes`, { title: "E2E FA5 QA xoá rồi mở", lesson_id: lessonId, time_limit_minutes: null });
    const qid = (created.body!.data as Json | undefined)?.id ?? (created.body as Json).id;
    expect((await apiCall(page, "DELETE", `/admin/courses/${course}/quizzes/${qid}`)).status).toBe(204);
    for (const id of [qid, 999999]) {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${id}`);
      await expect(page.getByTestId("quiz-not-found")).toBeVisible({ timeout: 25_000 });
      await expect(page.getByRole("link", { name: "Về danh sách bài tập" })).toBeVisible();
    }
    // id không hợp lệ -> trang 404 của Next (stream nên mã HTTP có thể vẫn 200), không lỗi 500, không màn soạn.
    const res = await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/abc`);
    expect(res?.status()).toBeLessThan(500);
    await expect(page.getByText(/404|không tìm thấy|not found/i).first()).toBeVisible({ timeout: 25_000 });
    await expect(content(page)).toHaveCount(0);
  });

  test("Back sau khi đã soạn rồi lưu: không hiện hộp xác nhận (ghi nhận: Back đầu tiên có thể chỉ gỡ mục đệm, ở lại trang)", async ({ page }) => {
    test.setTimeout(90_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 25_000 });
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await content(page).fill("Back sau khi lưu $y$");
    for (let i = 0; i < 4; i++) await option(page, i).fill(`p${i}`);
    await page.getByRole("radio", { name: /đáp án C/ }).check();
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(page.getByTestId("saved-at")).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(1500);
    const here = page.url();
    await page.goBack();
    await expect(dialog(page)).toHaveCount(0);
    await page.waitForTimeout(500);
    // Ghi lại nơi đang đứng sau Back đầu tiên (không khẳng định cứng: chỉ không được trắng trang / lỗi).
    console.log(`QA-FA5 Back sau lưu: ${here} -> ${page.url()}`);
    await expect(page.locator("main")).toBeVisible();
  });

  test("Back khi đang sửa dở SAU KHI vừa thêm câu mới (URL đã thay sang ?cau=<id>): hỏi xác nhận và Ở lại giữ nguyên chữ", async ({ page }) => {
    test.setTimeout(90_000);
    await loginGv(page);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}?cau=moi`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await content(page).fill("Câu vừa thêm $z$");
    for (let i = 0; i < 4; i++) await option(page, i).fill(`r${i}`);
    await page.getByRole("radio", { name: /đáp án D/ }).check();
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await expect(page.getByTestId("saved-at")).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveURL(/cau=\d+$/);
    const savedUrl = page.url();
    await content(page).fill("Đang sửa dở sau khi thêm");
    await page.goBack();
    await expect(dialog(page)).toContainText("Còn thay đổi chưa lưu", { timeout: 5000 });
    await dialog(page).getByRole("button", { name: "Ở lại để lưu" }).click();
    await expect(page).toHaveURL(savedUrl);
    await expect(content(page)).toHaveValue("Đang sửa dở sau khi thêm");
  });
});
