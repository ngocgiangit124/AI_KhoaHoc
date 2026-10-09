import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { loginStaff } from "@/lib/auth/api";
// Turnstile thật nạp script Cloudflare: thay bằng nút "giai-captcha" phát token tăng dần theo số lần mount (token mới mỗi lần mount).
const captchaStub = vi.hoisted(() => ({ mounts: 0 }));
vi.mock("@vitaminvui/ui", async () => {
  const React = await import("react");
  return {
    TurnstileWidget: ({ onToken }: { onToken: (t: string | null) => void }) => {
      const [n] = React.useState(() => ++captchaStub.mounts);
      return React.createElement("button", { type: "button", onClick: () => onToken(`tok-${n}`) }, "giai-captcha");
    },
  };
});

import { LoginForm } from "./LoginForm";

const replace = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh }) }));
vi.mock("@/lib/auth/api", () => ({ loginStaff: vi.fn() }));

async function fill(login = "admin@vitaminvui.vn", password = "matkhau123") {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/^Email/), login);
  await user.type(screen.getByLabelText(/^Mật khẩu/), password);
  await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
}

beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
});

describe("LoginForm (quản trị)", () => {
  it("không gọi API khi bỏ trống, báo lỗi dưới field", async () => {
    render(<LoginForm />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Đăng nhập" }));
    expect(await screen.findByText("Vui lòng nhập email")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập mật khẩu")).toBeInTheDocument();
    expect(loginStaff).not.toHaveBeenCalled();
  });

  it("Admin/QLT (mfa_required) → /xac-thuc-mfa, lưu gợi ý đã che", async () => {
    vi.mocked(loginStaff).mockResolvedValue({ mfaRequired: true, resendAvailableAt: "2030-01-01T00:00:00+07:00" });
    render(<LoginForm />);
    await fill();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/xac-thuc-mfa?next=%2Fquan-tri"));
    expect(sessionStorage.getItem("vv:admin-mfa-hint")).toBe("a****@vitaminvui.vn");
    expect(sessionStorage.getItem("vv:admin-mfa-resend-at")).toBe("2030-01-01T00:00:00+07:00");
  });

  it("Giáo viên → vào thẳng, next hợp lệ được giữ", async () => {
    vi.mocked(loginStaff).mockResolvedValue({ mfaRequired: false, resendAvailableAt: null });
    render(<LoginForm next="/quan-tri/khoa-hoc" />);
    await fill();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri/khoa-hoc"));
  });

  it("next ngoài site bị bỏ (open redirect)", async () => {
    vi.mocked(loginStaff).mockResolvedValue({ mfaRequired: false, resendAvailableAt: null });
    render(<LoginForm next="//evil.com" />);
    await fill();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri"));
  });

  it("WRONG_PORTAL → banner, xoá mật khẩu, không chuyển trang", async () => {
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(403, { message: "x", code: "WRONG_PORTAL" }));
    render(<LoginForm />);
    await fill();
    expect(await screen.findByText("Vui lòng đăng nhập tại trang dành cho bạn.")).toBeInTheDocument();
    expect(screen.getByLabelText(/^Mật khẩu/)).toHaveValue("");
    expect(screen.getByLabelText(/^Email/)).toHaveValue("admin@vitaminvui.vn");
    expect(replace).not.toHaveBeenCalled();
  });

  it("next trỏ vào trang auth bị bỏ", async () => {
    vi.mocked(loginStaff).mockResolvedValue({ mfaRequired: false, resendAvailableAt: null });
    render(<LoginForm next="/xac-thuc-mfa" />);
    await fill();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri"));
  });

  it("hiện thông báo idle từ ?reason=idle", () => {
    render(<LoginForm reason="idle" />);
    expect(screen.getByText(/hết hạn do không hoạt động/)).toBeInTheDocument();
  });
});

// ---- GL-A2: captcha sau khi sai nhiều lần ----
describe("LoginForm (quản trị) — captcha GL-A2", () => {
  beforeEach(() => {
    captchaStub.mounts = 0;
  });

  it("chưa bị đòi: không hiện widget, không gửi captcha_token", async () => {
    vi.mocked(loginStaff).mockResolvedValue({ mfaRequired: false, resendAvailableAt: null });
    render(<LoginForm captchaSiteKey="site-key" />);
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
    await fill();
    await waitFor(() => expect(loginStaff).toHaveBeenCalled());
    expect(vi.mocked(loginStaff).mock.calls[0]![0]).toMatchObject({ captchaToken: null });
  });

  it("422 captcha_required=true -> widget, khoá nút tới khi có token, gửi token, reset sau mỗi lần gửi, rồi sang MFA", async () => {
    vi.mocked(loginStaff)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", captcha_required: true }))
      .mockRejectedValueOnce(new ApiError(422, { message: "x", code: "CAPTCHA_INVALID", captcha_required: true }))
      .mockResolvedValueOnce({ mfaRequired: true, resendAvailableAt: null });
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(screen.getByText(/hoàn tất xác minh chống spam/, { selector: "div" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Đăng nhập" })).toBeDisabled();
    expect(captchaStub.mounts).toBe(1);

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "giai-captcha" }));
    await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
    await waitFor(() => expect(loginStaff).toHaveBeenCalledTimes(2));
    expect(vi.mocked(loginStaff).mock.calls[1]![0]).toMatchObject({ captchaToken: "tok-1" });
    expect(await screen.findByText(/không hợp lệ hoặc đã hết hạn/)).toBeInTheDocument();
    await waitFor(() => expect(captchaStub.mounts).toBe(2));
    expect(screen.getByRole("button", { name: "Đăng nhập" })).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "giai-captcha" }));
    await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/xac-thuc-mfa?next=%2Fquan-tri"));
    expect(vi.mocked(loginStaff).mock.calls[2]![0]).toMatchObject({ captchaToken: "tok-2" });
  });

  it("CAPTCHA_REQUIRED (không kèm cờ) hiện widget với thông điệp riêng", async () => {
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(422, { message: "x", code: "CAPTCHA_REQUIRED" }));
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    expect(await screen.findByText(/Bạn đã nhập sai nhiều lần/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
  });

  it("422 sai thông tin không có cờ -> không hiện widget", async () => {
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR" }));
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    await screen.findByText("Thông tin đăng nhập hoặc mật khẩu không đúng");
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
  });

  it("bị đòi captcha nhưng thiếu site key -> báo thử lại sau", async () => {
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(422, { message: "x", code: "CAPTCHA_REQUIRED" }));
    render(<LoginForm captchaSiteKey="" />);
    await fill();
    expect(await screen.findByText(/Chưa thể xác minh chống spam lúc này/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
  });

  it("429 -> hiện thời gian chờ theo Retry-After", async () => {
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(429, { message: "x", code: "TOO_MANY_ATTEMPTS" }, 1800));
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    expect(await screen.findByText(/thử lại sau 30 phút/)).toBeInTheDocument();
  });
});

describe("LoginForm — CSP cũ / widget không phản hồi (GL-A2 R1)", () => {
  const reload = vi.fn();
  const realLocation = window.location;
  beforeEach(() => {
    reload.mockReset();
    captchaStub.mounts = 0;
    sessionStorage.clear();
    Object.defineProperty(window, "location", { value: { ...realLocation, pathname: "/dang-nhap", reload }, writable: true });
  });
  afterEach(() => {
    Object.defineProperty(window, "location", { value: realLocation, writable: true });
    vi.restoreAllMocks();
    vi.useRealTimers();
  });
  const staleNav = () => vi.spyOn(performance, "getEntriesByType").mockReturnValue([{ name: "http://localhost/quan-tri/khoa-hoc" }] as unknown as PerformanceEntryList);

  it("tới /dang-nhap bằng điều hướng mềm và bị đòi captcha -> tải lại tài liệu, chỉ giữ định danh (không mật khẩu)", async () => {
    staleNav();
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", captcha_required: true }));
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    expect(JSON.stringify(Object.entries(sessionStorage))).not.toContain("matkhau123");
    expect(sessionStorage.getItem("vv:gla2-login")).toBe("admin@vitaminvui.vn");
  });

  it("sau khi tải lại: khôi phục định danh, hiện luôn widget, xoá cờ", async () => {
    sessionStorage.setItem("vv:gla2-login", "admin@vitaminvui.vn");
    render(<LoginForm captchaSiteKey="site-key" />);
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(screen.getByLabelText(/^Email/)).toHaveValue("admin@vitaminvui.vn");
    expect(sessionStorage.getItem("vv:gla2-login")).toBeNull();
  });

  it("tài liệu gốc đúng (không stale) thì không tải lại", async () => {
    vi.spyOn(performance, "getEntriesByType").mockReturnValue([{ name: "http://localhost/dang-nhap" }] as unknown as PerformanceEntryList);
    vi.mocked(loginStaff).mockRejectedValue(new ApiError(422, { message: "x", captcha_required: true }));
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill();
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });

  it("widget không bắn callback sau 10 giây -> nút tải lại trang; có token thì ẩn; nút khoá có aria-describedby", async () => {
    sessionStorage.setItem("vv:gla2-login", "admin@vitaminvui.vn");
    vi.useFakeTimers({ shouldAdvanceTime: true });
    render(<LoginForm captchaSiteKey="site-key" />);
    const btn = await screen.findByRole("button", { name: "Đăng nhập" });
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute("aria-describedby", "captcha-hint");
    expect(screen.queryByRole("button", { name: "tải lại trang" })).toBeNull();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_500);
    });
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(await screen.findByRole("button", { name: "tải lại trang" }));
    expect(reload).toHaveBeenCalled();
    // widget tự thử lại thành công -> cảnh báo biến mất
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(screen.getByRole("button", { name: "giai-captcha" }));
    expect(screen.queryByRole("button", { name: "tải lại trang" })).toBeNull();
    expect(screen.getByRole("button", { name: "Đăng nhập" })).not.toHaveAttribute("aria-describedby");
  });
});
