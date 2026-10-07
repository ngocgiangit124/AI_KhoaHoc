import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

const push = vi.fn();
const replace = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, replace, refresh: vi.fn() }) }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));

const forgotPassword = vi.fn();
const resetPassword = vi.fn();
const changePassword = vi.fn();
vi.mock("@/lib/auth/api", () => ({
  forgotPassword: (...a: unknown[]) => forgotPassword(...a),
  resetPassword: (...a: unknown[]) => resetPassword(...a),
  changePassword: (...a: unknown[]) => changePassword(...a),
}));

let authState: AuthState;
const refreshAuth = vi.fn();
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: authState, refresh: refreshAuth }) }));

import { ChangePasswordForm } from "@/components/account/ChangePasswordForm";
import { ForgotPasswordForm } from "./ForgotPasswordForm";
import { ResetPasswordForm } from "./ResetPasswordForm";

type U = ReturnType<typeof userEvent.setup>;
const wrap = (ui: React.ReactElement) => render(<ToastProvider>{ui}</ToastProvider>);

const assign = vi.fn();

describe("ForgotPasswordForm (bước 1)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(window, "location", { value: { assign, pathname: "/quen-mat-khau", search: "" }, writable: true });
    sessionStorage.clear();
  });

  it("để trống → lỗi dưới ô, không gọi API", async () => {
    const u = userEvent.setup();
    wrap(<ForgotPasswordForm captchaSiteKey={null} />);
    await u.click(screen.getByRole("button", { name: "Gửi mã" }));
    expect(await screen.findByText("Vui lòng nhập email hoặc số điện thoại.")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText(/Email hoặc số điện thoại/)).toHaveFocus()); // R4
    expect(forgotPassword).not.toHaveBeenCalled();
  });

  it("thành công → lưu login (không lên URL) và chuyển thẳng sang bước 2 (điều hướng cứng)", async () => {
    forgotPassword.mockResolvedValue({ message: "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.", resendAvailableAt: "2026-10-07T10:00:00Z" });
    const u = userEvent.setup();
    wrap(<ForgotPasswordForm captchaSiteKey={null} />);
    await u.type(screen.getByLabelText(/Email hoặc số điện thoại/), "a@x.vn");
    await u.click(screen.getByRole("button", { name: "Gửi mã" }));
    // Luôn chuyển thẳng sang bước 2 bằng điều hướng CỨNG (bước 2 cần CSP có Cloudflare cho Turnstile ẩn).
    await waitFor(() => expect(assign).toHaveBeenCalledWith("/quen-mat-khau/dat-lai"));
    expect(push).not.toHaveBeenCalled();
    expect(sessionStorage.getItem("vv:reset-login")).toBe("a@x.vn");
    expect(sessionStorage.getItem("vv:reset-resend-at")).toBe("2026-10-07T10:00:00Z");
    expect(forgotPassword).toHaveBeenCalledWith({ login: "a@x.vn", captchaToken: null });
  });

  it("429 → cảnh báo kèm thời gian chờ", async () => {
    forgotPassword.mockRejectedValue(new ApiError(429, { message: "m" }, 180));
    const u = userEvent.setup();
    wrap(<ForgotPasswordForm captchaSiteKey={null} />);
    await u.type(screen.getByLabelText(/Email hoặc số điện thoại/), "a@x.vn");
    await u.click(screen.getByRole("button", { name: "Gửi mã" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("thử lại sau 3 phút");
  });

  it("có Turnstile: nút khoá tới khi có token", () => {
    wrap(<ForgotPasswordForm captchaSiteKey="site-key" />);
    expect(screen.getByRole("button", { name: "Gửi mã" })).toBeDisabled();
  });
});

describe("ResetPasswordForm (bước 2)", () => {
  const props = { resendCooldownSeconds: 60, captchaSiteKey: null };

  async function fill(u: U, code = "123456", pw = "matkhau-moi-1", cf = pw) {
    await u.click(screen.getByLabelText(/Mã xác nhận/));
    if (code) await u.keyboard(code);
    await u.type(screen.getByLabelText(/^Mật khẩu mới/), pw);
    await u.type(screen.getByLabelText(/^Nhập lại mật khẩu mới/), cf);
  }

  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    sessionStorage.setItem("vv:reset-login", "a@x.vn");
  });

  it("không có login từ bước 1 → dẫn về bước 1", async () => {
    sessionStorage.clear();
    wrap(<ResetPasswordForm {...props} />);
    expect(await screen.findByRole("link", { name: "Quên mật khẩu" })).toHaveAttribute("href", "/quen-mat-khau");
  });

  it("hiện tài khoản đã che (PO 2026-10-07), thành công → xoá login, về /dang-nhap?trang-thai=dat-lai-xong", async () => {
    resetPassword.mockResolvedValue(undefined);
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    expect(await screen.findByText("a**@x.vn")).toBeInTheDocument();
    expect(screen.queryByText("a@x.vn")).not.toBeInTheDocument();
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    await waitFor(() => expect(push).toHaveBeenCalledWith("/dang-nhap?trang-thai=dat-lai-xong"));
    expect(resetPassword).toHaveBeenCalledWith({ login: "a@x.vn", code: "123456", password: "matkhau-moi-1", password_confirmation: "matkhau-moi-1" });
    // R2: không xoá login trước khi rời trang → không nháy màn "chưa yêu cầu mã"; trang đăng nhập dọn sau.
    expect(screen.queryByText(/chưa yêu cầu mã/)).not.toBeInTheDocument();
    expect(sessionStorage.getItem("vv:reset-login")).toBe("a@x.vn");
  });

  it("đăng nhập bằng SĐT → chỉ hiện 3 số cuối", async () => {
    sessionStorage.setItem("vv:reset-login", "0912345678");
    wrap(<ResetPasswordForm {...props} />);
    expect(await screen.findByText("*******678")).toBeInTheDocument();
  });

  it("thiếu mã / mật khẩu ngắn / không khớp → lỗi từng ô, không gọi API", async () => {
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    await screen.findByText("a**@x.vn");
    await fill(u, "", "ngan", "khac");
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    expect(await screen.findByText("Vui lòng nhập đủ 6 chữ số của mã.")).toBeInTheDocument();
    expect(screen.getByText("Mật khẩu mới cần tối thiểu 8 ký tự.")).toBeInTheDocument();
    expect(screen.getByText("Xác nhận mật khẩu không khớp.")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText(/Mã xác nhận/)).toHaveFocus());
    expect(resetPassword).not.toHaveBeenCalled();
  });

  it("MỌI lỗi mã (OTP_EXPIRED) → thông điệp hết hạn, khoá ô mã, Gửi lại mã nổi bật, MẬT KHẨU ĐÃ NHẬP ĐƯỢC GIỮ", async () => {
    resetPassword.mockRejectedValue(new ApiError(422, { message: "x", code: "OTP_EXPIRED", errors: { code: ["Mã OTP đã hết hạn."] } }));
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    await screen.findByText("a**@x.vn");
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    expect(await screen.findByText(/Mã OTP đã hết hạn hoặc không còn hiệu lực/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Mã xác nhận/)).toBeDisabled();
    expect(screen.getByLabelText(/^Mật khẩu mới/)).toHaveValue("matkhau-moi-1");
    expect(screen.getByRole("button", { name: "Đặt lại mật khẩu" })).toBeDisabled();
    expect(push).not.toHaveBeenCalled();
  });

  it("Gửi lại mã gọi lại forgot (không captcha ở local), mở lại ô mã và báo mã cũ không còn dùng được", async () => {
    // Bước 1 đã để lại mốc gửi lại trong quá khứ → hết thời gian chờ, nút bấm được ngay.
    sessionStorage.setItem("vv:reset-resend-at", new Date(Date.now() - 1000).toISOString());
    resetPassword.mockRejectedValue(new ApiError(422, { message: "x", code: "OTP_EXPIRED" }));
    forgotPassword.mockResolvedValue({ message: "Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.", resendAvailableAt: new Date(Date.now() + 60_000).toISOString() });
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    await screen.findByText("a**@x.vn");
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    await u.click(await screen.findByRole("button", { name: "Gửi lại mã" }));
    await waitFor(() => expect(forgotPassword).toHaveBeenCalledWith({ login: "a@x.vn", captchaToken: null }));
    expect(await screen.findByText(/Mã cũ không còn dùng được/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Mã xác nhận/)).not.toBeDisabled();
    expect(screen.getByLabelText(/^Mật khẩu mới/)).toHaveValue("matkhau-moi-1");
  });

  it("mật khẩu phổ biến → hiện nguyên errors.password[0] dưới ô, mã vẫn dùng được", async () => {
    resetPassword.mockRejectedValue(new ApiError(422, { message: "x", errors: { password: ["Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác."] } }));
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    await screen.findByText("a**@x.vn");
    await fill(u, "123456", "matkhau123");
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    expect(await screen.findByText("Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác.")).toBeInTheDocument();
    expect(screen.getByLabelText(/Mã xác nhận/)).not.toBeDisabled();
  });

  it("429 throttle → Alert cảnh báo, khoá nút", async () => {
    resetPassword.mockRejectedValue(new ApiError(429, { message: "m" }, 480));
    const u = userEvent.setup();
    wrap(<ResetPasswordForm {...props} />);
    await screen.findByText("a**@x.vn");
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đặt lại mật khẩu" }));
    expect(await screen.findByText("Bạn đã thử quá nhiều lần")).toBeInTheDocument();
    expect(screen.getByText(/thử lại sau 8 phút/)).toBeInTheDocument(); // theo Retry-After (R9)
    expect(screen.getByRole("button", { name: "Đặt lại mật khẩu" })).toBeDisabled();
  });
});

describe("ChangePasswordForm (đổi mật khẩu khi đã đăng nhập)", () => {
  async function fill(u: U, current = "cu-matkhau-1", pw = "moi-matkhau-2", cf = pw) {
    if (current) await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), current);
    await u.type(screen.getByLabelText(/^Mật khẩu mới/), pw);
    await u.type(screen.getByLabelText(/^Nhập lại mật khẩu mới/), cf);
  }

  beforeEach(() => {
    vi.clearAllMocks();
    authState = { status: "user", user: { id: 1, name: "A", email: "a@x.vn", phone: null, role: "hoc_sinh", grade_level: 9, is_verified: true, parent_consent_status: "not_required" } };
  });

  it("thành công → toast, xoá các ô, KHÔNG về đăng nhập khi session_kept", async () => {
    changePassword.mockResolvedValue({ sessionKept: true });
    const u = userEvent.setup();
    wrap(<ChangePasswordForm />);
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đổi mật khẩu" }));
    expect(await screen.findByText("Đã đổi mật khẩu")).toBeInTheDocument();
    expect(changePassword).toHaveBeenCalledWith({ current_password: "cu-matkhau-1", password: "moi-matkhau-2", password_confirmation: "moi-matkhau-2" });
    expect(screen.getByLabelText(/^Mật khẩu hiện tại/)).toHaveValue("");
    expect(replace).not.toHaveBeenCalled();
  });

  it("session_kept=false và /auth/me thành khách → về đăng nhập", async () => {
    changePassword.mockResolvedValue({ sessionKept: false });
    refreshAuth.mockResolvedValue({ status: "guest" });
    const u = userEvent.setup();
    wrap(<ChangePasswordForm />);
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đổi mật khẩu" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dang-nhap?next=/tai-khoan"));
  });

  it("422 current_password / password theo field; xoá mật khẩu hiện tại sau lỗi", async () => {
    changePassword.mockRejectedValue(
      new ApiError(422, { message: "x", errors: { current_password: ["Mật khẩu hiện tại không đúng."], password: ["Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác."] } }),
    );
    const u = userEvent.setup();
    wrap(<ChangePasswordForm />);
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đổi mật khẩu" }));
    expect(await screen.findByText("Mật khẩu hiện tại không đúng.")).toBeInTheDocument();
    expect(screen.getByText(/Mật khẩu quá phổ biến/)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText(/^Mật khẩu hiện tại/)).toHaveFocus()); // R4: focus ô lỗi đầu tiên
    expect(screen.getByLabelText(/^Mật khẩu hiện tại/)).toHaveValue("");
  });

  it("client: thiếu/ngắn/không khớp → lỗi từng ô, không gọi API", async () => {
    const u = userEvent.setup();
    wrap(<ChangePasswordForm />);
    await fill(u, "", "ngan", "khac");
    await u.click(screen.getByRole("button", { name: "Đổi mật khẩu" }));
    expect(await screen.findByText("Vui lòng nhập mật khẩu hiện tại.")).toBeInTheDocument();
    expect(screen.getByText("Mật khẩu mới cần tối thiểu 8 ký tự.")).toBeInTheDocument();
    expect(screen.getByText("Xác nhận mật khẩu không khớp.")).toBeInTheDocument();
    expect(changePassword).not.toHaveBeenCalled();
  });

  it("429 → cảnh báo kèm thời gian chờ", async () => {
    changePassword.mockRejectedValue(new ApiError(429, { message: "m" }, 900));
    const u = userEvent.setup();
    wrap(<ChangePasswordForm />);
    await fill(u);
    await u.click(screen.getByRole("button", { name: "Đổi mật khẩu" }));
    expect(await screen.findByText(/thử lại sau 15 phút/)).toBeInTheDocument();
  });
});
