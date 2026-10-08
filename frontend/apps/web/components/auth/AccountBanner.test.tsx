import { render as rtlRender, screen } from "@testing-library/react";
import type { ReactElement } from "react";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

let pathname = "/";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const render = (ui: ReactElement) => rtlRender(<ToastProvider>{ui}</ToastProvider>);

let authState: AuthState;
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: authState, refresh: vi.fn() }) }));

import { AccountBanner } from "./AccountBanner";

const base = {
  id: 1,
  name: "A",
  email: "a@x.vn",
  phone: "0912345678",
  role: "hoc_sinh",
  grade_level: 9,
  is_verified: false,
  parent_consent_status: "not_required" as const,
};

describe("AccountBanner", () => {
  beforeEach(() => {
    sessionStorage.clear();
    pathname = "/";
    authState = { status: "user", user: base };
  });

  it("khách/đang tải → không hiện", () => {
    authState = { status: "guest" };
    const { rerender } = render(<AccountBanner />);
    expect(screen.queryByText("Cần xác thực tài khoản")).not.toBeInTheDocument();
    authState = { status: "loading" };
    rerender(<ToastProvider><AccountBanner /></ToastProvider>);
    expect(screen.queryByText("Cần xác thực tài khoản")).not.toBeInTheDocument();
  });

  it("chưa xác thực → banner + nút Xác thực ngay trỏ /xac-thuc-otp", () => {
    render(<AccountBanner />);
    expect(screen.getByText("Cần xác thực tài khoản")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xác thực ngay" })).toHaveAttribute("href", "/xac-thuc-otp");
    expect(screen.queryByText(/phụ huynh/)).not.toBeInTheDocument();
  });

  it("mã cũ parent_consent_status=pending không còn đổi banner (ADR-006: chỉ theo is_verified)", () => {
    authState = { status: "user", user: { ...base, parent_consent_status: "pending" } };
    render(<AccountBanner />);
    expect(screen.getByText("Cần xác thực tài khoản")).toBeInTheDocument();
    expect(screen.queryByText(/phụ huynh/)).not.toBeInTheDocument();
  });

  it("đã xác thực nhưng mã cũ pending → không hiện banner chờ phụ huynh", () => {
    authState = { status: "user", user: { ...base, is_verified: true, parent_consent_status: "pending" } };
    render(<AccountBanner />);
    expect(screen.queryByRole("link", { name: "Xác thực ngay" })).not.toBeInTheDocument();
    expect(screen.queryByText(/phụ huynh/)).not.toBeInTheDocument();
  });

  it("đã xác thực, không cờ → không hiện; có cờ verified → báo thành công và xoá cờ", () => {
    authState = { status: "user", user: { ...base, is_verified: true } };
    const { unmount } = render(<AccountBanner />);
    expect(screen.queryByText("Xác thực tài khoản thành công")).not.toBeInTheDocument();
    unmount();
    sessionStorage.setItem("vv:account-flash", "verified");
    render(<AccountBanner />);
    // Design-system-v2 §12.8: thành công báo bằng toast, không còn banner.
    expect(screen.getByRole("status")).toHaveTextContent("Xác thực tài khoản thành công");
    expect(sessionStorage.getItem("vv:account-flash")).toBeNull();
  });

  it("không hiện ở /tai-khoan (trang đó tự có thông báo riêng)", () => {
    pathname = "/tai-khoan";
    render(<AccountBanner />);
    expect(screen.queryByText("Cần xác thực tài khoản")).not.toBeInTheDocument();
  });
});

describe("AccountBanner — cờ xoá tài khoản", () => {
  it("cờ account-deleted hiện toast một lần rồi xoá", () => {
    sessionStorage.clear();
    authState = { status: "guest" };
    sessionStorage.setItem("vv:account-flash", "account-deleted");
    render(<AccountBanner />);
    expect(screen.getByText("Tài khoản của bạn đã được xoá.")).toBeInTheDocument();
    expect(sessionStorage.getItem("vv:account-flash")).toBeNull();
  });
});
