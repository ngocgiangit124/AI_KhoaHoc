import { expect, test, type Page } from "@playwright/test";

/**
 * FW6 — "Khóa học của tôi" `/tai-khoan/khoa-hoc-cua-toi` và tiến độ `/tai-khoan/khoa-hoc-cua-toi/{course}` với backend thật.
 * Dữ liệu: `e2e/seed-e2e-progress.sh` (in `course=.. l1=.. l2=.. l3=.. l4=.. qa=.. qb=.. pend=.. rej=..`; truyền qua E2E_FW6). `--workers=1`, tuần tự.
 */
const PASSWORD = "matkhau-123";
const ids = Object.fromEntries((process.env.E2E_FW6 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần backend thật (E2E_REAL_BACKEND=1) và E2E_FW6 từ seed-e2e-progress.sh");
test.describe.configure({ mode: "serial" });

const LIST = "/tai-khoan/khoa-hoc-cua-toi";

async function login(page: Page, email: string) {
  if (process.env.E2E_DEBUG) {
    page.on("pageerror", (e) => console.log("[pageerror]", e.message.slice(0, 300)));
    page.on("response", (r) => /\/api\/v1\//.test(r.url()) && console.log("[api]", r.status(), r.request().method(), r.url().replace(/^.*\/api\/v1/, "")));
  }
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}

test.describe("Khóa học của tôi", () => {
  test("khách vào thẳng → chuyển tới đăng nhập kèm next", async ({ page }) => {
    await page.goto(LIST);
    await expect(page).toHaveURL(/\/dang-nhap\?.*next=/, { timeout: 90_000 });
  });

  test("học sinh có khóa: tiến độ 2/4 = 50%, Học tiếp tới bài 3, chờ duyệt / bị từ chối, menu header", async ({ page }) => {
    await login(page, "fw6-hs-own@example.com");
    await page.getByRole("navigation", { name: "Chính", exact: true }).getByRole("link", { name: "Khóa học của tôi" }).click();
    await expect(page).toHaveURL(new RegExp(`${LIST}$`));
    await expect(page.getByRole("heading", { level: 1, name: "Khóa học của tôi" })).toBeVisible({ timeout: 90_000 });

    const card = page.locator("article", { hasText: "E2E FW6 Tiến độ" });
    await expect(card.getByText("2/4 bài · 50%")).toBeVisible({ timeout: 90_000 });
    await expect(card.getByText("Đang học", { exact: true })).toBeVisible();
    await expect(card.getByText("Điểm trắc nghiệm cao nhất: 8,33/10")).toBeVisible();
    await expect(card.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "50");
    // Banner "Học tiếp" + nút trong thẻ cùng tới bài 3 (bài gần nhất chưa xong).
    for (const link of await page.getByRole("link", { name: "Tiếp tục học" }).all()) {
      await expect(link).toHaveAttribute("href", `/hoc/${ids.course}/bai/${ids.l3}`);
    }

    await page.getByRole("tab", { name: /Chờ duyệt/ }).click();
    await expect(page.getByText("E2E FW6 Chờ duyệt")).toBeVisible();
    await expect(page.getByText("Đang chờ duyệt")).toBeVisible();
    await page.getByRole("tab", { name: /Không được duyệt/ }).click();
    await expect(page.getByText("Khóa dành cho lớp chọn của trường.")).toBeVisible();
    await expect(page.getByRole("link", { name: "Xem khóa và đăng ký lại" })).toHaveAttribute("href", `/khoa-hoc/${ids.rej}`);

    await page.getByRole("tab", { name: /Đang học/ }).click();
    await card.getByRole("link", { name: "Tiếp tục học" }).click();
    await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l3}$`), { timeout: 90_000 });
    await expect(page.getByRole("heading", { level: 1, name: "Bài 3" })).toBeVisible({ timeout: 120_000 });
  });

  test("chi tiết tiến độ: điểm cao nhất, số lượt, link làm bài/kết quả, mục lục bài", async ({ page }) => {
    await login(page, "fw6-hs-own@example.com");
    await page.goto(LIST);
    await page.locator("article", { hasText: "E2E FW6 Tiến độ" }).getByRole("link", { name: "Xem tiến độ" }).click();
    await expect(page).toHaveURL(new RegExp(`${LIST}/${ids.course}$`), { timeout: 90_000 });
    await expect(page.getByRole("heading", { level: 1, name: "E2E FW6 Tiến độ" })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText("2/4 bài · 50%")).toBeVisible();

    const rowA = page.getByRole("row", { name: /Trắc nghiệm bài 1/ });
    await expect(rowA.getByText("8,33/10")).toBeVisible();
    await expect(rowA.getByText("2", { exact: true }).first()).toBeVisible(); // 2 lượt đã nộp
    await expect(rowA.getByRole("link", { name: /Làm lại/ })).toHaveAttribute("href", `/hoc/${ids.course}/quiz/${ids.qa}`);
    await expect(rowA.getByRole("link", { name: /Xem kết quả/ })).toHaveAttribute("href", `/hoc/${ids.course}/quiz/${ids.qa}/ket-qua`);
    const rowB = page.getByRole("row", { name: /Đề chương 2/ });
    await expect(rowB.getByText("Chưa làm")).toBeVisible();
    await expect(rowB.getByRole("link", { name: /Xem kết quả/ })).toHaveCount(0);
    await expect(rowB.getByRole("link", { name: /Làm bài/ })).toBeVisible();

    // Chương chứa bài học tiếp (Chương 2) mở sẵn; chương 1 mở ra thấy 2 bài đã xong.
    await expect(page.getByRole("link", { name: /Bài 3/ })).toContainText("Bài học tiếp theo");
    await page.getByText("Chương 1", { exact: true }).click();
    await expect(page.getByRole("link", { name: /Bài 1/ })).toContainText("Đã hoàn thành");
    await expect(page.getByText("Đã xong 2/2 bài")).toBeVisible();

    await rowA.getByRole("link", { name: /Xem kết quả/ }).click();
    await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/quiz/${ids.qa}/ket-qua`), { timeout: 90_000 });
    // Nội dung màn kết quả là của FW5 (lượt trong seed tạo bằng factory nên không có chi tiết từng câu): ở đây chỉ kiểm điều hướng.
  });

  test("học sinh chưa có khóa: trạng thái rỗng + liên kết danh mục; vào tiến độ khóa của người khác → chưa sở hữu; id không có → 404", async ({ page }) => {
    await login(page, "fw6-hs-none@example.com");
    await page.goto(LIST);
    await expect(page.getByText("Bạn chưa có khóa học nào")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole("link", { name: "Khám phá khóa học" })).toHaveAttribute("href", "/khoa-hoc");

    await page.goto(`${LIST}/${ids.course}`);
    await expect(page.getByText("Bạn chưa sở hữu khóa học này")).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole("link", { name: "Tới trang khóa học" })).toHaveAttribute("href", "/khoa-hoc/e2e-fw6-tiendo");

    await page.goto(`${LIST}/999999999`);
    await expect(page.getByText("Không tìm thấy khóa học")).toBeVisible({ timeout: 90_000 });

    const res = await page.goto(`${LIST}/abc`);
    expect(res?.status()).toBe(404);
  });

  test("lỗi mạng và 429: có thông báo + Thử lại tải lại được", async ({ page }) => {
    await login(page, "fw6-hs-own@example.com");
    let mode: "abort" | "429" | "pass" = "abort";
    await page.route("**/api/v1/me/courses*", async (route) => {
      if (mode === "abort") return route.abort("connectionrefused");
      if (mode === "429") return route.fulfill({ status: 429, contentType: "application/json", body: JSON.stringify({ message: "Too Many Attempts." }) });
      return route.continue();
    });
    await page.goto(LIST);
    await expect(page.getByText("Không tải được danh sách khóa học của bạn")).toBeVisible({ timeout: 90_000 });
    mode = "429";
    await page.getByRole("button", { name: "Thử lại" }).click();
    await expect(page.getByText("Bạn thao tác hơi nhanh")).toBeVisible();
    mode = "pass";
    await page.getByRole("button", { name: "Thử lại" }).click();
    await expect(page.locator("article", { hasText: "E2E FW6 Tiến độ" })).toBeVisible({ timeout: 90_000 });
  });

  test("nhiều khóa: 12 khóa/trang, trang 2 giữ qua F5 (URL), Trước/Sau", async ({ page }) => {
    await login(page, "fw6-hs-many@example.com");
    await page.goto(LIST);
    await expect(page.locator("article")).toHaveCount(12, { timeout: 90_000 });
    // Sắp học gần nhất lên đầu (AC4): "Nhiều 1" truy cập mới nhất.
    await expect(page.locator("article h3").first()).toHaveText("E2E FW6 Nhiều 1");
    await page.getByRole("link", { name: "Trang 2" }).click();
    await expect(page).toHaveURL(/trang=2/);
    await expect(page.locator("article")).toHaveCount(1, { timeout: 90_000 });
    await page.reload();
    await expect(page.locator("article")).toHaveCount(1, { timeout: 90_000 });
    await expect(page.locator("article h3")).toHaveText("E2E FW6 Nhiều 13");
    await page.getByRole("link", { name: /Trước/ }).click();
    await expect(page.locator("article")).toHaveCount(12, { timeout: 90_000 });
  });

  test("375px: không tràn ngang, nút/liên kết chính cao ≥ 44px, chữ nội dung ≥ 16px", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await login(page, "fw6-hs-own@example.com");
    for (const url of [LIST, `${LIST}/${ids.course}`]) {
      await page.goto(url);
      await expect(page.getByRole("progressbar").first()).toBeVisible({ timeout: 90_000 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      const wide = overflow > 0 ? await page.evaluate(() => [...document.querySelectorAll("main *")].filter((e) => !e.closest(".overflow-x-auto") && e.getBoundingClientRect().right > document.documentElement.clientWidth + 1).slice(0, 6).map((e) => `${e.tagName}.${String(e.className).slice(0, 60)}:${e.textContent?.slice(0, 30)}`)) : [];
      expect({ url, overflow, wide }).toEqual({ url, overflow: 0, wide: [] });
      const small = await page.evaluate(() => {
        const out: string[] = [];
        document.querySelectorAll("main a.focus-ring, main button").forEach((el) => {
          const r = (el as HTMLElement).getBoundingClientRect();
          if (r.width > 0 && r.height > 0 && r.height < 43.5 && /Tiếp tục học|Xem tiến độ|Làm lại|Làm bài|Xem kết quả|Học|Bắt đầu/.test(el.textContent ?? "")) out.push(`${el.textContent?.trim()}:${Math.round(r.height)}`);
        });
        return out;
      });
      expect(small).toEqual([]);
    }
    const title = await page.locator("main h1").evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(title).toBeGreaterThanOrEqual(16);
  });
});
