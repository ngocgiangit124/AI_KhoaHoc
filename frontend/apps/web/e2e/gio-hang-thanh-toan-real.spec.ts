import { expect, test, type Page } from "@playwright/test";

/**
 * FW3 (US-022) — giỏ hàng, thanh toán "Liên hệ Quản trị viên", "Đơn đã gửi", đơn của tôi, huỷ đơn, với backend thật.
 * Dữ liệu: `e2e/seed-e2e-fw3.sh --reset` (học sinh fw3-hs-*, khóa e2e-fw3-*, mã E2EFW3*, đơn VVFW3*). `--workers=1`, tuần tự.
 * Phần MoMo (FW3-MoMo) không thuộc task này.
 */
const PASSWORD = "matkhau-123";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://localhost:8025";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1) và dữ liệu từ seed-e2e-fw3.sh");
test.describe.configure({ mode: "serial" });

async function login(page: Page, key: string) {
  if (process.env.E2E_DEBUG) {
    page.on("pageerror", (e) => console.log("[pageerror]", e.message.slice(0, 300)));
    page.on("response", (r) => /\/api\/v1\//.test(r.url()) && console.log("[api]", r.status(), r.request().method(), r.url().replace(/^.*\/api\/v1/, "")));
  }
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(`fw3-hs-${key}@example.com`);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}

/** Trang không được cuộn ngang. */
async function expectNoHScroll(page: Page) {
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(over).toBeLessThanOrEqual(0);
}

/** Các dòng đơn trong danh sách "Đơn hàng của tôi" (chỉ trong vùng nội dung chính). */
const orderLinks = (page: Page) => page.locator('main a[href^="/tai-khoan/don-hang/"]');

const submit = (page: Page) => page.getByRole("button", { name: "Gửi đơn" }).first();

async function mailTo(request: import("@playwright/test").APIRequestContext, to: string, code: string): Promise<string> {
  let text = "";
  await expect
    .poll(
      async () => {
        const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to} subject:Đã nhận đơn`)}`);
        const list = (await res.json()) as { messages?: Array<{ ID: string }> };
        // Thư của các lần chạy trước cùng người nhận vẫn còn trong Mailpit: chỉ nhận thư chứa đúng mã đơn này.
        for (const m of list.messages ?? []) {
          const msg = (await (await request.get(`${MAILPIT}/api/v1/message/${m.ID}`)).json()) as { Text?: string; HTML?: string };
          const body = `${msg.Text ?? ""}\n${msg.HTML ?? ""}`;
          if (body.includes(code)) {
            text = body;
            return text;
          }
        }
        return "";
      },
      { timeout: 90_000, message: "chờ thư 'Đã nhận đơn' trong Mailpit" },
    )
    .not.toBe("");
  return text;
}

test.describe("khách chưa đăng nhập", () => {
  for (const path of ["/gio-hang", "/thanh-toan", "/tai-khoan/don-hang", "/tai-khoan/don-hang/VVFW3PEND001", "/thanh-toan/da-gui/VVFW3PEND001"]) {
    test(`${path} → đăng nhập kèm next`, async ({ page }) => {
      await page.goto(path);
      await expect(page).toHaveURL(/\/dang-nhap\?.*next=/, { timeout: 90_000 });
    });
  }
  test("mã đơn sai định dạng → 404 thật, không gọi API", async ({ page }) => {
    const res = await page.goto("/tai-khoan/don-hang/a..b");
    expect(res?.status()).toBe(404);
  });
});

test("luồng chính: thêm vào giỏ → giỏ → áp mã → thanh toán → Đơn đã gửi → đơn của tôi → thư có link đúng", async ({ page, request }) => {
  await login(page, "buy");
  await expect(page.getByRole("link", { name: "Giỏ hàng, 0 khóa" })).toBeVisible({ timeout: 90_000 });

  // Trang chi tiết khóa có phí: không còn "Sắp mở bán", có "Thêm vào giỏ".
  await page.goto("/khoa-hoc/e2e-fw3-toan");
  await expect(page.getByText("Sắp mở bán")).toHaveCount(0);
  await page.getByRole("button", { name: "Thêm vào giỏ" }).first().click();
  await expect(page.getByRole("link", { name: "Xem giỏ hàng" }).first()).toHaveAttribute("href", "/gio-hang", { timeout: 60_000 });
  await expect(page.getByRole("link", { name: "Giỏ hàng, 1 khóa" })).toBeVisible();
  await page.goto("/khoa-hoc/e2e-fw3-van");
  await page.getByRole("button", { name: "Thêm vào giỏ" }).first().click();
  await expect(page.getByRole("link", { name: "Xem giỏ hàng" }).first()).toBeVisible({ timeout: 60_000 });

  // Giỏ.
  await page.goto("/gio-hang");
  await expect(page.getByRole("heading", { level: 1, name: "Giỏ hàng" })).toBeVisible();
  await expect(page.getByRole("link", { name: "E2E FW3 Toán 9 nâng cao" })).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "E2E FW3 Ngữ văn 9" })).toBeVisible();
  await page.getByLabel(/Nhập mã/).fill("khong-co-ma");
  await page.getByRole("button", { name: "Áp dụng" }).click();
  await expect(page.getByText(/không tồn tại|không hợp lệ|chưa/i).first()).toBeVisible({ timeout: 60_000 });
  await page.getByLabel(/Nhập mã/).fill("e2efw3");
  await page.getByRole("button", { name: "Áp dụng" }).click();
  await expect(page.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
  await page.getByRole("button", { name: "Xoá khóa E2E FW3 Ngữ văn 9 khỏi giỏ" }).click();
  await expect(page.getByRole("link", { name: "E2E FW3 Ngữ văn 9" })).toHaveCount(0, { timeout: 60_000 });
  await expect(page.getByRole("link", { name: "Giỏ hàng, 1 khóa" })).toBeVisible();

  // Thanh toán.
  await page.getByRole("link", { name: "Tiếp tục đặt mua" }).first().click();
  await expect(page).toHaveURL(/\/thanh-toan$/);
  await expect(page.getByRole("heading", { level: 1, name: "Thanh toán" })).toBeVisible();
  const radios = page.getByRole("radio");
  await expect(radios).toHaveCount(1, { timeout: 90_000 });
  await expect(radios.first()).toBeChecked();
  await expect(page.getByText("Liên hệ Quản trị viên").first()).toBeVisible();
  await expect(page.getByText(/MoMo/i)).toHaveCount(0);
  await expect(page.getByText("Email: fw3-hs-buy@example.com")).toBeVisible();
  await expect(page.getByText(/Không ghi mật khẩu hay mã OTP/)).toBeVisible();
  await page.getByLabel(/Ghi chú cho Quản trị viên/).fill("Gọi sau 18h\nZalo của mẹ: 0987 654 321");
  await expect(page.getByText("37/500")).toBeVisible();
  await submit(page).click();

  // Đơn đã gửi.
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  const code = page.url().split("/").pop() as string;
  await expect(page.getByRole("heading", { level: 1, name: "Đã gửi đơn" })).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(code).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Sao chép mã đơn" })).toBeVisible();
  await expect(page.getByText(/ghi mã đơn/).first()).toBeVisible();
  await expect(page.getByRole("link", { name: /Gọi điện/ })).toHaveAttribute("href", "tel:0915592224");
  const zalo = page.getByRole("link", { name: /Nhắn Zalo/ });
  await expect(zalo).toHaveAttribute("href", "https://zalo.me/0915592224");
  await expect(zalo).toHaveAttribute("target", "_blank");
  await expect(zalo).toHaveAttribute("rel", "noopener noreferrer");
  await expect(page.getByRole("link", { name: /hotro@vitaminvui\.vn/ })).toHaveAttribute("href", /^mailto:hotro@vitaminvui\.vn\?subject=/);
  await expect(page.getByText("8h–17h")).toBeVisible();
  await expect(page.getByText(/không đăng số tài khoản trên website/)).toBeVisible();
  await expect(page.getByText(/chưa phải trả tiền/i).first()).toBeVisible();
  await expect(page.getByText(/còn \d+ giờ/)).toBeVisible();
  // Có giảm giá 10% của E2EFW3: 300.000 → 270.000.
  await expect(page.getByText(/270\.000/).first()).toBeVisible();
  await expectNoHScroll(page);

  // Đơn của tôi + chi tiết (ghi chú hiển thị đúng 2 dòng, không HTML).
  await page.goto("/tai-khoan/don-hang");
  const row = page.getByRole("link", { name: new RegExp(code) });
  await expect(row).toBeVisible({ timeout: 90_000 });
  await expect(row.getByText("Chờ Quản trị viên duyệt")).toBeVisible();
  await row.click();
  await expect(page).toHaveURL(new RegExp(`/tai-khoan/don-hang/${code}$`));
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText("Zalo của mẹ: 0987 654 321")).toBeVisible();
  await expect(page.getByRole("button", { name: "Huỷ đơn" })).toBeVisible();

  // Thư "Đã nhận đơn" dẫn tới màn "Đơn đã gửi".
  const mail = await mailTo(request, "fw3-hs-buy@example.com", code);
  expect(mail).toContain(`/thanh-toan/da-gui/${code}`);
});

test("375px: giỏ → thanh toán → gửi đơn đúng một lần dù bấm kép; gửi lại cùng giỏ dùng lại đơn; không cuộn ngang", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await login(page, "cart");
  await page.goto("/gio-hang");
  await expect(page.getByRole("link", { name: "E2E FW3 Toán 9 nâng cao" })).toBeVisible({ timeout: 90_000 });
  await expectNoHScroll(page);
  // Thanh dính đáy có tổng + nút ≥ 44px.
  const cont = page.getByRole("link", { name: "Tiếp tục đặt mua" }).last();
  await expect(cont).toBeVisible();
  expect((await cont.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  await cont.click();
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await expectNoHScroll(page);

  // Bấm kép: chỉ một POST /checkout.
  const posts: string[] = [];
  page.on("request", (r) => r.method() === "POST" && r.url().endsWith("/api/v1/checkout") && posts.push(r.url()));
  const btn = submit(page);
  await btn.dblclick();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  expect(posts).toHaveLength(1);
  const code = page.url().split("/").pop() as string;
  await expect(page.getByRole("heading", { level: 1, name: "Đã gửi đơn" })).toBeVisible({ timeout: 90_000 });
  await expectNoHScroll(page);
  expect((await page.getByRole("link", { name: /Gọi điện/ }).boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);

  // Giỏ không đổi → gửi lại dùng lại đúng đơn, có thông báo, không tạo thêm đơn.
  await page.goto("/thanh-toan");
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await expect(page.getByText(`Bạn đang có đơn ${code} chờ duyệt`)).toBeVisible();
  await submit(page).click();
  await expect(page).toHaveURL(new RegExp(`/thanh-toan/da-gui/${code}\\?dung-lai=1$`), { timeout: 90_000 });
  await expect(page.getByText("Đơn này bạn đã gửi trước đó")).toBeVisible({ timeout: 90_000 });
  await page.goto("/tai-khoan/don-hang");
  await expect(page.getByRole("link", { name: new RegExp(code) })).toBeVisible({ timeout: 90_000 });
  await expect(orderLinks(page)).toHaveCount(1);
  await expectNoHScroll(page);
});

test("2 tab cùng gửi đơn → chỉ 1 đơn", async ({ browser }) => {
  const context = await browser.newContext({ baseURL: "http://api.localhost:3000" });
  const a = await context.newPage();
  await login(a, "tabs");
  const b = await context.newPage();
  for (const p of [a, b]) {
    await p.goto("/thanh-toan");
    await expect(p.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  }
  await Promise.all([submit(a).click(), submit(b).click()]);
  await expect(a).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  await expect(b).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+/, { timeout: 90_000 });
  const codeOf = (p: Page) => new URL(p.url()).pathname.split("/").pop();
  expect(codeOf(a)).toBe(codeOf(b));
  await a.goto("/tai-khoan/don-hang");
  await expect(a.getByRole("link", { name: new RegExp(codeOf(b) as string) })).toBeVisible({ timeout: 90_000 });
  await expect(orderLinks(a)).toHaveCount(1);
  await context.close();
});

test("đã có đơn chờ khác nội dung: báo trước, 'Giữ đơn cũ', rồi 'Huỷ đơn cũ, đặt đơn mới' → đơn cũ 'Đã thay bằng đơn mới'", async ({ page }) => {
  await login(page, "replace");
  await page.goto("/thanh-toan");
  await expect(page.getByText("Bạn đang có đơn VVFW3REPL001 chờ duyệt")).toBeVisible({ timeout: 90_000 });
  await submit(page).click();
  const dialog = page.getByRole("dialog", { name: "Bạn đang có đơn chờ duyệt" });
  await expect(dialog).toBeVisible({ timeout: 60_000 });
  await expect(dialog.getByText("VVFW3REPL001")).toBeVisible();
  await expect(dialog.getByText(/1 khóa/)).toBeVisible();
  // Esc đóng hộp, không mất giỏ.
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await submit(page).click();
  await expect(dialog).toBeVisible({ timeout: 60_000 });
  await dialog.getByRole("button", { name: "Giữ đơn cũ" }).click();
  await expect(page).toHaveURL(/\/tai-khoan\/don-hang\/VVFW3REPL001$/, { timeout: 60_000 });
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });

  await page.goto("/thanh-toan");
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await submit(page).click();
  await page.getByRole("dialog").getByRole("button", { name: "Huỷ đơn cũ, đặt đơn mới" }).click();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  const newCode = page.url().split("/").pop() as string;
  expect(newCode).not.toBe("VVFW3REPL001");

  await page.goto("/tai-khoan/don-hang/VVFW3REPL001");
  await expect(page.getByText("Đơn đã được thay bằng đơn mới")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: `Xem đơn ${newCode}` })).toHaveAttribute("href", `/tai-khoan/don-hang/${newCode}`);
  await expect(page.getByRole("link", { name: /Gọi điện/ })).toHaveCount(0);
});

test("đổi giỏ giữa chừng → 409 CHECKOUT_CHANGED hiện tổng mới, phải bấm lại", async ({ browser }) => {
  const context = await browser.newContext({ baseURL: "http://api.localhost:3000" });
  const a = await context.newPage();
  await login(a, "change");
  await a.goto("/thanh-toan");
  await expect(a.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await expect(a.getByText(/270\.000/).first()).toBeVisible();
  // Tab khác gỡ mã giảm giá.
  const b = await context.newPage();
  await b.goto("/gio-hang");
  await b.getByRole("button", { name: "Gỡ mã" }).click();
  await expect(b.getByRole("button", { name: "Áp dụng" })).toBeVisible({ timeout: 60_000 });
  // Tab cũ gửi đơn với tổng cũ.
  await submit(a).click();
  const alert = a.getByRole("alert").filter({ hasText: "Giỏ hàng vừa thay đổi" });
  await expect(alert).toBeVisible({ timeout: 60_000 });
  await expect(alert).toContainText("300.000");
  await expect(a).toHaveURL(/\/thanh-toan$/);
  await submit(a).click();
  await expect(a).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  await context.close();
});

test("đơn của tôi: nhãn, hạn chờ, phân trang 10/trang, chi tiết theo từng trạng thái", async ({ page }) => {
  await login(page, "orders");
  await page.goto("/tai-khoan/don-hang");
  await expect(page.getByRole("heading", { level: 1, name: "Đơn hàng của tôi" })).toBeVisible();
  const items = orderLinks(page);
  await expect(items).toHaveCount(10, { timeout: 90_000 });
  await expect(items.first()).toContainText("VVFW3PEND001");
  await expect(items.first()).toContainText("Chờ Quản trị viên duyệt");
  await expect(items.first()).toContainText("Hạn chờ duyệt");
  await expect(page.getByText("Đã thanh toán").first()).toBeVisible();
  await expect(page.getByText("Đã huỷ bởi Quản trị viên")).toBeVisible();
  await expect(page.getByText("Đã huỷ do quá hạn chờ")).toBeVisible();
  await expect(page.getByText("Đã thay bằng đơn mới")).toBeVisible();
  await expect(page.getByText("Hạn chờ duyệt")).toHaveCount(1); // chỉ đơn pending
  await page.getByRole("link", { name: "Trang 2" }).first().click();
  await expect(page).toHaveURL(/\/tai-khoan\/don-hang\?trang=2$/);
  await expect(items).toHaveCount(2, { timeout: 90_000 });

  await page.goto("/tai-khoan/don-hang/VVFW3ADMC001");
  await expect(page.getByText("Quản trị viên đã huỷ đơn")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(/Không liên hệ được qua SĐT\./)).toBeVisible();
  await expect(page.getByRole("button", { name: "Huỷ đơn" })).toHaveCount(0);

  await page.goto("/tai-khoan/don-hang/VVFW3SUPR001");
  await expect(page.getByRole("link", { name: "Xem đơn VVFW3USRC001" })).toBeVisible({ timeout: 90_000 });

  await page.goto("/tai-khoan/don-hang/VVFW3PAID001");
  await expect(page.getByText("Các khóa trong đơn đã mở")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "Vào học" })).toBeVisible();

  await page.goto("/thanh-toan/da-gui/VVFW3PAID001"); // đơn đã duyệt: không còn hướng dẫn liên hệ
  await expect(page.getByText(/Các khóa trong đơn đã mở/)).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: /Gọi điện/ })).toHaveCount(0);

  for (const w of [375, 1280]) {
    await page.setViewportSize({ width: w, height: 800 });
    for (const path of ["/tai-khoan/don-hang", "/tai-khoan/don-hang/VVFW3PEND001", "/thanh-toan/da-gui/VVFW3PEND001", "/tai-khoan/don-hang/VVFW3ADMC001"]) {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
      await expectNoHScroll(page);
    }
  }
});

test("đơn của người khác hoặc không tồn tại → 'Không tìm thấy đơn hàng' (cùng thông điệp)", async ({ page }) => {
  await login(page, "other");
  for (const path of ["/tai-khoan/don-hang/VVFW3PEND001", "/thanh-toan/da-gui/VVFW3PEND001", "/tai-khoan/don-hang/VVFW3KHONGCO1"]) {
    await page.goto(path);
    await expect(page.getByText("Không tìm thấy đơn hàng")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("VVFW3PEND001", { exact: false })).toHaveCount(0);
  }
});

test("tự huỷ đơn: hộp xác nhận → 'Bạn đã huỷ đơn'; tải lại vẫn đã huỷ, không còn nút huỷ", async ({ page }) => {
  await login(page, "cancel");
  await page.goto("/tai-khoan/don-hang/VVFW3CANC001");
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
  await page.getByRole("button", { name: "Huỷ đơn" }).click();
  const dialog = page.getByRole("alertdialog").or(page.getByRole("dialog"));
  await expect(dialog.getByText(/Huỷ đơn VVFW3CANC001\?/)).toBeVisible();
  await expect(dialog.getByText(/Bạn đã chuyển khoản cho đơn này/)).toBeVisible();
  await dialog.getByRole("button", { name: "Không huỷ" }).click();
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible();
  await page.getByRole("button", { name: "Huỷ đơn" }).click();
  await dialog.getByRole("button", { name: "Huỷ đơn" }).click();
  await expect(page.getByText("Bạn đã huỷ đơn này")).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toHaveCount(0);
  await page.reload();
  await expect(page.getByText("Bạn đã huỷ đơn này")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("button", { name: "Huỷ đơn" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: /Gọi điện/ })).toHaveCount(0);
});

test("huỷ gặp 409 (QTV vừa duyệt, giả lập response) → thông báo, không thử lại", async ({ page }) => {
  await login(page, "race");
  await page.goto("/tai-khoan/don-hang/VVFW3RACE001");
  await expect(page.getByText("Đang chờ Quản trị viên liên hệ")).toBeVisible({ timeout: 90_000 });
  let calls = 0;
  await page.route("**/api/v1/orders/*/cancel", async (route) => {
    calls += 1;
    await route.fulfill({
      status: 409,
      contentType: "application/json",
      body: JSON.stringify({ message: "Đơn đã được Quản trị viên duyệt.", code: "ORDER_STATUS_CHANGED", errors: { status: "paid", status_reason: "manual_confirmed" } }),
    });
  });
  await page.getByRole("button", { name: "Huỷ đơn" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Huỷ đơn" }).click();
  await expect(page.getByText(/Đơn đã đổi trạng thái|Không huỷ được/)).toBeVisible({ timeout: 60_000 });
  expect(calls).toBe(1);
});

test("quá hạn mức đơn trong ngày → 429 MANUAL_ORDER_LIMIT hiện thông điệp + thời điểm đặt lại", async ({ page }) => {
  await login(page, "limit");
  await page.goto("/thanh-toan");
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await submit(page).click();
  const alert = page.getByRole("alert").filter({ hasText: "Bạn đã đặt quá nhiều đơn hôm nay" });
  await expect(alert).toBeVisible({ timeout: 60_000 });
  await expect(alert).toContainText(/Từ \d{2}:\d{2}, \d{2}\/\d{2}\/\d{4} bạn có thể đặt lại/);
  await expect(page).toHaveURL(/\/thanh-toan$/);
});

test("đơn 0đ: mã giảm 100% → 'Hoàn tất đăng ký' → đơn đã thanh toán ngay", async ({ page }) => {
  await login(page, "zero");
  await page.goto("/gio-hang");
  await page.getByLabel(/Nhập mã/).fill("E2EFW3FREE");
  await page.getByRole("button", { name: "Áp dụng" }).click();
  await expect(page.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
  await page.getByRole("link", { name: "Tiếp tục đặt mua" }).first().click();
  await expect(page.getByText("Đơn này được miễn phí nhờ mã giảm giá")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("radio")).toHaveCount(0);
  await page.getByRole("button", { name: "Hoàn tất đăng ký" }).first().click();
  await expect(page).toHaveURL(/\/thanh-toan\/da-gui\/VV\w+$/, { timeout: 90_000 });
  await expect(page.getByText(/Các khóa trong đơn đã mở/)).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "Vào học" })).toBeVisible();
});

test("chưa xác thực tài khoản → trang thanh toán hướng dẫn xác thực (403 ACCOUNT_NOT_VERIFIED)", async ({ page }) => {
  await login(page, "unv");
  await page.goto("/thanh-toan");
  await expect(page.getByText("Cần xác thực tài khoản")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "Xác thực ngay" })).toBeVisible();
});

test("giỏ: đơn chờ → cảnh báo (cả giỏ có hàng lẫn giỏ trống), không chặn thanh toán", async ({ page }) => {
  await login(page, "warn");
  await page.goto("/gio-hang");
  await expect(page.getByText(/Bạn đang có đơn VVFW3WARN001 chờ Quản trị viên duyệt/)).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "Xem đơn" })).toHaveAttribute("href", "/thanh-toan/da-gui/VVFW3WARN001");
  await expect(page.getByRole("link", { name: "Tiếp tục đặt mua" }).first()).toBeVisible();
  await page.context().clearCookies();
  await login(page, "orders"); // có đơn chờ VVFW3PEND001, giỏ trống
  await page.goto("/gio-hang");
  await expect(page.getByText("Giỏ hàng đang trống")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(/Bạn đang có đơn VVFW3PEND001 chờ Quản trị viên duyệt/)).toBeVisible();
});

test("thẻ khóa ở danh mục: Thêm vào giỏ → số giỏ header tăng → Xem giỏ hàng; khóa đã sở hữu → Vào học; 375px", async ({ page }) => {
  await login(page, "card");
  await expect(page.getByRole("link", { name: "Giỏ hàng, 1 khóa" })).toBeVisible({ timeout: 90_000 });
  await page.goto("/khoa-hoc?q=E2E+FW3");
  const add = page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Toán 9 nâng cao" });
  await expect(add).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole("link", { name: "Xem giỏ hàng: E2E FW3 Ngữ văn 9" })).toHaveAttribute("href", "/gio-hang");
  const own = page.getByRole("link", { name: "Vào học: E2E FW3 Tiếng Anh 9" });
  await expect(own).toHaveAttribute("href", /^\/hoc\/\d+/);
  await expect(page.getByRole("button", { name: /^Thêm vào giỏ: E2E FW3 Tiếng Anh/ })).toHaveCount(0);
  await add.click();
  await expect(page.getByText("Đã thêm E2E FW3 Toán 9 nâng cao vào giỏ")).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole("link", { name: "Xem giỏ hàng: E2E FW3 Toán 9 nâng cao" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Giỏ hàng, 2 khóa" })).toBeVisible({ timeout: 60_000 });
  await page.reload();
  await expect(page.getByRole("link", { name: "Xem giỏ hàng: E2E FW3 Toán 9 nâng cao" })).toBeVisible({ timeout: 90_000 });

  await page.setViewportSize({ width: 375, height: 800 });
  await expect(page.getByRole("link", { name: "Xem giỏ hàng: E2E FW3 Ngữ văn 9" })).toBeVisible();
  await expectNoHScroll(page);
  const box = await page.getByRole("link", { name: "Vào học: E2E FW3 Tiếng Anh 9" }).boundingBox();
  expect(box!.height).toBeGreaterThanOrEqual(43);
  expect(box!.x + box!.width).toBeLessThanOrEqual(375);
});

test("thẻ khóa: khách thấy 'Mua khóa học' → đăng nhập kèm next về trang khóa", async ({ page }) => {
  await page.goto("/khoa-hoc?q=E2E+FW3");
  const buy = page.getByRole("link", { name: "Mua khóa học: E2E FW3 Toán 9 nâng cao" });
  await expect(buy).toBeVisible({ timeout: 90_000 });
  await buy.click();
  await expect(page).toHaveURL(/\/dang-nhap\?next=%2Fkhoa-hoc%2Fe2e-fw3-toan/);
});
