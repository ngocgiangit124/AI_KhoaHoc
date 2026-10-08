import { expect, test, type Page } from "@playwright/test";
test.use({ baseURL: "http://api.localhost:3000" });
const MAILPIT = process.env.E2E_MAILPIT_URL ?? "http://127.0.0.1:8025";
const RUN = Date.now().toString(36);
let c = 0;
const uniq = () => { c++; const n = `${Date.now() % 100000000}`.padStart(8, "0").slice(-6) + String(c).padStart(2, "0"); return { email: `e2e-fw1adr6-${RUN}-${c}@example.com`, phone: `09${n}`, parent: `e2e-fw1adr6-ph-${RUN}-${c}@example.com`, pphone: `08${n}` }; };
const dob = (y: number) => { const d = new Date(); d.setFullYear(d.getFullYear() - y); return d.toISOString().slice(0, 10); };
interface MailItem { ID: string; Snippet?: string }
interface MailFull { Subject?: string; To?: unknown; Text?: string; HTML?: string }
async function mails(to: string): Promise<MailItem[]> { const r = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`); return ((await r.json()) as { messages?: MailItem[] }).messages ?? []; }
async function open(page: Page) {
  await page.goto("/dang-ky");
  await page.locator('[data-testid="turnstile-widget"] input[name="cf-turnstile-response"]').waitFor({ state: "attached", timeout: 20000 });
  await page.waitForTimeout(800);
}
async function fill(page: Page, u: ReturnType<typeof uniq>, y: number) {
  await page.getByLabel("Họ và tên").fill("Nguyễn Văn An");
  await page.getByLabel("Ngày sinh").fill(dob(y));
  await page.getByLabel(/^Email(?! phụ huynh)/).fill(u.email);
  await page.getByLabel(/^Số điện thoại(?! phụ huynh)/).fill(u.phone);
  await page.getByLabel("Lớp đang học").selectOption("9");
  await page.getByLabel(/^Mật khẩu/).fill("matkhau-123");
  await page.getByLabel("Xác nhận mật khẩu").fill("matkhau-123");
  await page.getByLabel(/Điều khoản sử dụng/).check();
  await page.getByLabel(/Chính sách xử lý dữ liệu/).check();
}
async function submit(page: Page) { const b = page.getByRole("button", { name: "Tạo tài khoản" }); await expect(b).toBeEnabled({ timeout: 15000 }); await b.click(); }

test("R1: nhập phụ huynh -> Bỏ qua -> gửi: không có parent_*, không thư phụ huynh", async ({ page }) => {
  const u = uniq(); let body = "";
  page.on("request", (r) => { if (r.url().includes("/auth/register") && r.method() === "POST") body = r.postData() ?? ""; });
  await open(page);
  await fill(page, u, 15);
  await page.getByLabel("Số điện thoại phụ huynh").fill(u.pphone);
  await page.getByLabel("Email phụ huynh").fill(u.parent);
  await page.getByRole("button", { name: /Bỏ qua/ }).click();
  await expect(page.getByTestId("parent-fields")).toHaveCount(0);
  await submit(page);
  await expect(page).toHaveURL(/\/$/, { timeout: 20000 });
  console.log("PAYLOAD " + body);
  expect(body).not.toContain("parent_");
  expect(body).not.toContain(u.parent);
  await page.waitForTimeout(4000);
  expect((await mails(u.parent)).length).toBe(0);
});

test("R1b: nhập sai định dạng -> Bỏ qua -> gửi được (lỗi ẩn không chặn)", async ({ page }) => {
  const u = uniq(); let body = "";
  page.on("request", (r) => { if (r.url().includes("/auth/register") && r.method() === "POST") body = r.postData() ?? ""; });
  await open(page); await fill(page, u, 15);
  await page.getByLabel("Email phụ huynh").fill("khong-hop-le");
  await page.getByLabel("Số điện thoại phụ huynh").fill("abc");
  await page.getByRole("button", { name: /Bỏ qua/ }).click();
  await submit(page);
  await expect(page).toHaveURL(/\/$/, { timeout: 20000 });
  expect(body).not.toContain("parent_");
});

test("đổi ngày sinh qua lại ngưỡng 18 sau khi mở/đóng tay", async ({ page }) => {
  await open(page);
  const pf = page.getByTestId("parent-fields"); const dobI = page.getByLabel("Ngày sinh");
  const st = async () => (await pf.count()) > 0;
  const log: string[] = [];
  await dobI.fill(dob(15)); await page.waitForTimeout(300); log.push("15 auto: " + await st());
  await page.getByRole("button", { name: /Bỏ qua/ }).click(); log.push("skip: " + await st());
  await dobI.fill(dob(25)); await page.waitForTimeout(300); log.push("25 after skip: " + await st());
  await dobI.fill(dob(15)); await page.waitForTimeout(300); log.push("15 after skip: " + await st());
  await dobI.fill(dob(25)); await page.waitForTimeout(300);
  await page.getByRole("button", { name: /Thêm thông tin phụ huynh/ }).click(); log.push("25 manual open: " + await st());
  await dobI.fill(dob(15)); await page.waitForTimeout(300); log.push("15 after open: " + await st());
  await dobI.fill(dob(25)); await page.waitForTimeout(300); log.push("25 after open: " + await st());
  console.log("TOGGLE\n" + log.join("\n"));
  await dobI.fill(""); await page.waitForTimeout(300); log.push("empty: " + await st());
  console.log("EMPTY " + await st());
});

test("under-18 có email phụ huynh -> xác thực OTP -> đúng 1 thư phụ huynh", async ({ page }) => {
  const u = uniq();
  await open(page); await fill(page, u, 15);
  await page.getByLabel("Email phụ huynh").fill(u.parent);
  await submit(page);
  await expect(page).toHaveURL(/\/$/, { timeout: 20000 });
  await page.waitForTimeout(3000);
  console.log("PARENT MAILS BEFORE OTP " + (await mails(u.parent)).length);
  expect((await mails(u.parent)).length).toBe(0);
  let code = "";
  for (let i = 0; i < 40 && !code; i++) { const m = (await mails(u.email))[0]; const s = m?.Snippet?.match(/(\d{6})/); if (s?.[1]) code = s[1]; else await new Promise((r) => setTimeout(r, 500)); }
  console.log("OTP " + (code ? "found" : "NONE"));
  expect(code).not.toBe("");
  await page.goto("/xac-thuc-otp");
  const box = page.getByLabel(/Mã xác nhận/);
  await expect(box).toBeVisible({ timeout: 15000 });
  await box.click(); await page.keyboard.type(code);
  await page.waitForTimeout(5000);
  let pm: MailItem[] = []; for (let i = 0; i < 20; i++) { pm = await mails(u.parent); if (pm.length) break; await new Promise((r) => setTimeout(r, 1000)); }
  await page.waitForTimeout(3000); pm = await mails(u.parent);
  console.log("PARENT MAILS AFTER OTP " + pm.length);
  expect(pm.length).toBe(1);
  const full = await (await fetch(`${MAILPIT}/api/v1/message/${pm[0]!.ID}`)).json() as MailFull;
  console.log("SUBJECT " + full.Subject + "\nTO " + JSON.stringify(full.To) + "\nTEXT " + (full.Text ?? "").slice(0, 1200));
  console.log("LINKS " + ((full.HTML ?? "").match(/href="[^"]+"/g) ?? []).join(" "));
});

test("pending (mô phỏng) không banner/chặn; /cho-phu-huynh 404", async ({ page }) => {
  const u = uniq();
  await open(page); await fill(page, u, 20); await submit(page);
  await expect(page).toHaveURL(/\/$/, { timeout: 20000 });
  let hits = 0;
  await page.route("**/auth/me", async (route) => {
    const res = await route.fetch(); const j = await res.json(); hits++;
    const d = j.data ?? j; d.parent_consent_status = "pending"; if (j.data) j.data = d;
    await route.fulfill({ response: res, json: j });
  });
  await page.reload(); await page.waitForTimeout(2500);
  console.log("ME HITS " + hits);
  await expect(page.locator("[role=alert],[role=status],[role=dialog]").filter({ hasText: /phụ huynh/i })).toHaveCount(0);
  await page.goto("/khoa-hoc"); await page.waitForTimeout(1500);
  await expect(page.getByText(/chờ phụ huynh|xác nhận của phụ huynh/i)).toHaveCount(0);
  expect((await page.goto("/cho-phu-huynh"))?.status()).toBe(404);
});

test("375 ảnh chụp mở/đóng", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await open(page); await page.getByLabel("Ngày sinh").fill(dob(15)); await page.waitForTimeout(400);
  const o1 = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  await page.getByRole("button", { name: /Bỏ qua/ }).click();
  const o2 = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  console.log(`OVERFLOW open=${o1} closed=${o2}`);
  expect(o1).toBeLessThanOrEqual(0);
  expect(o2).toBeLessThanOrEqual(0);
});
