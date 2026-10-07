import { expect, test, type Page } from "@playwright/test";

/**
 * FW2 — danh mục `/khoa-hoc`, `/lop-{grade}`, chi tiết `/khoa-hoc/{slug}` với backend thật
 * (E2E_REAL_BACKEND=1). Dữ liệu: `e2e/seed-e2e-catalog.sh` (tiền tố `e2e-fw2-`, học sinh fw2-hs-*).
 * Chạy lại: seed lại (test đăng ký miễn phí đổi trạng thái của fw2-hs-ok).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const FREE = "e2e-fw2-hinh-hoc-9-mien-phi";
const PAID = "e2e-fw2-dai-so-9-tra-phi";
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1", "Cần backend thật (E2E_REAL_BACKEND=1)");

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu\s*\*?$/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
}

const cards = (page: Page) => page.locator("article");

test.describe("Danh mục /khoa-hoc", () => {
  test("lọc theo lớp + chuyên đề giữ trên URL, F5 không mất; tìm không dấu; rỗng + xoá bộ lọc", async ({ page }) => {
    await page.goto("/khoa-hoc");
    await expect(page.getByRole("heading", { level: 1, name: "Khóa học Toán" })).toBeVisible();
    await expect(cards(page).first()).toBeVisible();

    // Lớp 9 (AC1)
    await page.getByRole("link", { name: "Lớp 9", exact: true }).click();
    await expect(page).toHaveURL(/grade=9/);
    await expect(page.getByRole("link", { name: "Lớp 9", exact: true })).toHaveAttribute("aria-current", "page");
    for (const badge of await cards(page).locator("span", { hasText: /^Lớp \d+$/ }).allTextContents()) {
      expect(badge).toBe("Lớp 9");
    }

    // + chuyên đề Hình học (AC2): chỉ còn khóa lớp 9 thuộc Hình học
    await page.getByLabel("E2E FW2 Hình học").check();
    await expect(page).toHaveURL(/subject_ids=\d+/);
    await expect(cards(page).filter({ hasText: "E2E FW2 Hình học lớp 9 miễn phí" })).toHaveCount(1);
    await expect(cards(page).filter({ hasText: "Đại số lớp 9" })).toHaveCount(0);

    // F5 giữ trạng thái
    await page.reload();
    await expect(page.getByLabel("E2E FW2 Hình học")).toBeChecked();
    await expect(page.getByRole("link", { name: "Lớp 9", exact: true })).toHaveAttribute("aria-current", "page");

    // Tìm không dấu, hoa/thường (AC5)
    await page.goto("/khoa-hoc");
    await page.getByRole("searchbox", { name: "Tìm trong danh mục khóa học" }).fill("HINH HOC lop 9");
    await page.getByRole("button", { name: "Tìm", exact: true }).click();
    await expect(page).toHaveURL(/q=HINH\+HOC\+lop\+9/);
    await expect(cards(page).filter({ hasText: "E2E FW2 Hình học lớp 9 miễn phí" })).toHaveCount(1);

    // Rỗng do lọc (AC3) + Xoá bộ lọc
    await page.getByRole("searchbox", { name: "Tìm trong danh mục khóa học" }).fill("khongcokhoanaotenvay");
    await page.getByRole("button", { name: "Tìm", exact: true }).click();
    await expect(page.getByText("Không tìm thấy khóa học phù hợp")).toBeVisible();
    await expect(page.getByText(/Hãy thử bỏ bớt chuyên đề/)).toBeVisible();
    await page.getByRole("link", { name: "Xoá bộ lọc" }).click();
    await expect(page).toHaveURL(/\/khoa-hoc$/);
    await expect(cards(page).first()).toBeVisible();
  });

  test("sắp xếp đổi URL; thẻ khóa có giá/Miễn phí, ảnh null có khung thay thế", async ({ page }) => {
    await page.goto("/khoa-hoc?q=E2E+FW2+lop+9");
    const free = cards(page).filter({ hasText: "Hình học lớp 9 miễn phí" });
    await expect(free.getByText("Miễn phí").first()).toBeVisible();
    await expect(free.getByText(/Ảnh bìa khóa/)).toBeAttached();
    await expect(cards(page).filter({ hasText: "Đại số lớp 9 trả phí" })).toContainText("299.000");
    await page.getByLabel("Sắp xếp").selectOption("popular");
    await expect(page).toHaveURL(/sort=popular/);
  });

  test("tham số sai trên URL bị bỏ qua êm, không lỗi", async ({ page }) => {
    const res = await page.goto("/khoa-hoc?grade=99&sort=lạ&page=-3&subject_ids=abc");
    expect(res?.status()).toBe(200);
    await expect(cards(page).first()).toBeVisible();
  });

  test("phân trang 25/trang: /lop-10 có >25 khóa, link trang 2 hoạt động", async ({ page }) => {
    await page.goto("/lop-10");
    await expect(cards(page)).toHaveCount(25);
    const nav = page.getByRole("navigation", { name: "Phân trang" });
    await expect(nav).toBeVisible();
    await nav.getByRole("link", { name: "Trang 2" }).click();
    await expect(page).toHaveURL(/\/lop-10\?page=2/);
    expect(await cards(page).count()).toBeGreaterThan(0);
    expect(await cards(page).count()).toBeLessThan(25);
    await expect(nav.getByRole("link", { name: "Trước" })).toBeVisible();
  });
});

test.describe("/lop-{grade}", () => {
  test("lop-9: H1, mô tả SEO, title, canonical, breadcrumb, không có chip lớp; lọc thêm theo chuyên đề", async ({ page }) => {
    await page.goto("/lop-9");
    await expect(page.getByRole("heading", { level: 1, name: "Khóa học Toán lớp 9" })).toBeVisible();
    await expect(page).toHaveTitle(/Khóa học Toán lớp 9/);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute("content", /lớp 9/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", /\/lop-9$/);
    await expect(page.getByRole("navigation", { name: "Đường dẫn" })).toContainText("Lớp 9");
    await expect(page.getByRole("link", { name: "Lớp 8", exact: true })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Xem lớp khác" })).toBeVisible();

    await page.getByLabel("E2E FW2 Đại số").check();
    await expect(page).toHaveURL(/\/lop-9\?subject_ids=\d+/);
    await expect(cards(page).filter({ hasText: "E2E FW2 Đại số lớp 9 trả phí" })).toHaveCount(1);
    await expect(cards(page).filter({ hasText: "Hình học lớp 9" })).toHaveCount(0);
  });

  for (const bad of ["lop-99", "lop-5", "lop-13", "lop-09", "lop-abc"]) {
    test(`${bad} -> HTTP 404 + trang không tìm thấy`, async ({ page }) => {
      const res = await page.goto(`/${bad}`);
      expect(res?.status()).toBe(404);
      await expect(page.getByText("Không tìm thấy trang")).toBeVisible();
    });
  }
});

test.describe("Chi tiết khóa học (khách)", () => {
  test("nội dung đầy đủ, giáo viên, outline, mô tả đã lọc XSS, JSON-LD có nonce", async ({ page }) => {
    const xssDialogs: string[] = [];
    page.on("dialog", (d) => {
      xssDialogs.push(d.message());
      void d.dismiss();
    });
    const res = await page.goto(`/khoa-hoc/${FREE}`);
    expect(res?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "E2E FW2 Hình học lớp 9 miễn phí" })).toBeVisible();
    await expect(page).toHaveTitle(/E2E FW2 Hình học lớp 9 miễn phí/);

    // AC6: tất cả giáo viên
    await expect(page.getByText("E2E FW2 Cô Lan")).toBeVisible();
    await expect(page.getByText("E2E FW2 Thầy Minh")).toBeVisible();

    // AC1: outline chương/bài + thời lượng; bài preview có nhãn
    await expect(page.getByRole("heading", { name: "Nội dung khóa học" })).toBeVisible();
    await expect(page.getByText(/2 chương · 6 bài ·/)).toBeVisible();
    await expect(page.getByText("Bài 1.1")).toBeVisible();
    await expect(page.getByText("Học thử", { exact: true })).toHaveCount(1);
    // AC8-edge: 0 học sinh -> câu chữ tích cực (desktop card + mobile ẩn)
    await expect(page.getByText("Chưa có học sinh đăng ký").first()).toBeAttached();

    // Mô tả: giữ nội dung an toàn, mất script/onerror/javascript:
    const desc = page.locator("#course-desc").locator("xpath=..");
    await expect(desc.getByText("Giới thiệu", { exact: true })).toBeVisible();
    await expect(desc.locator("script, img, iframe")).toHaveCount(0);
    expect(await desc.locator("[onerror], [onclick]").count()).toBe(0);
    expect(await desc.locator('a[href^="javascript:" i]').count()).toBe(0);
    const ok = desc.getByRole("link", { name: "tài liệu" });
    await expect(ok).toHaveAttribute("href", "https://example.com/tai-lieu");
    await expect(ok).toHaveAttribute("rel", /noopener/);
    expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
    expect(xssDialogs).toEqual([]);

    // "Xem thêm" cho mô tả dài
    const more = desc.getByRole("button", { name: "Xem thêm" });
    await expect(more).toBeVisible();
    await more.click();
    await expect(desc.getByRole("button", { name: "Thu gọn" })).toBeVisible();

    // JSON-LD: có nonce khớp CSP, parse được, đúng @type
    const csp = res?.headers()["content-security-policy"] ?? "";
    const nonce = /'nonce-([^']+)'/.exec(csp)?.[1];
    expect(nonce).toBeTruthy();
    const ld = page.locator('script[type="application/ld+json"]');
    await expect(ld).toHaveCount(1);
    // trình duyệt ẩn thuộc tính nonce sau khi parse (property `nonce` còn, attribute rỗng) — đọc qua property
    expect(await ld.evaluate((el) => (el as HTMLScriptElement).nonce)).toBe(nonce);
    const json = JSON.parse((await ld.textContent()) ?? "{}") as { "@type": string; name: string; offers: { price: number } };
    expect(json["@type"]).toBe("Course");
    expect(json.name).toBe("E2E FW2 Hình học lớp 9 miễn phí");
    expect(json.offers.price).toBe(0);
  });

  test("bài khoá là hàng tĩnh kèm câu giải thích; bài học thử có nhãn", async ({ page }) => {
    await page.goto(`/khoa-hoc/${PAID}`);
    await expect(page.getByText(/Bài có biểu tượng khoá mở khi bạn sở hữu khóa học/)).toBeVisible();
    await expect(page.getByRole("button", { name: /Bài 1\.2/ })).toHaveCount(0);
    await expect(page.getByRole("link", { name: /Bài 1\.2/ })).toHaveCount(0);
    await expect(page.getByText("Học thử", { exact: true })).toHaveCount(1);
  });

  test("khách: khóa miễn phí -> link đăng nhập giữ next; khóa có phí (thanh toán tạm khoá) -> giá + Sắp mở bán, không nút mua", async ({ page }) => {
    await page.goto(`/khoa-hoc/${FREE}`);
    const link = page.locator("#course-cta").getByRole("link", { name: "Đăng ký học miễn phí" });
    await expect(link).toBeVisible();
    await link.click();
    await expect(page).toHaveURL(new RegExp(`/dang-nhap\\?next=%2Fkhoa-hoc%2F${FREE}`));

    await page.goto(`/khoa-hoc/${PAID}`);
    const cta = page.locator("#course-cta");
    await expect(cta.getByText("Sắp mở bán").first()).toBeVisible();
    await expect(cta.getByRole("link", { name: /Mua/ })).toHaveCount(0);
    await expect(cta.getByRole("button", { name: /Mua/ })).toHaveCount(0);
    await expect(page.getByText("299.000đ").locator("visible=true").first()).toBeVisible();
  });

  test("404: slug không có / sai định dạng -> HTTP 404 + nút Về trang danh mục", async ({ page }) => {
    for (const slug of ["khong-co-khoa-nay", "SAI_DINH_DANG", "a%2f..%2fb"]) {
      const res = await page.goto(`/khoa-hoc/${slug}`);
      expect(res?.status(), slug).toBe(404);
      await expect(page.getByText("Không tìm thấy khóa học")).toBeVisible();
    }
    await page.getByRole("link", { name: "Về trang danh mục" }).click();
    await expect(page).toHaveURL(/\/khoa-hoc$/);
  });

  test("khóa chưa có chương/bài: outline rỗng, không lỗi", async ({ page }) => {
    const res = await page.goto("/khoa-hoc/e2e-fw2-chua-co-noi-dung-7");
    expect(res?.status()).toBe(200);
    await expect(page.getByText("Nội dung khóa học đang được cập nhật")).toBeVisible();
  });
});

test.describe("Chi tiết khóa học (học sinh đăng nhập — viewer-state)", () => {
  test("verified: Đăng ký miễn phí -> Đang chờ duyệt, F5 vẫn chờ duyệt", async ({ page }) => {
    await login(page, "fw2-hs-ok@example.com");
    await page.goto(`/khoa-hoc/${FREE}`);
    const cta = page.locator("#course-cta");
    await cta.getByRole("button", { name: "Đăng ký học miễn phí" }).click();
    await expect(cta.getByRole("status").filter({ hasText: "Đang chờ duyệt" })).toBeVisible();
    await expect(cta.getByRole("button")).toHaveCount(0);
    await page.reload();
    await expect(cta.getByRole("status").filter({ hasText: "Đang chờ duyệt" })).toBeVisible();
  });

  test("chưa xác thực: Đăng ký -> dẫn sang /xac-thuc-otp", async ({ page }) => {
    await login(page, "fw2-hs-unv@example.com");
    await page.goto(`/khoa-hoc/${FREE}`);
    await page.locator("#course-cta").getByRole("button", { name: "Đăng ký học miễn phí" }).click();
    await expect(page).toHaveURL(/\/xac-thuc-otp/, { timeout: 15_000 });
  });

  test("đang chờ duyệt (seed): hiện trạng thái ngay khi tải", async ({ page }) => {
    await login(page, "fw2-hs-pend@example.com");
    await page.goto(`/khoa-hoc/${FREE}`);
    await expect(page.locator("#course-cta").getByRole("status").filter({ hasText: "Đang chờ duyệt" })).toBeVisible();
  });

  test("đã sở hữu: nút Tiếp tục học vô hiệu (chưa có trang học, FW4); bài trong outline không phải link", async ({ page }) => {
    await login(page, "fw2-hs-own@example.com");
    await page.goto(`/khoa-hoc/${PAID}`);
    const cta = page.locator("#course-cta");
    await expect(cta.getByRole("button", { name: "Tiếp tục học" })).toBeDisabled();
    await expect(cta.getByRole("link", { name: "Tiếp tục học" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: /Bài 1\.2/ })).toHaveCount(0);
    await expect(page.getByText("Bài 1.2")).toBeVisible();
  });

  test("khóa có phí, chưa mua, thanh toán tạm khoá: Sắp mở bán, không nút mua", async ({ page }) => {
    await login(page, "fw2-hs-ok@example.com");
    await page.goto(`/khoa-hoc/${PAID}`);
    const cta = page.locator("#course-cta");
    await expect(cta.getByText("Sắp mở bán").first()).toBeVisible();
    await expect(cta.getByRole("button")).toHaveCount(0);
  });
});

test.describe("Mobile 375px", () => {
  test.use({ viewport: { width: 375, height: 800 } });

  test("chi tiết: nút dính đáy, không tràn ngang", async ({ page }) => {
    await page.goto(`/khoa-hoc/${PAID}`);
    const sticky = page.locator("div.fixed.bottom-0");
    await expect(sticky.getByText("Sắp mở bán")).toBeVisible();
    await expect(sticky).toContainText("299.000");
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });

  test("danh mục: bộ lọc thu gọn sau nút Bộ lọc, không tràn ngang", async ({ page }) => {
    await page.goto("/khoa-hoc");
    await expect(page.getByLabel("E2E FW2 Hình học")).toBeHidden();
    await page.getByRole("button", { name: /^Bộ lọc/ }).click();
    await expect(page.getByRole("dialog").getByLabel("E2E FW2 Hình học")).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe("SEO", () => {
  test("robots.txt và sitemap.xml", async ({ request }) => {
    const robots = await request.get("/robots.txt");
    expect(robots.status()).toBe(200);
    expect(await robots.text()).toContain("Sitemap: ");
    const sm = await request.get("/sitemap.xml");
    expect(sm.status()).toBe(200);
    expect(sm.headers()["content-type"]).toContain("xml");
    const xml = await sm.text();
    expect(xml).toContain("/lop-9</loc>");
    expect(xml).toContain("/khoa-hoc</loc>");
  });

  test("trang danh mục có CSP nonce; trang đã lọc là noindex", async ({ page }) => {
    const res = await page.goto("/khoa-hoc?q=toan");
    expect(res?.headers()["content-security-policy"]).toContain("nonce-");
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
  });
});
