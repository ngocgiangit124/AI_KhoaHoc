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
vi.mock("@/lib/auth/api", () => ({
  verifyOtp: (...a: unknown[]) => verifyOtp(...a),
  sendOtp: (...a: unknown[]) => sendOtp(...a),
}));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
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

/** OtpInput v2 là MỘT ô nhập thật (nhãn "Mã xác nhận"), vẽ thành 6 ô. */
const codeInput = () => screen.getByLabelText(/Mã xác nhận/) as HTMLInputElement;

async function typeCode(u: ReturnType<typeof userEvent.setup>, code: string) {
  await u.click(codeInput());
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

  it("hiện email đã che, thời hạn mã, lối thoát Đổi email (tới Tài khoản) và Để sau", () => {
    render(<OtpVerifyForm {...props} />);
    expect(screen.getByText("n******@gmail.com")).toBeInTheDocument();
    expect(screen.getByText(/có hiệu lực 10 phút/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Đổi email" })).toHaveAttribute("href", "/tai-khoan#doi-lien-he");
    expect(screen.getByRole("link", { name: "Để sau" })).toHaveAttribute("href", "/");
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

  it("OTP_INVALID → lỗi dưới ô, xoá mã, vẫn còn nút Xác nhận và ô nhập dùng được", async () => {
    verifyOtp.mockRejectedValue(new ApiError(422, { message: "x", code: "OTP_INVALID" }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByText("Mã OTP không đúng, vui lòng thử lại.")).toBeInTheDocument();
    expect(codeInput().value).toBe("");
    expect(codeInput()).not.toBeDisabled();
    expect(screen.getByRole("button", { name: "Xác nhận" })).toBeEnabled();
    expect(replace).not.toHaveBeenCalled();
  });

  it("OTP_EXPIRED → khoá ô mã, ẩn Xác nhận, Gửi lại mã thành nút chính", async () => {
    verifyOtp.mockRejectedValue(new ApiError(422, { message: "x", code: "OTP_EXPIRED", errors: { code: ["Mã OTP đã hết hạn. Vui lòng gửi lại mã."] } }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByText("Mã OTP đã hết hạn. Vui lòng gửi lại mã.")).toBeInTheDocument();
    expect(codeInput()).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Xác nhận" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Gửi lại mã" })).toBeEnabled();
  });

  it("429 của mã (sai 5 lần, không Retry-After) → như hết hạn, nói rõ sai 5 lần", async () => {
    verifyOtp.mockRejectedValue(new ApiError(429, { message: "Nhập sai quá nhiều lần.", code: "TOO_MANY_ATTEMPTS" }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByText(/sai mã này 5 lần/)).toBeInTheDocument();
    expect(codeInput()).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Xác nhận" })).not.toBeInTheDocument();
  });

  it("429 throttle (có Retry-After) → Alert cảnh báo, khoá ô và nút Xác nhận", async () => {
    verifyOtp.mockRejectedValue(new ApiError(429, { message: "Bạn thao tác quá nhanh.", code: "TOO_MANY_ATTEMPTS" }, 720));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByText("Bạn đã thử quá nhiều lần")).toBeInTheDocument();
    expect(screen.getByText(/thử lại sau 12 phút/)).toBeInTheDocument();
    expect(codeInput()).toBeDisabled();
    expect(screen.getByRole("button", { name: "Xác nhận" })).toBeDisabled();
  });

  it("lỗi lạ (5xx) khi verify → Alert chung, vẫn nhập lại được", async () => {
    verifyOtp.mockRejectedValue(new ApiError(500, { message: "Lỗi hệ thống" }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    expect(await screen.findByRole("alert")).toHaveTextContent("Lỗi hệ thống");
    expect(codeInput()).not.toBeDisabled();
  });

  it("gửi lại mã: nút khoá và đếm ngược theo resend_available_at, báo đã gửi tới email đã che", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      sendOtp.mockResolvedValue({ resendAvailableAt: new Date(Date.now() + 60_000).toISOString() });
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      render(<OtpVerifyForm {...props} />);
      await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
      const btn = await screen.findByRole("button", { name: /Gửi lại mã sau 1:00/ });
      expect(btn).toBeDisabled();
      expect(screen.getByText(/Đã gửi mã mới tới n\*+@gmail\.com/)).toBeInTheDocument();
      await act(async () => {
        vi.advanceTimersByTime(61_000);
      });
      expect(screen.getByRole("button", { name: "Gửi lại mã" })).toBeEnabled();
    } finally {
      vi.useRealTimers();
    }
  });

  it("sau OTP_EXPIRED, gửi lại thành công → mở lại ô mã, ẩn lỗi, có nút Xác nhận", async () => {
    verifyOtp.mockRejectedValue(new ApiError(422, { message: "x", code: "OTP_EXPIRED" }));
    sendOtp.mockResolvedValue({ resendAvailableAt: new Date(Date.now() + 60_000).toISOString() });
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123456");
    await u.click(await screen.findByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByRole("button", { name: "Xác nhận" })).toBeInTheDocument();
    expect(codeInput()).not.toBeDisabled();
    expect(screen.queryByText(/đã hết hạn/)).not.toBeInTheDocument();
  });

  it("gửi lại bị chặn (429, không có thời gian chờ ngắn) → khoá nút kèm thông điệp server", async () => {
    sendOtp.mockRejectedValue(new ApiError(429, { message: "Bạn đã gửi quá nhiều mã hôm nay." }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByText("Bạn đã gửi quá nhiều mã hôm nay.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Gửi lại mã" })).toBeDisabled();
  });

  it("429 khi gửi lại có Retry-After ngắn → đếm ngược theo đó", async () => {
    sendOtp.mockRejectedValue(new ApiError(429, { message: "Chờ chút." }, 42));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByRole("button", { name: /Gửi lại mã sau 0:4[12]/ })).toBeDisabled();
  });

  it("503 OTP_DELIVERY_FAILED khi gửi lại → Alert 'Chưa gửi được mã', gửi lại được ngay", async () => {
    sendOtp.mockRejectedValue(new ApiError(503, { message: "x", code: "OTP_DELIVERY_FAILED" }));
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(screen.getByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByText("Chưa gửi được mã")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Gửi lại mã" })).toBeEnabled();
  });

  it("/auth/me lỗi tạm thời → nút Thử lại, KHÔNG chuyển sang đăng nhập", async () => {
    authState = { status: "error" };
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    expect(replace).not.toHaveBeenCalled();
    await u.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(refreshAuth).toHaveBeenCalled();
  });

  it("vào ngay sau đăng ký → nút Gửi lại mã bắt đầu bị khoá theo cooldown config", () => {
    sessionStorage.setItem("vv:otp-sent-at", String(Date.now() - 10_000));
    render(<OtpVerifyForm {...props} />);
    expect(screen.getByRole("button", { name: /Gửi lại mã sau 0:(49|50)/ })).toBeDisabled();
  });

  it("bấm Xác nhận khi chưa đủ 6 số → lỗi dưới ô, không gọi API", async () => {
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await typeCode(u, "123");
    await u.click(screen.getByRole("button", { name: "Xác nhận" }));
    expect(await screen.findByText("Vui lòng nhập đủ 6 chữ số.")).toBeInTheDocument();
    expect(verifyOtp).not.toHaveBeenCalled();
  });

  it("ô mã chỉ nhận chữ số (dán chuỗi lẫn chữ)", async () => {
    const u = userEvent.setup();
    render(<OtpVerifyForm {...props} />);
    await u.click(codeInput());
    await u.paste("12ab34");
    expect(codeInput().value).toBe("1234");
  });
});
