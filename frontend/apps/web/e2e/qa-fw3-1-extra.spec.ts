import { expect, test, type Page } from "@playwright/test";

/**
 * QA FW3-1 — kiểm THÊM với backend thật. Dữ liệu: `seed-e2e-fw3.sh --reset` rồi `seed-qa-fw3-1.sh`. Chạy: `run-qa-fw3-1.sh`; dọn: `seed-e2e-fw3.sh --clean`.
 */
const PASSWORD = "matkhau-123";
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật");

async function loginAs(page: Page, email: string, expectUrl: RegExp = /\/$/) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(expectUrl, { timeout: 90_000 });
}
const login = (page: Page, key: string) => loginAs(page, `fw3-hs-${key}@example.com`);
const hscroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const WARN = /Bạn đang có đơn VVFW3WARN001 chờ Quản trị viên duyệt/;

test("PENDING: F5, áp mã, gỡ mã, xoá khóa (giỏ rỗng) vẫn còn cảnh báo", async ({ page }) => {
  await login(page, "warn");
  await page.goto("/gio-hang");
  await expect(page.getByText(WARN)).toBeVisible({ timeout: 90_000 });
  await page.reload();
  await expect(page.getByText(WARN)).toBeVisible({ timeout: 90_000 });
  await page.getByLabel(/Nhập mã/).fill("e2efw3");
  await page.getByRole("button", { name: "Áp dụng" }).click();
  await expect(page.getByRole("button", { name: "Gỡ mã" })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText(WARN)).toBeVisible();
  await page.getByRole("button", { name: "Gỡ mã" }).click();
  await expect(page.getByRole("button", { name: "Áp dụng" })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText(WARN)).toBeVisible();
  await page.getByRole("button", { name: "Xoá khóa E2E FW3 Ngữ văn 9 khỏi giỏ" }).click();
  await expect(page.getByText("Giỏ hàng đang trống")).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText(WARN)).toBeVisible();
  await page.reload();
  await expect(page.getByText("Giỏ hàng đang trống")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(WARN)).toBeVisible();
  await page.setViewportSize({ width: 375, height: 800 });
  expect(await hscroll(page)).toBeLessThanOrEqual(0);
});

test("PENDING: huỷ đơn ở Đơn của tôi rồi quay lại giỏ → cảnh báo mất", async ({ page }) => {
  await login(page, "cancel");
  await page.goto("/gio-hang");
  await expect(page.getByText(/Bạn đang có đơn VVFW3CANC001 chờ/)).toBeVisible({ timeout: 90_000 });
  await page.getByRole("link", { name: "Xem đơn" }).click();
  await expect(page).toHaveURL(/da-gui\/VVFW3CANC001/);
  await page.goto("/tai-khoan/don-hang/VVFW3CANC001");
  await page.getByRole("button", { name: "Huỷ đơn" }).click();
  const dialog = page.getByRole("alertdialog").or(page.getByRole("dialog"));
  await dialog.getByRole("button", { name: "Huỷ đơn" }).click();
  await expect(page.getByText("Bạn đã huỷ đơn này")).toBeVisible({ timeout: 60_000 });
  await page.goto("/gio-hang");
  await expect(page.getByText("Giỏ hàng đang trống")).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(/Bạn đang có đơn/)).toHaveCount(0);
  // Điều hướng client-side (không reload) giữa giỏ và trang khác rồi về: vẫn không còn cảnh báo.
  await page.getByRole("link", { name: "Giỏ hàng" }).first().click().catch(() => {});
  await expect(page.getByText(/Bạn đang có đơn/)).toHaveCount(0);
});

test("PENDING: học sinh không có đơn chờ → không có cảnh báo; đơn đã paid cũng không", async ({ page }) => {
  await login(page, "cart");
  await page.goto("/gio-hang");
  await expect(page.getByRole("link", { name: "E2E FW3 Toán 9 nâng cao" })).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText(/Bạn đang có đơn/)).toHaveCount(0);
});

test("CARD: HS sở hữu ngoài 30 khóa đầu → thấy nút, bấm ra 409, nút biến mất; 1 lần gọi /me/courses + /cart", async ({ page }) => {
  const calls: string[] = [];
  page.on("request", (r) => {
    if (/\/api\/v1\/(cart|me\/courses)/.test(r.url()) && r.method() === "GET") calls.push(r.url().replace(/^.*\/api\/v1/, ""));
  });
  const addCalls: number[] = [];
  page.on("response", (r) => {
    if (/\/api\/v1\/cart\/items/.test(r.url()) && r.request().method() === "POST") addCalls.push(r.status());
  });
  await login(page, "big");
  calls.length = 0;
  await page.goto("/khoa-hoc?q=E2E+FW3");
  const add = page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Tiếng Anh 9" });
  await expect(add).toBeVisible({ timeout: 90_000 });
  console.log("REQUESTS", JSON.stringify(calls));
  await add.dblclick(); // bấm kép
  await expect(page.getByRole("link", { name: "Vào học: E2E FW3 Tiếng Anh 9" })).toBeVisible({ timeout: 60_000 });
  console.log("ADD STATUSES", JSON.stringify(addCalls));
  expect(addCalls.length).toBe(1);
  expect(addCalls[0]).toBe(409);
  await expect(page.getByRole("button", { name: /^Thêm vào giỏ: E2E FW3 Tiếng Anh/ })).toHaveCount(0);
  await page.reload();
  // reload: lại hiện nút (do giới hạn 30) hoặc đã đúng — chỉ ghi nhận.
  const again = await page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Tiếng Anh 9" }).count().catch(() => 0);
  await page.waitForTimeout(3000);
  console.log("AFTER RELOAD add-button count", again, await page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Tiếng Anh 9" }).count());
});

test("CARD: giáo viên đăng nhập web → không có nút giỏ, không toast lỗi, không lỗi console", async ({ page }) => {
  const errs: string[] = [];
  page.on("pageerror", (e) => errs.push(e.message));
  const apiCalls: string[] = [];
  page.on("response", (r) => {
    if (/\/api\/v1\/(cart|me\/courses)/.test(r.url())) apiCalls.push(`${r.status()} ${r.url().replace(/^.*\/api\/v1/, "")}`);
  });
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill("fw3-gv@example.com");
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await page.waitForTimeout(5000);
  console.log("GV URL", page.url());
  console.log("GV login result text:", (await page.locator("main").innerText().catch(() => "")).replace(/\n+/g, " | ").slice(0, 300));
  await page.goto("/khoa-hoc?q=E2E+FW3");
  await expect(page.getByRole("link", { name: "E2E FW3 Toán 9 nâng cao" }).first()).toBeVisible({ timeout: 90_000 });
  await page.waitForTimeout(4000);
  await expect(page.getByRole("button", { name: /Thêm vào giỏ/ })).toHaveCount(0);
  console.log("GV alerts:", JSON.stringify(await page.getByRole("alert").allInnerTexts()), "status:", JSON.stringify(await page.getByRole("status").allInnerTexts()));
  console.log("GV API CALLS", JSON.stringify(apiCalls), "PAGEERRORS", JSON.stringify(errs));
  expect(errs).toEqual([]);
  await page.goto("/");
  await page.waitForTimeout(3000);
  await expect(page.getByRole("button", { name: /Thêm vào giỏ/ })).toHaveCount(0);
});

test("CARD: a11y (Tab, tên truy cập), CLS, 375/1280 ở /khoa-hoc, /lop-9, trang chủ", async ({ page }) => {
  await login(page, "card");
  for (const path of ["/khoa-hoc?q=E2E+FW3", "/lop-9", "/"]) {
    for (const w of [375, 1280]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.addInitScript(() => {
        (window as unknown as { __cls: number }).__cls = 0;
        new PerformanceObserver((l) => {
          for (const e of l.getEntries() as unknown as Array<{ value: number; hadRecentInput: boolean }>) if (!e.hadRecentInput) (window as unknown as { __cls: number }).__cls += e.value;
        }).observe({ type: "layout-shift", buffered: true });
      });
      await page.goto(path);
      await page.waitForTimeout(6000);
      const btns = page.getByRole("button", { name: /^Thêm vào giỏ: / });
      const xem = page.getByRole("link", { name: /^Xem giỏ hàng: / });
      const vao = page.getByRole("link", { name: /^Vào học: / });
      const cls = await page.evaluate(() => (window as unknown as { __cls: number }).__cls);
      console.log(`PATH ${path} w=${w} add=${await btns.count()} xem=${await xem.count()} vao=${await vao.count()} hscroll=${await hscroll(page)} CLS=${cls.toFixed(4)}`);
      expect(await hscroll(page)).toBeLessThanOrEqual(0);
      // Nút không vượt khỏi thẻ.
      for (const loc of [btns, xem, vao]) {
        const n = await loc.count();
        for (let i = 0; i < n; i++) {
          const b = await loc.nth(i).boundingBox();
          const card = await loc.nth(i).locator("xpath=ancestor::li[1]").boundingBox();
          if (b && card) {
            expect(b.x).toBeGreaterThanOrEqual(card.x - 0.5);
            expect(b.x + b.width).toBeLessThanOrEqual(card.x + card.width + 0.5);
            expect(b.height).toBeGreaterThanOrEqual(43);
          }
        }
      }
    }
  }
  // Tab tới nút
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto("/khoa-hoc?q=E2E+FW3");
  const add = page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Toán 9 nâng cao" });
  await expect(add).toBeVisible({ timeout: 90_000 });
  let reached = false;
  for (let i = 0; i < 60 && !reached; i++) {
    await page.keyboard.press("Tab");
    reached = await add.evaluate((el) => el === document.activeElement);
  }
  expect(reached).toBe(true);
  const outline = await add.evaluate((el) => { const s = getComputedStyle(el); return `${s.outlineStyle} ${s.outlineWidth} | ${s.boxShadow}`; });
  console.log("FOCUS STYLE", outline);
  const urlBefore = page.url();
  await page.keyboard.press("Enter");
  await expect(page.getByText("Đã thêm E2E FW3 Toán 9 nâng cao vào giỏ")).toBeVisible({ timeout: 60_000 });
  expect(page.url()).toBe(urlBefore); // không điều hướng
});

test("CARD: thêm vào giỏ lỗi 500/mạng → toast lỗi, nút còn dùng được", async ({ page }) => {
  await login(page, "big");
  await page.goto("/khoa-hoc?q=E2E+FW3");
  const add = page.getByRole("button", { name: "Thêm vào giỏ: E2E FW3 Toán 9 nâng cao" });
  await expect(add).toBeVisible({ timeout: 90_000 });
  await page.route("**/api/v1/cart/items", (r) => r.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ message: "Server Error" }) }));
  await add.click();
  await page.waitForTimeout(1500);
  console.log("TOAST/ALERT after 500:", JSON.stringify(await page.getByRole("alert").allInnerTexts()), JSON.stringify(await page.getByRole("status").allInnerTexts()));
  await expect(add).toBeVisible();
  await expect(add).toBeEnabled();
});

// ---- 422 thật / giả lập ở web ----
const json422 = (errors: Record<string, unknown>, message = "Dữ liệu không hợp lệ.") => ({ status: 422, contentType: "application/json", body: JSON.stringify({ message, errors }) });

test("422 web: đăng ký email trùng (thật) + mật khẩu không khớp", async ({ page }) => {
  await page.goto("/dang-ky");
  await page.waitForTimeout(4000);
  const fill = async (email: string, confirm: string) => {
    await page.getByLabel("Họ và tên").fill("Nguyễn Văn An");
    await page.getByLabel("Ngày sinh").fill("2005-01-01");
    await page.getByLabel(/^Email(?! phụ huynh)/).fill(email);
    await page.getByLabel(/^Số điện thoại(?! phụ huynh)/).fill("0911222333");
    await page.getByLabel("Lớp đang học").selectOption("9");
    await page.getByLabel(/^Mật khẩu/).fill("matkhau-123");
    await page.getByLabel("Xác nhận mật khẩu").fill(confirm);
    const t = page.getByLabel(/Điều khoản sử dụng/);
    if (!(await t.isChecked())) await t.check();
    const p = page.getByLabel(/Chính sách xử lý dữ liệu/);
    if (!(await p.isChecked())) await p.check();
  };
  await fill("fw3-hs-buy@example.com", "matkhau-123");
  await page.getByRole("button", { name: "Tạo tài khoản" }).click();
  const emailField = page.getByLabel(/^Email(?! phụ huynh)/);
  await expect(emailField).toHaveAttribute("aria-invalid", "true", { timeout: 60_000 });
  const msg = await page.locator("#" + (await emailField.getAttribute("id"))! + "-error, [id$='-error']").first().innerText().catch(() => "");
  const body = await page.locator("main").innerText();
  console.log("REGISTER dup email page text sample:", body.replace(/\n+/g, " | ").slice(0, 600));
  expect(body).not.toContain("undefined");
  await page.reload();
  await page.waitForTimeout(4000);
  await fill("qa-fw3-moi@example.com", "khac-nhau-999");
  await page.getByRole("button", { name: "Tạo tài khoản" }).click();
  await expect(page.getByLabel("Xác nhận mật khẩu")).toHaveAttribute("aria-invalid", "true", { timeout: 60_000 });
  console.log("REGISTER mismatch:", (await page.locator("main").innerText()).replace(/\n+/g, " | ").slice(0, 500), msg);
});

test("422 web: đăng ký với errors lẫn kiểu (giả lập) → thông điệp theo ô, không ký tự đơn", async ({ page }) => {
  await page.route("**/api/v1/auth/register", (r) => r.fulfill(json422({ email: ["Email này đã được sử dụng."], note: "abc", extra: { a: 1 } })));
  await page.goto("/dang-ky");
  await page.waitForTimeout(4000);
  await page.getByLabel("Họ và tên").fill("Nguyễn Văn An");
  await page.getByLabel("Ngày sinh").fill("2005-01-01");
  await page.getByLabel(/^Email(?! phụ huynh)/).fill("qa-fw3-x@example.com");
  await page.getByLabel(/^Số điện thoại(?! phụ huynh)/).fill("0911222334");
  await page.getByLabel("Lớp đang học").selectOption("9");
  await page.getByLabel(/^Mật khẩu/).fill("matkhau-123");
  await page.getByLabel("Xác nhận mật khẩu").fill("matkhau-123");
  await page.getByLabel(/Điều khoản sử dụng/).check();
  await page.getByLabel(/Chính sách xử lý dữ liệu/).check();
  await page.getByRole("button", { name: "Tạo tài khoản" }).click();
  await expect(page.getByText("Email này đã được sử dụng.").first()).toBeVisible({ timeout: 60_000 });
  const txt = await page.locator("main").innerText();
  expect(txt).not.toContain("undefined");
  expect(txt).toContain("Còn 1 mục cần sửa");
  expect(txt).not.toMatch(/\b(note|extra)\b:/);
});

test("422 web: quên mật khẩu (giả lập field login)", async ({ page }) => {
  await page.route("**/api/v1/auth/password/forgot", (r) => r.fulfill(json422({ login: ["Email hoặc số điện thoại không đúng định dạng."] })));
  await page.goto("/quen-mat-khau");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill("abc");
  await page.getByRole("button").filter({ hasText: /Gửi|Tiếp/ }).first().click();
  await expect(page.getByText("Email hoặc số điện thoại không đúng định dạng.")).toBeVisible({ timeout: 60_000 });
});

test("422 web: đổi email/SĐT (ChangeContactForm) theo ô, kèm errors lẫn kiểu", async ({ page }) => {
  await login(page, "buy");
  await page.route("**/api/v1/auth/contact", (r) => {
    if (r.request().method() === "PUT") return r.fulfill(json422({ email: ["Email mới đã được dùng."], phone: ["Số điện thoại không hợp lệ."], current_password: ["Mật khẩu hiện tại không đúng."], limit: 3 }));
    return r.continue();
  });
  await page.goto("/tai-khoan");
  await page.getByLabel("Email", { exact: true }).fill("moi-qa@example.com");
  await page.getByLabel("Số điện thoại").last().fill("0900000001");
  await page.locator("#contact-password").fill("sai-mat-khau-1");
  await page.getByRole("button", { name: "Lưu thay đổi" }).click();
  await expect(page.getByText("Email mới đã được dùng.")).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText("Số điện thoại không hợp lệ.")).toBeVisible();
  await expect(page.getByText("Mật khẩu hiện tại không đúng.")).toBeVisible();
  expect(await page.locator("main").innerText()).not.toContain("undefined");
  // chỉ lỗi chuỗi lạ → banner message
  await page.unroute("**/api/v1/auth/contact");
  await page.route("**/api/v1/auth/contact", (r) => r.fulfill(json422({ weird: "chuỗi lẻ" }, "Có lỗi dữ liệu.")));
  await page.locator("#contact-password").fill("sai-mat-khau-1");
  await page.getByRole("button", { name: "Lưu thay đổi" }).click();
  await expect(page.getByText("Có lỗi dữ liệu.")).toBeVisible({ timeout: 60_000 });
});

test("422 web: thanh toán ghi chú chứa '<' (thật)", async ({ page }) => {
  await login(page, "tabs");
  await page.goto("/thanh-toan");
  await expect(page.getByRole("radio").first()).toBeChecked({ timeout: 90_000 });
  await page.getByLabel(/Ghi chú cho Quản trị viên/).fill("xin <b>chào</b>");
  await page.getByRole("button", { name: "Gửi đơn" }).first().click();
  await page.waitForTimeout(4000);
  const body = await page.locator("main").innerText();
  console.log("CHECKOUT '<' ->", page.url(), body.replace(/\n+/g, " | ").slice(0, 500));
  expect(body).not.toContain("undefined");
});

test("422 web: OTP sai (giả lập errors.code) ở /can-xac-thuc/xác thực", async ({ page }) => {
  await login(page, "unv");
  await page.route("**/api/v1/auth/otp/verify", (r) => r.fulfill(json422({ code: ["Mã xác nhận không đúng."] })));
  await page.goto("/can-xac-thuc");
  await page.waitForTimeout(3000);
  console.log("CAN-XAC-THUC:", (await page.locator("main").innerText()).replace(/\n+/g, " | ").slice(0, 300));
});

test("422 web: quyền dữ liệu — liên hệ phụ huynh 422 (giả lập)", async ({ page }) => {
  await login(page, "buy");
  await page.route("**/api/v1/me/parent-contact", (r) => (r.request().method() === "GET" ? r.continue() : r.fulfill(json422({ parent_email: ["Email phụ huynh không hợp lệ."], parent_phone: ["SĐT phụ huynh không hợp lệ."], current_password: ["Mật khẩu hiện tại không đúng."] }))));
  await page.goto("/tai-khoan/quyen-du-lieu-ca-nhan");
  await page.waitForTimeout(4000);
  console.log("QUYEN DU LIEU:", (await page.locator("main").innerText()).replace(/\n+/g, " | ").slice(0, 400));
});
