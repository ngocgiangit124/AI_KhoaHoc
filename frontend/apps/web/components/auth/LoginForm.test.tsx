import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

const replace = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh }) }));
const loginStudent = vi.fn();
vi.mock("@/lib/auth/api", () => ({ loginStudent: (...a: unknown[]) => loginStudent(...a) }));

import { LoginForm } from "./LoginForm";

async function fill(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "a@example.com");
  await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
  await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
}

describe("LoginForm", () => {
  beforeEach(() => {
    replace.mockReset();
    refresh.mockReset();
    loginStudent.mockReset();
  });

  it("báo lỗi field khi để trống, không gọi API", async () => {
    const user = userEvent.setup();
    render(<LoginForm />);
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
    expect(await screen.findByText("Vui lòng nhập email hoặc số điện thoại")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập mật khẩu")).toBeInTheDocument();
    expect(loginStudent).not.toHaveBeenCalled();
  });

  it("thành công → chuyển tới ?next đã qua safeRedirect", async () => {
    loginStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<LoginForm next="/lop-9" />);
    await fill(user);
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/lop-9"));
    expect(loginStudent).toHaveBeenCalledWith({ login: "a@example.com", password: "matkhau123" });
  });

  it("?next ngoài site bị bỏ → về /", async () => {
    loginStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<LoginForm next="//evil.com" />);
    await fill(user);
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
  });

  it("422 → banner chung và xoá mật khẩu, giữ login", async () => {
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR" }));
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);
    expect(await screen.findByRole("alert")).toHaveTextContent("Thông tin đăng nhập hoặc mật khẩu không đúng");
    expect(screen.getByLabelText(/Email hoặc số điện thoại/)).toHaveValue("a@example.com");
    expect(screen.getByLabelText(/^Mật khẩu/)).toHaveValue("");
    expect(replace).not.toHaveBeenCalled();
  });

  it("ACCOUNT_LOCKED → thông điệp khoá", async () => {
    loginStudent.mockRejectedValue(new ApiError(403, { message: "m", code: "ACCOUNT_LOCKED" }));
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);
    expect(await screen.findByRole("alert")).toHaveTextContent("Tài khoản của bạn đã bị khoá");
  });
});
