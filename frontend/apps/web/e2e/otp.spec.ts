import { expect, test, type Page } from "@playwright/test";

/**
 * QA T04 + FW1 phần OTP — backend thật (E2E_REAL_BACKEND=1), mail đọc qua Mailpit.
 *
 * Người dùng `otp-e2e-1..12@example.com` (mật khẩu `matkhau-123`, chưa xác thực) phải được seed trước
 * (artisan tinker/factory) để không chạm limiter đăng ký 30 lượt/giờ/IP. Chỉ 1 test đăng ký qua UI.
 * Mỗi test dùng một user riêng, chạy lại thì seed lại.
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://127.0.0.1:8025";
const PASSWORD = "matkhau-123";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const seeded = (n: number) => `otp-e2e-${n}@example.com`;

interface Mail {
  ID: string;
  Snippet: string;
  Created: string;
}

async function mailsTo(email: string): Promise<Mail[]> {
  const res = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`);
  const body = (await res.json()) as { messages: Mail[] };
  return body.messages ?? [];
}

/** Đợi có mail MỚI (ID chưa có trong `known`) tới `email`, trả mã 6 số. */
async function waitForCode(email: string, known: string[] = []): Promise<string> {
  for (let i = 0; i < 40; i++) {
    const fresh = (await mailsTo(email)).filter((m) => !known.includes(m.ID));
    const snippet = fresh[0]?.Snippet;
    if (snippet) {
      const m = snippet.match(/(\d{6})/);
      if (m?.[1]) return m[1];
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`Không thấy mail OTP tới ${email}`);
}

async function knownIds(email: string): Promise<string[]> {
  return (await mailsTo(email)).map((m) => m.ID);
}

async function login(page: Page, email: string, next?: string) {
  await page.goto(next ? `/dang-nhap?next=${encodeURIComponent(next)}` : "/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
}

/** OtpInput v2: MỘT ô nhập thật có nhãn "Mã xác nhận" (vẽ thành 6 ô); nhập đủ 6 số là tự gửi. */
const codeBox = (page: Page) => page.getByLabel(/Mã xác nhận/);

async function typeCode(page: Page, code: string) {
  await codeBox(page).click();
  await page.keyboard.type(code);
}

async function openOtp(page: Page) {
  await page.goto("/xac-thuc-otp");
  await expect(page.getByRole("heading", { name: "Xác thực tài khoản" })).toBeVisible();
  await expect(codeBox(page)).toBeVisible({ timeout: 15_000 });
}

/** Seed user đăng nhập + gửi mã (bấm "Gửi lại mã") rồi trả mã. */
async function loginAndRequestCode(page: Page, n: number): Promise<string> {
  const email = seeded(n);
  const before = await knownIds(email);
  await login(page, email);
  await expect(page).toHaveURL(/\/$/);
  await openOtp(page);
  await page.getByRole("button", { name: "Gửi lại mã" }).click();
  await expect(page.getByText("Đã gửi mã mới")).toBeVisible();
  return waitForCode(email, before);
}

test.describe("OTP e2e", () => {
  test("AC1+AC8: đăng ký -> banner -> Xác thực ngay -> OTP đúng -> thành công, header có tên", async ({ page }) => {
    const run = Date.now().toString(36);
    const email = `qa-otp-ui-${run}@example.com`;
    await page.goto("/dang-ky");
    await page
      .locator('[data-testid="turnstile-widget"] input[name="cf-turnstile-response"]')
      .waitFor({ state: "attached", timeout: 20_000 });
    await page.waitForTimeout(500);
    await page.getByLabel("Họ và tên").fill("Trần Thị Ánh Tuyết");
    await page.getByLabel("Ngày sinh").fill("2000-01-01");
    await page.getByLabel(/^Email(?! phụ huynh)/).fill(email);
    await page.getByLabel(/^Số điện thoại(?! phụ huynh)/).fill(`09${String(Date.now()).slice(-8)}`);
    await page.getByLabel("Lớp đang học").selectOption("9");
    await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
    await page.getByLabel("Xác nhận mật khẩu").fill(PASSWORD);
    await page.getByLabel(/Điều khoản sử dụng/).check();
    await page.getByLabel(/Chính sách xử lý dữ liệu/).check();
    const btn = page.getByRole("button", { name: "Tạo tài khoản" });
    await expect(btn).toBeEnabled({ timeout: 15_000 });
    await btn.click();

    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText("Vui lòng xác thực tài khoản để có thể mua khóa học")).toBeVisible();
    await expect(page.getByRole("banner").getByText("Trần Thị Ánh Tuyết").first()).toBeVisible();

    const code = await waitForCode(email);
    await page.getByRole("link", { name: "Xác thực ngay" }).click();
    await expect(page).toHaveURL(/xac-thuc-otp/);
    // Vừa đăng ký: cooldown chạy từ lúc server gửi mã.
    await expect(page.getByRole("button", { name: /Gửi lại mã sau/ })).toBeDisabled();
    await expect(page.getByText(/có hiệu lực 10 phút/)).toBeVisible();

    await typeCode(page, code); // tự submit khi đủ 6 số
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText("Xác thực tài khoản thành công")).toBeVisible();
    await expect(page.getByText("Cần xác thực tài khoản")).toHaveCount(0);

    // F5: banner thành công không hiện lại, banner nhắc cũng không.
    await page.reload();
    await expect(page.getByText("Xác thực tài khoản thành công")).toHaveCount(0);
    await expect(page.getByText("Cần xác thực tài khoản")).toHaveCount(0);

    // Đã xác thực mà vào /xac-thuc-otp -> về /.
    await page.goto("/xac-thuc-otp");
    await expect(page).toHaveURL(/\/$/);
  });

  test("OTP sai -> lỗi, ô được xoá và focus ô đầu; nhập đúng sau đó thành công", async ({ page }) => {
    const code = await loginAndRequestCode(page, 1);
    const wrong = code === "000000" ? "111111" : "000000";
    await typeCode(page, wrong);
    await expect(page.getByText("Mã OTP không đúng, vui lòng thử lại.")).toBeVisible();
    await expect(codeBox(page)).toHaveValue("");
    await expect(codeBox(page)).toBeFocused();
    await expect(page).toHaveURL(/xac-thuc-otp/);

    await typeCode(page, code);
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText("Xác thực tài khoản thành công")).toBeVisible();
  });

  test("sai 5 lần -> khoá mã, nhập mã đúng vẫn bị chặn, gửi lại mã mới (sau cooldown) thì xác thực được", async ({ page }) => {
    test.setTimeout(180_000);
    const email = seeded(2);
    const code = await loginAndRequestCode(page, 2);
    const wrong = code === "000000" ? "111111" : "000000";
    for (let i = 0; i < 5; i++) {
      await typeCode(page, wrong);
      await expect(page.getByText("Mã OTP không đúng, vui lòng thử lại.")).toBeVisible();
      await codeBox(page).click();
    }
    await typeCode(page, code);
    // Lần thứ 6 trong phút: throttle `otp-verify` (429 + Retry-After) -> Alert cảnh báo, khoá ô mã và nút Xác nhận;
    // hoặc mã hết lượt (429 không Retry-After) -> "sai mã này 5 lần", "Gửi lại mã" thành nút chính. Cả hai chặn mã đúng.
    await expect(page.getByText("Bạn đã thử quá nhiều lần").or(page.getByText(/sai mã này 5 lần/))).toBeVisible();
    await expect(codeBox(page)).toBeDisabled();
    await expect(page).toHaveURL(/xac-thuc-otp/);

    // Chờ hết cooldown 60s rồi xin mã mới
    const before = await knownIds(email);
    const resend = page.getByRole("button", { name: "Gửi lại mã", exact: true });
    await expect(resend).toBeEnabled({ timeout: 90_000 });
    await resend.click();
    const fresh = await waitForCode(email, before);
    await expect(codeBox(page)).toBeEnabled({ timeout: 90_000 }); // ô mở lại khi hết Retry-After / sau khi gửi mã mới
    await typeCode(page, fresh);
    await expect(page).toHaveURL(/\/$/);
  });

  test("gửi lại trong cooldown: nút khoá, có đếm ngược", async ({ page }) => {
    await loginAndRequestCode(page, 3);
    const cooling = page.getByRole("button", { name: /Gửi lại mã sau/ });
    await expect(cooling).toBeDisabled();
    await expect(cooling).toContainText(/\d+:\d{2}/);
  });

  test("gửi lại khi còn cooldown (sau F5, UI không biết) -> 429, UI báo lỗi và phải bật đếm ngược theo Retry-After", async ({ page }) => {
    // BUG-2: backend không expose `Retry-After` qua CORS nên trình duyệt không đọc được header -> không có đếm ngược.
    await loginAndRequestCode(page, 11);
    await page.reload();
    await expect(codeBox(page)).toBeVisible({ timeout: 15_000 });
    await page.getByRole("button", { name: /^Gửi lại mã/ }).click();
    await expect(page.getByRole("alert").first()).toContainText(/quá nhanh/);
    await expect(page.getByRole("button", { name: /Gửi lại mã sau/ })).toBeDisabled({ timeout: 3_000 });
  });

  test("đổi email ở Tài khoản: trùng -> lỗi dưới ô; email mới -> mã mới tới email mới, mã cũ không dùng được", async ({ page }) => {
    const oldCode = await loginAndRequestCode(page, 4);
    await page.getByRole("link", { name: "Đổi email" }).click();
    await expect(page).toHaveURL(/tai-khoan#doi-lien-he/);

    // trùng với tài khoản khác (user seed số 5)
    const contact = page.locator("#doi-lien-he"); // trang có hai ô "Mật khẩu hiện tại" (đổi liên hệ, đổi mật khẩu)
    await contact.getByLabel("Email", { exact: true }).fill(seeded(5));
    await contact.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await contact.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByText("Email đã được sử dụng.")).toBeVisible();

    const newEmail = `otp-e2e-4-moi-${Date.now().toString(36)}@example.com`;
    await contact.getByLabel("Email", { exact: true }).fill(newEmail);
    await contact.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await contact.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByText(/Mã xác thực đã gửi tới email mới\./)).toBeVisible();
    const newCode = await waitForCode(newEmail);

    await page.getByRole("link", { name: "Xác thực ngay" }).click();
    await expect(codeBox(page)).toBeVisible({ timeout: 15_000 });
    await typeCode(page, oldCode);
    await expect(page.getByText("Mã OTP không đúng, vui lòng thử lại.")).toBeVisible();
    await typeCode(page, newCode);
    await expect(page).toHaveURL(/\/$/);
  });

  test("F5 giữa chừng: vẫn đăng nhập, ở lại trang OTP, ô trống; mã đã gửi vẫn dùng được", async ({ page }) => {
    const code = await loginAndRequestCode(page, 6);
    await codeBox(page).click();
    await page.keyboard.type(code.slice(0, 3));
    await page.reload();
    await expect(page).toHaveURL(/xac-thuc-otp/);
    await expect(codeBox(page)).toHaveValue("", { timeout: 15_000 });
    await typeCode(page, code);
    await expect(page).toHaveURL(/\/$/);
  });

  test("đăng xuất rồi vào thẳng /xac-thuc-otp -> /dang-nhap?next, đăng nhập xong quay lại trang OTP", async ({ page }) => {
    await login(page, seeded(7));
    await expect(page).toHaveURL(/\/$/);
    await page.getByRole("button", { name: "Đăng xuất" }).click();
    await expect(page).toHaveURL(/dang-nhap/);

    await page.goto("/xac-thuc-otp");
    await expect(page).toHaveURL(/dang-nhap\?next=(%2F|\/)xac-thuc-otp/);
    await page.getByLabel("Email hoặc số điện thoại").fill(seeded(7));
    await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page).toHaveURL(/xac-thuc-otp/);
    await expect(page.getByRole("heading", { name: "Xác thực tài khoản" })).toBeVisible();
  });

  test("375px: trang OTP, form đổi liên hệ và banner không tràn ngang, 6 ô nhìn thấy đủ", async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 375, height: 800 } });
    const page = await ctx.newPage();
    await login(page, seeded(8));
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText("Cần xác thực tài khoản")).toBeVisible();
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(await overflow()).toBeLessThanOrEqual(0);

    await openOtp(page);
    expect(await overflow()).toBeLessThanOrEqual(0);
    // Ô mã vẽ thành 6 ô: cả khối phải nằm gọn trong màn 375px.
    const box = await codeBox(page).boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(375);
    expect(box!.width / 6).toBeGreaterThanOrEqual(40);
    await page.screenshot({ path: "test-results/otp-375.png", fullPage: true });

    await page.getByRole("link", { name: "Đổi email" }).click();
    await expect(page.locator("#doi-lien-he").getByLabel("Email", { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(await overflow()).toBeLessThanOrEqual(0);
    await page.screenshot({ path: "test-results/otp-contact-375.png", fullPage: true });
    await ctx.close();
  });

  test("lỗi mạng /auth/me ở trang OTP: không bị đá sang /dang-nhap, có nút Thử lại (R4)", async ({ page }) => {
    await login(page, seeded(9));
    await expect(page).toHaveURL(/\/$/);
    let fail = true;
    await page.route("**/api/v1/auth/me", (route) => (fail ? route.fulfill({ status: 502, body: "{}" }) : route.continue()));
    await page.goto("/xac-thuc-otp");
    await expect(page.getByText("Không tải được thông tin tài khoản")).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveURL(/xac-thuc-otp/);
    fail = false;
    await page.getByRole("button", { name: "Thử lại" }).click();
    await expect(codeBox(page)).toBeVisible();
  });

  test("đổi chỉ SĐT (production chỉ email): không báo đã gửi mã mới, mã email cũ vẫn dùng được (R1)", async ({ page }) => {
    const code = await loginAndRequestCode(page, 10);
    await page.getByRole("link", { name: "Đổi email" }).click();
    const contact = page.locator("#doi-lien-he");
    await contact.getByLabel("Số điện thoại", { exact: true }).fill("0966123456");
    await contact.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await contact.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByText("Đã cập nhật thông tin liên hệ.", { exact: true })).toBeVisible();
    await expect(page.getByText(/Mã xác thực đã gửi tới email mới/)).toHaveCount(0);
    await openOtp(page);
    await typeCode(page, code);
    await expect(page).toHaveURL(/\/$/);
  });
});
