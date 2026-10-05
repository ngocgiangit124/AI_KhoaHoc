import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { STAFF_GATE_EVENT } from "@/lib/api";
import { logoutStaff } from "@/lib/auth/api";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import { AuthGate } from "./AuthGate";
import { AdminShell } from "./AdminShell";
import { RequireRole } from "./RequireRole";
import { SessionWatcher } from "./SessionWatcher";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const replace = vi.fn();
let pathname = "/quan-tri/khoa-hoc";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, refresh: vi.fn() }),
  usePathname: () => pathname,
}));
vi.mock("@/lib/auth/api", () => ({ logoutStaff: vi.fn().mockResolvedValue(undefined) }));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@vitaminvui/ui", async (orig) => ({ ...(await orig<typeof import("@vitaminvui/ui")>()), useToast: () => ({ show: vi.fn() }) }));

const user = (role: StaffUser["role"]): StaffUser => ({ id: 1, name: "Tên", email: null, role, permissions: null, mustChangePassword: false, session: null });
function setSession(state: SessionState) {
  vi.mocked(useSession).mockReturnValue({ state, refresh: vi.fn() });
}

beforeEach(() => {
  vi.clearAllMocks();
  pathname = "/quan-tri/khoa-hoc";
  localStorage.clear();
});

describe("AuthGate", () => {
  it.each([
    [{ kind: "guest", reason: null } as SessionState, "/dang-nhap?next=%2Fquan-tri%2Fkhoa-hoc"],
    [{ kind: "guest", reason: "idle" } as SessionState, "/dang-nhap?next=%2Fquan-tri%2Fkhoa-hoc&reason=idle"],
    [{ kind: "mfa_required" } as SessionState, "/xac-thuc-mfa?next=%2Fquan-tri%2Fkhoa-hoc"],
    [{ kind: "password_change_required" } as SessionState, "/doi-mat-khau?next=%2Fquan-tri%2Fkhoa-hoc"],
  ])("chuyển hướng theo trạng thái %o", (state, url) => {
    setSession(state);
    render(<AuthGate>nội dung</AuthGate>);
    expect(replace).toHaveBeenCalledWith(url);
    expect(screen.queryByText("nội dung")).toBeNull();
  });

  it("bị khoá → thông báo, không có nội dung", () => {
    setSession({ kind: "locked" });
    render(<AuthGate>nội dung</AuthGate>);
    expect(screen.getByText(/đã bị khóa/, { selector: "div" })).toBeInTheDocument();
    expect(screen.queryByText("nội dung")).toBeNull();
  });

  it("lỗi tạm thời → có nút Thử lại, không chuyển trang", () => {
    setSession({ kind: "error" });
    render(<AuthGate>nội dung</AuthGate>);
    expect(screen.getByRole("button", { name: "Thử lại" })).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it("staff → hiển thị khung + nội dung", () => {
    setSession({ kind: "staff", user: user("admin") });
    render(<AuthGate>nội dung</AuthGate>);
    expect(screen.getByText("nội dung")).toBeInTheDocument();
    expect(screen.getByTestId("staff-name")).toHaveTextContent("Tên");
  });
});

describe("AdminShell menu theo vai trò", () => {
  it("giáo viên không thấy mục Tài khoản staff / Nhật ký; mục chưa làm không phải link", () => {
    render(<AdminShell user={user("giao_vien")}>x</AdminShell>);
    expect(screen.queryByText("Tài khoản staff")).toBeNull();
    expect(screen.queryByText("Nhật ký thao tác")).toBeNull();
    expect(screen.getByRole("link", { name: "Tổng quan" })).toHaveAttribute("href", "/quan-tri");
    expect(screen.queryByRole("link", { name: /Khóa học/ })).toBeNull();
  });
  it("admin thấy đủ mục", () => {
    render(<AdminShell user={user("admin")}>x</AdminShell>);
    expect(screen.getByText("Tài khoản staff")).toBeInTheDocument();
    expect(screen.getByText("Nhật ký thao tác")).toBeInTheDocument();
  });
});

describe("RequireRole", () => {
  it("vai trò không đủ quyền → trang 403", () => {
    setSession({ kind: "staff", user: user("quan_ly_trang") });
    render(<RequireRole roles={["admin"]}>bí mật</RequireRole>);
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(screen.getByText("Bạn không có quyền truy cập trang này.")).toBeInTheDocument();
    expect(screen.queryByText("bí mật")).toBeNull();
  });
  it("đủ quyền → hiện nội dung", () => {
    setSession({ kind: "staff", user: user("admin") });
    render(<RequireRole roles={["admin"]}>bí mật</RequireRole>);
    expect(screen.getByText("bí mật")).toBeInTheDocument();
  });
});

describe("SessionWatcher", () => {
  const fire = (name: string, detail: unknown) =>
    act(() => {
      window.dispatchEvent(new CustomEvent(name, { detail }));
    });

  it("401 STAFF_IDLE_TIMEOUT → /dang-nhap kèm reason=idle và next", () => {
    render(<SessionWatcher />);
    fire(LOGIN_REQUIRED_EVENT, { code: "STAFF_IDLE_TIMEOUT" });
    expect(replace).toHaveBeenCalledWith("/dang-nhap?next=%2Fquan-tri%2Fkhoa-hoc&reason=idle");
  });
  it("401 khác → reason=expired", () => {
    render(<SessionWatcher />);
    fire(LOGIN_REQUIRED_EVENT, { code: "UNAUTHENTICATED" });
    expect(replace).toHaveBeenCalledWith("/dang-nhap?next=%2Fquan-tri%2Fkhoa-hoc&reason=expired");
  });
  it("không chuyển hướng khi đang ở trang đăng nhập", () => {
    pathname = "/dang-nhap";
    render(<SessionWatcher />);
    fire(LOGIN_REQUIRED_EVENT, { code: "UNAUTHENTICATED" });
    expect(replace).not.toHaveBeenCalled();
  });
  it("403 cổng: MFA / đổi mật khẩu → trang tương ứng; khoá → overlay", () => {
    render(<SessionWatcher />);
    fire(STAFF_GATE_EVENT, { code: "MFA_REQUIRED" });
    expect(replace).toHaveBeenCalledWith("/xac-thuc-mfa?next=%2Fquan-tri%2Fkhoa-hoc");
    fire(STAFF_GATE_EVENT, { code: "PASSWORD_CHANGE_REQUIRED" });
    expect(replace).toHaveBeenCalledWith("/doi-mat-khau?next=%2Fquan-tri%2Fkhoa-hoc");
    fire(STAFF_GATE_EVENT, { code: "ACCOUNT_LOCKED" });
    expect(screen.getByRole("alertdialog")).toHaveTextContent("Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.");
  });

  it("idle phía trình duyệt: quá hạn thì đăng xuất và chuyển về đăng nhập", async () => {
    vi.useFakeTimers();
    try {
      let t = 1_000_000;
      render(<SessionWatcher now={() => t} idleLimitMs={60_000} />);
      t += 61_000;
      await act(async () => {
        await vi.advanceTimersByTimeAsync(30_000);
      });
      expect(logoutStaff).toHaveBeenCalled();
      expect(replace).toHaveBeenCalledWith("/dang-nhap?next=%2Fquan-tri%2Fkhoa-hoc&reason=idle");
    } finally {
      vi.useRealTimers();
    }
  });
  it("idle mặc định đọc từ session.idle_timeout_minutes đã lưu", async () => {
    vi.useFakeTimers();
    try {
      localStorage.setItem("vv:admin-idle-minutes", "1");
      let t = 1_000_000;
      render(<SessionWatcher now={() => t} />);
      t += 61_000;
      await act(async () => {
        await vi.advanceTimersByTimeAsync(30_000);
      });
      expect(logoutStaff).toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });
  it("có thao tác trong hạn thì không đăng xuất", async () => {
    vi.useFakeTimers();
    try {
      let t = 1_000_000;
      render(<SessionWatcher now={() => t} idleLimitMs={60_000} />);
      t += 50_000;
      act(() => {
        window.dispatchEvent(new Event("pointerdown"));
      });
      t += 50_000;
      await act(async () => {
        await vi.advanceTimersByTimeAsync(30_000);
      });
      expect(logoutStaff).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });
});
