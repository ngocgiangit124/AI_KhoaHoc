import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { Button } from "./Button";

describe("Button", () => {
  it("gọi onClick khi bấm", async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Lưu</Button>);
    await userEvent.click(screen.getByRole("button", { name: "Lưu" }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("khoá nút và không gọi onClick khi loading", async () => {
    const onClick = vi.fn();
    render(
      <Button onClick={onClick} loading>
        Lưu
      </Button>,
    );
    const button = screen.getByRole("button", { name: /Lưu/ });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute("aria-busy", "true");
  });
});
