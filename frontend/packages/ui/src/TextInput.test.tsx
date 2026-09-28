import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { TextInput } from "./TextInput";

describe("TextInput", () => {
  it("hiển thị label, dấu * khi required, và gọi onChange khi gõ", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<TextInput label="Họ và tên" required onChange={onChange} />);

    const input = screen.getByLabelText(/Họ và tên/);
    await user.type(input, "An");

    expect(onChange).toHaveBeenCalled();
    expect(screen.getByText("*")).toBeInTheDocument();
  });

  it("hiển thị lỗi thay vì hint khi có error", () => {
    render(<TextInput label="Email" error="Email đã được sử dụng" hint="Không dùng để đăng nhập" />);

    expect(screen.getByRole("alert")).toHaveTextContent("Email đã được sử dụng");
    expect(screen.queryByText("Không dùng để đăng nhập")).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Email/)).toHaveAttribute("aria-invalid", "true");
  });
});
