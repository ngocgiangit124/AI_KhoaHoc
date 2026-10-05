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
