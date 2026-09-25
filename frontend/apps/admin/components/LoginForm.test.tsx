import { render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { publicFetch } from "@/lib/api";
import { LoginForm } from "./LoginForm";

vi.mock("@/lib/api", () => ({
  publicFetch: vi.fn(),
}));

describe("LoginForm (trạng thái CSRF — FE0)", () => {
  it("khoá nút Đăng nhập khi đang lấy CSRF token", () => {
    vi.mocked(publicFetch).mockReturnValue(new Promise(() => {}));
    render(<LoginForm />);
    expect(screen.getByRole("button", { name: /Đăng nhập/ })).toBeDisabled();
  });

  it("mở khoá nút Đăng nhập khi lấy CSRF token thành công", async () => {
    vi.mocked(publicFetch).mockResolvedValue({ token: "abc" });
    render(<LoginForm />);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /Đăng nhập/ })).toBeEnabled();
    });
  });

  it("hiển thị Alert lỗi khi không lấy được CSRF token", async () => {
    vi.mocked(publicFetch).mockRejectedValue(new Error("network"));
    render(<LoginForm />);
    await waitFor(() => {
      expect(screen.getByText("Không kết nối được máy chủ")).toBeInTheDocument();
    });
    expect(screen.getByRole("button", { name: /Đăng nhập/ })).toBeDisabled();
  });
});
