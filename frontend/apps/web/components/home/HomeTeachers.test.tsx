import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import type { HomeTeacherRow } from "@/lib/home/schemas";
import { HomeTeachers } from "./HomeTeachers";

const row = (id: number, over: Partial<HomeTeacherRow> = {}): HomeTeacherRow => ({
  id,
  name: `Cô Lan ${id}`,
  headline: "Giáo viên Toán THPT chuyên",
  bio: "Dòng một.\nDòng hai.",
  avatar_url: `http://localhost:8080/uploads/${id}.webp`,
  grade_levels: [9, 10],
  courses_count: 3,
  ...over,
});

describe("HomeTeachers (US-020 FW9)", () => {
  it.each([null, []])("%j -> không render gì (không tiêu đề, không khung trống)", (rows) => {
    const { container } = render(<HomeTeachers rows={rows} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("nhiều người: tiêu đề, tối đa 6 thẻ, 1→2→3 cột", () => {
    const { container } = render(<HomeTeachers rows={Array.from({ length: 8 }, (_, i) => row(i + 1))} />);
    expect(screen.getByRole("heading", { level: 2, name: "Thầy cô giảng dạy" })).toBeInTheDocument();
    expect(screen.getAllByRole("article")).toHaveLength(6);
    expect(container.querySelector("ul")?.className).toMatch(/md:grid-cols-2.*lg:grid-cols-3/);
  });

  it("một người: một thẻ rộng vừa, không lưới", () => {
    const { container } = render(<HomeTeachers rows={[row(1)]} />);
    expect(screen.getAllByRole("article")).toHaveLength(1);
    expect(container.querySelector("ul")).toBeNull();
  });

  it("thẻ: tên, chuyên môn, lớp, số khóa, liên kết danh mục ?teacher_id=", () => {
    render(<HomeTeachers rows={[row(7, { name: "Thầy Minh" })]} />);
    const card = screen.getByRole("article");
    expect(within(card).getByRole("heading", { name: "Thầy Minh" })).toBeInTheDocument();
    expect(within(card).getByText("Giáo viên Toán THPT chuyên")).toBeInTheDocument();
    expect(within(card).getByText(/Lớp 9, 10/)).toBeInTheDocument();
    const link = within(card).getByRole("link", { name: /Xem 3 khóa học/ });
    expect(link).toHaveAttribute("href", "/khoa-hoc?teacher_id=7");
  });

  it("ảnh có alt 'Ảnh thầy/cô {tên}'; ảnh lỗi -> chữ cái đầu, không còn thẻ img", () => {
    render(<HomeTeachers rows={[row(1, { name: "Nguyễn Thu Hà" })]} />);
    const img = screen.getByAltText("Ảnh thầy/cô Nguyễn Thu Hà");
    fireEvent.error(img);
    expect(screen.queryByAltText("Ảnh thầy/cô Nguyễn Thu Hà")).toBeNull();
    expect(screen.getByText("TH")).toBeInTheDocument();
  });

  it("bio là văn bản: HTML hiện nguyên chữ, không tạo phần tử; giữ xuống dòng; cắt 3 dòng", () => {
    const { container } = render(<HomeTeachers rows={[row(1, { bio: "<img src=x onerror=alert(1)>\nDòng hai" })]} />);
    expect(container.querySelector("img[src='x']")).toBeNull();
    const p = screen.getByText(/<img src=x onerror=alert\(1\)>/);
    expect(p.className).toMatch(/line-clamp-3/);
    expect(p.className).toMatch(/whitespace-pre-line/);
  });

  it("tên rất dài cắt 2 dòng (line-clamp-2), không vỡ thẻ", () => {
    render(<HomeTeachers rows={[row(1, { name: "A".repeat(150) })]} />);
    expect(screen.getByRole("heading", { name: "A".repeat(150) }).className).toMatch(/line-clamp-2/);
  });

  it("không có bio hoặc ảnh: ẩn bio, hiện chữ cái đầu", () => {
    render(<HomeTeachers rows={[row(1, { bio: null, avatar_url: null, name: "Lê Minh" })]} />);
    expect(screen.queryByAltText(/Ảnh thầy\/cô/)).toBeNull();
    expect(screen.getByText("LM")).toBeInTheDocument();
  });
});
