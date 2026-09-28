import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ToastProvider } from "@vitaminvui/ui";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { RegisterForm, type RegisterFormConfig } from "./RegisterForm";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const authFetchMock = vi.fn();
vi.mock("@/lib/api", () => ({
  authFetch: (...args: unknown[]) => authFetchMock(...args),
}));

// window.turnstile không tồn tại trong jsdom — khi captchaSiteKey được cấu hình, mock để
// widget "xác minh xong" ngay khi render.
function mockTurnstileAutoVerify(token = "turnstile-token") {
  window.turnstile = {
    render: (_el, options) => {
      options.callback?.(token);
      return "widget-test";
    },
    remove: vi.fn(),
    reset: vi.fn(),
  };
}

const BASE_CONFIG: RegisterFormConfig = {
  grades: [6, 7, 8, 9, 10, 11, 12],
  captchaSiteKey: null,
  parentConsentAge: 18,
  referralCodeEnabled: false,
  policyVersion: "2026-09",
};

function renderRegisterForm(config: RegisterFormConfig = BASE_CONFIG) {
  return render(
    <ToastProvider>
      <RegisterForm config={config} nonce="test-nonce" />
    </ToastProvider>,
  );
}

async function fillRequiredFields(user: ReturnType<typeof userEvent.setup>, dob = "2000-01-01") {
  await user.type(screen.getByLabelText(/Họ và tên/), "Nguyễn Minh An");
  const dobInput = screen.getByLabelText(/Ngày sinh/);
  await user.clear(dobInput);
  await user.type(dobInput, dob);
  await user.type(screen.getByLabelText(/^Email \*/), "minhan2010@gmail.com");
  await user.type(screen.getByLabelText(/^Số điện thoại \*/), "0987654321");
  await user.selectOptions(screen.getByLabelText(/Lớp đang học/), "9");
  await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
  await user.type(screen.getByLabelText(/Xác nhận mật khẩu/), "matkhau123");
}

describe("RegisterForm", () => {
  beforeEach(() => {
    authFetchMock.mockReset();
    pushMock.mockReset();
    delete (window as unknown as { turnstile?: unknown }).turnstile;
  });

  it("2 checkbox đồng ý KHÔNG tick sẵn (S7) và nút submit bị khoá tới khi tick đủ", async () => {
    renderRegisterForm();

    const checkboxes = screen.getAllByRole("checkbox");
    for (const checkbox of checkboxes) {
      expect(checkbox).not.toBeChecked();
    }
    expect(screen.getByRole("button", { name: "Tạo tài khoản" })).toBeDisabled();
  });

  it("chọn ngày sinh khiến tuổi < 18 thì hiện khối liên hệ phụ huynh; đủ tuổi thì ẩn", async () => {
    const user = userEvent.setup();
    renderRegisterForm();

    const dobInput = screen.getByLabelText(/Ngày sinh/);

    await user.type(dobInput, "2015-01-01");
    expect(screen.getByLabelText(/Số điện thoại phụ huynh/)).toBeInTheDocument();

    await user.clear(dobInput);
    await user.type(dobInput, "1990-01-01");
    expect(screen.queryByLabelText(/Số điện thoại phụ huynh/)).not.toBeInTheDocument();
  });

  it("thiếu liên hệ phụ huynh khi dưới ngưỡng tuổi -> báo lỗi đúng nội dung AC10 và không gọi API", async () => {
    const user = userEvent.setup();
    renderRegisterForm();

    await fillRequiredFields(user, "2015-01-01");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    expect(
      await screen.findByText("Vui lòng nhập ít nhất số điện thoại hoặc email phụ huynh"),
    ).toBeInTheDocument();
    expect(authFetchMock).not.toHaveBeenCalled();
  });

  it("mật khẩu và xác nhận không khớp -> lỗi dưới field xác nhận (AC5)", async () => {
    const user = userEvent.setup();
    renderRegisterForm();

    await user.type(screen.getByLabelText(/Họ và tên/), "Nguyễn Minh An");
    const dobInput = screen.getByLabelText(/Ngày sinh/);
    await user.type(dobInput, "1990-01-01");
    await user.type(screen.getByLabelText(/^Email \*/), "minhan2010@gmail.com");
    await user.type(screen.getByLabelText(/^Số điện thoại \*/), "0987654321");
    await user.selectOptions(screen.getByLabelText(/Lớp đang học/), "9");
    await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
    await user.type(screen.getByLabelText(/Xác nhận mật khẩu/), "khac-mat-khau");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    expect(await screen.findByText("Xác nhận mật khẩu không khớp")).toBeInTheDocument();
    expect(authFetchMock).not.toHaveBeenCalled();
  });

  it("đăng ký thành công (đủ tuổi) -> gọi register với device_id, điều hướng về trang chủ", async () => {
    const user = userEvent.setup();
    authFetchMock.mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" });
    renderRegisterForm();

    await fillRequiredFields(user, "1990-01-01");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));

    const [path, init] = authFetchMock.mock.calls[0] as [string, { body: string }];
    expect(path).toBe("/api/v1/auth/register");
    const payload = JSON.parse(init.body);
    expect(payload).toMatchObject({
      name: "Nguyễn Minh An",
      email: "minhan2010@gmail.com",
      phone: "0987654321",
      grade_level: 9,
      accept_terms: true,
      accept_privacy: true,
    });
    expect(payload.captcha_token).toBeTruthy();
    expect(payload.device_id).toBeTruthy();
    expect(payload.parent_phone).toBeUndefined();
  });

  it("lỗi 422 email/SĐT trùng -> hiển thị đúng dưới từng field (AC2)", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(
      new ApiError(422, {
        message: "Dữ liệu không hợp lệ",
        code: "VALIDATION_ERROR",
        errors: {
          email: ["Email đã được sử dụng"],
          phone: ["Số điện thoại đã được sử dụng"],
        },
      }),
    );
    renderRegisterForm();

    await fillRequiredFields(user, "1990-01-01");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    expect(await screen.findByText("Email đã được sử dụng")).toBeInTheDocument();
    expect(screen.getByText("Số điện thoại đã được sử dụng")).toBeInTheDocument();
    expect(pushMock).not.toHaveBeenCalled();
  });

  it("CAPTCHA_FAILED -> hiện banner lỗi và không điều hướng", async () => {
    const user = userEvent.setup();
    authFetchMock.mockRejectedValueOnce(
      new ApiError(422, { message: "Xác minh chống spam thất bại", code: "CAPTCHA_FAILED" }),
    );
    renderRegisterForm();

    await fillRequiredFields(user, "1990-01-01");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    expect(await screen.findByText("Xác minh chống spam thất bại")).toBeInTheDocument();
    expect(pushMock).not.toHaveBeenCalled();
  });

  it("khi captcha_site_key có cấu hình: hiện TurnstileWidget và submit dùng token thật từ callback", async () => {
    mockTurnstileAutoVerify("real-turnstile-token");
    const user = userEvent.setup();
    authFetchMock.mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" });
    renderRegisterForm({ ...BASE_CONFIG, captchaSiteKey: "test-site-key" });

    await fillRequiredFields(user, "1990-01-01");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));

    await waitFor(() => expect(screen.getByRole("button", { name: "Tạo tài khoản" })).not.toBeDisabled());
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));

    await waitFor(() => expect(authFetchMock).toHaveBeenCalledTimes(1));
    const [, init] = authFetchMock.mock.calls[0] as [string, { body: string }];
    expect(JSON.parse(init.body).captcha_token).toBe("real-turnstile-token");
  });
});
