import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { PasswordInput } from "./PasswordInput";

describe("PasswordInput", () => {
  it("mặc định ẩn mật khẩu, bấm nút Hiện thì đổi type sang text", async () => {
    const user = userEvent.setup();
    render(<PasswordInput label="Mật khẩu" required />);

    const input = screen.getByLabelText(/Mật khẩu/) as HTMLInputElement;
    expect(input.type).toBe("password");

    await user.click(screen.getByRole("button", { name: "Hiện mật khẩu" }));
    expect(input.type).toBe("text");

    await user.click(screen.getByRole("button", { name: "Ẩn mật khẩu" }));
    expect(input.type).toBe("password");
  });
});
