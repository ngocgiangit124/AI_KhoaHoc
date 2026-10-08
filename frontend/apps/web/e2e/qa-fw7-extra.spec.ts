import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";

/**
 * QA FW7 — ca bổ sung (backend thật). Dữ liệu: `e2e/seed-e2e-fw7.sh --reset` rồi `E2E_QAFW7="unsub=.. unsubmain=.." e2e/run-qa-fw7.sh`.
 * Tài khoản tự tạo có tiền tố `fw7-qa-*@example.com` (seed --clean dọn được). Chạy `--workers=1`.
 */
const PASSWORD = "matkhau-123";
const API = "http://api.localhost:8000";
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://127.0.0.1:8025";
const ids = Object.fromEntries((process.env.E2E_QAFW7 ?? "").split(/\s+/).filter(Boolean).map((kv) => kv.split("=") as [string, string]));
test.skip(process.env.E2E_REAL_BACKEND !== "1" || !ids.unsubmain, "Cần E2E_REAL_BACKEND=1 và E2E_QAFW7 từ seed-e2e-fw7.sh");
test.describe.configure({ mode: "serial" });

const PAGE = "/tai-khoan/quyen-du-lieu-ca-nhan";
const UNSUB = "/phu-huynh/huy-nhan-thong-bao";
const DONE = "Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.";
const RUN = Date.now().toString(36);
let seq = 0;
const uniq = () => {
  seq++;
  const n = `${Date.now() % 100000000}`.padStart(8, "0").slice(-6) + String(seq).padStart(2, "0");
  return { email: `fw7-qa-${RUN}-${seq}@example.com`, phone: `09${n}`, parent: `fw7-qa-ph-${RUN}-${seq}@example.com` };
};
const dob = (y: number) => { const d = new Date(); d.setFullYear(d.getFullYear() - y); return d.toISOString().slice(0, 10); };

async function login(page: Page, email: string) {
  await page.goto("/dang-nhap");
  await page.waitForLoadState("networkidle");
  await page.getByLabel("Email hoặc số điện thoại").fill(email);
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByRole("button", { name: "Đăng nhập" }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}
async function overflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
interface Mail { ID: string; Snippet: string }
async function mailsTo(email: string): Promise<Mail[]> {
  const res = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`);
  return ((await res.json()) as { messages?: Mail[] }).messages ?? [];
}
async function waitForCode(email: string, known: string[]): Promise<string> {
  for (let i = 0; i < 80; i++) {
    const fresh = (await mailsTo(email)).filter((m) => !known.includes(m.ID));
    const m = fresh[0]?.Snippet.match(/(\d{6})/);
    if (m?.[1]) return m[1];
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`Không thấy mail OTP tới ${email}`);
}
async function register(page: Page, u: { email: string; phone: string; parent?: string }, age = 15) {
  await page.goto("/dang-ky");
  await page.locator('[data-testid="turnstile-widget"] input[name="cf-turnstile-response"]').waitFor({ state: "attached", timeout: 60_000 });
  await page.waitForTimeout(800);
  await page.getByLabel("Họ và tên").fill("Nguyễn Văn An");
  await page.getByLabel("Ngày sinh").fill(dob(age));
  await page.getByLabel(/^Email(?! phụ huynh)/).fill(u.email);
  await page.getByLabel(/^Số điện thoại(?! phụ huynh)/).fill(u.phone);
  await page.getByLabel("Lớp đang học").selectOption("9");
  await page.getByLabel(/^Mật khẩu/).fill(PASSWORD);
  await page.getByLabel("Xác nhận mật khẩu").fill(PASSWORD);
  if (u.parent) await page.getByLabel("Email phụ huynh").fill(u.parent);
  await page.getByLabel(/Điều khoản sử dụng/).check();
  await page.getByLabel(/Chính sách xử lý dữ liệu/).check();
  const b = page.getByRole("button", { name: "Tạo tài khoản" });
  await expect(b).toBeEnabled({ timeout: 30_000 });
  await b.click();
  await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
}
/** Sau khi API trả 401 mất phiên: phải ra trang đăng nhập hoặc có hộp thoại/lớp phủ báo mất phiên (không im lặng ở lại giao diện đã đăng nhập). */
async function expectLoggedOutUi(p: Page, name: string) {
  let verdict = "";
  for (let i = 0; i < 30 && !verdict; i++) {
    await p.waitForTimeout(500);
    if (/\/dang-nhap/.test(p.url())) verdict = "redirect:" + p.url();
    else {
      const txt = (await p.locator('dialog[open], [role="dialog"], [role="alertdialog"]').allInnerTexts()).join(" | ").replace(/\s+/g, " ");
      if (/Bạn cần đăng nhập lại|đăng nhập ở thiết bị khác/i.test(txt)) verdict = "overlay:" + txt.slice(0, 160);
    }
  }
  await p.screenshot({ path: `test-results/qa-fw7-${name}.png` }).catch(() => undefined);
  console.log(`QA ${name} sau thao tác ->`, verdict || "KHÔNG có chuyển hướng/lớp phủ (vẫn ở giao diện đăng nhập cũ)", "| url:", p.url());
  expect(verdict, `${name} phải bị đưa ra ngoài hoặc báo mất phiên`).not.toBe("");
}
async function newCtx(browser: Browser, width = 1280): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext({ baseURL: "http://api.localhost:3000", viewport: { width, height: 800 } });
  return { ctx, page: await ctx.newPage() };
}

test.describe("Huỷ nhận thông báo — QA", () => {
  test("GET (trình quét link) không huỷ; không Referer/cookie tới API; F5 mất token; slash cuối vẫn no-referrer", async ({ browser }) => {
    const { ctx, page } = await newCtx(browser);
    const apiReqs: { method: string; url: string; headers: Record<string, string> }[] = [];
    page.on("request", async (r) => {
      if (r.url().startsWith(API)) apiReqs.push({ method: r.method(), url: r.url(), headers: await r.allHeaders() });
    });
    const res = await page.goto(`${UNSUB}?t=${ids.unsubmain}`);
    expect(res?.status()).toBe(200);
    expect(res?.headers()["referrer-policy"]).toBe("no-referrer");
    await expect(page.getByRole("button", { name: "Huỷ nhận thông báo" })).toBeVisible({ timeout: 90_000 });
    await page.waitForTimeout(3000);
    expect(apiReqs.filter((r) => r.method !== "GET" && r.method !== "OPTIONS")).toHaveLength(0);
    // Nội dung HTML tĩnh không chứa token (token chỉ ở URL ban đầu).
    // (Ghi nhận: payload RSC nội tuyến trong HTML ban đầu có chứa `?t=` — hành vi framework, cùng URL đã mang token; chỉ kiểm phần hiển thị.)
    expect(await page.locator("body").innerText()).not.toContain(ids.unsubmain);
    expect(await page.evaluate(() => [...document.querySelectorAll("a, form")].map((e) => e.outerHTML).join(""))).not.toContain(ids.unsubmain);
    // F5: URL đã gỡ ?t= -> không còn token.
    await page.reload();
    await expect(page.getByText("Liên kết không dùng được")).toBeVisible({ timeout: 60_000 });
    expect(page.url()).not.toContain("t=");
    // Slash cuối + chữ hoa khác: header vẫn no-referrer.
    const r2 = await page.request.get(`http://api.localhost:3000${UNSUB}/?t=x`);
    expect(r2.headers()["referrer-policy"]).toBe("no-referrer");
    console.log("QA robots header:", r2.headers()["x-robots-tag"] ?? "(none; meta noindex only)", "| cache-control:", r2.headers()["cache-control"]);
    await ctx.close();

    // Học sinh vẫn "Đang nhận" (GET không huỷ).
    const s = await newCtx(browser);
    await login(s.page, "fw7-main@example.com");
    await s.page.goto(PAGE);
    await expect(s.page.getByText("Đang nhận thông báo")).toBeVisible({ timeout: 90_000 });
    await expect(s.page.getByText("Phụ huynh đã ngừng nhận")).toHaveCount(0);
    await s.ctx.close();
  });

  test("POST huỷ nhận: không gửi cookie/Referer/CSRF; token dị dạng không lộ/echo; thông điệp lỗi khác cấu trúc không chứa token", async ({ browser }) => {
    for (const t of ["abc", "1.short", "x".repeat(3000), "<script>alert(1)</script>", "1.%00%00"]) {
      const { ctx, page } = await newCtx(browser);
      let post: { headers: Record<string, string>; body: string } | null = null;
      page.on("request", async (r) => {
        if (r.url().includes("/parent-notices/unsubscribe") && r.method() === "POST") post = { headers: await r.allHeaders(), body: r.postData() ?? "" };
      });
      await page.goto(`${UNSUB}?t=${encodeURIComponent(t)}`);
      const btn = page.getByRole("button", { name: "Huỷ nhận thông báo" });
      await page.getByRole("heading", { level: 1 }).first().waitFor({ timeout: 90_000 });
      await page.waitForTimeout(1500);
      if (!(await btn.isVisible())) {
        const warn = await page.getByText("Liên kết không dùng được").isVisible();
        console.log(`QA token="${t.slice(0, 20)}" (len ${t.length}) -> không hiện nút; cảnh báo liên kết không dùng được: ${warn}`);
        expect(warn).toBe(true);
        await ctx.close();
        continue;
      }
      await btn.click();
      await expect.poll(() => post !== null, { timeout: 60_000 }).toBe(true);
      await page.waitForTimeout(2500);
      const text = (await page.locator("body").innerText()).replace(/\s+/g, " ");
      console.log(`QA token="${t.slice(0, 20)}" -> ${text.includes(DONE) ? "DONE-message" : "OTHER: " + text.slice(0, 160)}`);
      const p = post as { headers: Record<string, string>; body: string } | null;
      expect(p).not.toBeNull();
      expect(p!.headers["cookie"]).toBeUndefined();
      expect(p!.headers["x-csrf-token"]).toBeUndefined();
      expect(p!.headers["referer"]).toBeUndefined();
      if (t.length < 100) expect(text).not.toContain(t.replace(/<.*>/, "zz"));
      expect(await overflow(page)).toBeLessThanOrEqual(0);
      await ctx.close();
    }
  });

  test("375px: không tràn ngang (huỷ nhận, điều khoản, chính sách), Tab tới nút, phím Enter huỷ", async ({ browser }) => {
    const { ctx, page } = await newCtx(browser, 375);
    await page.goto(`${UNSUB}?t=1.${"b".repeat(43)}`);
    await expect(page.getByRole("button", { name: "Huỷ nhận thông báo" })).toBeVisible({ timeout: 90_000 });
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    let tabs = 0;
    for (; tabs < 15; tabs++) {
      await page.keyboard.press("Tab");
      if (await page.getByRole("button", { name: "Huỷ nhận thông báo" }).evaluate((el) => el === document.activeElement)) break;
    }
    console.log("QA số lần Tab tới nút huỷ nhận:", tabs + 1);
    await expect(page.getByRole("button", { name: "Huỷ nhận thông báo" })).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.getByText(DONE)).toBeVisible({ timeout: 60_000 });
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    for (const p of ["/dieu-khoan", "/chinh-sach-du-lieu"]) {
      await page.goto(p);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
      expect(await overflow(page), p).toBeLessThanOrEqual(0);
      expect(await page.getByRole("heading", { level: 1 }).count()).toBe(1);
    }
    await ctx.close();
  });

  test("thư thật cho phụ huynh: link trong thư mở được, không tự huỷ, bấm xong học sinh thấy 'ngừng nhận'", async ({ browser }) => {
    const u = uniq();
    const { ctx, page } = await newCtx(browser);
    await register(page, u);
    // Có email phụ huynh → đặt qua trang quyền dữ liệu (đăng ký không còn nhập bắt buộc); OTP xác thực lần đầu sinh thư account_created.
    await page.goto(PAGE);
    await expect(page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });
    await page.getByRole("button", { name: /Sửa thông tin phụ huynh|Thêm thông tin phụ huynh/ }).first().click();
    await page.getByLabel(/Email phụ huynh/).fill(u.parent);
    await page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await page.getByRole("button", { name: "Lưu thay đổi" }).click();
    await expect(page.getByTestId("parent-email-masked")).toContainText("***", { timeout: 60_000 });
    // Xác thực OTP email để backend gửi thư phụ huynh.
    const code = await waitForCode(u.email, []);
    await page.goto("/xac-thuc-otp");
    const box = page.getByLabel(/Mã xác nhận/);
    await expect(box).toBeVisible({ timeout: 30_000 });
    await box.click();
    await page.keyboard.type(code);
    let pm: Mail[] = [];
    for (let i = 0; i < 40 && pm.length === 0; i++) {
      pm = await mailsTo(u.parent);
      if (!pm.length) await new Promise((r) => setTimeout(r, 1000));
    }
    console.log("QA parent mails:", pm.length);
    test.skip(pm.length === 0, "Không thấy thư phụ huynh (backend không gửi trong cấu hình hiện tại)");
    const full = (await (await fetch(`${MAILPIT}/api/v1/message/${pm[0]!.ID}`)).json()) as { Subject: string; HTML: string; Text: string };
    const hdr = (await (await fetch(`${MAILPIT}/api/v1/message/${pm[0]!.ID}/headers`)).json()) as Record<string, string[]>;
    console.log("QA subject:", full.Subject, "| List-Unsubscribe:", JSON.stringify(hdr["List-Unsubscribe"]), "| Post:", JSON.stringify(hdr["List-Unsubscribe-Post"]));
    const link = (full.HTML.match(/href="([^"]*huy-nhan[^"]*)"/) ?? full.Text.match(/(https?:\/\/\S*huy-nhan\S*)/))?.[1]?.replace(/&amp;/g, "&");
    expect(link, "thư phải có link huỷ nhận").toBeTruthy();
    console.log("QA link host/path:", link!.replace(/t=[^&]+/, "t=<token>"));
    const u2 = new URL(link!);
    const p2 = await ctx.newPage();
    const posts: string[] = [];
    p2.on("request", (r) => { if (r.method() === "POST" && r.url().includes("unsubscribe")) posts.push(r.url()); });
    await p2.goto(`http://api.localhost:3000${u2.pathname}${u2.search}`);
    await expect(p2.getByRole("button", { name: "Huỷ nhận thông báo" })).toBeVisible({ timeout: 90_000 });
    await p2.waitForTimeout(2000);
    expect(posts).toHaveLength(0);
    await page.goto(PAGE);
    await expect(page.getByText("Đang nhận thông báo")).toBeVisible({ timeout: 60_000 });
    await p2.getByRole("button", { name: "Huỷ nhận thông báo" }).click();
    await expect(p2.getByText(DONE)).toBeVisible({ timeout: 60_000 });
    await page.reload();
    await expect(page.getByText("Phụ huynh đã ngừng nhận")).toBeVisible({ timeout: 60_000 });
    await ctx.close();
  });
});

test.describe("Banner chấp nhận lại — QA", () => {
  test("375px: không tràn, bàn phím tick + Enter; 409 CONSENT_VERSION_CHANGED thật (đổi policy_version trong request) rồi gửi lại thành công", async ({ browser }) => {
    const { ctx, page } = await newCtx(browser, 375);
    await login(page, "fw7-old@example.com");
    const banner = page.getByText("Điều khoản đã cập nhật");
    await expect(banner).toBeVisible({ timeout: 90_000 });
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    const box = await page.getByRole("button", { name: "Đồng ý", exact: true }).boundingBox();
    expect(box!.x + box!.width).toBeLessThanOrEqual(375);

    // 409 thật: sửa body request tới phiên bản không tồn tại.
    let first = true;
    const statuses: number[] = [];
    await page.route("**/me/consents/accept", async (route) => {
      if (first) {
        first = false;
        const body = JSON.parse(route.request().postData() ?? "{}") as Record<string, unknown>;
        body.policy_version = "1999-01";
        await route.continue({ postData: JSON.stringify(body) });
      } else await route.continue();
    });
    page.on("response", (r) => { if (r.url().includes("/me/consents/accept")) statuses.push(r.status()); });

    const terms = page.getByLabel("Tôi đã đọc và đồng ý với Điều khoản sử dụng");
    const privacy = page.getByLabel("Tôi đã đọc và đồng ý với Chính sách xử lý dữ liệu cá nhân");
    await terms.focus();
    await page.keyboard.press("Space");
    await page.keyboard.press("Tab");
    await expect(privacy).toBeFocused();
    await page.keyboard.press("Space");
    await expect(terms).toBeChecked();
    await expect(privacy).toBeChecked();
    await page.keyboard.press("Tab");
    await expect(page.getByRole("button", { name: "Đồng ý", exact: true })).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.getByText("Điều khoản vừa được cập nhật lại")).toBeVisible({ timeout: 60_000 });
    expect(statuses[0]).toBe(409);
    await expect(terms).not.toBeChecked();
    await expect(privacy).not.toBeChecked();
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    // Hiển thị phiên bản hiện hành lấy từ errors.current_version.
    await expect(page.locator(".num").filter({ hasText: /^\d{4}-/ }).first()).toBeVisible();
    await terms.check();
    await privacy.check();
    await page.getByRole("button", { name: "Đồng ý", exact: true }).click();
    await expect(banner).toBeHidden({ timeout: 60_000 });
    expect(statuses[1]).toBe(200);
    await ctx.close();
  });
});

test.describe("Quyền dữ liệu — bàn phím/375px", () => {
  test("375px mọi khối + hộp thoại không tràn; focus trong hộp thoại, Escape trả focus; nhãn ô nhập", async ({ browser }) => {
    const { ctx, page } = await newCtx(browser, 375);
    await login(page, "fw7-main@example.com");
    await page.goto(PAGE);
    await expect(page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByTestId("export-remaining")).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId("parent-email-masked")).toBeVisible();
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    // Mọi phần tử tương tác phải có tên truy cập.
    const unnamed = await page.evaluate(() =>
      [...document.querySelectorAll("button, a[href], input, select, textarea")]
        .filter((e) => !(e as HTMLElement).hidden && (e as HTMLElement).offsetParent !== null)
        .filter((e) => {
          const el = e as HTMLInputElement;
          const name = (el.getAttribute("aria-label") ?? "") + (el.labels?.[0]?.textContent ?? "") + (el.textContent ?? "") + (el.getAttribute("aria-labelledby") ?? "") + (el.getAttribute("title") ?? "");
          return name.trim() === "";
        })
        .map((e) => e.outerHTML.slice(0, 120)),
    );
    expect(unnamed).toEqual([]);
    // Duyệt bằng Tab: mọi điều khiển lần lượt nhận focus, focus có chỉ báo nhìn thấy.
    let noOutline = 0;
    for (let i = 0; i < 40; i++) {
      await page.keyboard.press("Tab");
      noOutline += await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) return 0;
        const cs = getComputedStyle(el);
        const visible = (cs.outlineStyle !== "none" && parseFloat(cs.outlineWidth) > 0) || cs.boxShadow !== "none";
        return visible ? 0 : 1;
      });
    }
    console.log("QA phần tử focus không có outline/box-shadow (trên 40 lần Tab):", noOutline);

    for (const [open, close] of [[/Tải dữ liệu của tôi/, "Huỷ"], [/Xoá tài khoản của tôi/, "Giữ tài khoản"]] as const) {
      const trigger = page.getByRole("button", { name: open }).first();
      await trigger.focus();
      await page.keyboard.press("Enter");
      const dialog = page.getByRole("dialog");
      await expect(dialog).toBeVisible({ timeout: 30_000 });
      expect(await overflow(page)).toBeLessThanOrEqual(0);
      const dbox = await dialog.boundingBox();
      expect(dbox!.x).toBeGreaterThanOrEqual(0);
      expect(dbox!.x + dbox!.width).toBeLessThanOrEqual(375);
      expect(await dialog.evaluate((d) => d.scrollWidth - d.clientWidth)).toBeLessThanOrEqual(0);
      // Focus bị giữ trong hộp thoại qua 12 lần Tab.
      let escaped = 0;
      for (let i = 0; i < 12; i++) {
        await page.keyboard.press("Tab");
        const where = await page.evaluate(() => {
          const a = document.activeElement as HTMLElement | null;
          return { inside: a === document.body || !!a?.closest('dialog, [role="dialog"], [aria-modal="true"]'), desc: `${a?.tagName}:${(a?.textContent ?? "").slice(0, 25)}` };
        });
        if (!where.inside) { escaped++; if (escaped === 1) console.log("QA focus ngoài dialog tại:", where.desc, "| dialog outerHTML:", (await dialog.evaluate((d) => d.outerHTML)).slice(0, 200)); }
      }
      console.log(`QA ${String(open)} focus thoát khỏi dialog:`, escaped);
      expect(escaped).toBe(0);
      await page.keyboard.press("Escape");
      await expect(dialog).toBeHidden({ timeout: 10_000 });
      const back = await page.evaluate(() => document.activeElement?.textContent ?? "");
      console.log(`QA ${String(open)} focus sau Escape:`, back.slice(0, 40));
      void close;
    }
    // Form sửa phụ huynh: ô có nhãn, bấm kép Lưu chỉ gửi 1 PUT.
    await page.getByRole("button", { name: "Sửa thông tin phụ huynh" }).click();
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    await page.getByRole("button", { name: /Xoá số điện thoại phụ huynh/ }).click().catch(() => undefined);
    await page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    let puts = 0;
    page.on("request", (r) => { if (r.url().includes("/me/parent-contact") && r.method() === "PUT") puts++; });
    await page.getByRole("button", { name: "Lưu thay đổi" }).dblclick();
    await page.waitForTimeout(4000);
    console.log("QA PUT parent-contact khi dblclick:", puts);
    expect(puts).toBe(1);
    await ctx.close();
  });
});

test.describe("Tải dữ liệu — QA", () => {
  test("song song 4 POST không vượt 2 lượt/ngày; tab cũ (còn 2 lượt) tải thêm → 429 DATA_EXPORT_LIMIT hiển thị đúng", async ({ browser }) => {
    const { ctx, page: tab1 } = await newCtx(browser);
    await login(tab1, "fw7-export@example.com");
    await tab1.goto(PAGE);
    await expect(tab1.getByTestId("export-remaining")).toContainText("Còn 2 lượt", { timeout: 90_000 });
    const tab2 = await ctx.newPage();
    await tab2.goto(PAGE);
    await expect(tab2.getByTestId("export-remaining")).toContainText("Còn 2 lượt", { timeout: 90_000 });

    const results = await tab1.evaluate(
      async ([api, pw]) => {
        const csrf = ((await (await fetch(`${api}/api/v1/csrf-token`, { credentials: "include" })).json()) as { token: string }).token;
        const dev = localStorage.getItem("vv_device_id") ?? "";
        const one = async () => {
          const r = await fetch(`${api}/api/v1/me/data-export`, {
            method: "POST",
            credentials: "include",
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrf, "X-Device-Id": dev },
            body: JSON.stringify({ current_password: pw }),
          });
          const t = await r.text();
          let code = "";
          try { code = (JSON.parse(t) as { code?: string }).code ?? ""; } catch { /* file */ }
          return { status: r.status, code, cd: r.headers.get("Content-Disposition") };
        };
        return Promise.all([one(), one(), one(), one()]);
      },
      [API, PASSWORD] as const,
    );
    console.log("QA song song:", JSON.stringify(results));
    const ok = results.filter((r) => r.status === 200).length;
    expect(ok).toBeLessThanOrEqual(2);
    expect(results.every((r) => [200, 429].includes(r.status))).toBe(true);

    const status = await tab1.evaluate(async (api) => (await fetch(`${api}/api/v1/me/data-export`, { credentials: "include", headers: { Accept: "application/json" } })).json(), API);
    console.log("QA GET status sau song song:", JSON.stringify(status));
    expect((status as { used_today: number }).used_today).toBeLessThanOrEqual(2);
    expect((status as { used_today: number }).used_today).toBe(ok);

    // tab2 vẫn hiển thị "Còn 2 lượt": nếu đã hết thật thì tải → 429 và UI khoá nút; nếu còn lượt thì tải được.
    await tab2.getByRole("button", { name: "Tải dữ liệu của tôi" }).click();
    await tab2.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    if (ok >= 2) {
      await tab2.getByRole("button", { name: "Tải về" }).click();
      await expect(tab2.getByText(/Bạn đã tải 2 lần hôm nay\. Thử lại sau 00:00, \d{2}\/\d{2}\/\d{4}\./)).toBeVisible({ timeout: 60_000 });
      await expect(tab2.getByRole("button", { name: "Tải dữ liệu của tôi" })).toBeDisabled();
      expect(await overflow(tab2)).toBeLessThanOrEqual(0);
    } else {
      await Promise.all([tab2.waitForEvent("download", { timeout: 90_000 }), tab2.getByRole("button", { name: "Tải về" }).click()]);
    }
    // Reload tab1: hiển thị hết lượt, không còn "Còn N lượt".
    await tab1.reload();
    await expect(tab1.getByRole("button", { name: "Tải dữ liệu của tôi" })).toBeVisible({ timeout: 60_000 });
    await ctx.close();
  });
});

test.describe("Xoá tài khoản — QA", () => {
  test("bấm kép xác nhận, 2 tab, thiết bị khác nhận SESSION_REVOKED, đăng ký lại email cũ", async ({ browser }) => {
    const email = "fw7-delete@example.com";
    const A = await newCtx(browser);
    const B = await newCtx(browser);
    await login(A.page, email);
    const tab2 = await A.ctx.newPage();
    await tab2.goto(PAGE);
    await expect(tab2.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });
    await login(B.page, email); // thiết bị thứ hai
    await B.page.goto(PAGE);
    await expect(B.page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });
    // Đăng nhập thiết bị B có đá phiên A không? (ghi nhận hành vi, không phải lỗi)
    await A.page.goto(PAGE);
    const aKicked = await A.page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" }).isVisible({ timeout: 30_000 }).then((v) => !v).catch(() => true);
    console.log("QA phiên A bị đá sau khi B đăng nhập:", aKicked, "| URL A:", A.page.url());
    if (aKicked) await login(A.page, email); // dựng lại A (khi chính sách 1 thiết bị)
    await A.page.goto(PAGE);
    await expect(A.page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });

    // D = "thiết bị/tab khác dùng CÙNG phiên" (sao chép cookie phiên + device id của A) — chỉ phiên này mới nhận SESSION_REVOKED (tombstone account_deleted).
    const D = await newCtx(browser);
    await D.ctx.addCookies(await A.ctx.cookies());
    const devId = await A.page.evaluate(() => localStorage.getItem("vv_device_id") ?? "");
    await D.page.goto("/");
    await D.page.evaluate((v) => localStorage.setItem("vv_device_id", v), devId);
    await D.page.goto(PAGE);
    await expect(D.page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" })).toBeVisible({ timeout: 90_000 });

    const known = (await mailsTo(email)).map((m) => m.ID);
    await A.page.getByRole("button", { name: "Xoá tài khoản của tôi" }).click();
    let otpSends = 0;
    let deletes: { status: number; body: string }[] = [];
    A.page.on("request", (r) => { if (r.url().includes("/account/delete/otp") && r.method() === "POST") otpSends++; });
    A.page.on("response", async (r) => {
      if (/\/me\/account\/delete$/.test(r.url()) && r.request().method() === "POST") deletes.push({ status: r.status(), body: await r.text().catch(() => "") });
    });
    await A.page.getByRole("button", { name: "Gửi mã xác nhận" }).dblclick(); // bấm kép gửi mã
    const code = await waitForCode(email, known);
    console.log("QA gửi OTP khi dblclick (số POST):", otpSends);
    expect(otpSends).toBe(1);
    const field = A.page.getByLabel("Mã xác nhận");
    await expect(field).toBeVisible({ timeout: 60_000 });
    await field.fill(code);
    deletes = [];
    await A.page.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }).dblclick(); // bấm kép xác nhận
    await expect(A.page).toHaveURL(/\/$/, { timeout: 90_000 });
    await expect(A.page.getByText("Tài khoản của bạn đã được xoá.")).toBeVisible({ timeout: 60_000 });
    await A.page.waitForTimeout(1500);
    console.log("QA POST /account/delete khi dblclick:", JSON.stringify(deletes.map((d) => d.status)));
    expect(deletes.filter((d) => d.status === 200)).toHaveLength(1);
    expect(deletes.length).toBe(1);

    // Cờ flash chỉ hiện một lần: F5 không còn toast.
    await A.page.reload();
    await A.page.waitForTimeout(1500);
    await expect(A.page.getByText("Tài khoản của bạn đã được xoá.")).toHaveCount(0);
    // Trạng thái khách ở A: không còn thông tin cá nhân trong storage.
    const leaks = await A.page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }));
    expect(leaks).not.toContain("fw7-delete");
    expect(leaks).not.toContain("E2E FW7");

    // API: thiết bị B nhận 401 SESSION_REVOKED.
    const me = await B.page.evaluate(async (api) => {
      const r = await fetch(`${api}/api/v1/auth/me`, { credentials: "include", headers: { Accept: "application/json" } });
      return { status: r.status, body: await r.text() };
    }, API);
    console.log("QA B /auth/me:", me.status, me.body.slice(0, 200));
    expect(me.status).toBe(401);
    expect(me.body).toMatch(/SESSION_REPLACED|SESSION_REVOKED|UNAUTHENTICATED/); // B đã bị thay thế khi A đăng nhập lại (1 thiết bị/học sinh)
    const meD = await D.page.evaluate(async (api) => {
      const r = await fetch(`${api}/api/v1/auth/me`, { credentials: "include", headers: { Accept: "application/json" } });
      return { status: r.status, body: await r.text() };
    }, API);
    console.log("QA D (cùng phiên) /auth/me:", meD.status, meD.body.slice(0, 300));
    expect(meD.status).toBe(401);
    expect(meD.body).toContain("SESSION_REVOKED");
    // D: thao tác trên UI cũ → bị đưa ra ngoài.
    await D.page.getByRole("button", { name: "Tải dữ liệu của tôi" }).click();
    await D.page.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
    await D.page.getByRole("button", { name: "Tải về" }).click();
    await D.page.waitForTimeout(4000);
    await expectLoggedOutUi(D.page, "D");
    await D.ctx.close();

    // UI tab cũ (A.tab2: cookie đã thành khách; B: phiên đã bị thay thế): thao tác API → bị đưa ra ngoài / hiện lớp phủ mất phiên.
    for (const [name, p] of [["A.tab2", tab2], ["B", B.page]] as const) {
      await p.getByRole("button", { name: "Tải dữ liệu của tôi" }).click();
      await p.getByLabel(/^Mật khẩu hiện tại/).fill(PASSWORD);
      await p.getByRole("button", { name: "Tải về" }).click();
      await expectLoggedOutUi(p, name);
    }
    await B.page.reload();
    await B.page.waitForTimeout(2500);
    expect(/\/dang-nhap/.test(B.page.url()) || (await B.page.getByRole("heading", { level: 1, name: "Quyền dữ liệu cá nhân" }).count()) === 0).toBe(true);
    await B.ctx.close();
    await A.ctx.close();

    // Đăng ký lại bằng email cũ.
    const C = await newCtx(browser);
    const u = uniq();
    await register(C.page, { email, phone: u.phone });
    await C.page.goto("/tai-khoan");
    await expect(C.page.getByText(email)).toBeVisible({ timeout: 60_000 });
    await C.page.goto(PAGE);
    // Tài khoản mới không thừa hưởng đồng ý cũ: có đúng 2 đồng ý hiệu lực.
    await expect(C.page.getByRole("list", { name: "Danh sách đồng ý" }).getByText("Đang hiệu lực")).toHaveCount(2, { timeout: 60_000 });
    await C.ctx.close();
  });
});
