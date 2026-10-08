import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * FA5 — e2e THẬT soạn quiz: danh sách/tạo/sửa/xoá bài tập, soạn câu với xem trước KaTeX, kiểm tại chỗ, copy-on-write (câu đã có lượt
 * làm), trần 200 câu, rời trang chưa lưu, quyền (giáo viên được gán / không được gán), 375px.
 * Chạy với E2E_REAL_BACKEND=1 (admin-api.localhost:3001 + :8000; MFA của quản lý trang đọc từ Mailpit) qua `e2e/run-real.sh`.
 * Seed trước: `frontend/apps/admin/e2e/seed-e2e-quiz.sh --reset` (--clean để xoá hết). Chạy `--workers=1 --retries=0` (mỗi lần đăng nhập MFA tốn một OTP).
 * Dữ liệu thêm trong lúc chạy đều có tiền tố "E2E FA5 ".
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
const STAFF = process.env.FA5_STAFF ?? "fa5-qlt1";
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
  if (!hit) throw new Error(`Không thấy khóa "${title}" (đã chạy seed-e2e-quiz.sh chưa?)`);
  return hit.id as number;
}

interface Q { id: number; position: number; content: string; options: Array<{ content: string; is_correct: boolean }> }
interface QuizRow { id: number; title: string; questions_count?: number; time_limit_minutes: number | null }
async function quizzes(page: Page, courseId: number): Promise<QuizRow[]> {
  const res = await apiCall(page, "GET", `/admin/courses/${courseId}/quizzes`);
  expect(res.status).toBe(200);
  return res.body!.data as QuizRow[];
}
async function quizDetail(page: Page, courseId: number, quizId: number) {
  const res = await apiCall(page, "GET", `/admin/courses/${courseId}/quizzes/${quizId}`);
  expect(res.status).toBe(200);
  return res.body as unknown as { questions: Q[] };
}
const idOf = async (page: Page, courseId: number, title: string) => (await quizzes(page, courseId)).find((q) => q.title === title)!.id;

const toastText = (page: Page, text: string) => expect(page.getByText(text).first()).toBeVisible({ timeout: 15_000 });
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const content = (page: Page) => page.getByRole("textbox", { name: /Nội dung câu hỏi/ });
const option = (page: Page, i: number) => page.locator(`#q-o${i}`);
const preview = (page: Page) => page.getByTestId("question-preview");

async function fillQuestion(page: Page, c: string, opts: [string, string, string, string], correct: number) {
  await content(page).fill(c);
  for (const [i, o] of opts.entries()) await option(page, i).fill(o);
  await page.getByRole("radio", { name: new RegExp(`đáp án ${"ABCD"[correct]}`) }).check();
}

let course = 0;
let foreignCourse = 0;
let quizA = 0; // có lượt làm
let quizFull = 0; // 199 câu
let quizEmpty = 0;

test.describe("FA5 soạn quiz (thật)", () => {
  test("Quản lý trang (MFA): tab Bài tập, tạo/sửa/xoá bài tập, 422 và kiểm tại chỗ", async ({ page, request }) => {
    test.setTimeout(300_000);
    await loginMfa(page, request, STAFF);
    course = await findCourse(page, "E2E FA5 Khóa soạn quiz");
    foreignCourse = await findCourse(page, "E2E FA5 Của GV khác");
    quizA = await idOf(page, course, "E2E FA5 Quiz có lượt làm");
    quizFull = await idOf(page, course, "E2E FA5 Quiz gần đầy");
    quizEmpty = await idOf(page, course, "E2E FA5 Quiz trống");

    await test.step("tab Bài tập từ màn sửa khóa; bảng + cảnh báo quiz chưa có câu", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/sua`);
      await page.getByRole("navigation", { name: "Phần của khóa học" }).getByRole("link", { name: /^Bài tập/ }).click();
      await expect(page).toHaveURL(/tab=bai-tap/);
      await expect(page.getByRole("link", { name: "E2E FA5 Quiz có lượt làm", exact: true })).toBeVisible({ timeout: 25_000 });
      await expect(page.getByText("1 bài tập chưa có câu hỏi")).toBeVisible();
      await expect(page.getByRole("link", { name: "E2E FA5 Quiz gần đầy", exact: true })).toBeVisible();
      const row = page.getByRole("row", { name: /Quiz gần đầy/ });
      await expect(row).toContainText("199");
    });

    await test.step("tạo bài tập: kiểm tại chỗ rồi tạo thật", async () => {
      await page.getByRole("button", { name: "Tạo bài tập" }).click();
      const dlg = page.getByRole("dialog");
      await expect(dlg.getByRole("combobox")).toBeVisible({ timeout: 15_000 });
      await dlg.getByRole("spinbutton").fill("301");
      await dlg.getByRole("button", { name: "Tạo bài tập" }).click();
      await expect(dlg.getByText("Vui lòng nhập tên bài tập.")).toBeVisible();
      await expect(dlg.getByText(/Chọn chương hoặc bài học/)).toBeVisible();
      await expect(dlg.getByText("Thời gian từ 1 đến 300 phút.")).toBeVisible();
      await dlg.getByRole("textbox", { name: /Tên bài tập/ }).fill("E2E FA5 Quiz mới");
      await dlg.getByRole("combobox").selectOption({ label: "E2E FA5 Bài 2" });
      await dlg.getByRole("spinbutton").fill("20");
      await dlg.getByRole("button", { name: "Tạo bài tập" }).click();
      await toastText(page, "Đã tạo bài tập");
      await expect(page.getByRole("link", { name: "E2E FA5 Quiz mới", exact: true })).toBeVisible();
      const created = (await quizzes(page, course)).find((q) => q.title === "E2E FA5 Quiz mới")!;
      expect(created.time_limit_minutes).toBe(20);
      await expect(page.getByText("2 bài tập chưa có câu hỏi")).toBeVisible();
    });

    await test.step("sửa thông tin bài tập: đổi tên và 'không giới hạn'", async () => {
      await page.getByRole("link", { name: "E2E FA5 Quiz mới", exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: "E2E FA5 Quiz mới" })).toBeVisible({ timeout: 25_000 });
      await expect(page.getByText("Bài tập chưa có câu hỏi")).toBeVisible();
      await page.getByRole("button", { name: "Sửa thông tin" }).click();
      const dlg = page.getByRole("dialog");
      await expect(dlg.getByRole("combobox")).toBeVisible({ timeout: 15_000 });
      await dlg.getByRole("textbox", { name: /Tên bài tập/ }).fill("E2E FA5 Quiz mới (đổi tên)");
      await dlg.getByRole("checkbox", { name: "Không giới hạn thời gian" }).check();
      await dlg.getByRole("button", { name: "Lưu" }).click();
      await toastText(page, "Đã lưu thông tin bài tập");
      await expect(page.getByRole("heading", { level: 1, name: "E2E FA5 Quiz mới (đổi tên)" })).toBeVisible({ timeout: 15_000 });
      await expect(page.getByText("Không giới hạn thời gian")).toBeVisible();
    });

    await test.step("thêm câu đầu tiên: xem trước KaTeX theo phím, kiểm tại chỗ, lưu", async () => {
      await page.getByRole("link", { name: "Thêm câu hỏi đầu tiên" }).click();
      await expect(content(page)).toBeVisible({ timeout: 15_000 });
      // Gửi trống: không gọi API, có hộp tóm tắt lỗi.
      await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
      await expect(page.getByText(/còn 6 chỗ cần sửa/)).toBeVisible();
      await expect(page.locator("#tom-tat-loi")).toBeFocused();
      await page.getByRole("link", { name: "Đáp án đúng", exact: true }).click();
      await expect(page.locator("#q-o0-dung")).toBeFocused();
      // Dấu < sát chữ bị chặn như server.
      await content(page).fill("a <b");
      await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
      await expect(page.getByText(/giống thẻ HTML/).first()).toBeVisible();
      // Chèn nhanh + xem trước.
      await content(page).fill("Tính ");
      await page.getByRole("button", { name: "Phân số" }).click();
      await expect(content(page)).toHaveValue("Tính \\dfrac{}{}");
      await content(page).fill("Tính $\\dfrac{1}{2}+\\dfrac{1}{3}$ rồi so sánh $a \\lt b$ và $\\badmacro{$");
      await expect(preview(page).locator(".katex").first()).toBeVisible({ timeout: 5000 });
      await expect(preview(page).locator(".katex-error").first()).toBeVisible();
      await option(page, 0).fill("$\\dfrac{2}{5}$");
      await option(page, 1).fill("$\\dfrac{5}{6}$");
      await option(page, 2).fill("$1$");
      await option(page, 3).fill("Không tính được");
      await page.getByRole("radio", { name: /đáp án B/ }).check();
      await expect(preview(page).getByText("Đáp án đúng")).toBeVisible();
      await page.locator("#q-explanation").fill("Quy đồng: $\\dfrac{3}{6}+\\dfrac{2}{6}=\\dfrac{5}{6}$.");
      await expect(preview(page).getByText("Lời giải")).toBeVisible();
      await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
      await toastText(page, "Đã thêm câu hỏi");
      await expect(page).toHaveURL(/cau=\d+/);
      await expect(page.getByTestId("saved-at")).toContainText(/Đã lưu lúc \d{2}:\d{2}/);
      const qs = (await quizDetail(page, course, (await quizzes(page, course)).find((q) => q.title === "E2E FA5 Quiz mới (đổi tên)")!.id)).questions;
      expect(qs).toHaveLength(1);
      expect(qs[0]!.options.map((o) => o.is_correct)).toEqual([false, true, false, false]);
    });

    await test.step("xoá bài tập vừa tạo (có xác nhận)", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/sua?tab=bai-tap`);
      await page.getByRole("button", { name: "Xoá bài tập E2E FA5 Quiz mới (đổi tên)" }).click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Xoá bài tập");
      expect((await quizzes(page, course)).some((q) => q.title.startsWith("E2E FA5 Quiz mới"))).toBe(true);
      await dlg.getByRole("button", { name: "Xoá bài tập", exact: true }).click();
      await toastText(page, "Đã xoá bài tập");
      await expect(page.getByRole("link", { name: /^E2E FA5 Quiz mới/ })).toHaveCount(0);
      expect((await quizzes(page, course)).some((q) => q.title.startsWith("E2E FA5 Quiz mới"))).toBe(false);
    });

    await test.step("quiz không tồn tại / không thuộc khóa → trang không tìm thấy", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/99999999`);
      await expect(page.getByTestId("quiz-not-found")).toBeVisible({ timeout: 25_000 });
      const foreignQuiz = (await quizzes(page, foreignCourse))[0]!.id;
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${foreignQuiz}`);
      await expect(page.getByTestId("quiz-not-found")).toBeVisible({ timeout: 25_000 });
    });
  });

  test("Copy-on-write: sửa câu đã có lượt làm tạo bản mới; câu chưa ai làm sửa tại chỗ", async ({ page, request }) => {
    test.setTimeout(240_000);
    await loginMfa(page, request, process.env.FA5_STAFF2 ?? "fa5-qlt2");
    const before = (await quizDetail(page, course, quizA)).questions;
    expect(before).toHaveLength(2);
    const [old1, old2] = before as [Q, Q];

    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}`);
    await expect(page.getByTestId("question-list").getByRole("listitem")).toHaveCount(2, { timeout: 25_000 });
    await expect(page.getByTestId("question-count")).toHaveText(/2\/200 câu/);

    await test.step("câu 1 (đã có lượt nộp) → bản mới, URL sang id mới", async () => {
      await page.getByRole("link", { name: "Sửa câu 1" }).click();
      await expect(page).toHaveURL(new RegExp(`cau=${old1.id}$`));
      await expect(content(page)).toHaveValue(/Câu cũ/, { timeout: 15_000 });
      await content(page).fill("Câu đã sửa: $x^2 \\ge 0$");
      await expect(preview(page).locator(".katex").first()).toBeVisible({ timeout: 5000 });
      await page.getByRole("button", { name: "Lưu câu hỏi" }).click();
      await expect(page.getByText("Đã lưu thành bản mới của câu hỏi")).toBeVisible({ timeout: 15_000 });
      await expect(page).not.toHaveURL(new RegExp(`cau=${old1.id}$`));
      const after = (await quizDetail(page, course, quizA)).questions;
      expect(after).toHaveLength(2);
      expect(after[0]!.id).not.toBe(old1.id);
      expect(after[0]!.position).toBe(old1.position);
      expect(after[0]!.content).toBe("Câu đã sửa: $x^2 \\ge 0$");
      expect(after[1]!.id).toBe(old2.id);
      // id cũ không còn gọi được.
      const gone = await apiCall(page, "GET", `/admin/courses/${course}/quizzes/${quizA}/questions/${old1.id}`);
      expect(gone.status).toBe(404);
      // Mở lại URL cũ: thông báo thân thiện, không lỗi trắng trang.
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${old1.id}`);
      await expect(page.getByTestId("question-missing")).toBeVisible({ timeout: 25_000 });
    });

    await test.step("câu 2 (chưa ai làm) → sửa tại chỗ, giữ id", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${old2.id}`);
      await expect(content(page)).toHaveValue(/Câu chưa ai làm/, { timeout: 25_000 });
      await content(page).fill("Câu chưa ai làm (đã sửa)");
      await page.getByRole("button", { name: "Lưu câu hỏi" }).click();
      await expect(page.getByTestId("saved-at")).toContainText(/Đã lưu lúc/, { timeout: 15_000 });
      await expect(page.getByText("Đã lưu thành bản mới của câu hỏi")).toHaveCount(0);
      await expect(page).toHaveURL(new RegExp(`cau=${old2.id}$`));
      const after = (await quizDetail(page, course, quizA)).questions;
      expect(after.find((q) => q.id === old2.id)!.content).toBe("Câu chưa ai làm (đã sửa)");
    });

    await test.step("rời trang khi chưa lưu: hỏi xác nhận; ở lại giữ nguyên chữ; bỏ thay đổi thì đi", async () => {
      await content(page).fill("Chưa lưu!");
      await page.getByRole("link", { name: "Về danh sách câu" }).click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Còn thay đổi chưa lưu");
      await dlg.getByRole("button", { name: "Ở lại để lưu" }).click();
      await expect(content(page)).toHaveValue("Chưa lưu!");
      await page.getByRole("link", { name: "Về danh sách câu" }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Bỏ thay đổi" }).click();
      await expect(page.getByTestId("question-list")).toBeVisible({ timeout: 15_000 });
      await expect(page.getByTestId("question-list")).not.toContainText("Chưa lưu!");
    });

    await test.step("rời trang bằng sidebar / nút Back khi chưa lưu cũng hỏi xác nhận", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${old2.id}`);
      await expect(content(page)).toHaveValue(/đã sửa/, { timeout: 25_000 });
      await content(page).fill("Soạn dở ở câu 2");
      await page.getByRole("link", { name: "Khóa học", exact: true }).first().click();
      const dlg = page.getByRole("dialog");
      await expect(dlg).toContainText("Còn thay đổi chưa lưu");
      await dlg.getByRole("button", { name: "Ở lại để lưu" }).click();
      await expect(content(page)).toHaveValue("Soạn dở ở câu 2");
      await expect(page).toHaveURL(new RegExp(`bai-tap/${quizA}\\?cau=${old2.id}`));
      await page.goBack();
      await expect(page.getByRole("dialog")).toContainText("Còn thay đổi chưa lưu");
      await page.getByRole("dialog").getByRole("button", { name: "Ở lại để lưu" }).click();
      await expect(content(page)).toHaveValue("Soạn dở ở câu 2");
      await page.getByRole("link", { name: "Khóa học", exact: true }).first().click();
      await page.getByRole("dialog").getByRole("button", { name: "Bỏ thay đổi" }).click();
      await expect(page).toHaveURL(/\/quan-tri\/khoa-hoc$/, { timeout: 15_000 });
    });

    await test.step("422 từ server hiện dưới đúng ô (mô phỏng phản hồi Laravel) và giữ nguyên dữ liệu", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${old2.id}`);
      await expect(content(page)).toHaveValue(/đã sửa/, { timeout: 25_000 });
      await page.route(new RegExp(`/quizzes/${quizA}/questions/${old2.id}$`), async (route) => {
        if (route.request().method() !== "PUT") return route.continue();
        await route.fulfill({
          status: 422,
          contentType: "application/json",
          body: JSON.stringify({ message: "Dữ liệu không hợp lệ.", code: "VALIDATION_FAILED", errors: { "options.2.content": ["Đáp án C không hợp lệ (từ server)."], explanation: ["Lời giải không hợp lệ (từ server)."] } }),
        });
      });
      await page.locator("#q-explanation").fill("x");
      await page.getByRole("button", { name: "Lưu câu hỏi" }).click();
      await expect(page.getByText("Đáp án C không hợp lệ (từ server).").first()).toBeVisible({ timeout: 15_000 });
      await expect(page.getByText("Lời giải không hợp lệ (từ server).").first()).toBeVisible();
      await expect(page.locator("#q-o2")).toHaveAttribute("aria-invalid", "true");
      await expect(page.locator("#q-explanation")).toHaveValue("x");
      await page.unroute(new RegExp(`/quizzes/${quizA}/questions/${old2.id}$`));
    });

    await test.step("thêm câu mới rồi xoá (có xác nhận); đếm câu cập nhật", async () => {
      await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=moi`);
      await expect(content(page)).toBeVisible({ timeout: 25_000 });
      await fillQuestion(page, "Câu tạm $1$", ["a", "b", "c", "d"], 3);
      await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
      await toastText(page, "Đã thêm câu hỏi");
      await expect(page.getByRole("heading", { name: /^Câu 3$/ })).toBeVisible({ timeout: 15_000 });
      await page.getByRole("button", { name: "Xoá câu" }).click();
      await page.getByRole("dialog").getByRole("button", { name: "Xoá câu" }).click();
      await toastText(page, "Đã xoá câu hỏi");
      await expect(page.getByTestId("question-count")).toHaveText(/2\/200 câu/, { timeout: 15_000 });
      expect((await quizDetail(page, course, quizA)).questions).toHaveLength(2);
    });
  });

  test("Trần 200 câu: câu thứ 200 thêm được, nút thêm khoá, API trả QUIZ_QUESTION_LIMIT", async ({ page, request }) => {
    test.setTimeout(240_000);
    // OTP chỉ 1 mã/phút/tài khoản và qlt1 vừa đăng nhập ở test đầu: chờ cho đủ.
    await page.waitForTimeout(45_000);
    await loginMfa(page, request, process.env.FA5_STAFF3 ?? "fa5-qlt1");
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizFull}?cau=moi`);
    await expect(page.getByTestId("question-count")).toHaveText(/199\/200 câu/, { timeout: 25_000 });
    await fillQuestion(page, "Câu số 200", ["a", "b", "c", "d"], 0);
    await page.getByRole("button", { name: "Thêm câu hỏi" }).click();
    await toastText(page, "Đã thêm câu hỏi");
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizFull}`);
    await expect(page.getByTestId("question-count")).toHaveText(/200\/200 câu/, { timeout: 25_000 });
    await expect(page.getByRole("button", { name: "Thêm câu hỏi" })).toBeDisabled();
    await expect(page.getByText(/tối đa 200 câu/)).toBeVisible();
    const over = await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizFull}/questions`, {
      content: "Câu 201",
      explanation: null,
      options: [{ content: "a", is_correct: true }, { content: "b", is_correct: false }, { content: "c", is_correct: false }, { content: "d", is_correct: false }],
    });
    expect(over.status).toBe(422);
    expect(over.body!.code).toBe("QUIZ_QUESTION_LIMIT");
    // Vào thẳng ?cau=moi khi đủ 200 → giải thích, không có form.
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizFull}?cau=moi`);
    await expect(page.getByText("Bài tập đã đủ 200 câu")).toBeVisible({ timeout: 25_000 });
  });

  test("Giáo viên được gán soạn được ở 375px; giáo viên không được gán nhận 403", async ({ page }) => {
    test.setTimeout(180_000);
    await fillLogin(page, "fa5-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.setViewportSize({ width: 375, height: 800 });

    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/sua?tab=bai-tap`);
    await expect(page.getByRole("link", { name: "E2E FA5 Quiz có lượt làm", exact: true })).toBeVisible({ timeout: 25_000 });
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    await expect(page.getByRole("button", { name: "Tạo bài tập" })).toBeVisible();

    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizA}?cau=${(await quizDetail(page, course, quizA)).questions[0]!.id}`);
    await expect(content(page)).toBeVisible({ timeout: 25_000 });
    await content(page).fill("Giáo viên viết: $\\dfrac{a}{b} = \\sqrt{2}$ và công thức riêng dòng $$\\sum_{k=1}^{20} k = 1+2+3+4+5+6+7+8+9+10+11+12+13+14+15+16+17+18+19+20$$");
    await expect(preview(page).locator(".katex").first()).toBeVisible({ timeout: 5000 });
    expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    // Mục chạm tối thiểu 44px ở 375px.
    for (const name of ["Lưu câu hỏi", "Phân số"]) {
      const box = (await page.getByRole("button", { name }).boundingBox())!;
      expect(box.height).toBeGreaterThanOrEqual(43.5);
    }
    await page.getByRole("button", { name: "Lưu câu hỏi" }).click();
    await expect(page.getByText(/Đã lưu lúc|Đã lưu thành bản mới/).first()).toBeVisible({ timeout: 15_000 });

    // Khóa của GV khác: API 403 (đọc + ghi), UI báo không có quyền.
    const list = await apiCall(page, "GET", `/admin/courses/${foreignCourse}/quizzes`);
    expect(list.status).toBe(403);
    const create = await apiCall(page, "POST", `/admin/courses/${foreignCourse}/quizzes`, { title: "E2E FA5 không được", chapter_id: 1, time_limit_minutes: null });
    expect(create.status).toBe(403);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${foreignCourse}/sua?tab=bai-tap`);
    await expect(page.getByTestId("course-forbidden")).toBeVisible({ timeout: 25_000 });
  });

  test("Giáo viên không được gán vào thẳng trang soạn quiz của khóa người khác", async ({ page }) => {
    test.setTimeout(120_000);
    await fillLogin(page, "fa5-gv2");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    // gv2 sở hữu khóa foreign; khóa chính (course) thì không.
    const other = (await apiCall(page, "GET", `/admin/courses/${course}/quizzes`)).status;
    expect(other).toBe(403);
    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    await expect(page.getByTestId("quiz-forbidden")).toBeVisible({ timeout: 25_000 });
  });

  test("Sắp xếp câu: nút Lên/Xuống, kéo chuột thật, 422 QUIZ_QUESTIONS_MISMATCH khi danh sách lệch", async ({ page }) => {
    test.setTimeout(180_000);
    await fillLogin(page, "fa5-gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    const mk = (n: number) => ({ content: `Câu sắp xếp ${n}`, explanation: null, options: [0, 1, 2, 3].map((i) => ({ content: `${i}`, is_correct: i === 0 })) });
    for (const n of [1, 2, 3]) expect((await apiCall(page, "POST", `/admin/courses/${course}/quizzes/${quizEmpty}/questions`, mk(n))).status).toBe(201);
    const order = async () => (await quizDetail(page, course, quizEmpty)).questions.map((q) => q.content.replace("Câu sắp xếp ", ""));
    expect(await order()).toEqual(["1", "2", "3"]);

    await page.goto(`${ADMIN}/quan-tri/khoa-hoc/${course}/bai-tap/${quizEmpty}`);
    const list = page.getByTestId("question-list");
    await expect(list.getByRole("listitem")).toHaveCount(3, { timeout: 25_000 });
    await expect(page.getByText(/Chưa đổi được thứ tự/)).toHaveCount(0);

    await test.step("nút Xuống / Lên", async () => {
      await page.getByRole("button", { name: "Chuyển câu 1 xuống" }).click();
      await toastText(page, "Đã lưu thứ tự câu");
      expect(await order()).toEqual(["2", "1", "3"]);
      await page.reload();
      await expect(list.getByRole("listitem").first()).toContainText("Câu sắp xếp 2", { timeout: 25_000 });
      await page.getByRole("button", { name: "Chuyển câu 3 lên" }).click();
      await toastText(page, "Đã lưu thứ tự câu");
      expect(await order()).toEqual(["2", "3", "1"]);
    });

    await test.step("kéo bằng chuột thật: câu 3 lên đầu", async () => {
      const grip = page.getByRole("button", { name: "Kéo để đổi thứ tự: câu 3" });
      const first = list.getByRole("listitem").first();
      const a = (await grip.boundingBox())!;
      const b = (await first.boundingBox())!;
      await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
      await page.mouse.down();
      await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2 - 10, { steps: 4 });
      await page.mouse.move(b.x + b.width / 2, b.y + 10, { steps: 20 });
      await page.mouse.up();
      await expect.poll(order, { timeout: 15_000 }).toEqual(["1", "2", "3"]);
    });

    await test.step("danh sách lệch (người khác xoá câu): báo và tải lại", async () => {
      const qs = (await quizDetail(page, course, quizEmpty)).questions;
      expect((await apiCall(page, "DELETE", `/admin/courses/${course}/quizzes/${quizEmpty}/questions/${qs[2]!.id}`)).status).toBe(204);
      await page.getByRole("button", { name: "Chuyển câu 2 lên" }).click();
      await expect(page.getByText(/Đã tải lại danh sách mới/)).toBeVisible({ timeout: 15_000 });
      await expect(list.getByRole("listitem")).toHaveCount(2, { timeout: 15_000 });
    });

    await test.step("PUT order chậm: 'Sửa' và 'Thêm câu hỏi' bị khoá cho tới khi xong", async () => {
      await page.reload();
      await expect(list.getByRole("listitem")).toHaveCount(2, { timeout: 25_000 });
      let release!: () => void;
      const gate = new Promise<void>((r) => (release = r));
      await page.route(/\/questions\/order$/, async (route) => {
        await gate;
        await route.continue();
      });
      await page.getByRole("button", { name: "Chuyển câu 1 xuống" }).click();
      await expect(page.getByRole("button", { name: "Thêm câu hỏi" })).toBeDisabled();
      await expect(page.getByRole("link", { name: /^Sửa/ })).toHaveCount(0);
      release();
      await expect(page.getByRole("link", { name: /^Sửa/ })).toHaveCount(2, { timeout: 15_000 });
      await expect(page.getByRole("link", { name: "Thêm câu hỏi" })).toBeVisible();
      await page.unroute(/\/questions\/order$/);
    });

    await test.step("375px: nút đổi thứ tự >= 44px, không tràn ngang", async () => {
      await page.setViewportSize({ width: 375, height: 800 });
      await page.reload();
      await expect(list.getByRole("listitem")).toHaveCount(2, { timeout: 25_000 });
      for (const name of ["Chuyển câu 1 xuống", "Kéo để đổi thứ tự: câu 1"]) {
        const box = (await page.getByRole("button", { name }).boundingBox())!;
        expect(box.height).toBeGreaterThanOrEqual(43.5);
        expect(box.width).toBeGreaterThanOrEqual(43.5);
      }
      expect(await noOverflow(page)).toBeLessThanOrEqual(0);
    });
  });
});
