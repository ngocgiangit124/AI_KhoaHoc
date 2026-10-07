import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { founderPoster } from "@/lib/home/founder";
import { FounderPoster } from "./FounderPoster";

describe("FounderPoster (US-019 BR10/AC18)", () => {
  it("nội dung tạm: ảnh lazy không priority, có alt, tên, vai trò, câu, nút → /khoa-hoc", () => {
    render(<FounderPoster {...founderPoster!} />);
    const img = screen.getByRole("img");
    expect(img).toHaveAttribute("loading", "lazy");
    expect(img).toHaveAttribute("alt", founderPoster!.image.alt);
    expect(img).toHaveAttribute("width", "1600");
    expect(screen.getByText(founderPoster!.name)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Xem khóa học/ })).toHaveAttribute("href", "/khoa-hoc");
  });

  it.each([
    ["thiếu ảnh", { image: { ...founderPoster!.image, src: "" } }],
    ["thiếu tên", { name: "  " }],
    ["thiếu câu", { quote: "" }],
  ])("%s -> không render gì", (_n, over) => {
    const { container } = render(<FounderPoster {...founderPoster!} {...over} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("không có action -> không có nút", () => {
    render(<FounderPoster {...founderPoster!} action={undefined} />);
    expect(screen.queryByRole("link")).toBeNull();
  });
});
