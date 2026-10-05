import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { OtpInput } from "./OtpInput";

function Harness({ onComplete }: { onComplete?: (v: string) => void }) {
  const [v, setV] = useState("");
  return <OtpInput value={v} onChange={setV} onComplete={onComplete} />;
}

describe("OtpInput", () => {
  it("có 6 ô, chỉ nhận số và tự nhảy ô", async () => {
    const user = userEvent.setup();
    const onComplete = vi.fn();
    render(<Harness onComplete={onComplete} />);
    const cells = screen.getAllByRole("textbox");
    expect(cells).toHaveLength(6);
    await user.click(cells[0]!);
    await user.keyboard("1a2b3456");
    expect(cells.map((c) => (c as HTMLInputElement).value).join("")).toBe("123456");
    expect(onComplete).toHaveBeenCalledWith("123456");
  });

  it("Backspace ở ô trống lùi về ô trước và xoá", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const cells = screen.getAllByRole("textbox") as HTMLInputElement[];
    await user.click(cells[0]!);
    await user.keyboard("12");
    await user.keyboard("{Backspace}");
    expect(cells[1]!.value).toBe("");
    expect(cells[0]!.value).toBe("1");
  });

  it("dán cả mã, bỏ ký tự không phải số", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const cells = screen.getAllByRole("textbox") as HTMLInputElement[];
    await user.click(cells[0]!);
    await user.paste("12 34-56");
    expect(cells.map((c) => c.value).join("")).toBe("123456");
  });
});

describe("OtpInput (R7, busy)", () => {
  it("bấm ô phía sau khi đang trống: chữ số vào ô đầu, không để hổng", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const cells = screen.getAllByRole("textbox") as HTMLInputElement[];
    await user.click(cells[4]!);
    await user.keyboard("7");
    expect(cells[0]!.value).toBe("7");
    expect(cells[4]!.value).toBe("");
    expect(document.activeElement).toBe(cells[1]);
  });

  it("busy: readOnly + aria-busy, không nhận thêm số", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<OtpInput value="" onChange={onChange} busy />);
    const cell = screen.getAllByRole("textbox")[0]!;
    expect(cell).toHaveAttribute("readonly");
    expect(cell).toHaveAttribute("aria-busy", "true");
    await user.click(cell);
    await user.keyboard("1");
    expect(onChange).not.toHaveBeenCalled();
  });
});
