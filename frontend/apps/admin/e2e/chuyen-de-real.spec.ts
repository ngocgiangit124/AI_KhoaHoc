import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * FA2 — e2e THẬT màn Chuyên đề (E2E_REAL_BACKEND=1). Cùng điều kiện với admin-real.spec.ts: web admin ở
 * admin-api.localhost:3001, API admin-api.localhost:8000, MFA đọc từ Mailpit, queue worker chạy.
 * Seed trước (idempotent): `frontend/apps/admin/e2e/seed-e2e-subjects.sh` tạo chuyên đề "E2E Đang gán" + 1 khóa gán
 * (để thử 409 SUBJECT_IN_USE); tài khoản e2e-qlt/e2e-gv @example.com (mật khẩu `Password123!`) seed từ QA T28.
 * Chuyên đề tạo trong lúc chạy có tiền tố "E2E CD " và được dọn trong afterEach (kể cả khi test fail giữa chừng);
 * dự phòng: `seed-e2e-subjects.sh --clean`.
 */
const ADMIN = "http://admin-api.localhost:3001";
const MAILPIT = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "Password123!";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");
test.describe.configure({ mode: "serial" });

const email = (n: string) => `e2e-${n}@example.com`;
const nav = (page: Page) => page.getByRole("navigation", { name: "Menu quản trị" });

async function fillLogin(page: Page, who: string) {
  await page.goto(`${ADMIN}/dang-nhap`);
  await page.locator('input[name="login"]').fill(email(who));
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

async function latestCode(request: APIRequestContext, to: string, knownIds: Set<string>): Promise<string> {
  let code: string | null = null;
  await expect
    .poll(
      async () => {
        const list = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${to}` } });
        const messages = ((await list.json()) as { messages: { ID: string; Subject: string }[] }).messages;
        const fresh = messages.find((m) => !knownIds.has(m.ID) && /xác thực|mã/i.test(m.Subject));
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

async function loginStaffWithMfa(page: Page, request: APIRequestContext, who: string) {
  // Lọc theo ID mail đã có (không phụ thuộc đồng hồ) — xem N3 của QA T28.
  const before = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:${email(who)}` } });
  const known = new Set(((await before.json()) as { messages: { ID: string }[] }).messages.map((m) => m.ID));
  await fillLogin(page, who);
  await expect(page).toHaveURL(/\/xac-thuc-mfa/, { timeout: 20_000 });
  const code = await latestCode(request, email(who), known);
  await page.locator("input").first().click();
  await page.keyboard.type(code);
  await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
}

/** Xoá mọi chuyên đề tiền tố "E2E CD " bằng chính phiên đang đăng nhập của trang (không làm gì nếu chưa đăng nhập). */
async function cleanupStampedSubjects(page: Page) {
  if (!page.url().startsWith(ADMIN)) return;
  await page
    .evaluate(async () => {
      const api = "http://admin-api.localhost:8000/api/v1";
      const base = { credentials: "include" as const, headers: { Accept: "application/json", "X-Device-Id": localStorage.getItem("vv_device_id") ?? "" } };
      const list = await fetch(`${api}/admin/subjects?q=${encodeURIComponent("E2E CD ")}&per_page=50`, base);
      if (!list.ok) return;
      const rows = ((await list.json()) as { data: { id: number; name: string }[] }).data.filter((r) => r.name.startsWith("E2E CD "));
      if (rows.length === 0) return;
      const { token } = (await (await fetch(`${api}/csrf-token`, base)).json()) as { token: string };
      for (const r of rows) {
        await fetch(`${api}/admin/subjects/${r.id}`, { ...base, method: "DELETE", headers: { ...base.headers, "X-CSRF-TOKEN": token } });
      }
    })
    .catch(() => undefined);
}

const row = (page: Page, name: string) => page.getByRole("row").filter({ hasText: name });

test.describe("FA2 chuyên đề (thật)", () => {
  const stamp = Date.now();
  const NAME = `E2E CD ${stamp}`;
  const RENAMED = `E2E CD đổi ${stamp}`;

  test.afterEach(async ({ page }) => {
    await cleanupStampedSubjects(page);
  });

  test("Quản lý trang: tạo (trùng/rỗng/HTML bị chặn), sửa, ẩn/hiện, tìm/lọc trên URL, xoá, chặn xoá khi đang gán", async ({ page, request }) => {
    test.setTimeout(180_000);
    await loginStaffWithMfa(page, request, "qlt");
    await nav(page).getByRole("link", { name: "Chuyên đề" }).click();
    await expect(page).toHaveURL(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Chuyên đề" })).toHaveAttribute("aria-current", "page");

    // Tên rỗng: lỗi client dưới field, không gọi API.
    await page.getByRole("button", { name: "+ Tạo chuyên đề" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Lưu" }).click();
    await expect(dialog.getByText("Vui lòng nhập tên chuyên đề")).toBeVisible();

    // Có thẻ HTML: bị chặn.
    await dialog.getByLabel(/Tên chuyên đề/).fill("<b>x</b>");
    await dialog.getByRole("button", { name: "Lưu" }).click();
    await expect(dialog.getByText(/không được chứa ký tự/)).toBeVisible();

    // Tạo hợp lệ.
    await dialog.getByLabel(/Tên chuyên đề/).fill(`  ${NAME}  `);
    await dialog.getByRole("button", { name: "Lưu" }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
    await expect(page.getByText("Đã lưu chuyên đề")).toBeVisible();

    // Tìm theo tên → URL có q, F5 giữ nguyên.
    await page.getByLabel("Tìm theo tên").fill(NAME);
    await expect(page).toHaveURL(new RegExp(`q=${encodeURIComponent(NAME).replace(/%20/g, "(\\+|%20)")}`), { timeout: 10_000 });
    await expect(row(page, NAME)).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Tìm theo tên")).toHaveValue(NAME);
    await expect(row(page, NAME)).toBeVisible();

    // Tạo trùng (khác hoa/thường) → "Chuyên đề đã tồn tại" dưới field, giữ dữ liệu.
    await page.getByRole("button", { name: "+ Tạo chuyên đề" }).click();
    await dialog.getByLabel(/Tên chuyên đề/).fill(NAME.toLowerCase());
    await dialog.getByRole("button", { name: "Lưu" }).click();
    await expect(dialog.getByText(/Chuyên đề đã tồn tại/)).toBeVisible({ timeout: 15_000 });
    await expect(dialog.getByLabel(/Tên chuyên đề/)).toHaveValue(NAME.toLowerCase());
    await dialog.getByRole("button", { name: "Huỷ" }).click();
    await expect(dialog).toBeHidden();

    // Sửa tên.
    await row(page, NAME).getByRole("button", { name: /Sửa chuyên đề/ }).click();
    await expect(dialog.getByLabel(/Tên chuyên đề/)).toHaveValue(NAME);
    await dialog.getByLabel(/Tên chuyên đề/).fill(RENAMED);
    await dialog.getByRole("button", { name: "Lưu" }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
    await page.getByLabel("Tìm theo tên").fill(RENAMED);
    await expect(row(page, RENAMED)).toBeVisible({ timeout: 10_000 });

    // Ẩn rồi hiện lại.
    const sw = row(page, RENAMED).getByRole("switch");
    await expect(sw).toHaveAttribute("aria-checked", "true");
    await sw.click();
    await expect(page.getByText("Đã ẩn chuyên đề khỏi bộ lọc công khai")).toBeVisible();
    await expect(row(page, RENAMED).getByRole("switch")).toHaveAttribute("aria-checked", "false");
    await page.getByLabel("Trạng thái").selectOption("hidden");
    await expect(page).toHaveURL(/status=hidden/);
    await expect(row(page, RENAMED)).toBeVisible();
    await row(page, RENAMED).getByRole("switch").click();
    await expect(page.getByText("Đã hiển thị lại chuyên đề")).toBeVisible();
    await page.getByLabel("Trạng thái").selectOption("");

    // Xoá có xác nhận (chưa gán khóa học).
    await row(page, RENAMED).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await expect(dialog).toContainText("Hành động này không thể hoàn tác.");
    await dialog.getByRole("button", { name: "Huỷ" }).click();
    await expect(row(page, RENAMED)).toBeVisible();
    await row(page, RENAMED).getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await dialog.getByRole("button", { name: "Xoá" }).click();
    await expect(page.getByText("Đã xoá chuyên đề")).toBeVisible();
    await expect(row(page, RENAMED)).toHaveCount(0);

    // Đang gán khóa học: bị chặn, chỉ có "Đã hiểu" + gợi ý Ẩn.
    await page.getByLabel("Tìm theo tên").fill("E2E Đang gán");
    await expect(row(page, "E2E Đang gán")).toBeVisible({ timeout: 10_000 });
    await row(page, "E2E Đang gán").getByRole("button", { name: /Xoá chuyên đề/ }).click();
    await expect(dialog).toContainText("đang được gán cho");
    await expect(dialog.getByRole("button", { name: "Xoá", exact: true })).toHaveCount(0);
    await dialog.getByRole("button", { name: "Đã hiểu" }).click();
    await expect(dialog).toBeHidden();
    await expect(row(page, "E2E Đang gán")).toBeVisible();

    // 375px (cùng phiên, tiết kiệm lượt gửi OTP): không tràn ngang, vùng chạm >= 44px, modal nằm trong màn hình.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole("row").nth(1)).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    for (const loc of [page.getByRole("button", { name: /Sửa chuyên đề/ }).first(), page.getByRole("switch").first(), page.getByRole("button", { name: "+ Tạo chuyên đề" })]) {
      const box = await loc.boundingBox();
      expect(box!.height).toBeGreaterThanOrEqual(43.5);
    }
    await page.getByRole("button", { name: "+ Tạo chuyên đề" }).click();
    const b = await page.getByRole("dialog").boundingBox();
    expect(b!.x).toBeGreaterThanOrEqual(0);
    expect(b!.x + b!.width).toBeLessThanOrEqual(375);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toBeHidden();
  });

  test("Giáo viên: không có mục Chuyên đề trong menu, vào thẳng URL chỉ xem (active), không có nút ghi", async ({ page }) => {
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await expect(nav(page).getByText("Chuyên đề")).toHaveCount(0);
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole("button", { name: /Tạo chuyên đề/ })).toHaveCount(0);
    await expect(page.getByRole("switch")).toHaveCount(0);
    await expect(page.getByRole("button", { name: /Xoá|Sửa/ })).toHaveCount(0);
    await expect(page.getByLabel("Trạng thái")).toHaveCount(0);
  });

  test("SessionWatcher: 403 ACCOUNT_LOCKED giữa phiên bật overlay; 401 STAFF_IDLE_TIMEOUT về đăng nhập kèm thông báo idle", async ({ page }) => {
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible({ timeout: 20_000 });

    // Lời gọi API đi qua lib/api thật; chỉ phản hồi được dựng theo đúng envelope lỗi của backend.
    await page.route("**/api/v1/admin/subjects?*", (route) =>
      route.fulfill({ status: 403, contentType: "application/json", headers: { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" }, body: JSON.stringify({ message: "Tài khoản đã bị khóa.", code: "ACCOUNT_LOCKED" }) }),
    );
    await page.getByLabel("Tìm theo tên").fill("khoa");
    await expect(page.getByRole("alertdialog")).toContainText("Tài khoản đã bị khóa", { timeout: 15_000 });
    await page.unroute("**/api/v1/admin/subjects?*");

    await page.route("**/api/v1/admin/subjects?*", (route) =>
      route.fulfill({ status: 401, contentType: "application/json", headers: { "access-control-allow-origin": ADMIN, "access-control-allow-credentials": "true" }, body: JSON.stringify({ message: "Hết phiên.", code: "STAFF_IDLE_TIMEOUT" }) }),
    );
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page).toHaveURL(/\/dang-nhap\?.*reason=idle/, { timeout: 20_000 });
  });

  test("Mất phiên thật (xoá cookie) rồi thao tác: về đăng nhập, không treo", async ({ page, context }) => {
    await fillLogin(page, "gv");
    await expect(page).toHaveURL(`${ADMIN}/quan-tri`, { timeout: 20_000 });
    await page.goto(`${ADMIN}/quan-tri/chuyen-de`);
    await expect(page.getByRole("heading", { name: "Chuyên đề" })).toBeVisible({ timeout: 20_000 });
    await context.clearCookies();
    await page.getByLabel("Tìm theo tên").fill("mat phien");
    await expect(page).toHaveURL(/\/dang-nhap/, { timeout: 20_000 });
  });
});
