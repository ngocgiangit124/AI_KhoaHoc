import { expect, test, type Page } from "@playwright/test";

/**
 * FW5 — làm quiz `/hoc/{course}/quiz/{quiz}` và kết quả `/ket-qua` với backend thật.
 * Dữ liệu: `e2e/seed-e2e-quiz.sh` (in `course=.. l1=.. q1=.. q2=.. q3=.. q4=..`; truyền qua E2E_FW5). Chạy `--workers=1`, tuần tự.
 * Đáp án đúng của q1: câu1=B câu2=A câu3=C câu4=D; q2: A, B.
 */
const PASSWORD = "matkhau-123";
const ids = Object.fromEntries((process.env.E2E_FW5 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần backend thật (E2E_REAL_BACKEND=1) và E2E_FW5 từ seed-e2e-quiz.sh");
test.describe.configure({ mode: "serial" });

const quizUrl = (key: string) => `/hoc/${ids.course}/quiz/${ids[key]}`;

async function login(page: Page, email: string) {
  if (process.env.E2E_DEBUG) {
    page.on("console", (m) => console.log("[console]", m.type(), m.text().slice(0, 300)));
    page.on("pageerror", (e) => console.log("[pageerror]", e.message.slice(0, 300)));
    page.on("requestfailed", (r) => console.log("[reqfailed]", r.method(), r.url().replace(/^.*\/api\/v1/, ""), r.failure()?.errorText));
    page.on("response", (r) => /\/api\/v1\//.test(r.url()) && console.log("[api]", r.status(), r.request().method(), r.url().replace(/^.*\/api\/v1/, "")));
  }
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}

/** Chọn đáp án thứ `n` (0=A) của câu `q` bằng cách bấm cả hàng. */
const pick = (page: Page, q: number, n: number) => page.locator(`#cau-${q} label`).nth(n).click();
const start = async (page: Page, key: string) => {
  await page.goto(quizUrl(key));
  await page.getByRole("button", { name: /Bắt đầu làm bài|Làm tiếp|Làm lại/ }).click();
  await expect(page.locator("#cau-1")).toBeVisible({ timeout: 90_000 });
};

let ownAttemptId = "";

test.describe("Làm quiz", () => {
  test("từ bài học → màn giới thiệu → bắt đầu: công thức KaTeX hiển thị, font tự host không vi phạm CSP, HTML trong nội dung chỉ là chữ", async ({ page }) => {
    const violations: string[] = [];
    page.on("console", (m) => /Content Security Policy|violat/i.test(m.text()) && violations.push(m.text()));
    await login(page, "fw5-hs-own@example.com");
    await page.goto(`/hoc/${ids.course}/bai/${ids.l1}`);
    await expect(page.getByRole("heading", { level: 1, name: "Bài 1 có bài tập" })).toBeVisible({ timeout: 120_000 });
    await expect(page.getByRole("link", { name: /Bài kiểm tra: Công thức Toán/ })).toBeVisible(); // mục lục nối link
    await page.getByRole("link", { name: "Làm bài" }).first().click();

    await expect(page.getByRole("heading", { level: 1, name: "Công thức Toán" })).toBeVisible({ timeout: 120_000 });
    await expect(page.getByText("4 câu, mỗi câu chọn 1 trong 4 đáp án.")).toBeVisible();
    await expect(page.getByText("Không giới hạn thời gian.")).toBeVisible();
    await page.getByRole("button", { name: "Bắt đầu làm bài" }).click();

    await expect(page.locator("#cau-1")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("Đã trả lời 0/4", { exact: true })).toBeVisible();
    await expect(page.locator(".katex").first()).toBeVisible();
    await expect(page.locator("#cau-1")).not.toContainText("$"); // không lộ ký hiệu $
    await expect(page.locator("#cau-4")).toContainText("<b>không</b>"); // thẻ HTML hiện như chữ, không bị diễn giải
    await expect(page.locator("#cau-4 b")).toHaveCount(0);
    await expect(page.locator("a[href], img", { has: page.locator(".katex") })).toHaveCount(0);
    // Font KaTeX tải từ chính origin (CSP font-src 'self').
    const fontOk = await page.evaluate(async () => {
      await document.fonts.ready;
      return [...document.fonts].some((f) => f.family.startsWith("KaTeX") && f.status === "loaded");
    });
    expect(fontOk).toBe(true);
    expect(violations).toEqual([]);
    await expect(page.getByRole("contentinfo")).toHaveCount(0); // màn học yên tĩnh: không footer
    await expect(page.locator("main.bg-oly-page, body.bg-oly-page")).toHaveCount(0);
  });

  test("autosave: chọn → PUT đúng câu/đáp án một lần → 'Đã lưu'; F5 rồi 'Làm tiếp' vẫn còn đáp án", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await page.goto(quizUrl("q1"));
    const startReq = page.waitForResponse((r) => /\/learn\/quizzes\/\d+\/attempts$/.test(r.url()) && r.request().method() === "POST");
    await page.getByRole("button", { name: /Bắt đầu làm bài|Làm tiếp|Làm lại/ }).click();
    const startBody = await (await startReq).json();
    ownAttemptId = String(startBody.id);
    expect(JSON.stringify(startBody)).not.toMatch(/is_correct|correct_option_id/); // contract: không lộ đáp án đúng khi đang làm
    await expect(page.locator("#cau-1")).toBeVisible();

    const puts: string[] = [];
    page.on("request", (r) => r.method() === "PUT" && /\/answers\//.test(r.url()) && puts.push(r.postData() ?? ""));
    // Đổi ý nhanh (cùng một tick, không phụ thuộc tốc độ máy): chỉ gửi giá trị cuối.
    await page.evaluate(() => {
      const labels = document.querySelectorAll<HTMLLabelElement>("#cau-1 label");
      labels[0]!.click();
      labels[1]!.click();
    });
    await expect(page.getByText("Đã trả lời 1/4", { exact: true })).toBeVisible();
    await expect(page.locator("#cau-1").getByText("Đã lưu")).toBeVisible({ timeout: 10_000 });
    expect(puts).toHaveLength(1);
    expect(JSON.parse(puts[0]!)).toHaveProperty("option_id");

    await page.reload();
    await page.getByRole("button", { name: "Làm tiếp" }).click();
    await expect(page.getByText("Đã trả lời 1/4", { exact: true })).toBeVisible({ timeout: 90_000 });
    await expect(page.locator("#cau-1 input").nth(1)).toBeChecked();
  });

  test("bàn phím: Tab vào nhóm đáp án, mũi tên đổi lựa chọn và tự lưu; vùng chạm đáp án ≥ 44px; bảng câu có nhãn", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q1");
    const radios = page.locator("#cau-2 input[type=radio]");
    await radios.first().focus();
    await page.keyboard.press("Space");
    await expect(radios.first()).toBeChecked();
    await page.keyboard.press("ArrowDown");
    await expect(radios.nth(1)).toBeChecked();
    await expect(page.locator("#cau-2").getByText("Đã lưu")).toBeVisible({ timeout: 10_000 });
    const box = await page.locator("#cau-2 label").first().boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(44);
    await expect(page.getByRole("link", { name: "Câu 1, đã trả lời" })).toBeVisible();
    // đồng hồ: quiz không giới hạn giờ → không có đồng hồ
    await expect(page.getByText(/Thời gian còn lại/)).toHaveCount(0);
  });

  test("mất mạng: banner cảnh báo, đáp án vẫn giữ; có mạng lại thì tự gửi và hết cảnh báo", async ({ page, context }) => {
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q1");
    await context.setOffline(true);
    await pick(page, 3, 2);
    await expect(page.getByText("Mất kết nối mạng")).toBeVisible({ timeout: 15_000 });
    await expect(page.locator("#cau-3 input").nth(2)).toBeChecked();
    const put = page.waitForResponse((r) => r.request().method() === "PUT" && /\/answers\//.test(r.url()) && r.status() === 204, { timeout: 90_000 });
    await context.setOffline(false);
    await put;
    await expect(page.getByText("Mất kết nối mạng")).toHaveCount(0, { timeout: 10_000 });
  });

  test("rời trang bằng nút Thoát: đáp án vừa chọn (chưa kịp debounce) vẫn được gửi", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q1");
    const put = page.waitForResponse((r) => r.request().method() === "PUT" && /\/answers\//.test(r.url()));
    await pick(page, 4, 3);
    await page.getByRole("link", { name: /Thoát, quay lại bài học/ }).click(); // điều hướng mềm, thường trước khi hết debounce
    const res = await put; // phải chờ phản hồi: đóng context sớm sẽ huỷ request đang bay
    expect(res.status()).toBe(204);
    expect(JSON.parse(res.request().postData() ?? "{}")).toHaveProperty("option_id");
    await expect(page).toHaveURL(/\/hoc\/\d+\/bai\/\d+$/);
  });

  test("nộp đủ 4 câu → kết quả 10 điểm, đáp án đúng + lời giải KaTeX, lọc theo URL giữ sau F5", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q1");
    await pick(page, 1, 1);
    await pick(page, 2, 0);
    await pick(page, 3, 2);
    // Câu 4 (D) đã được lưu ở test "rời trang" → đủ 4 câu, nộp thẳng không hỏi xác nhận.
    await expect(page.getByText("Đã trả lời 4/4", { exact: true })).toBeVisible();
    const submitReq = page.waitForResponse((r) => /\/quiz-attempts\/\d+\/submit/.test(r.url()));
    await page.getByRole("button", { name: "Nộp bài" }).first().click();
    const body = await (await submitReq).json();
    expect(body.status).toBe("submitted");
    expect(typeof body.score === "number" || typeof body.score === "string").toBe(true);

    await expect(page).toHaveURL(new RegExp(`/ket-qua\\?lan=${ownAttemptId}$`), { timeout: 90_000 });
    await expect(page.getByText("trên 10 điểm")).toBeVisible();
    await expect(page.getByText("Đúng 4/4 câu · Sai 0 · Bỏ trống 0")).toBeVisible();
    await expect(page.getByText("Làm tốt lắm!")).toBeVisible();
    await expect(page.getByText("Đáp án đúng").first()).toBeVisible();
    await expect(page.locator("#cau-1 .katex").first()).toBeVisible();
    await expect(page.getByText("Lời giải").first()).toBeVisible();
    await expect(page.getByRole("link", { name: "Làm lại" })).toHaveAttribute("href", new RegExp(`/hoc/${ids.course}/quiz/${ids.q1}$`));
    await expect(page.getByRole("link", { name: "Học bài tiếp theo" })).toHaveAttribute("href", /\/hoc\/\d+\/bai\/\d+$/);

    // Lọc theo URL: câu sai = 0 → thông báo rỗng, F5 giữ trạng thái.
    await page.getByRole("link", { name: /Câu sai/ }).click();
    await expect(page).toHaveURL(/loc=sai/);
    await expect(page.getByText("Không có câu nào trong mục này.")).toBeVisible();
    await page.reload();
    await expect(page.getByText("Không có câu nào trong mục này.")).toBeVisible();
  });

  test("làm lại: giới thiệu hiện số lần đã làm + điểm cao nhất; lượt mới nộp 1 câu đúng, 3 bỏ trống → xác nhận → 2,5 điểm", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await page.goto(quizUrl("q1"));
    await expect(page.getByText(/Bạn đã làm 1 lần, điểm cao nhất 10\/10/)).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole("link", { name: "Xem kết quả lần trước" })).toBeVisible();
    await page.getByRole("button", { name: "Làm lại" }).click();
    await expect(page.locator("#cau-1")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("Đã trả lời 0/4", { exact: true })).toBeVisible(); // lượt mới, không mang đáp án cũ
    await pick(page, 2, 0); // đúng
    await page.getByRole("button", { name: "Nộp bài" }).first().click();
    await expect(page.getByText("Bạn còn 3 câu chưa trả lời")).toBeVisible();
    await page.getByRole("button", { name: "Làm tiếp" }).click(); // đóng hộp thoại, vẫn ở trang làm bài
    await expect(page.locator("#cau-1")).toBeVisible();
    await page.getByRole("button", { name: "Nộp bài" }).first().click();
    await page.getByRole("button", { name: "Vẫn nộp bài" }).click();
    await expect(page).toHaveURL(/\/ket-qua\?lan=\d+$/, { timeout: 90_000 });
    await expect(page.getByText("Đúng 1/4 câu · Sai 0 · Bỏ trống 3")).toBeVisible();
    await expect(page.getByText("Cùng xem lại bài nhé")).toBeVisible();
    await page.getByRole("link", { name: /Bỏ trống/ }).click();
    await expect(page.locator("article")).toHaveCount(3);
    await expect(page.getByText("Chưa trả lời").first()).toBeVisible();
  });

  test("kết quả không có ?lan= dùng lượt đã nộp gần nhất; lượt của người khác → không tìm thấy", async ({ page, browser }) => {
    await login(page, "fw5-hs-own@example.com");
    await page.goto(`${quizUrl("q1")}/ket-qua`);
    await expect(page.getByText("trên 10 điểm")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("Đúng 1/4 câu · Sai 0 · Bỏ trống 3")).toBeVisible();

    const other = await browser.newContext({ baseURL: "http://api.localhost:3000" });
    const op = await other.newPage();
    await login(op, "fw5-hs-other@example.com");
    await op.goto(`${quizUrl("q1")}/ket-qua?lan=${ownAttemptId}`);
    await expect(op.getByRole("heading", { level: 1, name: "Không tìm thấy bài kiểm tra" })).toBeVisible({ timeout: 90_000 });
    await expect(op.getByText("Đáp án đúng")).toHaveCount(0);
    await other.close();
  });

  test("375px: không cuộn ngang ở màn làm bài (công thức dài cuộn trong khung) và kết quả; nút Nộp bài dính đáy ≥ 44px", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 740 });
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q1");
    const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(await noOverflow()).toBe(true);
    const submit = page.getByRole("button", { name: "Nộp bài" }).last();
    await expect(submit).toBeVisible();
    expect((await submit.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await page.getByRole("button", { name: /^\d+\/4$/ }).click(); // bảng câu hỏi dạng sheet
    await expect(page.getByRole("dialog", { name: "Bảng câu hỏi" })).toBeVisible();
    await page.keyboard.press("Escape");
    await pick(page, 1, 1);
    await page.getByRole("button", { name: "Nộp bài" }).last().click();
    await page.getByRole("button", { name: "Vẫn nộp bài" }).click();
    await expect(page.getByText("trên 10 điểm")).toBeVisible({ timeout: 90_000 });
    expect(await noOverflow()).toBe(true);
  });

  test("quiz chưa có câu → 'Bài kiểm tra chưa sẵn sàng'; chưa ghi danh → chuyển về trang khóa học", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    await page.goto(quizUrl("q3"));
    await page.getByRole("button", { name: "Bắt đầu làm bài" }).click();
    await expect(page.getByRole("heading", { level: 1, name: "Bài kiểm tra chưa sẵn sàng" })).toBeVisible({ timeout: 90_000 });
    await page.context().clearCookies();
    await login(page, "fw5-hs-none@example.com");
    await page.goto(quizUrl("q1"));
    await expect(page).toHaveURL(/\/khoa-hoc\/e2e-fw5-quiz$/, { timeout: 120_000 });
  });

  test("đóng/tải lại cứng ngay sau khi chọn (chưa kịp debounce): đáp án vẫn tới server nhờ keepalive", async ({ page }) => {
    await login(page, "fw5-hs-own@example.com");
    page.on("dialog", (d) => void d.accept()); // hộp "rời trang?" vì còn đáp án chưa lưu (beforeunload)
    await start(page, "q4");
    await pick(page, 1, 2);
    await page.goto("/khoa-hoc"); // điều hướng cứng: pagehide
    await page.goto(quizUrl("q4"));
    await page.getByRole("button", { name: "Làm tiếp" }).click();
    await expect(page.getByText("Đã trả lời 1/2", { exact: true })).toBeVisible();
    await expect(page.locator("#cau-1 input").nth(2)).toBeChecked();
  });

  test("id sai định dạng → 404 thật; quiz của chương làm được và kết quả trỏ về khóa", async ({ page }) => {
    const res = await page.goto(`/hoc/${ids.course}/quiz/abc`);
    expect(res?.status()).toBe(404);
    await login(page, "fw5-hs-own@example.com");
    await start(page, "q4");
    await pick(page, 1, 0);
    await pick(page, 2, 1);
    await page.getByRole("button", { name: "Nộp bài" }).first().click();
    await expect(page.getByText("trên 10 điểm")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("Đúng 2/2 câu · Sai 0 · Bỏ trống 0")).toBeVisible();
  });

  test("có giới hạn giờ: đồng hồ theo server, aria không đọc từng giây; hết giờ → hộp thoại → tự nộp → kết quả", async ({ page }) => {
    test.setTimeout(900_000);
    await login(page, "fw5-hs-own@example.com");
    const startResp = page.waitForResponse((r) => /\/learn\/quizzes\/\d+\/attempts$/.test(r.url()) && r.request().method() === "POST");
    await page.goto(quizUrl("q2"));
    await expect(page.getByText(/1 phút/)).toBeVisible({ timeout: 90_000 });
    await page.getByRole("button", { name: "Bắt đầu làm bài" }).click();
    const attempt = await (await startResp).json();
    expect(attempt.remaining_seconds).toBeGreaterThan(40);
    expect(attempt.remaining_seconds).toBeLessThanOrEqual(60);
    await expect(page.locator("#cau-1")).toBeVisible();
    const clock = page.getByText(/Thời gian còn lại/);
    await expect(clock).toBeVisible();
    // Chỉ một vùng aria-live cho đồng hồ và nó rỗng (chỉ báo ở mốc 5 phút/1 phút), không phải cả đồng hồ.
    await expect(page.locator('header [aria-live="assertive"]')).toHaveText("");
    await pick(page, 1, 0); // đúng, câu 2 bỏ trống

    // Hết giờ → (hộp thoại "Đã hết giờ làm bài" chỉ hiện thoáng qua, đã có unit test) → tự nộp → kết quả, không cần bấm gì.
    await expect(page).toHaveURL(/\/ket-qua\?lan=\d+$/, { timeout: 150_000 });
    await expect(page.getByText("Đúng 1/2 câu · Sai 0 · Bỏ trống 1")).toBeVisible();
  });
});
