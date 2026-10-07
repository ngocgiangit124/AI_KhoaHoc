import { useState } from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { TeacherPicker } from "./TeacherPicker";

const OPTIONS = [
  { id: 1, name: "Cô Lan" },
  { id: 2, name: "Thầy Minh" },
  { id: 3, name: "Cô Hoa" },
];

function Harness({ initial = [1, 2] }: { initial?: number[] }) {
  const [ids, setIds] = useState(initial);
  return <TeacherPicker options={OPTIONS} selected={OPTIONS.filter((o) => ids.includes(o.id))} onChange={setIds} />;
}

describe("TeacherPicker", () => {
  it("bỏ chip bằng bàn phím: focus sang chip kế bên, có thông báo aria-live", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const first = screen.getByRole("button", { name: "Bỏ Cô Lan" });
    first.focus();
    await user.keyboard("{Enter}");
    expect(screen.getByRole("status")).toHaveTextContent("Đã bỏ Cô Lan");
    await waitFor(() => expect(screen.getByRole("button", { name: "Bỏ Thầy Minh" })).toHaveFocus());
  });

  it("thêm giáo viên: thông báo 'Đã thêm …'; không bỏ được người cuối", async () => {
    const user = userEvent.setup();
    render(<Harness initial={[1]} />);
    await user.selectOptions(screen.getByLabelText("Thêm giáo viên"), "3");
    expect(screen.getByRole("status")).toHaveTextContent("Đã thêm Cô Hoa");
    await user.click(screen.getByRole("button", { name: "Bỏ Cô Hoa" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Bỏ Cô Lan" })).toHaveFocus());
  });

  it("nút bỏ có lớp vùng chạm 44px ở mobile", () => {
    render(<Harness />);
    expect(screen.getByRole("button", { name: "Bỏ Cô Lan" }).className).toContain("max-sm:size-11");
  });
});
