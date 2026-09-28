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

  it("sai thông tin đăng nhập (422 VALIDATION_ERROR, envelope thật từ backend) -> banner dùng errors.login[0], KHÔNG dùng message chung, KHÔNG lộ field nào sai (BR5)", async () => {
    const user = userEvent.setup();
    // Envelope thật (backend/app/Services/Auth/LoginService.php genericFailure() +
    // ApiExceptionRenderer::resolve() cho ValidationException): `message` top-level chỉ
    // là câu validate CHUNG, câu thông báo thật nằm trong `errors.login[0]`.
    authFetchMock.mockRejectedValueOnce(
      new ApiError(422, {
        message: "Dữ liệu gửi lên không hợp lệ.",
        code: "VALIDATION_ERROR",
        errors: { login: ["Thông tin đăng nhập hoặc mật khẩu không đúng."] },
      }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "sai-mat-khau");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Thông tin đăng nhập hoặc mật khẩu không đúng.")).toBeInTheDocument();
    expect(screen.queryByText("Dữ liệu gửi lên không hợp lệ.")).not.toBeInTheDocument();
    // Không gắn lỗi xuống field (BR5) — chỉ có 1 dòng thông báo (banner), field vẫn "sạch".
    expect(screen.getByLabelText(/Email hoặc số điện thoại/)).not.toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText(/Mật khẩu/)).not.toHaveAttribute("aria-invalid", "true");
    expect(pushMock).not.toHaveBeenCalled();
  });

  it("429 TOO_MANY_ATTEMPTS -> hiển thị đúng message server (banner warning), không có errors object", async () => {
    const user = userEvent.setup();
    // Envelope thật: limiter `login` không có handler tuỳ chỉnh
    // (`AppServiceProvider::configureRateLimiters`) nên rơi vào nhánh
    // `HttpExceptionInterface` mặc định của `ApiExceptionRenderer` — không có `errors`.
    authFetchMock.mockRejectedValueOnce(
      new ApiError(429, {
        message: "Bạn thao tác quá nhanh, vui lòng thử lại sau.",
        code: "TOO_MANY_ATTEMPTS",
      }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Bạn thao tác quá nhanh, vui lòng thử lại sau.")).toBeInTheDocument();
  });

  it("ACCOUNT_LOCKED (403) -> hiển thị đúng message server (LoginService::authenticate), không có errors object", async () => {
    const user = userEvent.setup();
    // Envelope thật: `DomainException('ACCOUNT_LOCKED', 'Tài khoản của bạn đã bị khoá.', 403)`
    // (backend/app/Services/Auth/LoginService.php) — không kèm `context()`/`errors`.
    authFetchMock.mockRejectedValueOnce(
      new ApiError(403, {
        message: "Tài khoản của bạn đã bị khoá.",
        code: "ACCOUNT_LOCKED",
      }),
    );
    renderLoginForm();

    await user.type(screen.getByLabelText(/Email hoặc số điện thoại/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));

    expect(await screen.findByText("Tài khoản của bạn đã bị khoá.")).toBeInTheDocument();
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
