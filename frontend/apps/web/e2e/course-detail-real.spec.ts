import { expect, test } from "@playwright/test";

/**
 * BUG-2 (QA FW8): khóa vừa ngừng bán phải trả 404 trong ≤ 60 giây (+ làm mới nền). Backend thật + `fw8-proxy.mjs`
 * (`detail=404` giả lập phản hồi 404 của backend cho khóa đã ngừng bán). Seed: seed-e2e-home.sh --reset.
 */
const PROXY = "http://127.0.0.1:8001";
const SLUG = "e2e-fw8-khoa-3";

test("chi tiết khóa đã ngừng bán: 404 từ backend được phản ánh sau khi cache 60 giây hết hạn", async ({ page, request }) => {
  await request.post(`${PROXY}/__reset`);
  const ok = await page.goto(`/khoa-hoc/${SLUG}`);
  expect(ok?.status()).toBe(200);
  await expect(page.getByRole("heading", { level: 1 })).toContainText("E2E FW8 Khóa nổi bật 3");

  await request.post(`${PROXY}/__mode`, { data: { detail: "404" } });
  await expect
    .poll(async () => (await request.get(`/khoa-hoc/${SLUG}`)).status(), { timeout: 130_000, intervals: [3_000, 5_000] })
    .toBe(404);
  await request.post(`${PROXY}/__reset`);
});

test("375/320px: danh mục và chi tiết khóa không cuộn ngang với tên giáo viên 150 ký tự; breadcrumb ≥ 44px (QA BUG-1, BUG-3)", async ({ page, request }) => {
  await request.post(`${PROXY}/__reset`);
  // Lấy id giáo viên từ API thật (không mở `/` để không làm đầy Data Cache của trang chủ trước các ca lỗi của home-real).
  const teachers = await (await request.get("http://api.localhost:8000/api/v1/home/teachers")).json();
  const longTeacher = (teachers.data as Array<{ id: number; name: string }>).find((t) => t.name.length >= 140);
  expect(longTeacher, "seed thiếu giáo viên tên dài").toBeTruthy();
  const longTeacherHref = `/khoa-hoc?teacher_id=${longTeacher!.id}`;
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 800 });
    for (const path of ["/khoa-hoc", "/khoa-hoc?grade=9", longTeacherHref, `/khoa-hoc/e2e-fw8-khoa-1`]) {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
      const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
      expect(sw, `${path} @${width}: scrollWidth ${sw}`).toBeLessThanOrEqual(iw);
      const crumb = page.locator("nav[aria-label='Đường dẫn'] a:visible").first();
      if (await crumb.count()) {
        const box = (await crumb.boundingBox())!;
        expect(box.height, `${path} breadcrumb cao ${box.height}`).toBeGreaterThanOrEqual(43.5);
      }
    }
  }
});
