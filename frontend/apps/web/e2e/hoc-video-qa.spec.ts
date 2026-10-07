import { expect, test, type Page, type Request, type Response } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

/**
 * QA FW4 — các kịch bản rủi ro (docs/qa/FW4.md). Backend thật + VideoLab nội bộ, `--workers=1`.
 * Dữ liệu: `e2e/seed-e2e-learn.sh` → E2E_FW4="course=.. l1=.. l2=.. l3=.. l4=..".
 * Thao tác DB (thu hồi ghi danh, xoá tiến độ) do tiến trình phụ trên máy host làm khi thấy tệp tín hiệu trong
 * `test-results/qa-signal/<tên>.req` (container Playwright không có docker); xong nó tạo `<tên>.ack`.
 * Ca TTL ngắn chỉ chạy khi E2E_QA_TTL=1 (backend/.env tạm đặt VIDEO_PLAYBACK_TTL_MINUTES=2).
 */
const BASE = process.env.E2E_BASE_URL ?? "http://api.localhost:3000";
const PASSWORD = "matkhau-123";
const OWN = "fw4-hs-own@example.com";
const ids = Object.fromEntries((process.env.E2E_FW4 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
const SIGNAL_DIR = path.resolve("test-results/qa-signal");
test.use({ baseURL: BASE });
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.course, "Cần backend thật và E2E_FW4");

const lessonUrl = (key: string) => `/hoc/${ids.course}/bai/${ids[key]}`;
const isPlayback = (r: Request) => /\/api\/v1\/learn\/lessons\/\d+\/playback/.test(r.url());
const isHeartbeat = (r: Request) => r.method() === "POST" && /\/learn\/lessons\/(\d+)\/heartbeat/.test(r.url());
const video = (page: Page) => page.locator("video");
const currentTime = (page: Page) => video(page).evaluate((v: HTMLVideoElement) => v.currentTime);
const isPaused = (page: Page) => video(page).evaluate((v: HTMLVideoElement) => v.paused);

async function signal(name: string) {
  fs.mkdirSync(SIGNAL_DIR, { recursive: true });
  const ack = path.join(SIGNAL_DIR, `${name}.ack`);
  fs.rmSync(ack, { force: true });
  fs.writeFileSync(path.join(SIGNAL_DIR, `${name}.req`), "");
  await expect.poll(() => fs.existsSync(ack), { timeout: 60_000, intervals: [300] }).toBe(true);
}

async function login(page: Page, email = OWN, next?: string) {
  await page.goto(next ? `/dang-nhap?next=${encodeURIComponent(next)}` : "/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(next ? new RegExp(next.replace(/\//g, "\\/")) : /\/$/);
}

async function ready(page: Page) {
  await expect(page.getByRole("button", { name: "Phát video" })).toBeVisible({ timeout: 30_000 });
}

async function playMuted(page: Page, opts: { rate?: number; loop?: boolean } = {}) {
  await video(page).evaluate(
    async (v: HTMLVideoElement, o) => {
      v.muted = true;
      v.playbackRate = o.rate ?? 1;
      v.loop = !!o.loop;
      await v.play();
    },
    opts,
  );
}

test.describe("QA FW4", () => {
  test.beforeEach(async () => {
    await signal("restore");
    await signal("reset");
  });
  test.afterAll(async () => {
    await signal("restore");
    await signal("reset");
  });

  test("QA1 thu hồi ghi danh giữa phiên: heartbeat 403 → video dừng, báo thu hồi/chuyển về chi tiết khóa, không gửi heartbeat nữa", async ({ page }) => {
    test.setTimeout(150_000);
    await login(page);
    const hb: Array<{ status: number; at: number }> = [];
    page.on("response", (r: Response) => {
      if (isHeartbeat(r.request())) hb.push({ status: r.status(), at: Date.now() });
    });
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await playMuted(page, { loop: true });
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
    await signal("revoke");
    const revokedAt = Date.now();
    // Heartbeat kế tiếp (≤20 giây) nhận 403 → video dừng + báo thu hồi (hoặc chuyển về chi tiết khóa).
    await expect.poll(() => hb.some((h) => h.status === 403), { timeout: 45_000 }).toBe(true);
    await expect.poll(async () => (await video(page).count()) === 0 || (await video(page).evaluate((v: HTMLVideoElement) => v.paused)), { timeout: 10_000 }).toBe(true);
    await expect(page.getByText("Quyền học khóa này đã bị thu hồi").or(page.getByText(/chưa sở hữu/)).first()).toBeVisible({ timeout: 10_000 }).catch(() => expect(page).toHaveURL(/\/khoa-hoc\//));
    console.log(`QA1: báo thu hồi sau ${(Date.now() - revokedAt) / 1000}s; url=${page.url()}; heartbeat=${JSON.stringify(hb.map((h) => h.status))}`);
    const n = hb.length;
    await page.waitForTimeout(25_000);
    expect(hb.length).toBe(n); // đã dừng → không còn heartbeat
  });

  test("QA1b thu hồi ghi danh khi KHÔNG chuyển trang được (chặn errors.course): video dừng + màn chặn, heartbeat ngừng", async ({ page }) => {
    test.setTimeout(150_000);
    await login(page);
    await page.route("**/api/v1/learn/lessons/*", async (route) => {
      const u = route.request().url();
      if (u.includes("/playback") || u.includes("/heartbeat")) {
        if (!u.includes("/heartbeat")) return route.continue();
        const res = await route.fetch();
        if (res.status() !== 403) return route.fulfill({ response: res });
        const body = await res.json();
        delete body.errors;
        return route.fulfill({ response: res, json: body });
      }
      return route.continue();
    });
    let hb = 0;
    page.on("request", (r) => isHeartbeat(r) && hb++);
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await playMuted(page, { loop: true });
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
    await signal("revoke");
    await expect.poll(async () => (await video(page).count()) === 0 || (await video(page).evaluate((v: HTMLVideoElement) => v.paused)), { timeout: 45_000 }).toBe(true);
    await expect(page.getByText(/thu hồi|chưa sở hữu/i).first()).toBeVisible();
    const n = hb;
    await page.waitForTimeout(25_000);
    expect(hb).toBe(n);
    // Bấm phát lại sau khi bị thu hồi: không được tự phát tiếp / không spam API.
    const pb: string[] = [];
    page.on("request", (r) => isPlayback(r) && pb.push(r.url()));
    const playBtn = page.getByRole("button", { name: /^(Phát video|Phát)$/ });
    if ((await playBtn.count()) > 0 && (await playBtn.first().isVisible())) await playBtn.first().click().catch(() => {});
    await page.waitForTimeout(3000);
    console.log(`QA1b: sau bấm phát lại: videoCount=${await video(page).count()} playbackCalls=${pb.length} heartbeats=${hb - n}`);
  });

  test("QA3 hai tab cùng bài: heartbeat 429 không làm hỏng trang (không lỗi, không hộp thoại, video vẫn phát)", async ({ page, context }) => {
    test.setTimeout(240_000);
    await login(page);
    const page2 = await context.newPage();
    const statuses: number[] = [];
    const errors: string[] = [];
    for (const p of [page, page2]) {
      p.on("response", (r) => isHeartbeat(r.request()) && statuses.push(r.status()));
      p.on("pageerror", (e) => errors.push(String(e)));
    }
    await page.goto(lessonUrl("l1"));
    await page2.goto(lessonUrl("l1"));
    await ready(page);
    await ready(page2);
    await playMuted(page, { loop: true });
    await playMuted(page2, { loop: true });
    // Client giới hạn 10 giây/lần mỗi tab (≤6/phút); hai tab cùng user+bài cộng lại vượt limiter 6/phút/user/bài của backend.
    for (let i = 0; i < 7; i++) {
      await page.waitForTimeout(10_300);
      for (const p of [page, page2]) {
        await video(p).evaluate((v: HTMLVideoElement) => v.pause());
        await p.waitForTimeout(200);
        await playMuted(p, { loop: true });
      }
    }
    console.log(`QA3: statuses sau vòng pause/play=${JSON.stringify(statuses)}`);
    await expect.poll(() => statuses.filter((s) => s === 429).length, { timeout: 20_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(45_000); // qua nhịp 20 giây + cửa sổ 1 phút của limiter
    console.log(`QA3: heartbeat statuses=${JSON.stringify(statuses)}`);
    for (const p of [page, page2]) {
      await expect(p.getByText("Không tải được video")).toHaveCount(0);
      await expect(p.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toHaveCount(0);
      await expect(p.locator('main [role="alert"]')).toHaveCount(0);
      expect(await isPaused(p)).toBe(false);
    }
    expect(statuses.some((s) => s === 200)).toBe(true);
    expect(errors).toEqual([]);
  });

  test("QA4 rời trang giữa chừng: đổi bài bằng liên kết mềm và đóng/chuyển trang cứng đều ghi tiến độ (keepalive)", async ({ page }) => {
    test.setTimeout(150_000);
    await login(page);
    const bodies: Array<{ lesson: string; pos: number; delta: number }> = [];
    page.on("request", (r) => {
      const m = isHeartbeat(r) ? /lessons\/(\d+)\/heartbeat/.exec(r.url()) : null;
      if (m) bodies.push({ lesson: m[1] ?? "", pos: r.postDataJSON().position_seconds, delta: r.postDataJSON().watched_delta_seconds });
    });
    // Soft navigation: Bài 1 → Bài 2 qua liên kết trong mục lục sau ~9 giây (chưa tới nhịp 20 giây).
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await playMuted(page);
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(8);
    const before = bodies.length;
    await page.getByRole("link", { name: /Bài 2 video thật/ }).click();
    await expect(page).toHaveURL(new RegExp(`/bai/${ids.l2}$`));
    await expect.poll(() => bodies.filter((b) => b.lesson === ids.l1).length, { timeout: 10_000 }).toBeGreaterThan(before);
    const last = bodies.filter((b) => b.lesson === ids.l1).at(-1)!;
    console.log(`QA4 soft: heartbeat cuối bài 1 = ${JSON.stringify(last)}`);
    expect(last.pos).toBeGreaterThanOrEqual(7);
    // Quay lại bài 1: phải hồi đúng vị trí (resume_at_seconds).
    const pb = page.waitForResponse((r) => /lessons\/\d+\/playback/.test(r.url()) && r.url().includes(`/${ids.l1}/`));
    await page.goto(lessonUrl("l1"));
    const resume = (await (await pb).json()).data?.resume_at_seconds ?? (await (await pb).json()).resume_at_seconds;
    console.log(`QA4 soft: resume_at_seconds=${resume}`);
    expect(resume).toBeGreaterThanOrEqual(7);

    // Hard navigation: 3 cách rời trang cứng; mỗi cách xoá tiến độ, phát ~9 giây rồi rời đi, mở lại xem resume_at_seconds.
    const results: Record<string, number> = {};
    // "dong-tab" chạy ĐẦU: heartbeat bị throttle 6/phút/bài nên chạy cuối sẽ nhận 429 (lỗi của kịch bản, không phải của trang).
    for (const how of ["dong-tab", "goto-url-khac", "reload"] as const) {
      await signal("reset");
      let p = page;
      if (how === "dong-tab") p = await page.context().newPage();
      await p.goto(lessonUrl("l1"));
      await ready(p);
      await playMuted(p);
      await expect.poll(() => currentTime(p), { timeout: 20_000 }).toBeGreaterThan(8);
      const sent: string[] = [];
      p.on("request", (r) => isHeartbeat(r) && sent.push(r.postData() ?? ""));
      if (how === "goto-url-khac") await p.goto("/");
      else if (how === "reload") await p.reload();
      else await p.close({ runBeforeUnload: true });
      await page.waitForTimeout(1500);
      const pb2 = page.waitForResponse((r) => /lessons\/\d+\/playback/.test(r.url()));
      await page.goto(lessonUrl("l1"));
      const j2 = await (await pb2).json();
      results[how] = j2.data?.resume_at_seconds ?? j2.resume_at_seconds;
      console.log(`QA4 hard[${how}]: resume_at_seconds=${results[how]} heartbeatGửi=${JSON.stringify(sent)}`);
      await ready(page);
    }
    expect(results["dong-tab"]).toBeGreaterThanOrEqual(7);
    expect(results["goto-url-khac"]).toBeGreaterThanOrEqual(7);
    expect(results["reload"]).toBeGreaterThanOrEqual(7);
  });

  test("QA5a mạng chập chờn khi đang phát: offline 15 giây rồi online → trang không vỡ, phát tiếp được, heartbeat gửi bù", async ({ page, context }) => {
    test.setTimeout(180_000);
    await login(page);
    const hb: Array<{ ok: boolean; delta: number; pos: number }> = [];
    page.on("requestfinished", async (r) => {
      if (isHeartbeat(r)) hb.push({ ok: true, delta: r.postDataJSON().watched_delta_seconds, pos: r.postDataJSON().position_seconds });
    });
    page.on("requestfailed", (r) => {
      if (isHeartbeat(r)) hb.push({ ok: false, delta: r.postDataJSON().watched_delta_seconds, pos: r.postDataJSON().position_seconds });
    });
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await playMuted(page, { loop: true });
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(5);
    await context.setOffline(true);
    await page.waitForTimeout(15_000);
    await context.setOffline(false);
    const t0 = await currentTime(page);
    await page.waitForTimeout(6_000);
    const t1 = await currentTime(page);
    console.log(`QA5a: t0=${t0} t1=${t1} paused=${await isPaused(page)} hb=${JSON.stringify(hb)}`);
    await expect(page.getByText("Không tải được video")).toHaveCount(0);
    await expect(page.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toHaveCount(0);
    expect(await isPaused(page)).toBe(false);
    expect(t1).not.toBe(t0);
    // Sau khi có mạng lại, phải có heartbeat thành công (bù phần đã lỡ).
    await expect.poll(() => hb.filter((h) => h.ok).length, { timeout: 40_000 }).toBeGreaterThan(0);
    for (const h of hb) {
      expect(Number.isInteger(h.delta)).toBe(true);
      expect(h.delta).toBeLessThanOrEqual(60);
    }
  });

  test("QA5b mạng chập chờn khi TẢI video: segment/playlist lỗi 10 giây đầu → hoặc tự phục hồi, hoặc hiện lỗi + 'Thử lại' dùng được", async ({ page }) => {
    test.setTimeout(150_000);
    await login(page);
    let failing = true;
    let aborted = 0;
    await page.route("**/videolab/cdn/**", async (route) => {
      if (failing) {
        aborted++;
        return route.abort("connectionreset");
      }
      return route.continue();
    });
    let after = 0;
    page.on("request", (r) => /videolab\/cdn/.test(r.url()) && !failing && after++);
    await page.goto(lessonUrl("l1"));
    await page.waitForTimeout(10_000);
    failing = false;
    const retry = page.getByRole("button", { name: "Thử lại" });
    const play = page.getByRole("button", { name: "Phát video" });
    await expect(retry.or(play)).toBeVisible({ timeout: 40_000 }).catch(async (e) => {
      console.log(`QA5b: KẸT. cdnRequestSauKhiCóMạng=${after} phase="${await page.locator("section[aria-label^=\"Video\"]").innerText()}"`);
      throw e;
    });
    const showedError = await retry.isVisible();
    console.log(`QA5b: aborted=${aborted} hiệnLỗi=${showedError}`);
    if (showedError) {
      await retry.click();
      await expect(play).toBeVisible({ timeout: 30_000 });
    }
    await play.click();
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
  });

  test("QA5c playlist m3u8 lỗi 3 lần liên tiếp rồi bình thường (blip ~2 giây): phải tự phục hồi hoặc hiện 'Thử lại'", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    let n = 0;
    await page.route("**/videolab/cdn/**/*.m3u8", async (route) => {
      if (n < 3) {
        n++;
        return route.abort("connectionreset");
      }
      return route.continue();
    });
    await page.goto(lessonUrl("l1"));
    const retry = page.getByRole("button", { name: "Thử lại" });
    const play = page.getByRole("button", { name: "Phát video" });
    await expect(retry.or(play)).toBeVisible({ timeout: 40_000 }).catch(async (e) => {
      console.log(`QA5c: KẸT sau ${n} lần m3u8 lỗi: ${await page.locator('section[aria-label^="Video"]').innerText()}`);
      throw e;
    });
    console.log(`QA5c: m3u8 lỗi=${n}, hiệnLỗi=${await retry.isVisible()}`);
  });

  test("QA5d 3 segment .ts đầu lỗi rồi bình thường: tự phục hồi và phát được", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    let n = 0;
    await page.route("**/videolab/cdn/**/*.ts", async (route) => {
      if (n < 3) {
        n++;
        return route.abort("connectionreset");
      }
      return route.continue();
    });
    await page.goto(lessonUrl("l1"));
    const retry = page.getByRole("button", { name: "Thử lại" });
    const play = page.getByRole("button", { name: "Phát video" });
    await expect(retry.or(play)).toBeVisible({ timeout: 40_000 });
    console.log(`QA5d: ts lỗi=${n}, hiệnLỗi=${await retry.isVisible()}`);
    if (await retry.isVisible()) await retry.click();
    await expect(play).toBeVisible({ timeout: 30_000 });
    await play.click();
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
  });

  test("QA4x pagehide/visibilitychange: trạng thái visibility tại thời điểm pagehide khi điều hướng cứng", async ({ page }) => {
    await login(page);
    await page.addInitScript(() => {
      const log = (k: string) => localStorage.setItem("qa_" + k, `${document.visibilityState}@${Date.now()}`);
      window.addEventListener("pagehide", () => log("pagehide"));
      document.addEventListener("visibilitychange", () => log("vischange"));
    });
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await page.goto("/");
    const got = await page.evaluate(() => ({ ph: localStorage.getItem("qa_pagehide"), vc: localStorage.getItem("qa_vischange") }));
    console.log(`QA4x: ${JSON.stringify(got)}`);
  });

  test("QA6 mất phiên (đăng nhập thiết bị khác): video dừng, KHÔNG heartbeat/playback nữa; bấm 'Đăng nhập lại' → quay về đúng bài, phát → heartbeat 200 ghi tiến độ", async ({ page, browser }) => {
    test.setTimeout(180_000);
    await login(page);
    const hb: number[] = [];
    const pb: number[] = [];
    page.on("response", (r) => {
      if (isHeartbeat(r.request())) hb.push(r.status());
      if (isPlayback(r.request())) pb.push(r.status());
    });
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await playMuted(page, { loop: true });
    const other = await browser.newContext({ baseURL: BASE });
    await login(await other.newPage());
    await expect(page.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeVisible({ timeout: 60_000 });
    expect(await isPaused(page)).toBe(true);
    const nHb = hb.length;
    const nPb = pb.length;
    await page.waitForTimeout(25_000);
    expect(hb.length).toBe(nHb);
    expect(pb.length).toBe(nPb);
    expect(await isPaused(page)).toBe(true);
    await other.close();
    // Hộp thoại: "Đăng nhập lại" → trang đăng nhập (?next=) → đăng nhập → về đúng bài.
    await page.getByRole("link", { name: /Đăng nhập lại/ }).click();
    await expect(page).toHaveURL(/\/dang-nhap/);
    await page.getByLabel("Email hoặc số điện thoại").fill(OWN);
    await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
    await page.getByRole("button", { name: "Đăng nhập" }).click();
    await expect(page).toHaveURL(new RegExp(`/hoc/${ids.course}/bai/${ids.l1}$`), { timeout: 30_000 });
    await ready(page);
    hb.length = 0;
    await playMuted(page, { loop: true });
    await expect.poll(() => hb.length, { timeout: 45_000 }).toBeGreaterThan(0);
    console.log(`QA6: heartbeat sau đăng nhập lại = ${JSON.stringify(hb)}`);
    expect(hb.every((s) => s === 200)).toBe(true);
  });

  test("QA7 'Đánh dấu đã học': bấm đúp = 1 request; 429 báo lỗi dưới nút và bấm lại được; tải lại vẫn 'Đã học'; bài video không có nút", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    const completes: Request[] = [];
    page.on("request", (r) => r.method() === "POST" && /\/complete$/.test(r.url()) && completes.push(r));
    await page.goto(lessonUrl("l3"));
    const btn = page.getByRole("button", { name: "Đánh dấu đã học" });

    // 429 giả lập (route) cho lần đầu
    let faked = 0;
    await page.route("**/learn/lessons/*/complete", async (route) => {
      if (faked < 1) {
        faked++;
        return route.fulfill({ status: 429, contentType: "application/json", headers: { "retry-after": "30", "access-control-allow-origin": BASE, "access-control-allow-credentials": "true" }, body: JSON.stringify({ message: "Too Many Attempts.", code: "TOO_MANY_REQUESTS" }) });
      }
      return route.continue();
    });
    await btn.click();
    const msg = page.locator('p[role="alert"]');
    await expect(msg).toBeVisible();
    console.log(`QA7 429 alert: ${await msg.innerText()}`);
    await expect(btn).toBeVisible();
    await expect(btn).toBeEnabled();
    await expect(page.getByText("Đã học", { exact: true })).toHaveCount(0);

    // bấm đúp thật
    await btn.dblclick();
    await expect(page.getByText("Đã học", { exact: true })).toBeVisible();
    const real = completes.filter((r) => !faked || r !== completes[0]);
    console.log(`QA7: tổng POST complete=${completes.length} (1 giả 429)`);
    expect(real.length).toBe(1); // bấm đúp chỉ gửi 1 request thật
    await expect(page.locator('p[role="alert"]')).toHaveCount(0);

    await page.reload();
    await expect(page.getByText("Đã học", { exact: true })).toBeVisible();
    await expect(btn).toHaveCount(0);
    await page.goto(lessonUrl("l2"));
    await ready(page);
    await expect(btn).toHaveCount(0);
  });

  test("QA7b 'Đánh dấu đã học' 429 THẬT: đốt limiter 6/phút/bài bằng API rồi bấm nút", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    await signal("reset");
    await page.goto(lessonUrl("l3"));
    const btn = page.getByRole("button", { name: "Đánh dấu đã học" });
    await expect(btn).toBeVisible();
    const cookies = await page.context().cookies("http://api.localhost:8000");
    const xsrf = decodeURIComponent(cookies.find((c) => c.name === "XSRF-TOKEN")?.value ?? "");
    const device = await page.evaluate(() => Object.entries(localStorage).find(([k]) => /device/i.test(k))?.[1] ?? "");
    const statuses: number[] = [];
    for (let i = 0; i < 8; i++) {
      const r = await page.request.post(`http://api.localhost:8000/api/v1/learn/lessons/${ids.l3}/complete`, {
        headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrf, "X-Device-Id": device, Origin: BASE, Referer: `${BASE}/` },
      });
      statuses.push(r.status());
    }
    console.log(`QA7b: ${JSON.stringify(statuses)} device=${device ? "có" : "thiếu"}`);
    if (!statuses.includes(429)) test.info().annotations.push({ type: "note", description: `không đốt được limiter: ${statuses}` });
    await signal("reset"); // quay về chưa học (limiter vẫn còn) → bấm nút sẽ 429
    await page.reload();
    const b2 = page.getByRole("button", { name: "Đánh dấu đã học" });
    await expect(b2).toBeVisible();
    await b2.click();
    await expect(page.locator('p[role="alert"]')).toBeVisible();
    console.log(`QA7b alert: ${await page.locator('p[role="alert"]').innerText()}`);
    await expect(b2).toBeEnabled();
  });

  test("QA8 375px: không cuộn ngang; thanh tua và nút ≥ 44px; nút tắt tiếng hiện", async ({ browser }) => {
    test.setTimeout(120_000);
    const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 375, height: 667 }, hasTouch: true, isMobile: true });
    const page = await ctx.newPage();
    await login(page);
    const noHScroll = async (label: string) => {
      const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bw: document.body.scrollWidth }));
      console.log(`QA8 ${label}: ${JSON.stringify(m)}`);
      expect(m.sw).toBeLessThanOrEqual(m.cw);
    };
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await noHScroll("l1");
    const seek = page.getByLabel("Tua video");
    const sb = (await seek.boundingBox())!;
    console.log(`QA8 seek: ${JSON.stringify(sb)}`);
    expect(sb.height).toBeGreaterThanOrEqual(44);
    const sizes: Record<string, { w: number; h: number }> = {};
    for (const name of ["Phát", "Tắt tiếng", "Toàn màn hình"]) {
      const b = page.getByRole("button", { name, exact: true });
      await expect(b).toBeVisible();
      const bb = (await b.boundingBox())!;
      sizes[name] = { w: bb.width, h: bb.height };
      expect(bb.width).toBeGreaterThanOrEqual(44);
      expect(bb.height).toBeGreaterThanOrEqual(44);
    }
    const sel = (await page.getByLabel("Tốc độ phát").boundingBox())!;
    console.log(`QA8 nút: ${JSON.stringify(sizes)} select=${JSON.stringify(sel)}`);
    expect(sel.height).toBeGreaterThanOrEqual(44);
    const big = (await page.getByRole("button", { name: "Phát video" }).boundingBox())!;
    expect(big.width).toBeGreaterThanOrEqual(44);
    // Chạm thật vào nút tắt tiếng và phát.
    await page.getByRole("button", { name: "Tắt tiếng", exact: true }).tap();
    await expect(page.getByRole("button", { name: "Tắt tiếng", exact: true })).toHaveAttribute("aria-pressed", /true|false/);
    await page.screenshot({ path: "test-results/qa-375-l1.png" });
    const hit = await page.getByRole("button", { name: "Phát video" }).evaluate((el) => {
      const r = el.getBoundingClientRect();
      const pts = [[0.5, 0.5], [0.5, 0.2], [0.5, 0.8]].map(([fx = 0.5, fy = 0.5]) => {
        const t = document.elementFromPoint(r.left + r.width * fx, r.top + r.height * fy);
        return { fx, fy, isButton: !!t && (t === el || el.contains(t)) };
      });
      return { rect: { top: r.top, bottom: r.bottom, h: r.height }, pts };
    });
    console.log(`QA8 nút phát lớn bị che? ${JSON.stringify(hit)}`);
    await page.getByRole("button", { name: "Phát", exact: true }).tap();
    await expect.poll(() => currentTime(page), { timeout: 20_000 }).toBeGreaterThan(1.5);
    expect(hit.pts.every((q) => q.isButton)).toBe(true); // nút phát lớn phải chạm được ở giữa
    for (const k of ["l3", "l4"]) {
      await page.goto(lessonUrl(k));
      await page.waitForLoadState("networkidle");
      await noHScroll(k);
    }
    await page.screenshot({ path: "test-results/qa-375-l4.png" });
    await ctx.close();
  });

  test("QA8b 375px: màn chặn (chưa ghi danh) và 404 không cuộn ngang", async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 375, height: 667 }, hasTouch: true, isMobile: true });
    const page = await ctx.newPage();
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
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    expect(m.sw).toBeLessThanOrEqual(m.cw);
    await ctx.close();
  });
});

test.describe("QA FW4 — TTL ngắn", () => {
  test.skip(process.env.E2E_QA_TTL !== "1", "Cần E2E_QA_TTL=1 và VIDEO_PLAYBACK_TTL_MINUTES=2 ở backend");

  test("QA2 bài dài qua mốc làm mới link (TTL 2 phút, video lặp): đo khựng/dừng khi đổi link; link cũ hết hạn vẫn phát", async ({ page }) => {
    test.setTimeout(420_000);
    await login(page);
    const pbCalls: Array<{ at: number; expires?: string }> = [];
    const t0 = Date.now();
    page.on("response", async (r) => {
      if (isPlayback(r.request()) && r.status() === 200) {
        const j = await r.json().catch(() => null);
        pbCalls.push({ at: (Date.now() - t0) / 1000, expires: j?.data?.expires_at ?? j?.expires_at });
      }
    });
    const cdn403: Array<{ at: number; url: string }> = [];
    page.on("response", (r) => {
      if (/videolab\/cdn/.test(r.url()) && r.status() >= 400) cdn403.push({ at: (Date.now() - t0) / 1000, url: r.url().slice(-60) });
    });
    const errorsSeen: string[] = [];
    page.on("pageerror", (e) => errorsSeen.push(String(e)));
    await page.goto(lessonUrl("l1"));
    await ready(page);
    await video(page).evaluate((v: HTMLVideoElement) => {
      const w = window as unknown as { __s: Array<{ t: number; ct: number; paused: boolean; rs: number; src: string }>; __ev: Array<{ t: number; e: string }> };
      w.__s = [];
      w.__ev = [];
      setInterval(() => w.__s.push({ t: performance.now(), ct: v.currentTime, paused: v.paused, rs: v.readyState, src: v.currentSrc || v.src }), 100);
      for (const e of ["waiting", "stalled", "emptied", "error", "seeking", "loadstart"]) v.addEventListener(e, () => w.__ev.push({ t: performance.now(), e }));
    });
    await playMuted(page, { loop: true });
    const start = Date.now();
    // Phát liên tục 100 giây (qua mốc làm mới theo lịch ~60 giây).
    await page.waitForTimeout(100_000);
    const mid = await currentTime(page);
    // Pause 2 giây (đây là lúc link cất được đổi), rồi phát tiếp.
    await video(page).evaluate((v: HTMLVideoElement) => v.pause());
    const ctPause = await currentTime(page);
    await page.waitForTimeout(2500);
    await playMuted(page, { loop: true });
    await page.waitForTimeout(4000);
    const ctAfter = await currentTime(page);
    // Tiếp tục tới 200 giây (link ban đầu đã hết hạn lúc 120 giây).
    await page.waitForTimeout(Math.max(0, 200_000 - (Date.now() - start)));
    const data = await page.evaluate(() => {
      const w = window as unknown as { __s: Array<{ t: number; ct: number; paused: boolean; rs: number; src: string }>; __ev: Array<{ t: number; e: string }> };
      return { s: w.__s, ev: w.__ev };
    });
    // Khựng = khoảng thời gian liên tiếp khi không paused mà currentTime không tăng (loại quay vòng loop).
    let worst = 0;
    let run = 0;
    let srcChanges = 0;
    const base = data.s[0]?.t ?? 0;
    const stalls: Array<{ at: number; dur: number }> = [];
    for (let i = 1; i < data.s.length; i++) {
      const a = data.s[i - 1]!;
      const b = data.s[i]!;
      if (a.src !== b.src) srcChanges++;
      if (!b.paused && b.ct === a.ct) {
        run += b.t - a.t;
        worst = Math.max(worst, run);
      } else {
        if (run > 500) stalls.push({ at: (a.t - base) / 1000, dur: run });
        run = 0;
      }
    }
    console.log(`QA2: playback=${JSON.stringify(pbCalls)}`);
    console.log(`QA2: cdn lỗi=${JSON.stringify(cdn403)}`);
    console.log(`QA2: đổi src=${srcChanges} khựng tối đa=${Math.round(worst)}ms stalls>0.5s=${JSON.stringify(stalls)}`);
    console.log(`QA2: sự kiện=${JSON.stringify(data.ev.map((e) => ({ at: Math.round((e.t - base) / 1000), e: e.e })))}`);
    console.log(`QA2: mid=${mid} pause=${ctPause} sauPhátLại=${ctAfter} lỗiTrang=${JSON.stringify(errorsSeen)}`);
    expect(pbCalls.length).toBeGreaterThanOrEqual(2); // có làm mới theo lịch
    await expect(page.getByText("Không tải được video")).toHaveCount(0);
    expect(await isPaused(page)).toBe(false);
    expect(worst).toBeLessThan(1500);
    expect(ctAfter).toBeGreaterThan(ctPause - 0.5); // hồi đúng vị trí sau đổi link (có thể quay vòng loop)
  });
});
