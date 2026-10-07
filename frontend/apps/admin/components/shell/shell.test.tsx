import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { STAFF_GATE_EVENT } from "@/lib/api";
import { logoutStaff } from "@/lib/auth/api";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import { AuthGate } from "./AuthGate";
import { AdminShell, navGroups } from "./AdminShell";
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
vi.mock("@vitaminvui/ui/v2", async (orig) => ({ ...(await orig<typeof import("@vitaminvui/ui/v2")>()), useToast: () => ({ show: vi.fn() }) }));

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
  it("giáo viên không thấy mục Tài khoản staff / Nhật ký; thấy Khóa học (FA3) như một link", () => {
    render(<AdminShell user={user("giao_vien")}>x</AdminShell>);
    expect(screen.queryByText("Tài khoản staff")).toBeNull();
    expect(screen.queryByText("Nhật ký thao tác")).toBeNull();
    expect(screen.getByRole("link", { name: "Tổng quan" })).toHaveAttribute("href", "/quan-tri");
    expect(screen.getAllByRole("link", { name: "Khóa học" })[0]).toHaveAttribute("href", "/quan-tri/khoa-hoc");
  });
  it("admin thấy đủ mục", () => {
    render(<AdminShell user={user("admin")}>x</AdminShell>);
    // Sidebar + ngăn kéo mobile cùng có trong DOM.
    expect(screen.getAllByText("Tài khoản staff").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Nhật ký thao tác").length).toBeGreaterThan(0);
  });
});

describe("navGroups (menu v2)", () => {
  it("nhóm theo design: Nội dung / Bán hàng / Hệ thống; mục chưa có màn là mờ kèm nhãn, không phải link", () => {
    const groups = navGroups({ role: "admin", permissions: null }, "/quan-tri/chuyen-de");
    expect(groups.map((g) => g.label)).toEqual(["Nội dung", "Bán hàng", "Hệ thống"]);
    const items = groups.flatMap((g) => g.items);
    expect(items.find((i) => i.label === "Chuyên đề")).toMatchObject({ href: "/quan-tri/chuyen-de", current: true });
    const courses = items.find((i) => i.label === "Khóa học");
    expect(courses).toMatchObject({ href: "/quan-tri/khoa-hoc", current: false });
    expect(courses).not.toHaveProperty("disabledNote");
    expect(items.find((i) => i.label === "Đơn hàng")).toMatchObject({ disabledNote: "V2", disabledReason: "Mở khi bật thanh toán trực tuyến" });
    expect(items.find((i) => i.label === "Mã giảm giá")?.disabledNote).toBe("Sắp có");
  });
  it("giáo viên: chỉ nhóm Nội dung, ẩn hẳn mục không có quyền", () => {
    const groups = navGroups({ role: "giao_vien", permissions: null }, "/quan-tri");
    expect(groups.map((g) => g.label)).toEqual(["Nội dung"]);
    expect(groups[0]!.items.map((i) => i.label)).toEqual(["Tổng quan", "Khóa học"]);
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
    // Overlay đặc (không trong suốt): không lộ khung/menu phía sau.
    expect(screen.getByRole("alertdialog")).toHaveClass("bg-paper");
    // `<dialog>` modal: focus chuyển vào liên kết đăng nhập (polyfill showModal không tự focus → kiểm thuộc tính open + link có mặt).
    expect(screen.getByRole("alertdialog").tagName).toBe("DIALOG");
    expect(screen.getByRole("alertdialog")).toHaveAttribute("open");
    expect(screen.getByRole("link", { name: "Về trang đăng nhập" })).toHaveAttribute("href", "/dang-nhap");
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
