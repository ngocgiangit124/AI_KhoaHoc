import { existsSync } from "node:fs";
import { expect, test, type APIRequestContext, type BrowserContext, type Page } from "@playwright/test";

/**
 * e2e QA (laravel-qa) cho FW8 + FW9 — bản build production (`next start`), backend thật, dữ liệu `e2e-fw8-*`.
 * Chạy bằng `e2e/run-home-qa.sh` (cần `e2e/qa-fw8-control.py` chạy trên host để đổi dữ liệu giữa các lần đo).
 * `STATIC_URL` (http://localhost:8080) không có máy chủ ở local nên giả lập bằng `context.route` (tệp thật từ uploads của backend).
 */
test.skip(process.env.FW8_QA !== "1", "chỉ chạy qua e2e/run-home-qa.sh");
test.describe.configure({ mode: "serial" });

const CONTROL = "http://host.docker.internal:8099";
const PROXY = "http://127.0.0.1:8001";
const UPLOADS = process.env.UPLOADS_DIR ?? "";
const SHOTS = process.env.QA_SHOTS ?? "";
const ids: Record<string, number> = {};

async function control(request: APIRequestContext, action: string): Promise<string> {
  const res = await request.get(`${CONTROL}/${action}`, { timeout: 120_000 });
  const body = await res.text();
  expect(res.status(), `${action}: ${body}`).toBe(200);
  return body;
}

async function emulateStatic(ctx: BrowserContext) {
  await ctx.route(/^http:\/\/localhost:8080\//, async (route) => {
    const name = new URL(route.request().url()).pathname.replace(/^\//, "");
    if (!UPLOADS || !/^[\w.-]+$/.test(name) || !existsSync(`${UPLOADS}/${name}`)) return route.fulfill({ status: 404, body: "not found" });
    return route.fulfill({ status: 200, path: `${UPLOADS}/${name}`, contentType: "image/png" });
  });
}

const html = async (request: APIRequestContext, path = "/", headers: Record<string, string> = {}) => {
  const t = Date.now();
  const res = await request.get(path, { headers, timeout: 30_000 });
  if (Date.now() - t > 8_000) console.log(`[QA slow] GET ${path} mất ${Date.now() - t} ms`);
  return { status: res.status(), text: await res.text(), headers: res.headers() };
};

/** Gọi `/` (HTML thô = view-source) tới khi `gone` đúng; trả số ms kể từ lúc đổi dữ liệu. Giới hạn cứng 75 s (60 s + biên). */
async function elapsedUntil(request: APIRequestContext, t0: number, done: (t: string) => boolean, path = "/"): Promise<number> {
  for (;;) {
    const { text } = await html(request, path);
    if (done(text)) return Date.now() - t0;
    console.log(`[QA poll] +${Math.round((Date.now() - t0) / 1000)} s chưa đổi`);
    if (Date.now() - t0 > 75_000) return Date.now() - t0;
    await new Promise((r) => setTimeout(r, 2_000));
  }
}

async function waitFor(request: APIRequestContext, ok: (t: string) => boolean, path = "/", maxMs = 150_000) {
  const t0 = Date.now();
  while (Date.now() - t0 < maxMs) {
    if (ok((await html(request, path)).text)) return;
    await new Promise((r) => setTimeout(r, 3_000));
  }
  throw new Error(`hết ${maxMs} ms chờ điều kiện ở ${path}`);
}

function trackConsole(page: Page, bag: string[]) {
  page.on("console", (m) => {
    if (/Content Security Policy|Refused to|violates the following/i.test(m.text())) bag.push(m.text());
  });
  page.on("pageerror", (e) => bag.push(`pageerror: ${e.message}`));
}

async function smallTargets(page: Page) {
  return page.evaluate(() =>
    [...document.querySelectorAll("main a, main button, main input, main select")]
      .filter((el) => !el.className.toString().includes("after:absolute"))
      .map((el) => ({ t: (el.textContent ?? (el as HTMLInputElement).name ?? "").trim().slice(0, 40), r: el.getBoundingClientRect() }))
      .filter((x) => x.r.width > 0 && (x.r.height < 43.5 || x.r.width < 43.5))
      .map((x) => `${x.t} ${Math.round(x.r.width)}x${Math.round(x.r.height)}`),
  );
}

const teachersSection = (page: Page) => page.locator("section[aria-labelledby='giao-vien-title']");
const teacherSectionHtml = (t: string) => /id="giao-vien-title"[\s\S]*?<\/section>/.exec(t)?.[0] ?? "";

test.beforeAll(async ({ request }) => {
  const out = await control(request, "ids");
  Object.assign(ids, JSON.parse(/JSON:\s*(\{.*\})/.exec(out)?.[1] ?? "{}"));
  await request.post(`${PROXY}/__reset`);
});

test.afterAll(async ({ request }) => {
  for (const a of ["restore_gv2", "unlock_gv7", "pub_gv7course", "pub_k3", "avatar_off"]) await control(request, a).catch(() => undefined);
});

const idOf = (n: string) => ids[`e2e-fw8-${n}@example.com`];

test("Q1: ảnh giáo viên thật hiển thị (STATIC_URL giả lập), CSP img-src không chặn, không lỗi CSP; header cache của trang chủ", async ({ page, context, request }) => {
  expect(Object.keys(ids).length).toBeGreaterThanOrEqual(7);
  await control(request, "avatar_on");
  await waitFor(request, (t) => t.includes("qa-fw8-avatar.png") && t.includes("E2E FW8 Cô Hoa"));
  const csp: string[] = [];
  trackConsole(page, csp);
  await emulateStatic(context);
  const failedImgs: string[] = [];
  page.on("requestfailed", (r) => r.resourceType() === "image" && failedImgs.push(r.url()));
  await page.setViewportSize({ width: 1280, height: 900 });
  const nav = await page.goto("/");
  const cspHeader = nav!.headers()["content-security-policy"];
  expect(cspHeader).toMatch(/img-src 'self' data: http:\/\/localhost:8080/);
  expect(nav!.headers()["cache-control"] ?? "", "trang có nonce không được cache chung").not.toMatch(/public|s-maxage/);
  const section = teachersSection(page);
  await section.scrollIntoViewIfNeeded();
  const imgs = section.locator("article img");
  await expect(imgs).toHaveCount(4);
  await expect
    .poll(() => imgs.evaluateAll((els) => els.map((e) => (e as HTMLImageElement).complete && (e as HTMLImageElement).naturalWidth > 0)), { timeout: 15_000 })
    .toEqual([true, true, true, true]);
  for (const alt of ["Ảnh thầy/cô E2E FW8 Cô Hoa", "Ảnh thầy/cô E2E FW8 Thầy Nam", "Ảnh thầy/cô E2E FW8 Cô Mai"]) await expect(section.getByAltText(alt)).toBeVisible();
  // Không còn chữ cái đầu thế chỗ ảnh
  await expect(section.getByText("CH", { exact: true })).toHaveCount(0);
  expect(failedImgs, failedImgs.join(",")).toEqual([]);
  expect(csp, csp.join("\n")).toEqual([]);
  if (SHOTS) await section.screenshot({ path: `${SHOTS}/q1-teachers-1280.png` });
  // Đối chứng: ảnh từ origin lạ BỊ CSP chặn (xác nhận CSP có tác dụng, kết quả trên không phải do CSP tắt)
  const blocked = await page.evaluate(
    () => new Promise<boolean>((res) => { const i = new Image(); i.onload = () => res(false); i.onerror = () => res(true); i.src = "http://evil.example/x.png"; }),
  );
  expect(blocked).toBe(true);
});

test("Q2: khoá/rút đồng ý/hết khóa đang bán/ngừng bán -> biến mất khỏi HTML thô trang chủ trong <= 60 s (+biên 15 s)", async ({ request }) => {
  test.setTimeout(900_000);
  const log: string[] = [];
  const measure = async (name: string, action: string, restore: string | null, gone: (t: string) => boolean, present: (t: string) => boolean, path = "/") => {
    await waitFor(request, present, path);
    await control(request, action);
    const t0 = Date.now();
    const ms = await elapsedUntil(request, t0, gone, path);
    log.push(`${name}: ${Math.round(ms / 1000)} s`);
    test.info().annotations.push({ type: "timing", description: `${name}: ${ms} ms` });
    expect(ms, `${name} vẫn còn sau ${ms} ms`).toBeLessThanOrEqual(75_000);
    if (restore) await control(request, restore);
    return ms;
  };

  // gv2 rút đồng ý: không còn thẻ giáo viên (alt ảnh + bio) — họ tên vẫn có thể hiện ở thẻ khóa học (BR5: họ tên công khai)
  const gv2Gone = (t: string) => !teacherSectionHtml(t).includes("Cô Hoa") && !t.includes("Giảng chậm, rõ từng bước.") && !t.includes("Ảnh thầy/cô E2E FW8 Cô Hoa");
  await measure("rút đồng ý gv2", "withdraw_gv2", "restore_gv2", gv2Gone, (t) => teacherSectionHtml(t).includes("Cô Hoa"));
  await waitFor(request, (t) => teacherSectionHtml(t).includes("Cô Hoa")); // đồng ý lại -> hiện lại (<= 60 s)

  // gv7 bị khoá
  const gv7Gone = (t: string) => !t.includes("E2E FW8 Cô Mai") && !t.includes("Cô Mai dạy lớp 12.");
  await measure("khoá tài khoản gv7", "lock_gv7", "unlock_gv7", gv7Gone, (t) => teacherSectionHtml(t).includes("Cô Mai"));
  await waitFor(request, (t) => teacherSectionHtml(t).includes("Cô Mai"));

  // gv7 hết khóa đang bán (khóa duy nhất ngừng bán)
  await measure("khóa duy nhất của gv7 ngừng bán", "unpub_gv7course", "pub_gv7course", gv7Gone, (t) => teacherSectionHtml(t).includes("Cô Mai"));
  await waitFor(request, (t) => teacherSectionHtml(t).includes("Cô Mai"));

  // khóa nổi bật số 3 ngừng bán
  const k3Gone = (t: string) => !t.includes("E2E FW8 Khóa nổi bật 3") && !t.includes("/khoa-hoc/e2e-fw8-khoa-3");
  await measure("khóa nổi bật 3 ngừng bán", "unpub_k3", "pub_k3", k3Gone, (t) => t.includes("E2E FW8 Khóa nổi bật 3"));
  console.log(`[QA timing] ${log.join(" | ")}`);
});

test("Q2b: chi tiết khóa vừa ngừng bán (FW2, ngoài phạm vi FW8) — còn truy cập được bao lâu? (đo tối đa 2 phút; đã đo 303 s ở lượt trước)", async ({ request }) => {
  test.setTimeout(300_000);
  await waitFor(request, (t) => t.includes("E2E FW8 Khóa nổi bật 3"), "/khoa-hoc/e2e-fw8-khoa-3");
  await control(request, "unpub_k3");
  const t0 = Date.now();
  let d = await html(request, "/khoa-hoc/e2e-fw8-khoa-3");
  while (Date.now() - t0 < 120_000 && d.text.includes("E2E FW8 Khóa nổi bật 3")) {
    await new Promise((r) => setTimeout(r, 5_000));
    d = await html(request, "/khoa-hoc/e2e-fw8-khoa-3");
  }
  const secs = Math.round((Date.now() - t0) / 1000);
  console.log(`[QA detail] khóa ngừng bán: HTTP ${d.status}, còn nội dung khóa: ${d.text.includes("E2E FW8 Khóa nổi bật 3")}, sau ${secs} s`);
  await control(request, "pub_k3");
  // BUG-1 đã xác nhận (FW2 detail, ngoài FW8): ghi nhận thay vì làm fail để không chặn các ca sau (serial).
  test.info().annotations.push({ type: "BUG-1 chi tiết khóa ngừng bán", description: `còn nội dung=${d.text.includes("E2E FW8 Khóa nổi bật 3")} sau ${secs} s` });
});

test("Q3: /khoa-hoc?teacher_id= với giáo viên chưa đồng ý / không đủ điều kiện / không tồn tại / rác -> không 500, không lộ ảnh/bio", async ({ request }) => {
  await waitFor(request, (t) => t.includes("E2E FW8 Khóa nổi bật 3")); // đã khôi phục sau Q2
  const cases: Array<[string, string]> = [
    ["gv4 chưa đồng ý", `${idOf("gv4")}`],
    ["gv5 khóa duy nhất ngừng bán", `${idOf("gv5")}`],
    ["gv6 bị khoá", `${idOf("gv6")}`],
    ["không tồn tại", "999999999"],
    ["0", "0"],
    ["âm", "-1"],
    ["chữ", "abc"],
    ["số quá dài", "99999999999999999999"],
    ["khoa học", "1e3"],
    ["SQL", "1%20OR%201=1"],
    ["HTML", "%3Cscript%3Ealert(1)%3C/script%3E"],
    ["mảng", "[]=1"],
    ["rỗng", ""],
  ];
  const leaks = ["Học sinh đạt giải cấp tỉnh", "10 năm luyện thi vào 10", ".webp", "qa-fw8-avatar"];
  const report: string[] = [];
  for (const [label, v] of cases) {
    const path = label === "mảng" ? `/khoa-hoc?teacher_id${v}` : `/khoa-hoc?teacher_id=${v}`;
    const r = await html(request, path);
    report.push(`${label} -> ${r.status} noindex=${/noindex/.test(r.text)} chip=${/Giáo viên: [^<"\\]*/.exec(r.text)?.[0] ?? "-"}`);
    expect(r.status, `${label}: ${path}`).toBeLessThan(500);
    expect(r.status, label).toBe(200);
    for (const l of leaks) expect(r.text.includes(l), `${label} lộ "${l}"`).toBe(false);
  }
  console.log(`[QA teacher_id]\n${report.join("\n")}`);
  // Ngoài ra: gv5/gv6/id lạ KHÔNG hiện tên trong HTML của trang lọc (gv5 không còn khóa bán; id lạ không có kết quả)
  for (const [n, name] of [["gv5", "E2E FW8 Ngừng bán"], ["gv4", "E2E FW8 Chưa đồng ý"], ["gv6", "E2E FW8 Bị khoá"]] as const) {
    const r = await html(request, `/khoa-hoc?teacher_id=${idOf(n)}`);
    test.info().annotations.push({ type: `tên ${n} trên trang lọc`, description: String(r.text.includes(name)) });
  }
  expect((await html(request, `/khoa-hoc?teacher_id=${idOf("gv5")}`)).text).not.toContain("E2E FW8 Ngừng bán");
  expect((await html(request, "/khoa-hoc?teacher_id=999999999")).text).not.toContain("E2E FW8");
  // Tên GV chưa đồng ý chỉ là họ tên (BR5) — kiểm chip không kèm ảnh/bio ở trang chi tiết khóa của gv4
  const detail = await html(request, "/khoa-hoc/e2e-fw8-khoa-gv4");
  expect(detail.status).toBe(200);
  for (const l of leaks) expect(detail.text.includes(l), `chi tiết gv4 lộ "${l}"`).toBe(false);
});

test("Q4 (R2): SSR gắn X-Client-IP khi có teacher_id, không gắn cho trang chủ; quét nhiều teacher_id không làm 429/500 trang chủ", async ({ request }) => {
  await request.post(`${PROXY}/__reset`);
  const ip = "203.0.113.9";
  const r1 = await html(request, `/khoa-hoc?teacher_id=${idOf("gv1")}`, { "x-forwarded-for": `${ip}, 10.0.0.1` });
  expect(r1.status).toBe(200);
  await html(request, `/khoa-hoc?teacher_id=${idOf("gv2")}&page=1`, { "x-forwarded-for": "not-an-ip" });
  await html(request, `/khoa-hoc?teacher_id=${idOf("gv3")}`); // không có XFF (dev/local)
  // quét 60 teacher_id khác nhau, mỗi lần một IP khác nhau
  for (let i = 0; i < 60; i++) {
    const r = await html(request, `/khoa-hoc?teacher_id=${5_000_000 + i}`, { "x-forwarded-for": `198.51.100.${(i % 250) + 1}` });
    expect(r.status, `teacher_id=${5_000_000 + i}`).toBe(200);
  }
  const log = (await (await request.get(`${PROXY}/__log`)).json()) as Array<{ path: string; clientIp: string | null; token: boolean; status: number }>;
  const tid = log.filter((l) => l.path.includes("teacher_id="));
  expect(tid.length).toBeGreaterThanOrEqual(60);
  expect(tid.every((l) => l.token)).toBe(true);
  const gv1 = tid.find((l) => l.path.includes(`teacher_id=${idOf("gv1")}`))!;
  expect(gv1.clientIp, "XFF đầu tiên hợp lệ phải thành X-Client-IP").toBe(ip);
  expect(tid.find((l) => l.path.includes(`teacher_id=${idOf("gv2")}`))!.clientIp, "XFF sai định dạng -> không gắn").toBeNull();
  expect(tid.find((l) => l.path.includes(`teacher_id=${idOf("gv3")}`))!.clientIp, "không có XFF: Next tự thêm địa chỉ socket vào x-forwarded-for").toMatch(/127\.0\.0\.1/);
  const sweep = tid.filter((l) => l.path.includes("teacher_id=50000"));
  expect(sweep.every((l) => l.clientIp !== null)).toBe(true);
  expect(log.filter((l) => l.status === 429 || l.status >= 500).map((l) => `${l.status} ${l.path}`)).toEqual([]);
  // Trang chủ (cache còn hạn hay vừa làm mới đều được): các request nguồn của trang chủ KHÔNG mang X-Client-IP
  const home = log.filter((l) => l.path.startsWith("/api/v1/home/teachers") || l.path.includes("sort=featured"));
  expect(home.filter((l) => l.path.includes("teacher_id=")).length).toBe(0);
  expect(home.every((l) => l.clientIp === null)).toBe(true);
  const h = await html(request, "/");
  expect(h.status).toBe(200);
  expect(h.text).not.toContain("Không tải được khóa học nổi bật");
});

test("Q5: 375px — trang chủ và danh mục /khoa-hoc với tên giáo viên 150 ký tự: không cuộn ngang, vùng chạm >= 44px", async ({ page, context, request }) => {
  await emulateStatic(context);
  const csp: string[] = [];
  trackConsole(page, csp);
  await page.setViewportSize({ width: 375, height: 800 });
  const urls = ["/", "/khoa-hoc", "/khoa-hoc?sort=featured", `/khoa-hoc?teacher_id=${idOf("gv1")}`, `/khoa-hoc?teacher_id=${idOf("gv1")}&grade=10`, `/khoa-hoc/e2e-fw8-khoa-1`, "/khoa-hoc?grade=9"];
  const small: Record<string, string[]> = {};
  const overflow: string[] = [];
  for (const u of urls) {
    const res = await page.goto(u);
    expect(res?.status(), u).toBe(200);
    await page.waitForLoadState("networkidle");
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    if (sw > 375) overflow.push(`${u}: scrollWidth ${sw}`);
    small[u] = await smallTargets(page);
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/q5-${u.replace(/[^\w]+/g, "_")}.png`, fullPage: true });
  }
  // Tên 150 ký tự thực sự có mặt trên trang (kiểm thật không phải "né" ca xấu)
  const lh = await html(request, `/khoa-hoc?teacher_id=${idOf("gv1")}`);
  expect(lh.text).toContain("E2E FW8 Nguyễn Thị Hoàng Phương Thảo Nguyễn Thị");
  // thêm 320px
  await page.setViewportSize({ width: 320, height: 700 });
  for (const u of ["/", `/khoa-hoc?teacher_id=${idOf("gv1")}`]) {
    await page.goto(u);
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    if (sw > 320) overflow.push(`${u} @320: scrollWidth ${sw}`);
  }
  const bad = Object.entries(small).filter(([, v]) => v.length > 0).map(([u, v]) => `${u}: ${v.join(" | ")}`);
  expect(bad, `vùng chạm < 44px:\n${bad.join("\n")}`).toEqual([]);
  expect(csp, csp.join("\n")).toEqual([]);
  expect(overflow, `cuộn ngang:\n${overflow.join("\n")}`).toEqual([]);
});

test("Q6: bàn phím — Tab đi qua poster và từng thẻ giáo viên theo thứ tự DOM, focus nhìn thấy được", async ({ page, context }) => {
  await emulateStatic(context);
  const csp: string[] = [];
  trackConsole(page, csp);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto("/");
  type F = { tag: string; text: string; href: string | null; section: string | null; outline: string; shadow: string; w: number; h: number };
  const seq: F[] = [];
  for (let i = 0; i < 120; i++) {
    await page.keyboard.press("Tab");
    const f = await page.evaluate((): F | null => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return {
        tag: el.tagName,
        text: (el.textContent ?? "").trim().slice(0, 50),
        href: el.getAttribute("href"),
        section: el.closest("section")?.getAttribute("aria-labelledby") ?? null,
        outline: `${cs.outlineStyle} ${cs.outlineWidth} ${cs.outlineColor} offset ${cs.outlineOffset}`,
        shadow: cs.boxShadow,
        w: Math.round(r.width),
        h: Math.round(r.height),
      };
    });
    if (f) seq.push(f);
    if (f?.section === "steps-title" || f?.section === "parent-title") break;
  }
  console.log(`[QA focus]\n${seq.map((s) => `${s.section ?? "-"} | ${s.tag} ${s.href ?? ""} "${s.text}" | ${s.outline} | ${s.shadow.slice(0, 40)} | ${s.w}x${s.h}`).join("\n")}`);
  const poster = seq.filter((s) => s.section === "nguoi-sang-lap-title");
  const teachers = seq.filter((s) => s.section === "giao-vien-title");
  expect(poster.length, "poster có liên kết focus được").toBeGreaterThanOrEqual(1);
  expect(teachers.length, "mỗi thẻ giáo viên có liên kết focus được").toBeGreaterThanOrEqual(4);
  expect(seq.findIndex((s) => s.section === "nguoi-sang-lap-title")).toBeLessThan(seq.findIndex((s) => s.section === "giao-vien-title"));
  expect(teachers.every((t) => /^\/khoa-hoc\?teacher_id=\d+$/.test(t.href ?? ""))).toBe(true);
  const noRing = [...poster, ...teachers].filter((s) => (/^none/.test(s.outline) || /\b0px\b/.test(s.outline.split(" ")[1] ?? "")) && s.shadow === "none");
  expect(noRing.map((s) => s.text), "phần tử focus không có vòng focus").toEqual([]);
  // Enter trên liên kết giáo viên đầu tiên dẫn tới danh mục lọc
  await page.goto("/");
  await teachersSection(page).getByRole("link", { name: /^Xem \d+ khóa học/ }).first().focus();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/khoa-hoc\?teacher_id=\d+$/);
  expect(csp, csp.join("\n")).toEqual([]);
});
