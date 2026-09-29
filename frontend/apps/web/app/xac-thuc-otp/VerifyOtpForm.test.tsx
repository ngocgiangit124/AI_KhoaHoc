import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ToastProvider } from "@vitaminvui/ui";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { VerifyOtpForm } from "./VerifyOtpForm";

const pushMock = vi.fn();
const replaceMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock, replace: replaceMock }),
}));

const authFetchMock = vi.fn();
vi.mock("@/lib/api", () => ({
  authFetch: (...args: unknown[]) => authFetchMock(...args),
}));

function renderForm(props: { otpTtlMinutes?: number; resendCooldownSeconds?: number } = {}) {
  return render(
    <ToastProvider>
      <VerifyOtpForm otpTtlMinutes={props.otpTtlMinutes ?? 10} resendCooldownSeconds={props.resendCooldownSeconds ?? 60} />
    </ToastProvider>,
  );
}

async function typeOtp(user: ReturnType<typeof userEvent.setup>, code: string) {
  const boxes = screen.getAllByRole("textbox");
  for (const [i, digit] of code.split("").entries()) {
    await user.type(boxes[i]!, digit);
  }
}

describe("VerifyOtpForm", () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-28T10:00:00.000Z"));
    authFetchMock.mockReset();
    pushMock.mockReset();
    replaceMock.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("hiện email đã che một phần lấy từ GET /auth/me", async () => {
    authFetchMock.mockResolvedValueOnce({
      id: 1,
      name: "Nguyễn Minh An",
      email: "minhan2010@gmail.com",
      is_verified: false,
    });
    renderForm();

    expect(await screen.findByText("min***@gmail.com")).toBeInTheDocument();
  });

  it("tài khoản đã xác thực (is_verified=true) -> chuyển hướng về trang chủ, không hiện form", async () => {
    authFetchMock.mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", is_verified: true });
    renderForm();

    await waitFor(() => expect(replaceMock).toHaveBeenCalledWith("/"));
    expect(screen.queryByRole("group")).not.toBeInTheDocument();
  });

  it("nhập đủ 6 số tự động xác nhận -> gọi otp/verify, thành công thì điều hướng về trang chủ", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", is_verified: true });
    renderForm();

    await screen.findByText("min***@gmail.com");
    await typeOtp(user, "482913");

    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
    const [path, init] = authFetchMock.mock.calls[1] as [string, { method: string; body: string }];
    expect(path).toBe("/api/v1/auth/otp/verify");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body)).toEqual({ code: "482913" });
  });

  it("mã sai (422) -> hiện lỗi dưới ô nhập, xoá mã để nhập lại, không điều hướng", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockRejectedValueOnce(
        new ApiError(422, { message: "Mã OTP không đúng, vui lòng thử lại.", code: "VALIDATION_ERROR" }),
      );
    renderForm();

    await screen.findByText("min***@gmail.com");
    await typeOtp(user, "482913");

    expect(await screen.findByText("Mã OTP không đúng, vui lòng thử lại.")).toBeInTheDocument();
    expect(pushMock).not.toHaveBeenCalled();
    const boxes = screen.getAllByRole("textbox");
    expect(boxes[0]).toHaveValue("");
  });

  it("429 khi xác thực -> khoá form, hiện banner cảnh báo, không cho gửi lại/nhập tiếp", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockRejectedValueOnce(
        new ApiError(429, {
          message: "Bạn đã nhập sai quá nhiều lần. Tài khoản tạm khoá xác thực trong 24 giờ.",
          code: "TOO_MANY_ATTEMPTS",
        }),
      );
    renderForm();

    await screen.findByText("min***@gmail.com");
    await typeOtp(user, "482913");

    expect(
      await screen.findByText("Bạn đã nhập sai quá nhiều lần. Tài khoản tạm khoá xác thực trong 24 giờ."),
    ).toBeInTheDocument();
    for (const box of screen.getAllByRole("textbox")) {
      expect(box).toBeDisabled();
    }
  });

  it("đếm ngược gửi lại mã bằng cooldown mặc định (config/public) khi chưa có resend_available_at thật", async () => {
    authFetchMock.mockResolvedValueOnce({
      id: 1,
      name: "Nguyễn Minh An",
      email: "minhan2010@gmail.com",
      is_verified: false,
    });
    renderForm({ resendCooldownSeconds: 60 });

    await screen.findByText("min***@gmail.com");
    expect(await screen.findByText("01:00")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Gửi lại mã" })).not.toBeInTheDocument();

    await act(async () => {
      vi.advanceTimersByTime(60_000);
    });

    expect(await screen.findByRole("button", { name: "Gửi lại mã" })).toBeInTheDocument();
  });

  it("bấm 'Gửi lại mã' gọi otp/send và đếm ngược lại theo resend_available_at thật của server", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockResolvedValueOnce({ resend_available_at: "2026-09-28T10:05:00.000Z" });
    renderForm({ resendCooldownSeconds: 60 });

    await screen.findByText("min***@gmail.com");
    await act(async () => {
      vi.advanceTimersByTime(60_000);
    });
    await user.click(await screen.findByRole("button", { name: "Gửi lại mã" }));

    const [path, init] = authFetchMock.mock.calls[1] as [string, { method: string; body: string }];
    expect(path).toBe("/api/v1/auth/otp/send");
    expect(JSON.parse(init.body)).toEqual({ channel: "email" });
    // `resend_available_at` thật của server (~4 phút sau) phải thay thế mốc đếm ngược cũ
    // (fallback 60s) — chỉ kiểm còn > 60s, tránh so khớp giây chính xác (dễ chập chờn do
    // `shouldAdvanceTime` làm đồng hồ giả trôi theo thời gian chờ thật của userEvent/act).
    await waitFor(() => {
      const text = screen.getByText(/^\d{2}:\d{2}$/).textContent ?? "";
      const [mm] = text.split(":").map(Number);
      expect(mm).toBeGreaterThanOrEqual(2);
    });
  });

  it("429 khi xác thực có Retry-After -> banner kèm gợi ý thời gian thử lại (T04 review L3, phụ trợ)", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockRejectedValueOnce(
        new ApiError(
          429,
          { message: "Bạn thao tác quá nhanh, vui lòng thử lại sau.", code: "TOO_MANY_ATTEMPTS" },
          120,
        ),
      );
    renderForm();

    await screen.findByText("min***@gmail.com");
    await typeOtp(user, "482913");

    expect(
      await screen.findByText("Bạn thao tác quá nhanh, vui lòng thử lại sau. (thử lại sau khoảng 2 phút)"),
    ).toBeInTheDocument();
  });

  it("bấm 'Gửi lại mã' gặp 429 -> dùng Retry-After dựng lại đồng hồ đếm ngược, không cho bấm dồn dập", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockRejectedValueOnce(
        new ApiError(429, { message: "Bạn gửi mã quá nhanh, vui lòng thử lại sau.", code: "TOO_MANY_ATTEMPTS" }, 90),
      );
    renderForm({ resendCooldownSeconds: 60 });

    await screen.findByText("min***@gmail.com");
    await act(async () => {
      vi.advanceTimersByTime(60_000);
    });
    await user.click(await screen.findByRole("button", { name: "Gửi lại mã" }));

    expect(await screen.findByText(/Bạn gửi mã quá nhanh/)).toBeInTheDocument();
    // Nút "Gửi lại mã" phải biến mất ngay (thay bằng đồng hồ đếm ngược mới ~90s) thay vì cho
    // bấm lại ngay lập tức.
    expect(screen.queryByRole("button", { name: "Gửi lại mã" })).not.toBeInTheDocument();
    expect(await screen.findByText(/^\d{2}:\d{2}$/)).toBeInTheDocument();
  });

  it("lỗi mạng khi xác thực -> banner lỗi mạng, không khoá form", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An", email: "minhan2010@gmail.com", is_verified: false })
      .mockRejectedValueOnce(new NetworkError(new TypeError("Failed to fetch")));
    renderForm();

    await screen.findByText("min***@gmail.com");
    await typeOtp(user, "482913");

    expect(
      await screen.findByText("Không thể kết nối tới máy chủ. Vui lòng kiểm tra kết nối mạng và thử lại."),
    ).toBeInTheDocument();
    for (const box of screen.getAllByRole("textbox")) {
      expect(box).not.toBeDisabled();
    }
  });
});
