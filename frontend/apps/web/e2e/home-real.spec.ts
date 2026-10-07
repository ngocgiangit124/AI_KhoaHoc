import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

/**
 * e2e THẬT cho trang chủ (FW8 + FW9): backend Laravel thật + dữ liệu `e2e-fw8-*` (seed-e2e-home.sh --reset).
 * Chạy bằng `e2e/run-home-real.sh`. SSR gọi API qua `e2e/fw8-proxy.mjs` (chuyển tiếp nguyên trạng tới backend thật); proxy chỉ
 * làm lỗi/rỗng/rút gọn/chậm hai nguồn của trang chủ để kiểm "một khu vực hỏng không làm hỏng cả trang" — tình huống không thể
 * tạo trên DB dùng chung. Thứ tự test CÓ Ý NGHĨA: Data Cache `revalidate: 60` giữ kết quả thành công nên các trạng thái lỗi
 * (không bị cache) chạy trước, rồi 3 khóa/1 giáo viên, rồi rỗng, rồi dữ liệu đầy đủ (mỗi lần đổi phải chờ cache hết hạn).
 */
const PROXY = "http://127.0.0.1:8001";
const SECTION_TITLES = [
  "Bạn đang học lớp mấy?",
  "Khóa học nổi bật",
  "Lời nhắn từ đội ngũ sáng lập",
  "Thầy cô giảng dạy",
  "Một buổi học trên VitaminVui",
  "Dành cho phụ huynh",
];

test.describe.configure({ mode: "serial" });

async function setMode(request: APIRequestContext, mode: { courses?: string; teachers?: string }) {
  const res = await request.post(`${PROXY}/__mode`, { data: mode });
  expect(res.ok()).toBe(true);
}

async function stats(request: APIRequestContext): Promise<{ courses: number; teachers: number }> {
  return (await (await request.get(`${PROXY}/__stats`)).json()).stats;
}

/** Chờ Data Cache hết hạn (≤ 60 s + làm mới nền): gọi lại tới khi HTML thoả điều kiện. */
async function waitForHtml(request: APIRequestContext, ok: (html: string) => boolean) {
  await expect
    .poll(async () => ok(await (await request.get("/")).text()), { timeout: 150_000, intervals: [3_000, 5_000] })
    .toBe(true);
}

const h2s = (page: Page) => page.locator("main h2").allTextContents();

async function noHorizontalScroll(page: Page) {
  const { scrollWidth, innerWidth } = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth }));
  expect(scrollWidth, `scrollWidth ${scrollWidth} > innerWidth ${innerWidth}`).toBeLessThanOrEqual(innerWidth);
}

test.beforeAll(async ({ request }) => {
  await request.post(`${PROXY}/__reset`);
});

test("A1: cả hai API lỗi 5xx -> trang vẫn 200, khu khóa nổi bật báo lỗi + Tải lại, giáo viên ẩn hẳn (AC9, AC11, AC15)", async ({ page, request }) => {
  await setMode(request, { courses: "500", teachers: "500" });
  const res = await page.goto("/");
  expect(res?.status()).toBe(200);
  await expect(page.getByRole("heading", { level: 1 })).toHaveCount(1);
  await expect(page.getByText("Không tải được khóa học nổi bật")).toBeVisible();
  await expect(page.getByRole("link", { name: /Tải lại/ })).toBeVisible();
  // Hero, chọn lớp, poster (nội dung tạm), một buổi học, phụ huynh vẫn có.
  expect(await h2s(page)).toEqual(SECTION_TITLES.filter((t) => t !== "Thầy cô giảng dạy"));
  await expect(page.getByRole("link", { name: /^Lớp\s*9/ })).toBeVisible();
  await expect(page.getByAltText(/Hình minh hoạ: giáo viên cách điệu/)).toBeAttached();
  await expect(page.getByText("Thầy cô giảng dạy")).toHaveCount(0);
});

test("A2: API khóa học quá chậm -> trang trả về trong ~4 giây với khu lỗi tại chỗ, không treo (AC9)", async ({ page, request }) => {
  await setMode(request, { courses: "slow500", teachers: "500" });
  const t0 = Date.now();
  const res = await page.goto("/", { waitUntil: "domcontentloaded", timeout: 20_000 });
  const elapsed = Date.now() - t0;
  expect(res?.status()).toBe(200);
  await expect(page.getByText("Không tải được khóa học nổi bật")).toBeVisible();
  expect(elapsed, `mất ${elapsed} ms`).toBeLessThan(6_000);
  // Request chậm vẫn chạy nền (proxy trả lỗi sau 6,5 s); Next gộp các fetch trùng URL đang bay nên phải đợi nó xong,
  // nếu không test sau sẽ nhận lại chính lỗi này.
  await expect.poll(async () => (await stats(request)).courses, { timeout: 10_000 }).toBeGreaterThan(0);
  await page.waitForTimeout(4_000);
});

test("B: 3 khóa + 1 giáo viên; 'Tải lại' hồi phục; thẻ 1 người; tên 150 ký tự/bio 5 dòng không vỡ ở 375px; cache 60 giây (AC4, AC12, AC16)", async ({ page, request }) => {
  // Khôi phục: API đang lỗi -> khu lỗi; API hồi phục -> bấm "Tải lại" gọi lại API và hiện đủ khóa (lỗi không vào Data Cache).
  await setMode(request, { courses: "500", teachers: "500" });
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto("/");
  await expect(page.getByText("Không tải được khóa học nổi bật")).toBeVisible();
  await setMode(request, { courses: "slice:3", teachers: "slice:1" });
  await page.getByRole("link", { name: /Tải lại/ }).click();
  const featured = page.locator("section[aria-labelledby='featured-title']");
  await expect(featured.getByRole("article")).toHaveCount(3);
  await expect(featured.getByRole("heading", { level: 3 }).first()).toHaveText("E2E FW8 Khóa nổi bật 1");

  const teachers = page.locator("section[aria-labelledby='giao-vien-title']");
  await expect(teachers.getByRole("heading", { level: 2, name: "Thầy cô giảng dạy" })).toBeVisible();
  await expect(teachers.getByRole("article")).toHaveCount(1);
  await expect(teachers.locator("ul")).toHaveCount(0); // 1 người -> thẻ rộng vừa, không lưới

  const name = teachers.getByRole("heading", { level: 3 });
  const nameBox = await name.evaluate((el) => {
    const lh = parseFloat(getComputedStyle(el).lineHeight);
    return { client: el.clientHeight, scroll: el.scrollHeight, lh, text: el.textContent?.length ?? 0 };
  });
  expect(nameBox.text).toBeGreaterThanOrEqual(140);
  expect(nameBox.client).toBeLessThanOrEqual(nameBox.lh * 2 + 2); // cắt tối đa 2 dòng
  expect(nameBox.scroll).toBeGreaterThan(nameBox.client);

  const bio = teachers.locator("p.line-clamp-3");
  const bioBox = await bio.evaluate((el) => ({ client: el.clientHeight, scroll: el.scrollHeight, lh: parseFloat(getComputedStyle(el).lineHeight), ws: getComputedStyle(el).whiteSpace }));
  expect(bioBox.client).toBeLessThanOrEqual(bioBox.lh * 3 + 2); // cắt đúng 3 dòng
  expect(bioBox.scroll).toBeGreaterThan(bioBox.client); // bio 5 dòng nên bị cắt
  expect(bioBox.ws).toBe("pre-line"); // giữ xuống dòng
  await expect(teachers.getByRole("link", { name: /^Xem 2 khóa học/ })).toBeVisible();
  await noHorizontalScroll(page);

  // Cache: ba lần tải lại liên tiếp trong 60 giây không gọi thêm API.
  const before = await stats(request);
  for (let i = 0; i < 3; i++) await page.reload();
  expect(await stats(request)).toEqual(before);
});

test("C: không có khóa và không có giáo viên -> 'sẽ sớm được cập nhật'; khu giáo viên không để lại tiêu đề hay khoảng trắng (AC5, AC11)", async ({ page, request }) => {
  await setMode(request, { courses: "empty", teachers: "empty" });
  await waitForHtml(request, (html) => html.includes("Khóa học sẽ sớm được cập nhật") && !html.includes("Thầy cô giảng dạy"));
  await page.goto("/");
  await expect(page.getByText(/Khóa học sẽ sớm được cập nhật/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Xem danh mục khóa học" })).toHaveAttribute("href", "/khoa-hoc");
  expect(await h2s(page)).toEqual(SECTION_TITLES.filter((t) => t !== "Thầy cô giảng dạy"));
  // Poster liền kề khối "Một buổi học": khoảng cách chỉ là padding 48px của khối sau, không có phần tử rỗng ở giữa.
  const gap = await page.evaluate(() => {
    const a = document.querySelector("section[aria-labelledby='nguoi-sang-lap-title']")!.getBoundingClientRect();
    const b = document.querySelector("section[aria-labelledby='steps-title']")!.getBoundingClientRect();
    return { gap: b.top - a.bottom, nextIsSteps: document.querySelector("section[aria-labelledby='nguoi-sang-lap-title']")!.nextElementSibling?.getAttribute("aria-labelledby") };
  });
  expect(gap.nextIsSteps).toBe("steps-title");
  expect(gap.gap).toBeLessThanOrEqual(0.5);
});

test("D: dữ liệu thật — thứ tự khu vực, 4 khóa nổi bật, giáo viên đủ điều kiện, SEO, CSP, poster, XSS, bộ lọc giáo viên", async ({ page, request }) => {
  await setMode(request, { courses: "ok", teachers: "ok" });
  await waitForHtml(request, (html) => html.includes("E2E FW8 Khóa nổi bật 1") && html.includes("E2E FW8 Cô Hoa"));

  // ---- HTML ban đầu (chưa chạy JS): SEO, h1, danh sách khóa và alt ảnh đã nằm trong HTML (AC2)
  const res = await request.get("/");
  expect(res.status()).toBe(200);
  const html = await res.text();
  expect(html.match(/<h1[\s>]/g)?.length).toBe(1);
  expect(html).toMatch(/<title>[^<]*VitaminVui[^<]*<\/title>/);
  expect(html).toMatch(/<meta name="description" content="[^"]{50,}"/);
  expect(html).toMatch(/<link rel="canonical" href="https?:\/\/[^"]+"/);
  expect(html).toMatch(/<meta property="og:title"/);
  expect(html).toContain("E2E FW8 Khóa nổi bật 4");
  expect(html).toContain("Ảnh thầy/cô E2E FW8 Cô Hoa");
  expect(html).toMatch(/<script type="application\/ld\+json" nonce="[^"]+"/);
  // Không lộ giáo viên không đủ điều kiện (chưa đồng ý / khóa duy nhất ngừng bán / bị khoá) và khóa ngừng bán.
  for (const hidden of ["E2E FW8 Chưa đồng ý", "E2E FW8 Ngừng bán", "E2E FW8 Bị khoá", "E2E FW8 Khóa đã ngừng bán"]) expect(html, hidden).not.toContain(hidden);

  // ---- Trình duyệt 1280px
  const cspErrors: string[] = [];
  page.on("console", (m) => {
    if (/Content Security Policy|Refused to/i.test(m.text())) cspErrors.push(m.text());
  });
  await page.addInitScript(() => {
    (window as unknown as { __cls: number }).__cls = 0;
    new PerformanceObserver((list) => {
      for (const e of list.getEntries() as unknown as Array<{ hadRecentInput: boolean; value: number }>) {
        if (!e.hadRecentInput) (window as unknown as { __cls: number }).__cls += e.value;
      }
    }).observe({ type: "layout-shift", buffered: true });
  });
  await page.setViewportSize({ width: 1280, height: 900 });
  const nav = await page.goto("/");
  expect(nav?.headers()["content-security-policy"]).toContain("nonce-");

  // Thứ tự khu vực (AC1) và 7 ô lớp (AC7)
  expect(await h2s(page)).toEqual(SECTION_TITLES);
  const gradeLinks = page.locator("section[aria-labelledby='grade-title'] a");
  await expect(gradeLinks).toHaveCount(7);
  for (const g of [6, 7, 8, 9, 10, 11, 12]) await expect(gradeLinks.nth(g - 6)).toHaveAttribute("href", `/khoa-hoc?grade=${g}`);

  // Khóa nổi bật: đúng 4 khóa theo manual_order, khóa ngừng bán không có (AC3, AC8)
  const featured = page.locator("section[aria-labelledby='featured-title']");
  await expect(featured.getByRole("article")).toHaveCount(4);
  await expect(featured.getByRole("heading", { level: 3 })).toHaveText([
    "E2E FW8 Khóa nổi bật 1",
    "E2E FW8 Khóa nổi bật 2 trả phí",
    "E2E FW8 Khóa nổi bật 3",
    "E2E FW8 Khóa nổi bật 4",
  ]);
  for (let n = 1; n <= 4; n++) {
    await expect(featured.locator(`a[href='/khoa-hoc/e2e-fw8-khoa-${n}']`)).toHaveCount(1);
  }
  await expect(featured.getByText("Sắp mở bán")).toHaveCount(1); // chỉ khóa trả phí; thanh toán đang khóa
  await expect(featured.getByRole("button")).toHaveCount(0);
  await expect(featured.getByRole("link", { name: /mua|giỏ hàng/i })).toHaveCount(0);
  await expect(featured.getByRole("link", { name: /Xem tất cả khóa học/ })).toHaveAttribute("href", "/khoa-hoc?sort=featured");

  // Poster (AC13–AC16)
  const poster = page.locator("section[aria-labelledby='nguoi-sang-lap-title']");
  const img = poster.getByRole("img");
  await expect(img).toHaveAttribute("loading", "lazy");
  await expect(img).not.toHaveAttribute("fetchpriority", "high");
  await expect(poster.getByText("Đội ngũ sáng lập VitaminVui")).toBeVisible();
  await expect(poster.getByRole("link", { name: /Xem khóa học/ })).toHaveAttribute("href", "/khoa-hoc");
  await img.scrollIntoViewIfNeeded();
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);

  // Giáo viên: 4 thẻ đúng thứ tự, đủ điều kiện (AC12, AC6, AC13, AC14, AC19)
  const teachers = page.locator("section[aria-labelledby='giao-vien-title']");
  await teachers.scrollIntoViewIfNeeded();
  await expect(teachers.getByRole("article")).toHaveCount(4);
  const names = await teachers.getByRole("heading", { level: 3 }).allTextContents();
  expect(names[0]).toMatch(/^E2E FW8 Nguyễn Thị Hoàng/);
  expect(names.slice(1)).toEqual(["E2E FW8 Cô Hoa", "E2E FW8 Thầy Nam", "E2E FW8 Cô Mai"]);
  // Ảnh 404 (tệp không tồn tại / miền tĩnh không tới được) -> chữ cái đầu, không icon ảnh vỡ (AC17)
  const hoa = teachers.getByRole("article").filter({ hasText: "E2E FW8 Cô Hoa" });
  await expect(hoa.getByText("CH", { exact: true })).toBeVisible();
  await expect(hoa.locator("img")).toHaveCount(0);
  await expect(hoa.getByText(/Lớp 8, 9/)).toBeVisible();
  await expect(hoa.getByText(/2 khóa đang bán/)).toBeVisible();
  // bio là văn bản: HTML/script hiện nguyên chữ, không thực thi (AC4)
  const nam = teachers.getByRole("article").filter({ hasText: "E2E FW8 Thầy Nam" });
  await expect(nam.getByText("<script>window.__fw8xss=1</script>", { exact: false })).toBeVisible();
  expect(await nam.locator("p.line-clamp-3 b, p.line-clamp-3 img, p.line-clamp-3 script").count()).toBe(0);
  expect(await page.evaluate(() => (window as unknown as { __fw8xss?: number }).__fw8xss)).toBeUndefined();

  // 3 cột từ 1024px, 2 cột ở 768, 1 cột ở 375 (AC16); không cuộn ngang; vùng bấm ≥ 44px; CLS nhỏ
  const columns = () =>
    teachers.getByRole("article").evaluateAll((els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().left))).size);
  expect(await columns()).toBe(3);
  await noHorizontalScroll(page);
  for (const [width, cols] of [[768, 2], [375, 1]] as const) {
    await page.setViewportSize({ width, height: 800 });
    await expect.poll(columns).toBe(cols);
    await noHorizontalScroll(page);
  }
  // Vùng bấm ≥ 44x44 ở 375px cho mọi liên kết/nút trong nội dung (thẻ khóa học dùng lớp phủ toàn thẻ nên bỏ qua liên kết `after:absolute`)
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("main a, main button")]
      .filter((el) => !el.className.toString().includes("after:absolute"))
      .map((el) => ({ t: (el.textContent ?? "").trim().slice(0, 40), r: el.getBoundingClientRect() }))
      .filter((x) => x.r.width > 0 && (x.r.height < 43.5 || x.r.width < 43.5))
      .map((x) => `${x.t} ${Math.round(x.r.width)}x${Math.round(x.r.height)}`),
  );
  expect(small, `vùng bấm < 44px: ${small.join(" | ")}`).toEqual([]);
  expect(await page.evaluate(() => (window as unknown as { __cls: number }).__cls)).toBeLessThanOrEqual(0.1);
  expect(cspErrors, cspErrors.join("\n")).toEqual([]);

  // ---- "Xem N khóa học" -> danh mục lọc theo giáo viên, chip + bỏ lọc (FW9/Q6)
  await page.setViewportSize({ width: 1280, height: 900 });
  const link = hoa.getByRole("link", { name: /^Xem 2 khóa học/ });
  const href = await link.getAttribute("href");
  expect(href).toMatch(/^\/khoa-hoc\?teacher_id=\d+$/);
  await link.click();
  await expect(page).toHaveURL(new RegExp(`${href!.replace("?", "\\?")}$`));
  await expect(page.getByRole("heading", { level: 2, name: "2 khóa học" })).toBeVisible();
  await expect(page.getByRole("link", { name: /Giáo viên: E2E FW8 Cô Hoa/ })).toBeVisible();
  const titles = await page.locator("main article h3").allTextContents();
  expect(titles.sort()).toEqual(["E2E FW8 Khóa nổi bật 1", "E2E FW8 Khóa nổi bật 3"]);
  await page.getByRole("link", { name: /Giáo viên: E2E FW8 Cô Hoa/ }).click();
  await expect(page).toHaveURL(/\/khoa-hoc$/);

  // teacher_id không tồn tại -> trạng thái rỗng, không lỗi; sai kiểu -> bỏ qua
  const none = await page.goto("/khoa-hoc?teacher_id=999999999");
  expect(none?.status()).toBe(200);
  await expect(page.getByText("Không tìm thấy khóa học phù hợp")).toBeVisible();
  await page.goto("/khoa-hoc?teacher_id=abc");
  await expect(page.getByRole("heading", { level: 2, name: /khóa học$/ })).not.toHaveText("0 khóa học");

  // Chọn lớp -> danh mục lọc grade (AC7)
  await page.goto("/");
  await page.locator("section[aria-labelledby='grade-title']").getByRole("link", { name: /^Lớp\s*9/ }).click();
  await expect(page).toHaveURL(/\/khoa-hoc\?grade=9$/);
  await expect(page.getByRole("link", { name: "Lớp 9", exact: true })).toHaveAttribute("aria-current", "page");

  // Cache: tải lại trang chủ không gọi thêm API trong 60 giây (kết quả thật đã vào Data Cache ở lần đầu)
  await page.goto("/");
  const before = await stats(request);
  await page.reload();
  await page.reload();
  expect(await stats(request)).toEqual(before);
});

test("E: 375px trang chủ đầy đủ không cuộn ngang, khóa nổi bật 1 cột/2 cột; ảnh poster không méo", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto("/");
  await noHorizontalScroll(page);
  const poster = page.locator("section[aria-labelledby='nguoi-sang-lap-title'] img");
  await poster.scrollIntoViewIfNeeded();
  const box = await poster.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.width / box!.height).toBeCloseTo(4 / 5, 1); // khung 4:5 giữ chỗ, không méo
  const cols = await page
    .locator("section[aria-labelledby='featured-title'] article")
    .evaluateAll((els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().left))).size);
  expect(cols).toBe(1);
});
