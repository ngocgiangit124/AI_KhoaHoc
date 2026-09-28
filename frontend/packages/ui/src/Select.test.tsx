import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { Select } from "./Select";

describe("Select", () => {
  it("hiển thị placeholder disabled và các option, gọi onChange khi chọn", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <Select
        label="Lớp đang học"
        required
        placeholder="-- Chọn lớp --"
        options={[
          { value: "6", label: "Lớp 6" },
          { value: "9", label: "Lớp 9" },
        ]}
        onChange={onChange}
      />,
    );

    const select = screen.getByLabelText(/Lớp đang học/);
    await user.selectOptions(select, "9");

    expect(onChange).toHaveBeenCalled();
    expect(screen.getByRole("option", { name: "-- Chọn lớp --" })).toBeDisabled();
  });
});
