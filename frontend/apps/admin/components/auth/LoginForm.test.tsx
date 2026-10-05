import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { loginStaff } from "@/lib/auth/api";
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
