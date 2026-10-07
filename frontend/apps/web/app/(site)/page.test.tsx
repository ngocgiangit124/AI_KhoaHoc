import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
vi.mock("next/headers", () => ({ headers: async () => new Headers({ "x-nonce": "abc" }) }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_SITE_URL: "http://site.test", NEXT_PUBLIC_STATIC_URL: "http://static.test" } }));

const api = vi.hoisted(() => ({
  fetchFeaturedCourses: vi.fn(),
  fetchHomeTeachers: vi.fn(),
  fetchPaidCheckoutEnabled: vi.fn(),
}));
vi.mock("@/lib/home/api", () => api);

import HomePage, { metadata } from "./page";

const course = (id: number) => ({
  id,
  title: `Khóa nổi bật ${id}`,
  slug: `khoa-${id}`,
  short_description: null,
  grade_level: 9,
  price: 0,
  is_free: true,
  thumbnail_url: null,
  enrollments_count: 0,
  published_at: null,
  subjects: [],
  teachers: [],
});
const teacher = { id: 5, name: "Cô Lan", headline: null, bio: "Giới thiệu", avatar_url: null, grade_levels: [9], courses_count: 2 };

async function renderHome() {
  render(await HomePage());
}

const headingsOf = () => screen.getAllByRole("heading", { level: 2 }).map((h) => h.textContent);

beforeEach(() => {
  api.fetchFeaturedCourses.mockResolvedValue([course(1), course(2)]);
  api.fetchHomeTeachers.mockResolvedValue([teacher]);
  api.fetchPaidCheckoutEnabled.mockResolvedValue(false);
});

describe("Trang chủ (FW8 + FW9)", () => {
  it("đúng một h1; đúng 7 ô lớp 6–12 dẫn tới /khoa-hoc?grade=N", async () => {
    await renderHome();
    expect(screen.getAllByRole("heading", { level: 1 })).toHaveLength(1);
    for (const g of [6, 7, 8, 9, 10, 11, 12]) {
      expect(screen.getByRole("link", { name: new RegExp(`^Lớp\\s*${g}(?!\\d)`) })).toHaveAttribute("href", `/khoa-hoc?grade=${g}`);
    }
    expect(screen.queryByRole("link", { name: /^Lớp\s*5/ })).toBeNull();
  });

  it("thứ tự: chọn lớp → khóa nổi bật → poster → giáo viên → một buổi học → phụ huynh", async () => {
    await renderHome();
    expect(headingsOf()).toEqual([
      "Bạn đang học lớp mấy?",
      "Khóa học nổi bật",
      "Lời nhắn từ đội ngũ sáng lập",
      "Thầy cô giảng dạy",
      "Một buổi học trên VitaminVui",
      "Dành cho phụ huynh",
    ]);
  });

  it("không có nút 'Học thử', '10–25 phút' hay câu phụ huynh nhận email (US-019 AC12)", async () => {
    await renderHome();
    expect(document.body.textContent).not.toMatch(/Học thử|10–25 phút|nhận email/);
  });

  it("API khóa học lỗi: các khu khác vẫn hiện, khu nổi bật báo lỗi + Tải lại, poster vẫn có (AC9)", async () => {
    api.fetchFeaturedCourses.mockRejectedValue(new Error("boom"));
    await renderHome();
    expect(screen.getByText("Không tải được khóa học nổi bật")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Tải lại/ })).toBeInTheDocument();
    expect(headingsOf()).toContain("Lời nhắn từ đội ngũ sáng lập");
    expect(headingsOf()).toContain("Thầy cô giảng dạy");
    expect(headingsOf()).toContain("Dành cho phụ huynh");
  });

  it.each([
    ["rỗng", () => api.fetchHomeTeachers.mockResolvedValue([])],
    ["lỗi", () => api.fetchHomeTeachers.mockRejectedValue(new Error("429"))],
    ["sai schema", () => api.fetchHomeTeachers.mockRejectedValue(new Error("zod"))],
  ])("giáo viên %s: không tiêu đề, không khung (AC11/AC15), phần còn lại bình thường", async (_n, arrange) => {
    arrange();
    await renderHome();
    expect(headingsOf()).not.toContain("Thầy cô giảng dạy");
    expect(screen.queryAllByRole("article").filter((a) => a.textContent?.includes("Cô Lan"))).toHaveLength(0);
    expect(headingsOf()).toContain("Khóa học nổi bật");
    // khoảng cách: không giáo viên -> khối kế tiếp dùng pt-12 thay vì pt-4
    const steps = document.querySelector("section[aria-labelledby='steps-title']");
    expect(steps?.className).toContain("pt-12");
  });

  it("có giáo viên: khối kế tiếp pt-4 (khu giáo viên đã có py-12)", async () => {
    await renderHome();
    expect(document.querySelector("section[aria-labelledby='steps-title']")?.className).toContain("pt-4");
  });

  it("khóa có phí khi lỗi cấu hình thanh toán vẫn không có lối mua", async () => {
    api.fetchPaidCheckoutEnabled.mockResolvedValue(false);
    api.fetchFeaturedCourses.mockResolvedValue([{ ...course(1), is_free: false, price: 100000 }]);
    await renderHome();
    expect(screen.getByText("Sắp mở bán")).toBeInTheDocument();
  });

  it("JSON-LD có nonce và SEO: title/description/canonical/OG", async () => {
    await renderHome();
    const ld = document.querySelector("script[type='application/ld+json']");
    expect(ld?.getAttribute("nonce")).toBe("abc");
    expect(JSON.parse(ld?.textContent ?? "{}")["@type"]).toBe("Organization");
    expect(metadata.title).toEqual({ absolute: expect.stringContaining("VitaminVui") });
    expect(String(metadata.description).length).toBeGreaterThan(50);
    expect(metadata.alternates?.canonical).toBe("/");
    expect(metadata.openGraph).toMatchObject({ type: "website", locale: "vi_VN" });
  });
});
