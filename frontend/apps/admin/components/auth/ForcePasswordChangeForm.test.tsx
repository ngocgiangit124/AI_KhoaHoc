import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { changeStaffPassword } from "@/lib/auth/api";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import { ForcePasswordChangeForm } from "./ForcePasswordChangeForm";

const replace = vi.fn();
const refresh = vi.fn();
const show = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, refresh: vi.fn() }) }));
vi.mock("@vitaminvui/ui/v2", async (orig) => ({ ...(await orig<typeof import("@vitaminvui/ui/v2")>()), useToast: () => ({ show }) }));
vi.mock("@/lib/auth/api", () => ({ changeStaffPassword: vi.fn() }));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));

function setSession(state: SessionState) {
  vi.mocked(useSession).mockReturnValue({ state, refresh });
}

async function fill(pw: string, confirm: string) {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "tamthoi1234");
  await user.type(screen.getByLabelText(/^Mật khẩu mới/), pw);
  await user.type(screen.getByLabelText(/^Xác nhận mật khẩu mới/), confirm);
  await user.click(screen.getByRole("button", { name: "Đặt mật khẩu mới và tiếp tục" }));
}

beforeEach(() => {
  vi.clearAllMocks();
  setSession({ kind: "password_change_required" });
  refresh.mockResolvedValue({ kind: "staff" });
});

describe("ForcePasswordChangeForm", () => {
  it("không có nút bỏ qua / để sau", () => {
    render(<ForcePasswordChangeForm />);
    expect(screen.queryByRole("button", { name: /bỏ qua|để sau/i })).toBeNull();
  });

  it("xác nhận không khớp → lỗi dưới field, không gọi API", async () => {
    render(<ForcePasswordChangeForm />);
    await fill("MatKhauMoi#2026", "KhacHoanToan#99");
    expect(await screen.findByText("Xác nhận mật khẩu không khớp")).toBeInTheDocument();
    expect(changeStaffPassword).not.toHaveBeenCalled();
  });

  it("mật khẩu staff tối thiểu 12 ký tự: gợi ý + minLength + lỗi dưới ô, không gọi API", async () => {
    render(<ForcePasswordChangeForm />);
    expect(screen.getByLabelText(/^Mật khẩu mới/)).toHaveAttribute("minlength", "12");
    expect(screen.getByText(/Tối thiểu 12 ký tự, không chứa phần trước @ của email/)).toBeInTheDocument();
    await fill("NganHon#1", "NganHon#1");
    expect(await screen.findByText("Mật khẩu tối thiểu 12 ký tự")).toBeInTheDocument();
    expect(changeStaffPassword).not.toHaveBeenCalled();
  });

  it("mật khẩu mới trùng mật khẩu hiện tại → lỗi, không gọi API", async () => {
    render(<ForcePasswordChangeForm />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "MatKhauMoi#2026");
    await user.type(screen.getByLabelText(/^Mật khẩu mới/), "MatKhauMoi#2026");
    await user.type(screen.getByLabelText(/^Xác nhận mật khẩu mới/), "MatKhauMoi#2026");
    await user.click(screen.getByRole("button", { name: "Đặt mật khẩu mới và tiếp tục" }));
    expect(await screen.findByText("Mật khẩu mới phải khác mật khẩu hiện tại")).toBeInTheDocument();
    expect(changeStaffPassword).not.toHaveBeenCalled();
  });

  it("thành công → toast + vào next", async () => {
    vi.mocked(changeStaffPassword).mockResolvedValue();
    render(<ForcePasswordChangeForm next="/quan-tri/khoa-hoc" />);
    await fill("MatKhauMoi#2026", "MatKhauMoi#2026");
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri/khoa-hoc"));
    expect(changeStaffPassword).toHaveBeenCalledWith({ current_password: "tamthoi1234", password: "MatKhauMoi#2026", password_confirmation: "MatKhauMoi#2026" });
    expect(show).toHaveBeenCalledWith({ tone: "success", title: "Đổi mật khẩu thành công" });
  });

  it("422 → lỗi dưới field, giữ dữ liệu đã nhập", async () => {
    vi.mocked(changeStaffPassword).mockRejectedValue(
      new ApiError(422, { message: "x", errors: { password: ["Mật khẩu quá phổ biến"] } }),
    );
    render(<ForcePasswordChangeForm />);
    await fill("MatKhauMoi#2026", "MatKhauMoi#2026");
    expect(await screen.findByText("Mật khẩu quá phổ biến")).toBeInTheDocument();
    expect(screen.getByLabelText(/^Mật khẩu mới/)).toHaveValue("MatKhauMoi#2026");
    expect(replace).not.toHaveBeenCalled();
  });

  it("phiên mất sau khi đổi → về đăng nhập", async () => {
    vi.mocked(changeStaffPassword).mockResolvedValue();
    refresh.mockResolvedValue({ kind: "guest", reason: null });
    render(<ForcePasswordChangeForm />);
    await fill("MatKhauMoi#2026", "MatKhauMoi#2026");
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dang-nhap?reason=password_changed"));
  });

  it("chưa đăng nhập → về /dang-nhap; đã hết bị buộc → vào next", () => {
    setSession({ kind: "guest", reason: null });
    const { unmount } = render(<ForcePasswordChangeForm />);
    expect(replace).toHaveBeenCalledWith("/dang-nhap");
    unmount();
    setSession({ kind: "staff", user: { id: 1, name: "A", email: null, role: "admin", permissions: null, mustChangePassword: false, session: null } });
    render(<ForcePasswordChangeForm />);
    expect(replace).toHaveBeenCalledWith("/quan-tri");
  });
});
