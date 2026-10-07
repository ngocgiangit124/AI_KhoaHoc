import { describe, expect, it } from "vitest";
import { founderPoster } from "./founder";

/** US-019 BR10: giới hạn nội dung poster; chặn nội dung vượt giới hạn hoặc chữ giữ chỗ lọt vào production. */
describe("founderPoster (hằng số nội dung)", () => {
  it("đủ mục bắt buộc, câu ≤ 200 ký tự, tên/vai trò không quá dài", () => {
    expect(founderPoster).not.toBeNull();
    const p = founderPoster!;
    expect(p.image.src).toMatch(/^\/trang-chu\//);
    expect(p.image.alt.trim().length).toBeGreaterThan(0);
    expect(p.image.alt).not.toBe(p.quote);
    expect(p.name.trim().length).toBeGreaterThan(0);
    expect(p.name.length).toBeLessThanOrEqual(80);
    expect(p.role.trim().length).toBeGreaterThan(0);
    expect(p.quote.trim().length).toBeGreaterThan(0);
    expect(p.quote.length).toBeLessThanOrEqual(200);
  });

  it("đích nút là /khoa-hoc; không có chữ giữ chỗ hay ảnh mẫu", () => {
    const p = founderPoster!;
    expect(p.action?.href).toBe("/khoa-hoc");
    const all = JSON.stringify(p);
    expect(all).not.toMatch(/\(tên mẫu\)|ảnh mẫu|\/v2\/mau\//i);
  });
});
