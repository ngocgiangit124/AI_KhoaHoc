import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";
import { RegisterSuccessBanner } from "./RegisterSuccessBanner";

describe("RegisterSuccessBanner", () => {
  beforeEach(() => sessionStorage.clear());

  it("không có cờ → không hiện", () => {
    render(<RegisterSuccessBanner />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("có cờ → hiện đúng biến thể và xoá cờ (F5 không hiện lại)", async () => {
    sessionStorage.setItem("vv:register-flash", "parent_pending");
    const { unmount } = render(<RegisterSuccessBanner />);
    expect(await screen.findByRole("alert")).toHaveTextContent("email xác nhận tới phụ huynh");
    expect(sessionStorage.getItem("vv:register-flash")).toBeNull();
    unmount();
    render(<RegisterSuccessBanner />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
