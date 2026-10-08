import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

const replace = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh }) }));
vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
vi.mock("@vitaminvui/api-client", async (orig) => ({
  ...(await orig<typeof import("@vitaminvui/api-client")>()),
  getDeviceId: () => "dev-1",
}));
const registerStudent = vi.fn();
vi.mock("@/lib/auth/api", async (orig) => ({
  ...(await orig<typeof import("@/lib/auth/api")>()),
  registerStudent: (...a: unknown[]) => registerStudent(...a),
}));

import { RegisterForm } from "./RegisterForm";

const props = {
  grades: [6, 7, 8, 9, 10, 11, 12],
  parentSuggestAge: 18,
  referralEnabled: false,
  policyVersion: "2026-09",
  captchaSiteKey: null,
};

type U = ReturnType<typeof userEvent.setup>;

async function fillValid(user: U, dob = "2000-01-01") {
  await user.type(screen.getByLabelText(/Họ và tên/), "Nguyễn Văn A");
  await user.type(screen.getByLabelText(/Ngày sinh/), dob);
  await user.type(screen.getByLabelText(/^Email(?! phụ huynh)/), "a@example.com");
  await user.type(screen.getByLabelText(/^Số điện thoại \*/), "0912345678");
  await user.selectOptions(screen.getByLabelText(/Lớp đang học/), "9");
  await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
  await user.type(screen.getByLabelText(/Xác nhận mật khẩu/), "matkhau123");
}

describe("RegisterForm", () => {
  beforeEach(() => {
    replace.mockReset();
    refresh.mockReset();
    registerStudent.mockReset();
    sessionStorage.clear();
  });

  it("2 checkbox đồng ý không tick sẵn; hiện phiên bản chính sách", () => {
    render(<RegisterForm {...props} />);
    expect(screen.getByLabelText(/Điều khoản sử dụng/)).not.toBeChecked();
    expect(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/)).not.toBeChecked();
    expect(screen.getByText(/2026-09/)).toBeInTheDocument();
  });

  it("thiếu 1 checkbox → lỗi đồng ý, không gọi API", async () => {
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(
      await screen.findByText(
        "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân",
      ),
    ).toBeInTheDocument();
    expect(registerStudent).not.toHaveBeenCalled();
  });

  it("khối phụ huynh ghi 'không bắt buộc'; mở sẵn khi dưới ngưỡng gợi ý, có nút mở tay khi đủ tuổi", async () => {
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await user.type(screen.getByLabelText(/Ngày sinh/), "2000-01-01");
    expect(screen.queryByTestId("parent-fields")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Thêm thông tin phụ huynh \(không bắt buộc\)/ }));
    expect(screen.getByTestId("parent-fields")).toHaveTextContent("không bắt buộc");
    expect(screen.getByTestId("parent-fields")).toHaveTextContent(/huỷ nhận/);
    expect(screen.getByLabelText(/Email phụ huynh \(không bắt buộc\)/)).not.toBeRequired();
  });

  it("dưới 18 tuổi để trống phụ huynh vẫn gửi được, payload không có key parent_*", async () => {
    registerStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user, "2012-05-01");
    expect(screen.getByTestId("parent-fields")).toBeInTheDocument();
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    await waitFor(() => expect(registerStudent).toHaveBeenCalled());
    const payload = registerStudent.mock.calls[0]?.[0];
    expect(payload).not.toHaveProperty("parent_email");
    expect(payload).not.toHaveProperty("parent_phone");
  });

  it("đủ tuổi mà nhập email phụ huynh (mở tay) vẫn được gửi đi", async () => {
    registerStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByRole("button", { name: /Thêm thông tin phụ huynh/ }));
    await user.type(screen.getByLabelText(/Email phụ huynh/), "ph@example.com");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    await waitFor(() => expect(registerStudent).toHaveBeenCalled());
    expect(registerStudent.mock.calls[0]?.[0]).toMatchObject({ parent_email: "ph@example.com" });
  });

  it("nhập phụ huynh -> Bỏ qua -> gửi: payload không có parent_*, focus về nút Thêm", async () => {
    registerStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user, "2012-05-01");
    await user.type(screen.getByLabelText(/Email phụ huynh/), "ph@example.com");
    await user.click(screen.getByRole("button", { name: /Bỏ qua/ }));
    const toggle = screen.getByRole("button", { name: /Thêm thông tin phụ huynh/ });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(toggle).toHaveAttribute("aria-controls", "reg-parent-fields");
    await waitFor(() => expect(toggle).toHaveFocus());
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    await waitFor(() => expect(registerStudent).toHaveBeenCalled());
    const payload = registerStudent.mock.calls[0]?.[0];
    expect(payload).not.toHaveProperty("parent_email");
    expect(payload).not.toHaveProperty("parent_phone");
  });

  it("nhập sai định dạng phụ huynh -> Bỏ qua -> gửi được, không lỗi ở ô ẩn; mở lại thì ô trống", async () => {
    registerStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user, "2012-05-01");
    await user.type(screen.getByLabelText(/Email phụ huynh/), "sai");
    await user.click(screen.getByRole("button", { name: /Bỏ qua/ }));
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    await waitFor(() => expect(registerStudent).toHaveBeenCalled());
    expect(screen.queryByText("Email phụ huynh không hợp lệ")).not.toBeInTheDocument();
  });

  it("mở khối bằng nút Thêm -> focus vào ô đầu tiên", async () => {
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await user.type(screen.getByLabelText(/Ngày sinh/), "2000-01-01");
    await user.click(screen.getByRole("button", { name: /Thêm thông tin phụ huynh/ }));
    await waitFor(() => expect(screen.getByLabelText(/Số điện thoại phụ huynh/)).toHaveFocus());
  });

  it("422 parent_email trùng email học sinh -> lỗi dưới ô email phụ huynh", async () => {
    registerStudent.mockRejectedValue(
      new ApiError(422, { message: "x", errors: { parent_email: ["Email phụ huynh phải khác email của bạn."] } }),
    );
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user, "2012-05-01");
    await user.type(screen.getByLabelText(/Email phụ huynh/), "a@example.com");
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findAllByText("Email phụ huynh phải khác email của bạn.")).not.toHaveLength(0);
    expect(screen.getByLabelText(/Email phụ huynh/)).toHaveAttribute("aria-invalid", "true");
  });

  it("khối phụ huynh tự mở theo tuổi khi đổi ngày sinh", async () => {
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await user.type(screen.getByLabelText(/Ngày sinh/), "2000-01-01");
    expect(screen.queryByTestId("parent-fields")).not.toBeInTheDocument();
    await user.clear(screen.getByLabelText(/Ngày sinh/));
    await user.type(screen.getByLabelText(/Ngày sinh/), "2020-01-01");
    expect(screen.getByTestId("parent-fields")).toBeInTheDocument();
  });

  it("lỗi client: hộp tóm tắt liệt kê từng mục có liên kết tới ô, focus vào hộp", async () => {
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findByText(/Còn \d+ mục cần sửa/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Họ và tên" })).toHaveAttribute("href", "#reg-name");
    expect(screen.getByRole("link", { name: "Đồng ý điều khoản" })).toHaveAttribute("href", "#reg-accept-terms");
    await waitFor(() => expect(screen.getByText(/Còn \d+ mục cần sửa/).closest("[tabindex='-1']")).toHaveFocus());
  });

  it("422 mật khẩu phổ biến → hiện nguyên errors.password[0] dưới ô mật khẩu", async () => {
    registerStudent.mockRejectedValue(
      new ApiError(422, { message: "x", errors: { password: ["Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác."] } }),
    );
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findAllByText(/Mật khẩu quá phổ biến/)).not.toHaveLength(0);
    expect(screen.getByLabelText(/^Mật khẩu/)).toHaveAttribute("aria-invalid", "true");
  });

  it("ô mã giới thiệu chỉ hiện khi flag bật", () => {
    const { rerender } = render(<RegisterForm {...props} />);
    expect(screen.queryByLabelText(/Mã giới thiệu/)).not.toBeInTheDocument();
    rerender(<RegisterForm {...props} referralEnabled />);
    expect(screen.getByLabelText(/Mã giới thiệu/)).toBeInTheDocument();
  });

  it("thành công → chuyển / (banner do /auth/me quyết định, không dùng query/cờ)", async () => {
    registerStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
    expect(replace).not.toHaveBeenCalledWith(expect.stringContaining("?"));
    const payload = registerStudent.mock.calls[0]?.[0];
    expect(payload).toMatchObject({ grade_level: 9, accept_terms: true, accept_privacy: true, device_id: "dev-1" });
    expect(payload).not.toHaveProperty("captcha_token");
  });

  it("422 email trùng → lỗi dưới field email, giữ dữ liệu, xoá mật khẩu", async () => {
    registerStudent.mockRejectedValue(
      new ApiError(422, { message: "x", code: "VALIDATION_ERROR", errors: { email: ["Email đã được sử dụng"] } }),
    );
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findByText("Email đã được sử dụng")).toBeInTheDocument();
    expect(screen.getByLabelText(/Họ và tên/)).toHaveValue("Nguyễn Văn A");
    expect(screen.getByLabelText(/^Mật khẩu/)).toHaveValue("");
    expect(replace).not.toHaveBeenCalled();
  });

  it.each([null, "", "   "])("site key %j → không bật captcha, nút không bị khoá", (key) => {
    render(<RegisterForm {...props} captchaSiteKey={key} />);
    expect(screen.queryByTestId("turnstile-widget")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Tạo tài khoản" })).toBeEnabled();
  });

  it("có Turnstile: nút khoá tới khi có token", () => {
    render(<RegisterForm {...props} captchaSiteKey="site-key" />);
    expect(screen.getByRole("button", { name: "Tạo tài khoản" })).toBeDisabled();
  });

  it("lỗi 422 của parent_email khi client tưởng đủ tuổi → ép hiện khối phụ huynh + focus", async () => {
    registerStudent.mockRejectedValue(
      new ApiError(422, { message: "x", errors: { parent_email: ["Cần liên hệ phụ huynh"] } }),
    );
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findByText("Cần liên hệ phụ huynh")).toBeInTheDocument();
    expect(screen.getByTestId("parent-fields")).toBeInTheDocument();
    // Focus vào hộp tóm tắt lỗi (design-system-v2 §12.8), có liên kết tới ô phụ huynh.
    await waitFor(() => expect(screen.getByText("Còn 1 mục cần sửa").closest("[tabindex='-1']")).toHaveFocus());
    expect(screen.getByRole("link", { name: "Liên hệ phụ huynh" })).toHaveAttribute("href", "#reg-parent_phone");
  });

  it("lỗi 422 referral_code khi ô bị ẩn → vào banner", async () => {
    registerStudent.mockRejectedValue(
      new ApiError(422, { message: "x", errors: { referral_code: ["Mã giới thiệu không hợp lệ"] } }),
    );
    const user = userEvent.setup();
    render(<RegisterForm {...props} />);
    await fillValid(user);
    await user.click(screen.getByLabelText(/Điều khoản sử dụng/));
    await user.click(screen.getByLabelText(/Chính sách xử lý dữ liệu cá nhân/));
    await user.click(screen.getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Mã giới thiệu không hợp lệ");
  });
});
