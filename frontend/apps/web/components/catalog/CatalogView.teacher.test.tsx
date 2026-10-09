import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

// CardCartProvider kéo `@/lib/api` (cần biến NEXT_PUBLIC_*); test này chỉ kiểm phần render nên mock gọn.
vi.mock("@/lib/api", () => ({ authFetch: vi.fn(), publicFetch: vi.fn() }));
vi.mock("@/lib/auth/AuthProvider", () => ({ useOptionalAuth: () => null }));

import { parseCatalogQuery } from "@/lib/catalog/query";
import type { CourseList } from "@/lib/catalog/schemas";
import { CatalogView } from "./CatalogView";

const courses = (n: number): CourseList => ({
  data: Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    title: `Khóa ${i + 1}`,
    slug: `khoa-${i + 1}`,
    short_description: null,
    grade_level: 9,
    price: 0,
    is_free: true,
    thumbnail_url: null,
    enrollments_count: 0,
    published_at: null,
    subjects: [],
    teachers: [
      { id: 3, name: "Khác" },
      { id: 7, name: "Cô Lan" },
    ],
  })),
  meta: { current_page: 1, per_page: 25, total: n, last_page: 1 },
});

const view = (search: Record<string, string>, data: CourseList) =>
  render(
    <CatalogView
      basePath="/khoa-hoc"
      query={parseCatalogQuery(search)}
      fixedGrade={null}
      subjects={[]}
      courses={data}
      paidCheckoutEnabled={false}
      title="Khóa học Toán"
      intro="x"
      breadcrumb={[{ label: "Khóa học" }]}
    />,
  );

describe("Danh mục lọc theo giáo viên (?teacher_id=, US-020 Q6)", () => {
  it("hiện chip 'Giáo viên: {tên}' lấy từ teachers[] của kết quả; bấm bỏ lọc về danh mục không còn teacher_id", () => {
    view({ teacher_id: "7" }, courses(2));
    const chip = screen.getByRole("link", { name: /Giáo viên: Cô Lan/ });
    expect(chip).toHaveAttribute("href", "/khoa-hoc");
  });

  it("form tìm kiếm giữ teacher_id", () => {
    const { container } = view({ teacher_id: "7" }, courses(2));
    expect(container.querySelector("input[type=hidden][name=teacher_id]")).toHaveAttribute("value", "7");
  });

  it("không khóa nào: trạng thái 'không tìm thấy' kèm nút xoá bộ lọc (không rò tên)", () => {
    view({ teacher_id: "999" }, courses(0));
    expect(screen.getByText("Không tìm thấy khóa học phù hợp")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xoá bộ lọc" })).toHaveAttribute("href", "/khoa-hoc");
  });

  it("không có teacher_id: không có chip giáo viên", () => {
    view({}, courses(1));
    expect(screen.queryByText(/Giáo viên: /, { selector: "a" })).toBeNull();
  });
});
