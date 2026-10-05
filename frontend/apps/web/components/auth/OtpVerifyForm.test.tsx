import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

const replace = vi.fn();
const refreshRouter = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh: refreshRouter }) }));

const verifyOtp = vi.fn();
const sendOtp = vi.fn();
const updateContact = vi.fn();
vi.mock("@/lib/auth/api", () => ({
  verifyOtp: (...a: unknown[]) => verifyOtp(...a),
  sendOtp: (...a: unknown[]) => sendOtp(...a),
  updateContact: (...a: unknown[]) => updateContact(...a),
}));

let authState: AuthState;
const refreshAuth = vi.fn();
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: authState, refresh: refreshAuth }) }));

import { OtpVerifyForm } from "./OtpVerifyForm";

const user = {
  id: 1,
  name: "A",
  email: "nguyenvana@gmail.com",
  phone: "0912345678",
  role: "hoc_sinh",
  grade_level: 9,
  is_verified: false,
  parent_consent_status: "not_required" as const,
};

const props = { ttlMinutes: 10, resendCooldownSeconds: 60 };

async function typeCode(u: ReturnType<typeof userEvent.setup>, code: string) {
  await u.click(screen.getAllByRole("textbox")[0]!);
  await u.keyboard(code);
}

describe("OtpVerifyForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    authState = { status: "user", user };
  });

  it("khách → chuyển sang đăng nhập kèm next", () => {
    authState = { status: "guest" };
    render(<OtpVerifyForm {...props} />);
    expect(replace).toHaveBeenCalledWith("/dang-nhap?next=/xac-thuc-otp");
  });

  it("đã xác thực → về trang chủ", () => {
    authState = { status: "user", user: { ...user, is_verified: true } };
    render(<OtpVerifyForm {...props} />);
    expect(replace).toHaveBeenCalledWith("/");
  });

  it("hiện email đã che và thời hạn mã", () => {
    render(<OtpVerifyForm {...props} />);
    expect(screen.getByText("n******@gmail.com")).toBeInTheDocument();
    expect(screen.getByText(/hiệu lực trong 10 phút/)).toBeInTheDocument();
  });

  it("nhập đủ 6 số → gọi verify, ghi cờ và về /", async () => {
    verifyOtp.mockResolvedValue({});
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
    expect(verifyOtp).toHaveBeenCalledWith("123456");
    expect(sessionStorage.getItem("vv:account-flash")).toBe("verified");
  });

  it("422 → hiện lỗi của server, xoá ô nhập, không chuyển trang", async () => {
    verifyOtp.mockRejectedValue(new ApiError(422, { message: "x", errors: { code: ["Mã OTP đã hết hạn."] } }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByText("Mã OTP đã hết hạn.")).toBeInTheDocument();
    expect((screen.getAllByRole("textbox")[0] as HTMLInputElement).value).toBe("");
    expect(replace).not.toHaveBeenCalled();
  });

  it("gửi lại mã: nút khoá và đếm ngược theo resend_available_at", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      sendOtp.mockResolvedValue({ resendAvailableAt: new Date(Date.now() + 60_000).toISOString() });
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      render(<OtpVerifyForm {...props} />);
      await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
      const btn = await screen.findByRole("button", { name: /Gửi lại mã sau 01:00/ });
      expect(btn).toBeDisabled();
      expect(screen.getByText(/Đã gửi mã mới/)).toBeInTheDocument();
      await act(async () => {
        vi.advanceTimersByTime(61_000);
      });
      expect(screen.getByRole("button", { name: "Gửi lại mã" })).toBeEnabled();
    } finally {
      vi.useRealTimers();
    }
  });

  it("gửi lại bị chặn (429) → hiện thông điệp server", async () => {
    sendOtp.mockRejectedValue(new ApiError(429, { message: "Bạn đã gửi quá nhiều mã hôm nay." }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByText("Bạn đã gửi quá nhiều mã hôm nay.")).toBeInTheDocument();
  });

  it("đổi liên hệ: chỉ gửi field đổi, rồi làm mới user và quay lại màn nhập mã", async () => {
    updateContact.mockResolvedValue({ resendAvailableAt: null });
    refreshAuth.mockResolvedValue(authState);
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Đổi email/SĐT" }));
    const email = screen.getByLabelText(/^Email/);
    await u.clear(email);
    await u.type(email, "moi@example.com");
    await u.click(screen.getByRole("button", { name: "Lưu và gửi mã mới" }));
    await waitFor(() => expect(updateContact).toHaveBeenCalledWith({ email: "moi@example.com" }));
    expect(refreshAuth).toHaveBeenCalled();
    expect(await screen.findByText(/gửi mã xác thực mới/)).toBeInTheDocument();
  });

  it("đổi liên hệ: 422 hiện lỗi dưới field email", async () => {
    updateContact.mockRejectedValue(new ApiError(422, { message: "x", errors: { email: ["Email đã được sử dụng"] } }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Đổi email/SĐT" }));
    const email = screen.getByLabelText(/^Email/);
    await u.clear(email);
    await u.type(email, "trung@example.com");
    await u.click(screen.getByRole("button", { name: "Lưu và gửi mã mới" }));
    expect(await screen.findByText("Email đã được sử dụng")).toBeInTheDocument();
  });
});
