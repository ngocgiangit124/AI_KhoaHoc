import { expect, test, type Page, type Request } from "@playwright/test";

/**
 * FW4 — trang học video `/hoc/{course}/bai/{lesson}` với backend thật + VideoLab nội bộ (VIDEO_PROVIDER=internal).
 * Dữ liệu: `e2e/seed-e2e-learn.sh` (in ra `course=.. l1=.. l2=.. l3=.. l4=..`; truyền qua biến E2E_FW4="course=819 l1=...").
 * Học sinh riêng của FW4: fw4-hs-own (đã ghi danh), fw4-hs-none (chưa ghi danh). Chạy `--workers=1`.
 * Dev server phải có NEXT_PUBLIC_VIDEO_HOSTS=http://video.localhost:8000 (CSP connect-src/media-src).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const ids = Object.fromEntries((process.env.E2E_FW4 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần backend thật (E2E_REAL_BACKEND=1) và E2E_FW4 từ seed-e2e-learn.sh");

const lessonUrl = (key: string) => `/hoc/${ids.course}/bai/${ids[key]}`;
const isPlayback = (r: Request) => /\/api\/v1\/learn\/lessons\/\d+\/playback/.test(r.url());

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/);
}

const video = (page: Page) => page.locator("video");
const currentTime = (page: Page) => video(page).evaluate((v: HTMLVideoElement) => v.currentTime);

async function playMuted(page: Page, rate = 1) {
  await video(page).evaluate(async (v: HTMLVideoElement, r: number) => {
    v.muted = true;
    v.playbackRate = r;
    await v.play();
  }, rate);
}

test.describe("Học video", () => {
  test("học sinh đã ghi danh xem được bài: playback gọi từ trình duyệt, video phát qua hls.js, có mục lục, không có header/footer site", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    const playbackReq = page.waitForRequest(isPlayback);
    await page.goto(lessonUrl("l1"));
    const req = await playbackReq;
    expect(req.headers()["x-device-id"]).toBeTruthy(); // authFetch từ trình duyệt (cookie Sanctum), không phải SSR
    expect(new URL(req.url()).host).toBe("api.localhost:8000");

    await expect(page.getByRole("heading", { level: 1, name: "Bài 1 video thật" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("complementary", { name: "Nội dung khóa học" })).toBeVisible();
    await expect(page.getByRole("link", { name: /Bài 2 video thật/ })).toBeVisible();
    await expect(page.getByRole("contentinfo")).toHaveCount(0); // không footer
    await expect(page.locator("body.bg-oly-page, main.bg-oly-page")).toHaveCount(0); // không nền ô ly

    await page.getByRole("button", { name: "Phát video" }).click();
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
    await page.getByRole("button", { name: "Tạm dừng" }).click();
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible();
    // Tốc độ + toàn màn hình có trong điều khiển (câu hỏi 9 của design).
    await expect(page.getByLabel("Tốc độ phát")).toBeVisible();
    await expect(page.getByRole("button", { name: "Toàn màn hình" })).toBeVisible();
  });

  test("học sinh CHƯA ghi danh bị chặn (403) và tự chuyển về trang chi tiết khóa; không thấy video", async ({ page }) => {
    await login(page, "fw4-hs-none@example.com");
    await page.goto(lessonUrl("l1"));
    await expect(page).toHaveURL(/\/khoa-hoc\/e2e-fw4-hoc-video$/, { timeout: 60_000 }); // dev server biên dịch trang khóa lần đầu nên có thể chậm
    await expect(video(page)).toHaveCount(0);
  });

  test("403 không kèm errors.course (khóa nháp/ẩn): ở lại màn 'chưa sở hữu' + nút về danh mục", async ({ page }) => {
    await login(page, "fw4-hs-none@example.com");
    await page.route("**/api/v1/learn/lessons/*", async (route) => {
      if (route.request().url().includes("/playback")) return route.continue();
      const res = await route.fetch();
      const body = await res.json();
      delete body.errors;
      await route.fulfill({ response: res, json: body });
    });
    await page.goto(lessonUrl("l1"));
    await expect(page.getByRole("heading", { level: 1, name: "Bạn chưa sở hữu khóa học này" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Xem danh sách khóa học" })).toHaveAttribute("href", "/khoa-hoc");
    await expect(page).toHaveURL(/\/hoc\//);
  });

  test("CDN trả 403 (link hết hạn) → xin link mới ngầm và video vẫn phát, không hiện lỗi", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    let blocked = 0;
    await page.route("**/videolab/cdn/**/*.ts", async (route) => {
      if (blocked < 1) {
        blocked += 1;
        await route.fulfill({ status: 403, headers: { "access-control-allow-origin": BASE }, body: "" });
      } else await route.continue();
    });
    const playbackCalls: string[] = [];
    page.on("request", (r) => isPlayback(r) && playbackCalls.push(r.url()));
    await page.goto(lessonUrl("l1"));
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    await page.getByRole("button", { name: "Phát video" }).click();
    await expect.poll(() => playbackCalls.length, { timeout: 30_000 }).toBeGreaterThanOrEqual(2);
    await expect.poll(() => currentTime(page), { timeout: 30_000 }).toBeGreaterThan(1.5);
    await expect(page.getByText("Không tải được video")).toHaveCount(0);
    expect(blocked).toBe(1);
  });

  test("tự làm mới link TRƯỚC khi hết hạn (không chờ 403) khi bài dài hơn 15 phút", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    await page.clock.install({ time: new Date() });
    const calls: string[] = [];
    page.on("request", (r) => isPlayback(r) && calls.push(r.url()));
    await page.goto(lessonUrl("l1"));
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    const before = calls.length; // dev server bật StrictMode nên có thể là 2 (effect chạy đúp); production là 1
    await page.clock.fastForward("14:30"); // qua mốc 14 phút (15 phút - 60 giây)
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBe(before + 1);
    await expect(page.getByText("Không tải được video")).toHaveCount(0);
  });

  test("heartbeat: xem hết bài (2×) → gửi số nguyên, bài được đánh dấu hoàn thành ngay trong mục lục + toast", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    const bodies: Array<{ position_seconds: number; watched_delta_seconds: number }> = [];
    page.on("request", (r) => {
      if (r.method() === "POST" && /\/learn\/lessons\/\d+\/heartbeat/.test(r.url())) bodies.push(r.postDataJSON());
    });
    await page.goto(lessonUrl("l2"));
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    await playMuted(page, 2);
    await expect(page.getByText("Bạn đã hoàn thành bài này")).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText("Đã hoàn thành bài này", { exact: true })).toBeVisible();
    await expect(page.getByRole("link", { name: /Bài 2 video thật/ })).toContainText("Đang xem"); // bài hiện tại giữ aria-current
    await expect(page.getByText("Đã xong 2/4 bài")).toHaveCount(0);
    await expect(page.getByText("Đã xong 1/4 bài")).toBeVisible(); // mục lục đổi ngay không cần tải lại
    expect(bodies.length).toBeGreaterThanOrEqual(1);
    for (const b of bodies) {
      expect(Number.isInteger(b.position_seconds)).toBe(true);
      expect(Number.isInteger(b.watched_delta_seconds)).toBe(true);
      expect(b.watched_delta_seconds).toBeLessThanOrEqual(60);
    }
    const sum = bodies.reduce((a, b) => a + b.watched_delta_seconds, 0);
    expect(sum).toBeGreaterThanOrEqual(22); // >= 90% của 24 giây
  });

  test("đăng nhập thiết bị khác → heartbeat 401 SESSION_REPLACED: hộp thoại hiện và video bị pause()", async ({ page, browser }) => {
    test.setTimeout(120_000);
    await login(page, "fw4-hs-own@example.com");
    await page.goto(lessonUrl("l1"));
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    await playMuted(page);
    const other = await browser.newContext({ baseURL: BASE });
    await login(await other.newPage(), "fw4-hs-own@example.com");
    await expect(page.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeVisible({ timeout: 60_000 });
    expect(await video(page).evaluate((v: HTMLVideoElement) => v.paused)).toBe(true);
    await other.close();
  });

  test("link ngoài: iframe có sandbox; 'Đánh dấu đã học' cập nhật mục lục, tải lại vẫn 'Đã học'; bài chưa có video: 'đang được xử lý'", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    await page.goto(lessonUrl("l3"));
    const frame = page.locator("iframe");
    await expect(frame).toHaveAttribute("sandbox", "allow-scripts allow-same-origin allow-presentation");
    await expect(frame).toHaveAttribute("src", /^https:\/\/www\.youtube-nocookie\.com\/embed\//);
    await expect(page.getByText("không tự ghi tiến độ")).toBeVisible();
    const doneText = page.getByText(/^Đã xong \d\/4 bài$/);
    const doneBefore = Number(/(\d)\/4/.exec((await doneText.textContent()) ?? "")?.[1]);

    await page.getByRole("button", { name: "Đánh dấu đã học" }).click();
    await expect(page.getByText("Đã học", { exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Đánh dấu đã học" })).toHaveCount(0);
    await expect(doneText).toHaveText(`Đã xong ${doneBefore + 1}/4 bài`); // mục lục + tiến độ đổi ngay

    await page.reload();
    await expect(page.getByText("Đã học", { exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Đánh dấu đã học" })).toHaveCount(0);

    await page.goto(lessonUrl("l4"));
    await expect(page.getByText("Video bài này đang được xử lý")).toBeVisible();
  });

  test("bài video thường KHÔNG có nút 'Đánh dấu đã học'", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    await page.goto(lessonUrl("l1"));
    await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("button", { name: "Đánh dấu đã học" })).toHaveCount(0);
  });

  test("/hoc/{course} đưa tới bài cần học tiếp; id sai định dạng → 404", async ({ page }) => {
    await login(page, "fw4-hs-own@example.com");
    await page.goto(`/hoc/${ids.course}`);
    await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/\\d+$`));
    const res = await page.goto("/hoc/abc/bai/xyz");
    expect(res?.status()).toBe(404);
  });
});
