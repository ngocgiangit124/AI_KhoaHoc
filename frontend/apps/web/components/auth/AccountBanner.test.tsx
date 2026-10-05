import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

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
    authState = { status: "user", user: base };
  });

  it("khách/đang tải → không hiện", () => {
    authState = { status: "guest" };
    const { rerender } = render(<AccountBanner />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    authState = { status: "loading" };
    rerender(<AccountBanner />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("chưa xác thực → banner + nút Xác thực ngay trỏ /xac-thuc-otp", () => {
    render(<AccountBanner />);
    expect(screen.getByText("Cần xác thực tài khoản")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xác thực ngay" })).toHaveAttribute("href", "/xac-thuc-otp");
    expect(screen.queryByText(/phụ huynh/)).not.toBeInTheDocument();
  });

  it("chưa xác thực + phụ huynh pending → thêm lưu ý phụ huynh (theo server, không theo tuổi)", () => {
    authState = { status: "user", user: { ...base, parent_consent_status: "pending" } };
    render(<AccountBanner />);
    expect(screen.getByText(/email xác nhận tới phụ huynh/)).toBeInTheDocument();
  });

  it("đã xác thực, không cờ → không hiện; có cờ verified → báo thành công và xoá cờ", () => {
    authState = { status: "user", user: { ...base, is_verified: true } };
    const { unmount } = render(<AccountBanner />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    unmount();
    sessionStorage.setItem("vv:account-flash", "verified");
    render(<AccountBanner />);
    expect(screen.getByRole("alert")).toHaveTextContent("Xác thực tài khoản thành công");
    expect(sessionStorage.getItem("vv:account-flash")).toBeNull();
  });
});
