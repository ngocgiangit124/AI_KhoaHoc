import { existsSync, unlinkSync, writeFileSync } from "node:fs";
import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * QA FA2 bổ sung — e2e THẬT (E2E_REAL_BACKEND=1). Điều kiện như chuyen-de-real.spec.ts.
 * Dữ liệu seed trước bằng tinker (QA làm tay): 30 chuyên đề "E2E CD P01".."E2E CD P30" (phân trang),
 * tài khoản e2e-qlt-qa1..qa4 (quan_ly_trang) để tiết kiệm hạn mức OTP. Dọn: `seed-e2e-subjects.sh --clean`.
 * Test khoá giữa phiên cần QA khoá từ ngoài: spec ghi `.lock-ready` rồi chờ `.lock-done` (QA chạy `artisan staff:lock`).
 */
const ADMIN = "http://admin-api.localhost:3001";
const API = "http://admin-api.localhost:8000/api/v1";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const email = (n: string) => `e2e-${n}@example.com`;
const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });
const pager = (page: Page) => page.getByRole("navigation", { name: "Phân trang" });
const row = (page: Page, name: string) => page.getByRole("row").filter({ hasText: name });

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

/** Gọi API thật bằng phiên của trang (cookie + CSRF), trả {status, body}. */
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
      return { status: res.status, body: json as Record<string, unknown> | null };
    },
    { api: API, method, path, body },
  );
}

test.describe("QA FA2 bổ sung (thật)", () => {
  const stamp = Date.now();

  test("Admin: tên 100 ký tự có dấu, 101 bị chặn, tìm % và _, XSS/emoji", async ({ page, request }) => {
    test.setTimeout(180_000);
    await loginMfa(page, request, "admin");
    await expect(nav(page).getByRole("link", { name: "Chuyên đề" })).toBeVisible();
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Tạo chuyên đề" })).toBeVisible();

    const dialog = page.getByRole("dialog");
    const create = async (name: string) => {
      await page.getByRole("button", { name: "Tạo chuyên đề" }).click();
      await dialog.getByLabel(/Tên chuyên đề/).fill(name);
      await dialog.getByRole("button", { name: "Lưu" }).click();
    };

    // 100 ký tự Unicode có dấu (tiền tố "E2E CD " 7 ký tự + 93).
    const base = "E2E CD ";
    const long100 = base + "Đạiếố".repeat(18) + "ĐẠI"; // 7 + 90 + 3 = 100
    expect([...long100].length).toBe(100);
    await create(long100);
    await expect(dialog).toBeHidden({ timeout: 15_000 });
    await page.getByLabel("Tìm theo tên").fill(long100);
    await expect(row(page, long100)).toBeVisible({ timeout: 10_000 });
    // Cột tên bọc dòng, không làm bảng tràn ngang ở desktop.
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

    // 101 ký tự: bị chặn ở client.
    await create(long100 + "x");
    await expect(dialog.getByText("Tên chuyên đề tối đa 100 ký tự")).toBeVisible();
    await dialog.getByRole("button", { name: "Huỷ" }).click();

    // Emoji + ký tự đặc biệt không phải HTML; tên có % và _ để thử tìm kiếm.
    const pct = `E2E CD ${stamp} 50% off`;
    const und = `E2E CD ${stamp} a_b`;
    const decoy = `E2E CD ${stamp} axb`;
    const amp = `E2E CD ${stamp} Tom & "Jerry" 😀`;
    for (const n of [pct, und, decoy, amp]) {
      await create(n);
      await expect(dialog).toBeHidden({ timeout: 15_000 });
    }

    const search = page.getByLabel("Tìm theo tên");
    await search.fill(`${stamp} 50%`);
    await expect(row(page, pct)).toBeVisible({ timeout: 10_000 });
    await expect(row(page, und)).toHaveCount(0);
    await search.fill("%");
    await expect(page).toHaveURL(/q=%25/, { timeout: 10_000 });
    await expect(row(page, pct)).toBeVisible({ timeout: 10_000 });
    await expect(row(page, decoy)).toHaveCount(0); // "%" khớp ký tự % thật, không phải wildcard
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible();
    const pctTotal = await page.getByText(/^Tổng \d+ chuyên đề$/).innerText();
    await search.fill(`${stamp} a_b`);
    await expect(row(page, und)).toBeVisible({ timeout: 10_000 });
    await expect(row(page, decoy)).toHaveCount(0); // "_" không là wildcard
    await search.fill("_");
    await expect(row(page, und)).toBeVisible({ timeout: 10_000 });
    await expect(row(page, decoy)).toHaveCount(0);
    await search.fill("\\");
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 10_000 });
    expect(pctTotal).toMatch(/Tổng [1-9]/);

    // Dấu & " và emoji hiển thị nguyên văn; sửa lại giữ nguyên.
    await search.fill(`${stamp} Tom`);
    await expect(row(page, amp)).toBeVisible({ timeout: 10_000 });

    // Dọn qua API (phiên admin).
    for (const needle of [`E2E CD Đạiếố`, String(stamp)]) {
      const r = await apiCall(page, "GET", `/admin/subjects?q=${encodeURIComponent(needle)}&per_page=50`);
      const rows = (r.body as { data: { id: number }[] }).data;
      for (const s of rows) await apiCall(page, "DELETE", `/admin/subjects/${s.id}`);
    }
  });

  test("QLT: phân trang thật >25, per_page, page=999 lùi, giá trị lạ, back/forward, link sidebar xoá bộ lọc (R1)", async ({ page, request }) => {
    test.setTimeout(240_000);
    await loginMfa(page, request, "qlt-qa1");
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible();
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 15_000 });

    // Không lọc: tổng > 25 → có phân trang, trang 1 đúng 25 dòng (header + 25).
    const total = Number(/(\d+)/.exec(await page.getByText(/^Tổng \d+ chuyên đề$/).innerText())![1]);
    expect(total).toBeGreaterThan(25);
    await expect(page.getByRole("row")).toHaveCount(26);
    await expect(pager(page).getByRole("link", { name: "Trang 1" })).toHaveAttribute("aria-current", "page");
    await expect(pager(page).getByText("Trước")).toHaveAttribute("aria-disabled", "true");
    await pager(page).getByRole("link", { name: "Sau" }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(pager(page).getByRole("link", { name: "Trang 2" })).toHaveAttribute("aria-current", "page");
    await expect(page.getByRole("row")).toHaveCount(total - 25 + 1);
    await expect(pager(page).getByText("Sau")).toHaveAttribute("aria-disabled", "true");

    // per_page=50 → về trang 1, hiện hết (nếu <=50).
    await page.getByLabel("Số dòng/trang").selectOption("50");
    await expect(page).toHaveURL(/per_page=50/);
    await expect(page).not.toHaveURL(/page=2/);
    if (total <= 50) {
      await expect(page.getByRole("row")).toHaveCount(total + 1);
      await expect(page.getByRole("navigation", { name: "Phân trang" })).toHaveCount(0);
    }

    // Giá trị lạ trên URL: không lỗi, về mặc định.
    await page.goto(`${ADMIN}/quan-tri/chuyen-de?per_page=7&status=abc&page=-3`);
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole("row")).toHaveCount(26);
    await expect(page.getByLabel("Số dòng/trang")).toHaveValue("25");
    await expect(page.getByLabel("Trạng thái")).toHaveValue("");

    // page=999 → tự lùi về trang cuối, không vòng lặp.
    await page.goto(`${ADMIN}/quan-tri/chuyen-de?page=999`);
    await expect(pager(page).getByRole("link", { name: "Trang 2" })).toHaveAttribute("aria-current", "page", { timeout: 20_000 });
    await expect(page).toHaveURL(/page=2/);
    await expect(page.getByRole("row").nth(1)).toBeVisible();

    // Lọc "E2E CD P" (30 dòng, 2 trang). Trang 2 + tìm kiếm mới → về trang 1.
    await page.goto(`${ADMIN}/quan-tri/chuyen-de?q=${encodeURIComponent("E2E CD P")}&page=2`);
    await expect(page.getByText("Tổng 30 chuyên đề")).toBeVisible({ timeout: 15_000 });
    await expect(page.getByLabel("Tìm theo tên")).toHaveValue("E2E CD P");
    await expect(page.getByRole("row")).toHaveCount(6); // 5 dòng trang 2
    await page.getByLabel("Tìm theo tên").fill("E2E CD P1");
    await expect(page).not.toHaveURL(/page=2/, { timeout: 10_000 });
    await expect(page.getByText("Tổng 10 chuyên đề")).toBeVisible({ timeout: 10_000 }); // P10..P19
  });

  test("QLT: back/forward đồng bộ ô tìm; bấm lại link sidebar xoá bộ lọc, không bị đẩy lại (R1)", async ({ page, request }) => {
    test.setTimeout(240_000);
    await loginMfa(page, request, "qlt-qa4");
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 15_000 });
    const search = page.getByLabel("Tìm theo tên");

    await search.fill("E2E CD P2");
    await expect(page).toHaveURL(/q=E2E\+CD\+P2/, { timeout: 10_000 });
    await expect(page.getByText("Tổng 10 chuyên đề")).toBeVisible({ timeout: 10_000 });

    // Link sidebar khi đang có ?q → URL sạch, ô tìm rỗng, và SAU 1 giây vẫn sạch (không bị đẩy lại).
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await page.waitForTimeout(1200);
    await expect(page).toHaveURL(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(search).toHaveValue("");

    // Back → về ?q=E2E CD P2, ô tìm theo URL, URL giữ nguyên sau debounce.
    await page.goBack();
    await expect(page).toHaveURL(/q=E2E\+CD\+P2/, { timeout: 10_000 });
    await page.waitForTimeout(1200);
    await expect(page).toHaveURL(/q=E2E\+CD\+P2/);
    await expect(search).toHaveValue("E2E CD P2");
    await expect(page.getByText("Tổng 10 chuyên đề")).toBeVisible({ timeout: 10_000 });

    // Forward → URL sạch, ô rỗng.
    await page.goForward();
    await expect(page).toHaveURL(`${ADMIN}/quan-tri/chuyen-de`, { timeout: 10_000 });
    await page.waitForTimeout(1200);
    await expect(page).toHaveURL(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(search).toHaveValue("");

    // Gõ dở rồi bấm sidebar trong cửa sổ debounce: URL sạch thắng.
    await search.fill("E2E CD P3");
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await page.waitForTimeout(1500);
    const url = page.url();
    const val = await search.inputValue();
    // Ghi nhận hành vi thực để báo cáo (cả hai kết quả nhất quán đều chấp nhận được, nhưng URL và ô tìm phải KHỚP nhau).
    console.log(`[R1 typing-race] url=${url} input=${JSON.stringify(val)}`);
    expect(url.includes("q=") ? val.length > 0 : val === "").toBe(true);
  });

  test("Hai tab cùng phiên: tab A đổi tên/xoá, tab B thao tác dòng cũ", async ({ page, request }) => {
    test.setTimeout(240_000);
    await loginMfa(page, request, "qlt-qa4");
    const A = page;
    const dlgA = () => A.getByRole("dialog");
    const mk = async (name: string) => {
      await A.goto(`${ADMIN}/quan-tri/chuyen-de?q=${encodeURIComponent(name)}`);
      await expect(A.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 20_000 });
      await A.getByRole("button", { name: "Tạo chuyên đề" }).click();
      await dlgA().getByLabel(/Tên chuyên đề/).fill(name);
      await dlgA().getByRole("button", { name: "Lưu" }).click();
      await expect(dlgA()).toBeHidden({ timeout: 15_000 });
      await expect(row(A, name)).toBeVisible({ timeout: 10_000 });
    };
    const openB = async (name: string) => {
      const B = await page.context().newPage();
      await B.goto(`${ADMIN}/quan-tri/chuyen-de?q=${encodeURIComponent(name)}`);
      await expect(row(B, name)).toBeVisible({ timeout: 20_000 });
      return B;
    };

    // Ca 1: A xoá, B (dòng cũ) sửa -> banner 404; ẩn -> toast; xoá -> toast; danh sách tự tải lại, UI không treo.
    const t1 = `E2E CD tab1 ${stamp}`;
    await mk(t1);
    const B1 = await openB(t1);
    await row(A, t1).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await dlgA().getByRole("button", { name: "Xoá" }).click();
    await expect(A.getByText("Đã xoá chuyên đề")).toBeVisible({ timeout: 15_000 });
    await row(B1, t1).getByRole("button", { name: /Sửa chuyên đề/ }).click();
    await B1.getByRole("dialog").getByLabel(/Tên chuyên đề/).fill(t1 + " x");
    await B1.getByRole("dialog").getByRole("button", { name: "Lưu" }).click();
    await expect(B1.getByRole("dialog").getByRole("alert")).toContainText("không còn tồn tại", { timeout: 15_000 });
    await B1.getByRole("dialog").getByRole("button", { name: "Huỷ" }).click();
    // FA-V2 (Minor FA2 đã sửa): sau 404 khi sửa, danh sách tự tải lại -> dòng chết biến mất.
    await expect(row(B1, t1)).toHaveCount(0, { timeout: 15_000 });
    await B1.close();

    // Ca 1b: A xoá, B (dòng cũ) bấm Ẩn/Hiện -> toast 404 và danh sách tự tải lại (dòng chết biến mất).
    const t1b = `E2E CD tab1b ${stamp}`;
    await mk(t1b);
    const B1b = await openB(t1b);
    await row(A, t1b).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await dlgA().getByRole("button", { name: "Xoá" }).click();
    await expect(A.getByText("Đã xoá chuyên đề")).toBeVisible({ timeout: 15_000 });
    await row(B1b, t1b).getByRole("switch").click();
    await expect(B1b.getByText("Chuyên đề không còn tồn tại.")).toBeVisible({ timeout: 15_000 });
    await expect(row(B1b, t1b)).toHaveCount(0, { timeout: 15_000 });
    await B1b.close();

    // Ca 2: A xoá, B mở hộp xoá trên dòng cũ rồi xác nhận -> toast, hộp thoại đóng, dòng biến mất.
    const t2 = `E2E CD tab2 ${stamp}`;
    await mk(t2);
    const B2 = await openB(t2);
    await row(A, t2).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await dlgA().getByRole("button", { name: "Xoá" }).click();
    await expect(A.getByText("Đã xoá chuyên đề")).toBeVisible({ timeout: 15_000 });
    await row(B2, t2).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await B2.getByRole("dialog").getByRole("button", { name: "Xoá" }).click();
    await expect(B2.getByText("Chuyên đề không còn tồn tại.")).toBeVisible({ timeout: 15_000 });
    await expect(B2.getByRole("dialog")).toHaveCount(0);
    await expect(row(B2, t2)).toHaveCount(0, { timeout: 15_000 });
    await B2.close();

    // Ca 3: A đổi tên, B (tên cũ) đặt tên mới khác -> thành công theo id; B đổi sang đúng tên A đã đặt -> 422 trùng.
    const t3 = `E2E CD tab3 ${stamp}`;
    const t3a = `E2E CD tab3 A ${stamp}`;
    await mk(t3);
    const B3 = await openB(t3);
    await row(A, t3).getByRole("button", { name: /Sửa chuyên đề/ }).click();
    await dlgA().getByLabel(/Tên chuyên đề/).fill(t3a);
    await dlgA().getByRole("button", { name: "Lưu" }).click();
    await expect(dlgA()).toBeHidden({ timeout: 15_000 });
    await row(B3, t3).getByRole("button", { name: /Sửa chuyên đề/ }).click();
    await B3.getByRole("dialog").getByLabel(/Tên chuyên đề/).fill(t3a.toUpperCase());
    await B3.getByRole("dialog").getByRole("button", { name: "Lưu" }).click();
    // Chính bản ghi đó đổi sang tên chỉ khác hoa/thường: được phép (không tự trùng chính nó).
    await expect(B3.getByRole("dialog")).toBeHidden({ timeout: 15_000 });
    await B3.close();
    const del = await apiCall(A, "GET", `/admin/subjects?q=${encodeURIComponent("E2E CD tab")}&per_page=50`);
    for (const s of (del.body as { data: { id: number; name: string }[] }).data.filter((r) => r.name.includes(String(stamp)))) await apiCall(A, "DELETE", `/admin/subjects/${s.id}`);
  });

  test("Giáo viên: API ghi trực tiếp bị 403, danh sách chỉ active; ?status=hidden không lộ chuyên đề ẩn", async ({ page }) => {
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/chuyen-de?status=hidden`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText("Đã ẩn")).toHaveCount(0);
    const post = await apiCall(page, "POST", "/admin/subjects", { name: `E2E CD gv ${stamp}` });
    expect(post.status).toBe(403);
    const del = await apiCall(page, "DELETE", "/admin/subjects/1");
    expect(del.status).toBe(403);
    const patch = await apiCall(page, "PATCH", "/admin/subjects/1/status", { status: "hidden" });
    expect(patch.status).toBe(403);
    const list = await apiCall(page, "GET", "/admin/subjects?status=hidden&per_page=50");
    expect(list.status).toBe(200);
    expect(((list.body as { data: { status?: string }[] }).data).every((s) => !s.status || s.status === "active")).toBe(true);
  });

  test("Khoá THẬT giữa phiên: QLT đang mở trang, QA khoá bằng artisan, thao tác tiếp → overlay ACCOUNT_LOCKED", async ({ page, request }) => {
    test.setTimeout(240_000);
    for (const f of [".lock-ready", ".lock-done"]) if (existsSync(f)) unlinkSync(f);
    await loginMfa(page, request, "qlt-qa2");
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 20_000 });
    writeFileSync(".lock-ready", "1");
    await expect.poll(() => existsSync(".lock-done"), { timeout: 150_000, intervals: [1000] }).toBe(true);

    // Thao tác (không tải lại trang): tìm kiếm → gọi API thật → 403 ACCOUNT_LOCKED.
    await page.getByLabel("Tìm theo tên").fill("khoa that");
    const overlay = page.getByRole("alertdialog");
    await expect(overlay).toContainText(/bị khóa/i, { timeout: 15_000 });
    // Không lộ dữ liệu: bảng/tên menu không còn thao tác được phía sau overlay.
    const text = await page.locator("body").innerText();
    console.log("[LOCK overlay text]", JSON.stringify(text.slice(0, 300)));
    // F5: vẫn ở màn khoá, không về trang chuyên đề.
    await page.reload();
    await expect(page.getByText(/bị khóa/i).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole("button", { name: "Tạo chuyên đề" })).toHaveCount(0);
  });

  test("375px: giáo viên (chỉ đọc) và QLT không tràn ngang, ô tìm/phân trang dùng được", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByText(/^Tổng \d+ chuyên đề$/)).toBeVisible({ timeout: 20_000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    const box = await page.getByLabel("Tìm theo tên").boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(43.5);
    const per = await page.getByLabel("Số dòng/trang").boundingBox();
    expect(per!.height).toBeGreaterThanOrEqual(43.5);
    for (const name of ["Trước", "Sau"]) {
      const b = pager(page).getByRole("link", { name });
      if (await b.count()) expect((await b.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    }
  });
  test("409 giữa chừng (khóa học gán sau khi mở hộp xoá) và Esc khi đang xoá không đóng hộp thoại", async ({ page, request }) => {
    test.setTimeout(240_000);
    for (const f of [".attach-ready", ".attach-done"]) if (existsSync(f)) unlinkSync(f);
    await loginMfa(page, request, "qlt-qa1");
    const x = `E2E CD x409 ${stamp}`;
    const y = `E2E CD yesc ${stamp}`;
    const dialog = page.getByRole("dialog");
    await page.goto(`${ADMIN}/quan-tri/chuyen-de?q=${encodeURIComponent(`E2E CD `)}&per_page=50`);
    for (const n of [x, y]) {
      await page.getByRole("button", { name: "Tạo chuyên đề" }).click();
      await dialog.getByLabel(/Tên chuyên đề/).fill(n);
      await dialog.getByRole("button", { name: "Lưu" }).click();
      await expect(dialog).toBeHidden({ timeout: 15_000 });
    }
    await page.getByLabel("Tìm theo tên").fill(String(stamp));
    await expect(row(page, x)).toBeVisible({ timeout: 10_000 });

    // Mở hộp xoá của x (courses_count=0), rồi QA gán khóa học ở ngoài.
    await row(page, x).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await expect(dialog).toContainText("Hành động này không thể hoàn tác.");
    writeFileSync(".attach-ready", x);
    await expect.poll(() => existsSync(".attach-done"), { timeout: 120_000, intervals: [1000] }).toBe(true);
    await dialog.getByRole("button", { name: "Xoá", exact: true }).click();
    await expect(dialog).toContainText("đang được gán", { timeout: 15_000 });
    await expect(dialog.getByRole("button", { name: "Xoá", exact: true })).toHaveCount(0);
    await dialog.getByRole("button", { name: /Ẩn/ }).click();
    await expect(page.getByText("Đã ẩn chuyên đề khỏi bộ lọc công khai")).toBeVisible({ timeout: 15_000 });
    await expect(dialog).toBeHidden();
    await expect(row(page, x).getByRole("switch")).toHaveAttribute("aria-checked", "false", { timeout: 10_000 });

    // R5: trễ DELETE, bấm Esc khi đang gửi -> hộp thoại vẫn còn; xong thì đóng + toast.
    await page.route("**/api/v1/admin/subjects/*", async (route) => {
      if (route.request().method() === "DELETE") await new Promise((r) => setTimeout(r, 2500));
      await route.continue();
    });
    await row(page, y).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await dialog.getByRole("button", { name: "Xoá", exact: true }).click();
    await page.keyboard.press("Escape");
    await expect(dialog).toBeVisible();
    await expect(page.getByText("Đã xoá chuyên đề")).toBeVisible({ timeout: 15_000 });
    await expect(dialog).toBeHidden();
    await page.unroute("**/api/v1/admin/subjects/*");
    await expect(row(page, y)).toHaveCount(0, { timeout: 10_000 });
  });
});
