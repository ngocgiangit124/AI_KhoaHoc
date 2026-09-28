import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { OtpInput } from "./OtpInput";

function Controlled({ onComplete }: { onComplete?: (value: string) => void }) {
  const [value, setValue] = useState("");
  return <OtpInput value={value} onChange={setValue} onComplete={onComplete} />;
}

describe("OtpInput", () => {
  it("hiện đúng số ô (mặc định 6), ô đầu có autoComplete one-time-code", () => {
    render(<OtpInput value="" onChange={vi.fn()} />);

    const boxes = screen.getAllByRole("textbox");
    expect(boxes).toHaveLength(6);
    expect(boxes[0]).toHaveAttribute("autoComplete", "one-time-code");
    expect(boxes[0]).toHaveAttribute("inputMode", "numeric");
  });

  it("gõ từng số tự nhảy ô tiếp theo và gọi onChange với chuỗi ghép", async () => {
    const user = userEvent.setup();
    render(<Controlled />);

    const boxes = screen.getAllByRole("textbox");
    await user.type(boxes[0]!, "4");
    expect(boxes[1]).toHaveFocus();
    await user.type(boxes[1]!, "8");
    expect(boxes[2]).toHaveFocus();
  });

  it("gõ đủ 6 số gọi onComplete với chuỗi đầy đủ", async () => {
    const user = userEvent.setup();
    const onComplete = vi.fn();
    render(<Controlled onComplete={onComplete} />);

    const boxes = screen.getAllByRole("textbox");
    for (const [i, digit] of ["4", "8", "2", "9", "1", "3"].entries()) {
      await user.type(boxes[i]!, digit);
    }

    expect(onComplete).toHaveBeenCalledWith("482913");
  });

  it("Backspace ở ô rỗng quay lại ô trước và xoá số của ô đó", async () => {
    const user = userEvent.setup();
    render(<Controlled />);

    const boxes = screen.getAllByRole("textbox");
    await user.type(boxes[0]!, "4");
    await user.type(boxes[1]!, "8");
    // Con trỏ đang ở ô 2 (đã nhảy sau khi gõ "8"); backspace lần 1 xoá số ở ô 2.
    await user.keyboard("{Backspace}");
    expect(boxes[1]).toHaveValue("");
    // Backspace lần 2 (ô 2 đang rỗng) nhảy về ô 1 và xoá số ở đó.
    await user.keyboard("{Backspace}");
    expect(boxes[0]).toHaveFocus();
    expect(boxes[0]).toHaveValue("");
  });

  it("dán 6 số vào ô đầu điền đủ các ô và gọi onComplete", async () => {
    const user = userEvent.setup();
    const onComplete = vi.fn();
    render(<Controlled onComplete={onComplete} />);

    const boxes = screen.getAllByRole("textbox");
    boxes[0]!.focus();
    await user.paste("482913");

    expect(onComplete).toHaveBeenCalledWith("482913");
  });

  it("hiện thông điệp lỗi và aria-invalid khi có error", () => {
    render(<OtpInput value="" onChange={vi.fn()} error="Mã OTP không đúng, vui lòng thử lại." />);

    expect(screen.getByRole("alert")).toHaveTextContent("Mã OTP không đúng, vui lòng thử lại.");
    expect(screen.getByRole("group")).toHaveAttribute("aria-invalid", "true");
  });

  it("disabled thì mọi ô đều không sửa được", () => {
    render(<OtpInput value="123456" onChange={vi.fn()} disabled />);

    for (const box of screen.getAllByRole("textbox")) {
      expect(box).toBeDisabled();
    }
  });
});
