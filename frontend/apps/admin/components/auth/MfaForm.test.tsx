import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { logoutStaff, resendMfa, verifyMfa } from "@/lib/auth/api";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import { MfaForm } from "./MfaForm";

const replace = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh: vi.fn() }) }));
vi.mock("@/lib/auth/api", () => ({ verifyMfa: vi.fn(), logoutStaff: vi.fn(), resendMfa: vi.fn() }));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));

function setSession(state: SessionState) {
  vi.mocked(useSession).mockReturnValue({ state, refresh });
}

beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  refresh.mockResolvedValue({ kind: "staff" });
});

describe("MfaForm", () => {
  it("khách → về /dang-nhap", () => {
    setSession({ kind: "guest", reason: null });
    render(<MfaForm />);
    expect(replace).toHaveBeenCalledWith("/dang-nhap");
  });

  it("hiện đích gửi mã đã che, nút khoá tới khi đủ 6 số", () => {
    sessionStorage.setItem("vv:admin-mfa-hint", "a***@x.vn");
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    expect(screen.getByText("a***@x.vn")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Xác nhận" })).toBeDisabled();
  });

  it("nhập đủ mã → verify, rồi vào next", async () => {
    vi.mocked(verifyMfa).mockResolvedValue();
    setSession({ kind: "mfa_required" });
    render(<MfaForm next="/quan-tri/khoa-hoc" />);
    await userEvent.setup().keyboard("123456");
    await waitFor(() => expect(verifyMfa).toHaveBeenCalledWith("123456"));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri/khoa-hoc"));
  });

  it("sau MFA mà còn phải đổi mật khẩu → /doi-mat-khau", async () => {
    vi.mocked(verifyMfa).mockResolvedValue();
    refresh.mockResolvedValue({ kind: "password_change_required" });
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    await userEvent.setup().keyboard("123456");
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/doi-mat-khau?next=%2Fquan-tri"));
  });

  it("sai mã → báo lỗi, xoá ô nhập, không chuyển trang", async () => {
    vi.mocked(verifyMfa).mockRejectedValue(new ApiError(422, { message: "x", errors: { code: ["Mã không đúng"] } }));
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    await userEvent.setup().keyboard("000000");
    expect(await screen.findByText("Mã không đúng")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it("Gửi lại mã: khoá khi đang cooldown, hết cooldown thì gửi và đếm ngược lại", async () => {
    sessionStorage.setItem("vv:admin-mfa-resend-at", new Date(Date.now() - 1000).toISOString());
    vi.mocked(resendMfa).mockResolvedValue({ resendAvailableAt: new Date(Date.now() + 60_000).toISOString() });
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Gửi lại mã" }));
    expect(await screen.findByText("Đã gửi mã mới. Mã cũ không còn hiệu lực.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Gửi lại mã sau/ })).toBeDisabled();
  });

  it("resend 409 (đã qua MFA ở tab khác) → đồng bộ phiên", async () => {
    sessionStorage.setItem("vv:admin-mfa-resend-at", new Date(Date.now() - 1000).toISOString());
    vi.mocked(resendMfa).mockRejectedValue(new ApiError(409, { message: "x", code: "ALREADY_PROCESSED" }));
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Gửi lại mã" }));
    await waitFor(() => expect(refresh).toHaveBeenCalled());
  });

  it("còn cooldown từ lúc đăng nhập → nút gửi lại bị khoá", () => {
    sessionStorage.setItem("vv:admin-mfa-resend-at", new Date(Date.now() + 45_000).toISOString());
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    expect(screen.getByRole("button", { name: /Gửi lại mã sau/ })).toBeDisabled();
  });

  it("'Đăng nhập bằng tài khoản khác' huỷ phiên chờ MFA", async () => {
    vi.mocked(logoutStaff).mockResolvedValue();
    setSession({ kind: "mfa_required" });
    render(<MfaForm />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Đăng nhập bằng tài khoản khác" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dang-nhap"));
    expect(logoutStaff).toHaveBeenCalled();
  });
});
