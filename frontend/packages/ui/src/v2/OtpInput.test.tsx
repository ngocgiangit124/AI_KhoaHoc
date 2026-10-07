import { useState } from "react";
import { createEvent, fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { OtpInput } from "./OtpInput";

function Harness({ onComplete }: { onComplete?: (v: string) => void }) {
  const [v, setV] = useState("");
  return <OtpInput value={v} onChange={setV} onComplete={onComplete} />;
}

function paste(el: HTMLElement, text: string) {
  const event = createEvent.paste(el, { clipboardData: { getData: () => text } });
  fireEvent(el, event);
  return event;
}

describe("OtpInput v2", () => {
  it("dán '633 723' → lọc chữ số trước khi cắt và tự gửi", () => {
    const onComplete = vi.fn();
    render(<Harness onComplete={onComplete} />);
    const input = screen.getByRole("textbox") as HTMLInputElement;
    const event = paste(input, "633 723");
    expect(event.defaultPrevented).toBe(true);
    expect(input.value).toBe("633723");
    expect(onComplete).toHaveBeenCalledWith("633723");
  });

  it("dán chuỗi dài hơn 6 số → chỉ lấy 6 số đầu", () => {
    const onComplete = vi.fn();
    render(<Harness onComplete={onComplete} />);
    const input = screen.getByRole("textbox") as HTMLInputElement;
    paste(input, "Mã: 12-34-56-78");
    expect(input.value).toBe("123456");
    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("dán thiếu số → giữ nguyên, không tự gửi", () => {
    const onComplete = vi.fn();
    render(<Harness onComplete={onComplete} />);
    const input = screen.getByRole("textbox") as HTMLInputElement;
    paste(input, "12 34");
    expect(input.value).toBe("1234");
    expect(onComplete).not.toHaveBeenCalled();
  });

  it("đang gửi (busy) thì bỏ qua dán", () => {
    const onChange = vi.fn();
    render(<OtpInput value="" onChange={onChange} busy />);
    paste(screen.getByRole("textbox"), "123456");
    expect(onChange).not.toHaveBeenCalled();
  });
});
