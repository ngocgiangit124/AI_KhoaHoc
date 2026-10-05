import { expect, test, type Browser, type Page } from "@playwright/test";

/**
 * QA T03 + FW1 phần 1 — chạy với backend thật (E2E_REAL_BACKEND=1, captcha driver `fake`).
 * Dùng mock API mặc định thì các test này bị bỏ qua vì mock không hiểu /auth/*.
 */
/**
 * BUG-3 (QA): Chromium coi `localhost:3000` và `api.localhost:8000` là cross-site nên cookie SameSite=Lax
 * của API không được lưu (419 ở mọi POST). Để chạy được e2e, mở web ở `api.localhost:3000`
 * (cùng site với api.localhost:8000) và đặt backend FRONTEND_URL/SANCTUM_STATEFUL_DOMAINS tương ứng.
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

const RUN = Date.now().toString(36);
let counter = 0;
function unique() {
  counter += 1;
  const n = `${Date.now() % 100000000}`.padStart(8, "0").slice(-6) + String(counter).padStart(2, "0");
  return { email: `qa-${RUN}-${counter}@example.com`, phone: `09${n}` };
}

function dobYearsAgo(years: number): string {
  const d = new Date();
  d.setFullYear(d.getFullYear() - years);
  return d.toISOString().slice(0, 10);
}

interface Fill {
  name?: string;
  dob?: string;
  email?: string;
  phone?: string;
  grade?: string;
  password?: string;
  confirm?: string;
  parentEmail?: string;
  terms?: boolean;
  privacy?: boolean;
}

/** Đợi hydrate xong (dev server) rồi mới nhập, tránh fill trước khi React gắn handler. */
async function openPage(page: Page, url: string) {
  await page.goto(url);
  if (url.startsWith("/dang-ky")) {
    // Turnstile liên tục gọi mạng nên không bao giờ "networkidle": chờ widget (test key không có iframe) rồi nghỉ ngắn cho hydrate.
    await page.locator('[data-testid="turnstile-widget"] input[name="cf-turnstile-response"]').waitFor({ state: "attached", timeout: 20_000 });
    await page.waitForTimeout(500);
  } else {
    await page.waitForLoadState("networkidle");
  }
}

async function submitRegister(page: Page) {
  const btn = page.getByRole("button", { name: "Tạo tài khoản" });
  await expect(btn).toBeEnabled({ timeout: 15_000 });
  await btn.click();
}

async function fillRegister(page: Page, f: Fill) {
  const u = unique();
  await page.getByLabel("Họ và tên").fill(f.name ?? "Nguyễn Văn An");
  await page.getByLabel("Ngày sinh").fill(f.dob ?? dobYearsAgo(20));
  if (f.parentEmail) await page.getByLabel("Email phụ huynh").fill(f.parentEmail);
  await page.getByLabel(/^Email\s*\*?$/).fill(f.email ?? u.email);
  await page.getByLabel(/^Số điện thoại\s*\*?$/).fill(f.phone ?? u.phone);
  await page.getByLabel("Lớp đang học").selectOption(f.grade ?? "9");
  await page.getByLabel(/^Mật khẩu\s*\*?$/).fill(f.password ?? "matkhau-123");
  await page.getByLabel("Xác nhận mật khẩu").fill(f.confirm ?? f.password ?? "matkhau-123");
  if (f.terms !== false) await page.getByLabel(/Điều khoản sử dụng/).check();
  if (f.privacy !== false) await page.getByLabel(/Chính sách xử lý dữ liệu/).check();
}

async function registerOk(browser: Browser, f: Fill = {}) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const u = unique();
  const email = f.email ?? u.email;
  const phone = f.phone ?? u.phone;
  await openPage(page, "/dang-ky");
  await fillRegister(page, { ...f, email, phone });
  await submitRegister(page);
  await expect(page).toHaveURL(/\/$/);
  await ctx.close();
  return { email, phone };
}

test.describe("đăng ký", () => {
  test("AC1: đủ tuổi -> về trang chủ kèm lời nhắc xác thực", async ({ page }) => {
    await openPage(page, "/dang-ky");
    await fillRegister(page, {});
    await submitRegister(page);
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText("Vui lòng xác thực tài khoản để có thể mua khóa học")).toBeVisible();

    // F5 không hiện lại banner
    await page.reload();
    await expect(page.getByText("Vui lòng xác thực tài khoản để có thể mua khóa học")).toHaveCount(0);
  });

  test("AC10: dưới 18 tuổi hiện khối phụ huynh, thiếu -> lỗi, có -> banner chờ phụ huynh", async ({ page }) => {
    await openPage(page, "/dang-ky");
    await expect(page.getByTestId("parent-fields")).toHaveCount(0);
    await page.getByLabel("Ngày sinh").fill(dobYearsAgo(15));
    await expect(page.getByTestId("parent-fields")).toBeVisible();

    await fillRegister(page, { dob: dobYearsAgo(15) });
    await submitRegister(page);
    await expect(page.getByText(/ít nhất 1|phụ huynh/i).first()).toBeVisible();
    await expect(page).toHaveURL(/dang-ky/);

    await fillRegister(page, { dob: dobYearsAgo(15), parentEmail: "ph@example.com" });
    await submitRegister(page);
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText(/email xác nhận tới phụ huynh/)).toBeVisible();
  });

  test("AC2: email/SĐT trùng -> lỗi dưới field, giữ dữ liệu, xoá mật khẩu", async ({ page, browser }) => {
    const first = await registerOk(browser);
    await openPage(page, "/dang-ky");
    await fillRegister(page, { email: first.email, phone: first.phone });
    await submitRegister(page);

    await expect(page.getByText("Email đã được sử dụng.")).toBeVisible();
    await expect(page.getByText("Số điện thoại đã được sử dụng.")).toBeVisible();
    await expect(page.getByLabel("Họ và tên")).toHaveValue("Nguyễn Văn An");
    await expect(page.getByLabel(/^Mật khẩu\s*\*?$/)).toHaveValue("");
    await expect(page).toHaveURL(/dang-ky/);
  });

  test("AC5: mật khẩu xác nhận không khớp -> lỗi tại field xác nhận", async ({ page }) => {
    await openPage(page, "/dang-ky");
    await fillRegister(page, { password: "matkhau-123", confirm: "matkhau-124" });
    await submitRegister(page);
    const field = page.getByLabel("Xác nhận mật khẩu");
    await expect(field).toHaveAttribute("aria-invalid", "true");
    await expect(page).toHaveURL(/dang-ky/);
  });

  test("US-017: thiếu 1 trong 2 đồng ý -> lỗi, không tạo tài khoản", async ({ page }) => {
    await openPage(page, "/dang-ky");
    await fillRegister(page, { privacy: false });
    await submitRegister(page);
    await expect(page.getByText(/đồng ý/i).last()).toBeVisible();
    await expect(page).toHaveURL(/dang-ky/);
  });

  test("Trường bắt buộc rỗng -> lỗi từng field, không gọi API", async ({ page }) => {
    const calls: string[] = [];
    page.on("request", (r) => {
      if (r.url().includes("/auth/register")) calls.push(r.url());
    });
    await openPage(page, "/dang-ky");
    await submitRegister(page);
    await expect(page.getByLabel("Họ và tên")).toHaveAttribute("aria-invalid", "true");
    expect(calls).toHaveLength(0);
  });

  test("Họ tên chứa HTML được lưu và hiển thị nguyên văn, không thực thi script", async ({ page }) => {
    let dialog = false;
    page.on("dialog", () => {
      dialog = true;
    });
    await openPage(page, "/dang-ky");
    await fillRegister(page, { name: '<img src=x onerror=alert(1)>"><script>alert(2)</script>' });
    await submitRegister(page);
    await expect(page).toHaveURL(/\/$/);
    expect(dialog).toBe(false);
  });
});

test.describe("đăng nhập / đăng xuất", () => {
  test("AC3: đăng nhập bằng email và bằng SĐT", async ({ browser }) => {
    const acct = await registerOk(browser);

    for (const login of [acct.email, acct.phone]) {
      const ctx = await browser.newContext();
      const page = await ctx.newPage();
      await openPage(page, "/dang-nhap");
      await page.getByLabel("Email hoặc số điện thoại").fill(login);
      await page.getByLabel(/^Mật khẩu\s*\*?$/).fill("matkhau-123");
      await page.getByRole("button", { name: "Đăng nhập" }).click();
      await expect(page).toHaveURL(/\/$/);
      await ctx.close();
    }
  });

  test("AC4: sai thông tin -> lỗi chung, không lộ tài khoản tồn tại, xoá mật khẩu", async ({ page, browser }) => {
    const acct = await registerOk(browser);

    for (const login of [acct.email, "khongco-" + RUN + "@example.com"]) {
      await openPage(page, "/dang-nhap");
      await page.getByLabel("Email hoặc số điện thoại").fill(login);
      await page.getByLabel(/^Mật khẩu\s*\*?$/).fill("sai-mat-khau-1");
      await page.getByRole("button", { name: "Đăng nhập" }).click();
      await expect(page.getByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeVisible();
      await expect(page.getByLabel(/^Mật khẩu\s*\*?$/)).toHaveValue("");
    }
  });

  test("WRONG_PORTAL: tài khoản giáo viên đăng nhập ở cổng học sinh", async ({ page }) => {
    await openPage(page, "/dang-nhap");
    await page.getByLabel("Email hoặc số điện thoại").fill("teacher@vitaminvui.test");
    await page.getByLabel(/^Mật khẩu\s*\*?$/).fill("password");
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page.getByRole("alert").filter({ hasText: "không đăng nhập ở trang học sinh" })).toBeVisible();
    await expect(page).toHaveURL(/dang-nhap/);
  });

  for (const evil of ["//evil.com", "/\\evil.com", "%2F%2Fevil.com", "https://evil.com", "javascript:alert(1)"]) {
    test(`?next=${evil} không dẫn ra ngoài`, async ({ browser }) => {
      const acct = await registerOk(browser);
      const ctx = await browser.newContext();
      const page = await ctx.newPage();
      await openPage(page, `/dang-nhap?next=${evil}`);
      await page.getByLabel("Email hoặc số điện thoại").fill(acct.email);
      await page.getByLabel(/^Mật khẩu\s*\*?$/).fill("matkhau-123");
      await page.getByRole("button", { name: "Đăng nhập" }).click();
      await page.waitForURL((u) => !u.pathname.startsWith("/dang-nhap"));
      expect(new URL(page.url()).origin).toBe(new URL(BASE).origin);
      await ctx.close();
    });
  }

  test("?next=/dang-ky (đường dẫn nội bộ hợp lệ) được giữ", async ({ browser }) => {
    const acct = await registerOk(browser);
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await openPage(page, "/dang-nhap?next=/dang-nhap%3Fx%3D1");
    await page.getByLabel("Email hoặc số điện thoại").fill(acct.email);
    await page.getByLabel(/^Mật khẩu\s*\*?$/).fill("matkhau-123");
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page).toHaveURL(/x=1/);
    await ctx.close();
  });
});

test.describe("CSP và giao diện", () => {
  test("CSP: /dang-ky có Cloudflare ở connect-src/frame-src, không có ở script-src; / thì không", async ({ page }) => {
    const reg = await page.goto("/dang-ky");
    const csp = reg?.headers()["content-security-policy"] ?? "";
    expect(csp).toContain("nonce-");
    expect(csp).toMatch(/connect-src[^;]*challenges\.cloudflare\.com/);
    expect(csp).toMatch(/frame-src[^;]*challenges\.cloudflare\.com/);
    expect(csp.match(/script-src[^;]*/)?.[0]).not.toContain("cloudflare");
    expect(csp).not.toContain("unsafe-inline'; style") ;

    const home = await page.goto("/");
    expect(home?.headers()["content-security-policy"] ?? "").not.toContain("cloudflare");

    const login = await page.goto("/dang-nhap");
    expect(login?.headers()["content-security-policy"] ?? "").not.toContain("cloudflare");
  });

  test("Không có vi phạm CSP trong console ở /dang-ky, /dang-nhap", async ({ page }) => {
    const violations: string[] = [];
    page.on("console", (m) => {
      if (/Content Security Policy|violates the following/i.test(m.text())) violations.push(m.text());
    });
    await openPage(page, "/dang-ky");
    await page.waitForTimeout(3000);
    await openPage(page, "/dang-nhap");
    expect(violations).toEqual([]);
  });

  for (const path of ["/dang-ky", "/dang-nhap", "/"]) {
    test(`375px: ${path} không tràn ngang`, async ({ page }, info) => {
      await page.setViewportSize({ width: 375, height: 800 });
      await page.goto(path);
      if (path === "/dang-ky") await page.getByLabel("Ngày sinh").fill(dobYearsAgo(15));
      await page.waitForTimeout(500);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow).toBeLessThanOrEqual(0);
      await page.screenshot({
        path: info.outputPath(`375${path.replace("/", "-") || "-home"}.png`),
        fullPage: true,
      });
    });
  }
});
