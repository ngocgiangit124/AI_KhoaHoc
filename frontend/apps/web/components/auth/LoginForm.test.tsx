import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const replace = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh }) }));
const loginStudent = vi.fn();
vi.mock("@/lib/auth/api", () => ({ loginStudent: (...a: unknown[]) => loginStudent(...a) }));

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
    expect(loginStudent).toHaveBeenCalledWith({ login: "a@example.com", password: "matkhau123", captchaToken: null });
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

  it("R10: đăng nhập thành công hoặc vừa đặt lại mật khẩu xong thì dọn login giữ ở bước 2", async () => {
    sessionStorage.setItem("vv:reset-login", "a@x.vn");
    const { unmount } = render(<LoginForm notice="dat-lai-xong" />);
    expect(sessionStorage.getItem("vv:reset-login")).toBeNull();
    unmount();
    sessionStorage.setItem("vv:reset-login", "a@x.vn");
    loginStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);
    await waitFor(() => expect(sessionStorage.getItem("vv:reset-login")).toBeNull());
  });

  it("liên kết Quên mật khẩu / Đăng ký là <a> thường (điều hướng cứng, CSP Turnstile)", () => {
    render(<LoginForm />);
    expect(screen.getByRole("link", { name: "Quên mật khẩu?" })).toHaveAttribute("href", "/quen-mat-khau");
    expect(screen.getByRole("link", { name: "Đăng ký ngay" })).toHaveAttribute("href", "/dang-ky");
  });
});

// ---- GL-A2: captcha sau khi sai nhiều lần ----
describe("LoginForm — captcha GL-A2", () => {
  const stub = captchaStub;
  beforeEach(() => {
    loginStudent.mockReset();
    stub.mounts = 0;
  });

  it("chưa bị đòi: không hiện widget, không gửi captcha_token", async () => {
    loginStudent.mockResolvedValue({});
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
    await fill(user);
    await waitFor(() => expect(loginStudent).toHaveBeenCalled());
    expect(loginStudent.mock.calls[0]![0]).toMatchObject({ captchaToken: null });
  });

  it("422 có captcha_required=true -> hiện widget, khoá nút tới khi có token, gửi token, reset sau mỗi lần gửi", async () => {
    loginStudent
      .mockRejectedValueOnce(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", captcha_required: true }))
      .mockRejectedValueOnce(new ApiError(422, { message: "x", code: "CAPTCHA_INVALID", captcha_required: true }))
      .mockResolvedValueOnce({});
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("hoàn tất xác minh chống spam");
    expect(screen.getByRole("button", { name: "Đăng nhập" })).toBeDisabled();
    expect(stub.mounts).toBe(1);

    await user.click(screen.getByRole("button", { name: "giai-captcha" }));
    await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
    await waitFor(() => expect(loginStudent).toHaveBeenCalledTimes(2));
    expect(loginStudent.mock.calls[1]![0]).toMatchObject({ captchaToken: "tok-1" });
    // CAPTCHA_INVALID: thông điệp riêng, widget được mount MỚI và nút lại khoá (token cũ đã dùng).
    expect(await screen.findByRole("alert")).toHaveTextContent("không hợp lệ hoặc đã hết hạn");
    await waitFor(() => expect(stub.mounts).toBe(2));
    expect(screen.getByRole("button", { name: "Đăng nhập" })).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "giai-captcha" }));
    await user.type(screen.getByLabelText(/^Mật khẩu/), "matkhau123");
    await user.click(screen.getByRole("button", { name: "Đăng nhập" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
    expect(loginStudent.mock.calls[2]![0]).toMatchObject({ captchaToken: "tok-2" });
  });

  it("CAPTCHA_REQUIRED (không kèm cờ) cũng hiện widget với thông điệp riêng", async () => {
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", code: "CAPTCHA_REQUIRED" }));
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    expect(await screen.findByRole("alert")).toHaveTextContent("Bạn đã nhập sai nhiều lần");
    expect(screen.getByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
  });

  it("422 sai thông tin không có cờ -> không hiện widget", async () => {
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR" }));
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    await screen.findByRole("alert");
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
  });

  it("bị đòi captcha nhưng chưa cấu hình site key -> báo thử lại sau, không gửi token", async () => {
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", code: "CAPTCHA_REQUIRED" }));
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);
    expect(await screen.findByText("Chưa thể xác minh chống spam lúc này.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "giai-captcha" })).not.toBeInTheDocument();
  });

  it("429 -> hiện thời gian chờ theo Retry-After", async () => {
    loginStudent.mockRejectedValue(new ApiError(429, { message: "x", code: "TOO_MANY_ATTEMPTS" }, 1800));
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    expect(await screen.findByRole("alert")).toHaveTextContent("thử lại sau 30 phút");
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
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", captcha_required: true }));
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    expect(JSON.stringify(Object.entries(sessionStorage))).not.toContain("matkhau123");
    expect(sessionStorage.getItem("vv:gla2-login")).toBe("a@example.com");
  });

  it("sau khi tải lại: khôi phục định danh, hiện luôn widget, xoá cờ", async () => {
    sessionStorage.setItem("vv:gla2-login", "a@example.com");
    render(<LoginForm captchaSiteKey="site-key" />);
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(screen.getByLabelText(/Email hoặc số điện thoại/)).toHaveValue("a@example.com");
    expect(sessionStorage.getItem("vv:gla2-login")).toBeNull();
  });

  it("tài liệu gốc đúng (không stale) thì không tải lại", async () => {
    vi.spyOn(performance, "getEntriesByType").mockReturnValue([{ name: "http://localhost/dang-nhap" }] as unknown as PerformanceEntryList);
    loginStudent.mockRejectedValue(new ApiError(422, { message: "x", captcha_required: true }));
    const user = userEvent.setup();
    render(<LoginForm captchaSiteKey="site-key" />);
    await fill(user);
    expect(await screen.findByRole("button", { name: "giai-captcha" })).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });

  it("widget không bắn callback sau 10 giây -> nút tải lại trang; có token thì ẩn; nút khoá có aria-describedby", async () => {
    sessionStorage.setItem("vv:gla2-login", "a@example.com");
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
