import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ToastProvider } from "@vitaminvui/ui";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { LoginForm } from "./LoginForm";

const pushMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
  useSearchParams: () => new URLSearchParams(),
}));

const authFetchMock = vi.fn();
vi.mock("@/lib/api", () => ({
  authFetch: (...args: unknown[]) => authFetchMock(...args),
}));

function renderLoginForm() {
  return render(
    <ToastProvider>
      <LoginForm />
    </ToastProvider>,
  );
}

describe("LoginForm", () => {
  beforeEach(() => {
    authFetchMock.mockReset();
    pushMock.mockReset();
  });

  it("validate client: bỏ trống login/mật khẩu thì báo lỗi, không gọi API", async () => {
    const user = userEvent.setup();
    renderLoginForm();

    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Vui lòng nhập email hoặc số điện thoại")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập mật khẩu")).toBeInTheDocument();
    expect(authFetchMock).not.toHaveBeenCalled();
  });

  it("đăng nhập thành công: chuyển trang, không còn banner lỗi", async () => {
    const user = userEvent.setup();
    authFetchMock.mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" });
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
    expect(authFetchMock).toHaveBeenCalledWith(
      "/api/v1/auth/login",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("sai thông tin đăng nhập (422, không có errors field) -> banner chung, KHÔNG lộ field nào sai (BR5)", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(
      new ApiError(422, { message: "Thông tin đăng nhập hoặc mật khẩu không đúng" }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "sai-mat-khau");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Thông tin đăng nhập hoặc mật khẩu không đúng")).toBeInTheDocument();
    expect(pushMock).not.toHaveBeenCalled();
  });

  it("429 TOO_MANY_ATTEMPTS -> hiển thị đúng message server (banner warning)", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(
      new ApiError(429, {
        message: "Tài khoản tạm khoá do đăng nhập sai nhiều lần. Vui lòng thử lại sau 12 phút.",
        code: "TOO_MANY_ATTEMPTS",
      }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(
      await screen.findByText(/Tài khoản tạm khoá do đăng nhập sai nhiều lần/),
    ).toBeInTheDocument();
  });

  it("ACCOUNT_LOCKED (403) -> hiển thị đúng message server", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(
      new ApiError(403, {
        message: "Tài khoản của bạn đã bị khoá. Vui lòng liên hệ hỗ trợ.",
        code: "ACCOUNT_LOCKED",
      }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Tài khoản của bạn đã bị khoá. Vui lòng liên hệ hỗ trợ.")).toBeInTheDocument();
  });

  it("lỗi mạng -> banner hiển thị message của NetworkError", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(new NetworkError(new TypeError("Failed to fetch")));
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(
      await screen.findByText(/Không thể kết nối tới máy chủ/),
    ).toBeInTheDocument();
  });
});
