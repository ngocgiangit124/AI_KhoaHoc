import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import type { CourseListItem } from "@/lib/catalog/schemas";
import { FeaturedCourses } from "./FeaturedCourses";

const course = (id: number, over: Partial<CourseListItem> = {}): CourseListItem => ({
  id,
  title: `Khóa ${id}`,
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
  ...over,
});

describe("FeaturedCourses (US-019)", () => {
  it("lỗi API: thông báo + 'Tải lại' tại chỗ, tiêu đề khu vực vẫn còn", () => {
    render(<FeaturedCourses courses={null} paidCheckoutEnabled={false} />);
    expect(screen.getByRole("heading", { level: 2, name: "Khóa học nổi bật" })).toBeInTheDocument();
    expect(screen.getByText("Không tải được khóa học nổi bật")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Tải lại/ })).toHaveAttribute("href", "/");
  });

  it("rỗng: 'Khóa học sẽ sớm được cập nhật' + liên kết danh mục, không thẻ trống", () => {
    render(<FeaturedCourses courses={[]} paidCheckoutEnabled />);
    expect(screen.getByText(/Khóa học sẽ sớm được cập nhật/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xem danh mục khóa học" })).toHaveAttribute("href", "/khoa-hoc");
    expect(screen.queryAllByRole("article")).toHaveLength(0);
  });

  it.each([1, 3, 4])("%i khóa: đúng số thẻ, mỗi thẻ dẫn tới /khoa-hoc/{slug}", (n) => {
    render(<FeaturedCourses courses={Array.from({ length: n }, (_, i) => course(i + 1))} paidCheckoutEnabled />);
    expect(screen.getAllByRole("article")).toHaveLength(n);
    expect(screen.getByRole("link", { name: "Khóa 1" })).toHaveAttribute("href", "/khoa-hoc/khoa-1");
  });

  it("'Xem tất cả khóa học' dẫn tới danh mục sắp theo featured", () => {
    render(<FeaturedCourses courses={[course(1)]} paidCheckoutEnabled />);
    expect(screen.getByRole("link", { name: /Xem tất cả khóa học/ })).toHaveAttribute("href", "/khoa-hoc?sort=featured");
  });

  it("thanh toán khóa: khóa có phí hiện 'Sắp mở bán', khóa miễn phí hiện 'Miễn phí', không nút mua/giỏ hàng", () => {
    render(
      <FeaturedCourses
        courses={[course(1, { is_free: false, price: 299000 }), course(2)]}
        paidCheckoutEnabled={false}
      />,
    );
    expect(screen.getByText("Sắp mở bán")).toBeInTheDocument();
    expect(screen.getAllByText("Miễn phí").length).toBeGreaterThan(0);
    expect(screen.queryByRole("button", { name: /mua|giỏ hàng/i })).toBeNull();
    expect(screen.queryByRole("link", { name: /mua|giỏ hàng/i })).toBeNull();
  });

  it("bật thanh toán: không ghi 'Sắp mở bán'", () => {
    render(<FeaturedCourses courses={[course(1, { is_free: false, price: 299000 })]} paidCheckoutEnabled />);
    expect(screen.queryByText("Sắp mở bán")).toBeNull();
  });
});
