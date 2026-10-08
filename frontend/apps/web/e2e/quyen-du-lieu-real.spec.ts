import { expect, test, type Page } from "@playwright/test";

/**
 * FW7 — quyền dữ liệu cá nhân (`/tai-khoan/quyen-du-lieu-ca-nhan`), banner chấp nhận lại chính sách, trang công khai huỷ nhận thông báo,
 * trang chính sách tạm. Backend thật. Dữ liệu: `e2e/seed-e2e-fw7.sh --reset` (in `unsub=<token> unsubmain=<token>`; truyền qua E2E_FW7).
 * Mail OTP đọc qua Mailpit (E2E_MAILPIT_URL). Chạy tuần tự (`--workers=1`).
 */
const PASSWORD = "matkhau-123";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://127.0.0.1:8025";
const ids = Object.fromEntries((process.env.E2E_FW7 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.unsub, "Cần backend thật (E2E_REAL_BACKEND=1) và E2E_FW7 từ seed-e2e-fw7.sh");
test.describe.configure({ mode: "serial" });

const PAGE = "/tai-khoan/quyen-du-lieu-ca-nhan";

async function login(page: Page, email: string, password = PASSWORD) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(password);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}

async function noHorizontalOverflow(page: Page) {
  const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(over).toBeLessThanOrEqual(0);
}

interface Mail { ID: string; Snippet: string }
async function mailsTo(email: string): Promise<Mail[]> {
  const res = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`);
  return ((await res.json()) as { messages?: Mail[] }).messages ?? [];
}
async function waitForCode(email: string, known: string[]): Promise<string> {
  for (let i = 0; i < 60; i++) {
    const fresh = (await mailsTo(email)).filter((m) => !known.includes(m.ID));
    const m = fresh[0]?.Snippet.match(/(\d{6})/);
    if (m?.[1]) return m[1];
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`Không thấy mail OTP tới ${email}`);
}

test.describe("Trang chính sách tạm", () => {
  for (const [path, title] of [["/dieu-khoan", "Điều khoản sử dụng"], ["/chinh-sach-du-lieu", "Chính sách xử lý dữ liệu cá nhân"]] as const) {
    test(`${path}: nhãn bản tạm + phiên bản từ /config/public`, async ({ page }) => {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible({ timeout: 90_000 });
      await expect(page.getByText("Bản tạm — chờ pháp chế")).toBeVisible();
      await expect(page.getByTestId("policy-version")).toHaveText(/^\d{4}-\d{2}/);
    });
  }
});

test.describe("Huỷ nhận thông báo (công khai)", () => {
  test("không tự POST, gỡ ?t= khỏi URL, no-referrer + noindex, bấm một lần ra thông điệp chung", async ({ page }) => {
    let posts = 0;
    let body = "";
    page.on("request", (r) => {
      if (r.url().includes("/parent-notices/unsubscribe") && r.method() === "POST") {
        posts++;
        body = r.postData() ?? "";
      }
    });
    const res = await page.goto(`/phu-huynh/huy-nhan-thong-bao?t=${ids.unsub}`);
    expect(res?.headers()["referrer-policy"]).toBe("no-referrer");
    const btn = page.getByRole("button", { name: "Huỷ nhận thông báo" });
    await expect(btn).toBeVisible({ timeout: 90_000 });
    await expect(page).toHaveURL(/\/phu-huynh\/huy-nhan-thong-bao$/);
    expect(await page.locator('meta[name="robots"]').getAttribute("content")).toContain("noindex");
    await page.waitForTimeout(1500);
    expect(posts).toBe(0);
    await btn.dblclick();
    await expect(page.getByText("Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.")).toBeVisible({ timeout: 60_000 });
    expect(posts).toBe(1);
    expect(JSON.parse(body)).toEqual({ token: ids.unsub });
  });

  test("token sai cũng ra đúng thông điệp chung (không lộ gì)", async ({ page }) => {
    await page.goto(`/phu-huynh/huy-nhan-thong-bao?t=1.${"a".repeat(43)}`);
    await page.getByRole("button", { name: "Huỷ nhận thông báo" }).click();
    await expect(page.getByText("Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.")).toBeVisible({ timeout: 60_000 });
  });

  test("không có token: báo liên kết không dùng được", async ({ page }) => {
    await page.goto("/phu-huynh/huy-nhan-thong-bao");
    await expect(page.getByText("Liên kết không dùng được")).toBeVisible({ timeout: 90_000 });
  });

  test("học sinh thấy 'Phụ huynh đã ngừng nhận' sau khi phụ huynh huỷ", async ({ page }) => {
    await login(page, "fw7-unsub@example.com");
    await page.goto(PAGE);
    await expect(page.getByText("Phụ huynh đã ngừng nhận")).toBeVisible({ timeout: 90_000 });
  });
});

test.describe("Quyền dữ liệu cá nhân", () => {
  test("khách vào thẳng → chuyển tới đăng nhập kèm next", async ({ page }) => {
    await page.goto(PAGE);
    await expect(page).toHaveURL(/\/dang-nhap\?.*next=/, { timeout: 90_000 });
  });

  test("đồng ý (không lộ ip/ua) + phụ huynh che + sửa/xoá + link từ trang tài khoản + 375px", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await login(page, "fw7-main@example.com");
    await page.goto("/tai-khoan");
    await page.getByRole("link", { name: /Đồng ý, thông tin phụ huynh/ }).click();
    await expect(page).toHaveURL(new RegExp(`${PAGE}$`), { timeout: 90_000 });
    await expect(page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });

    const consents = page.getByRole("list", { name: "Danh sách đồng ý" });
    await expect(consents.getByText("Điều khoản sử dụng")).toBeVisible({ timeout: 60_000 });
    await expect(consents.getByText("Chính sách xử lý dữ liệu cá nhân")).toBeVisible();
    await expect(consents.getByText("Đang hiệu lực")).toHaveCount(2);
    const html = await page.content();
    expect(html).not.toContain("203.0.113.9");
    expect(html).not.toContain("e2e-fw7");

    await expect(page.getByTestId("parent-email-masked")).toContainText("***");
    await expect(page.getByTestId("parent-email-masked")).not.toContainText("fw7-main-ph@example.com");
    await expect(page.getByTestId("parent-phone-masked")).toHaveText(/^\*+678$/);
    await expect(page.getByText("Đang nhận thông báo")).toBeVisible();
    await noHorizontalOverflow(page);

    // Sai mật khẩu → lỗi dưới ô mật khẩu, không đổi gì.
    await page.getByRole("button", { name: "Sửa thông tin phụ huynh" }).click();
    await page.getByRole("button", { name: "Xoá số điện thoại phụ huynh" }).click();
    await page.getByLabel(/^Mật khẩu hiện tại/).fill("sai-mat-khau-1");
    await page.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByText("Mật khẩu hiện tại không đúng.")).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId("parent-phone-masked")).toHaveText(/^\*+678$/);

    // Đúng mật khẩu → xoá SĐT phụ huynh (gửi null).
    let sent = "";
    page.on("request", (r) => { if (r.url().includes("/me/parent-contact") && r.method() === "PUT") sent = r.postData() ?? ""; });
    await page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await page.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByTestId("parent-phone-masked")).toHaveText("Chưa có", { timeout: 60_000 });
    expect(JSON.parse(sent)).toEqual({ current_password: PASSWORD, parent_phone: null });
    await expect(page.getByTestId("parent-email-masked")).toContainText("***");
    await noHorizontalOverflow(page);
  });

  test("banner chấp nhận lại: không chặn trang, 2 ô trống sẵn, đồng ý xong banner biến mất và đồng ý mới có trong danh sách", async ({ page }) => {
    await login(page, "fw7-old@example.com");
    const banner = page.getByText("Điều khoản đã cập nhật");
    await expect(banner).toBeVisible({ timeout: 90_000 });
    await page.goto("/khoa-hoc"); // không chặn duyệt khóa
    await expect(banner).toBeVisible({ timeout: 90_000 });
    const terms = page.getByLabel("Tôi đã đọc và đồng ý với Điều khoản sử dụng");
    const privacy = page.getByLabel("Tôi đã đọc và đồng ý với Chính sách xử lý dữ liệu cá nhân");
    await expect(terms).not.toBeChecked();
    await expect(privacy).not.toBeChecked();
    await page.getByRole("button", { name: "Đồng ý", exact: true }).click();
    await expect(page.getByText("Vui lòng tích cả hai ô để tiếp tục.")).toBeVisible();
    await terms.check();
    await privacy.check();
    await page.getByRole("button", { name: "Đồng ý", exact: true }).click();
    await expect(banner).toBeHidden({ timeout: 60_000 });
    await page.goto(PAGE);
    await expect(page.getByRole("list", { name: "Danh sách đồng ý" }).getByText("Đang hiệu lực")).toHaveCount(2, { timeout: 60_000 });
    await expect(page.getByRole("list", { name: "Danh sách đồng ý" }).getByText("Bản cũ", { exact: true })).toHaveCount(2);
  });

  test("tải dữ liệu: còn 2 lượt → sai mật khẩu → tải file → còn 1 → tải nữa → hết lượt, nút khoá", async ({ page }) => {
    await login(page, "fw7-export@example.com");
    await page.goto(PAGE);
    await expect(page.getByTestId("export-remaining")).toContainText("Còn 2 lượt hôm nay", { timeout: 90_000 });

    await page.getByRole("button", { name: "Tải dữ liệu của tôi" }).click();
    await page.getByLabel(/^Mật khẩu hiện tại/).fill("sai-mat-khau-1");
    await page.getByRole("button", { name: "Tải về" }).click();
    await expect(page.getByText(/không đúng/i)).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId("export-remaining")).toContainText("Còn 2 lượt hôm nay"); // sai mật khẩu không tốn lượt
    await page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    const [download] = await Promise.all([page.waitForEvent("download", { timeout: 90_000 }), page.getByRole("button", { name: "Tải về" }).click()]);
    expect(download.suggestedFilename()).toMatch(/^vitaminvui-du-lieu-ca-nhan-\d{8}\.json$/);
    const fs = await import("node:fs/promises");
    const text = await fs.readFile((await download.path())!, "utf8");
    const data = JSON.parse(text) as { format_version: number; account: { email: string } };
    expect(data.format_version).toBe(1);
    expect(data.account.email).toBe("fw7-export@example.com");
    await expect(page.getByTestId("export-remaining")).toContainText("Còn 1 lượt hôm nay", { timeout: 60_000 });

    await page.getByRole("button", { name: "Tải dữ liệu của tôi" }).click();
    await page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await Promise.all([page.waitForEvent("download", { timeout: 90_000 }), page.getByRole("button", { name: "Tải về" }).click()]);
    await expect(page.getByText(/Bạn đã tải 2 lần hôm nay\. Thử lại sau 00:00, \d{2}\/\d{2}\/\d{4}\./)).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole("button", { name: "Tải dữ liệu của tôi" })).toBeDisabled();
  });

  test("xoá tài khoản chưa xác thực email: hướng dẫn xác thực (403)", async ({ page }) => {
    await login(page, "fw7-unverified@example.com");
    await page.goto(PAGE);
    await page.getByRole("button", { name: "Xoá tài khoản của tôi" }).click();
    await expect(page.getByText(/xoá vĩnh viễn|Khi xoá tài khoản/).first()).toBeVisible();
    await page.getByRole("button", { name: "Gửi mã xác nhận" }).click();
    await expect(page.getByText(/xác thực email trước khi xoá/i)).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole("link", { name: "Xác thực email" })).toHaveAttribute("href", "/xac-thuc-otp");
  });

  test("xoá tài khoản: cảnh báo → OTP → mã sai → mã đúng → về trang chủ kèm thông báo, không đăng nhập lại được", async ({ page }) => {
    const email = "fw7-delete@example.com";
    await login(page, email);
    await page.goto(PAGE);
    const known = (await mailsTo(email)).map((m) => m.ID);
    await page.getByRole("button", { name: "Xoá tài khoản của tôi" }).click();
    await expect(page.getByText("Khóa học đang học và đơn hàng được giữ lại", { exact: false })).toBeVisible();
    await page.getByRole("button", { name: "Gửi mã xác nhận" }).click();
    const code = await waitForCode(email, known);
    const field = page.getByLabel("Mã xác nhận");
    await expect(field).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole("button", { name: /Gửi lại mã sau/ })).toBeDisabled();

    const wrong = code === "000000" ? "111111" : "000000";
    await field.fill(wrong);
    await page.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }).click();
    await expect(page.getByText(/không đúng/i)).toBeVisible({ timeout: 60_000 });

    await page.getByLabel("Mã xác nhận").fill(code);
    await page.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }).click();
    await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
    await expect(page.getByText("Tài khoản của bạn đã được xoá.")).toBeVisible({ timeout: 60_000 });

    await page.goto("/dang-nhap");
    await page.waitForLoadState("networkidle");
    await page.getByLabel("Email hoặc số điện thoại").fill(email);
    await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page.getByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeVisible({ timeout: 60_000 });
  });
});
