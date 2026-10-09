import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";
import { resetPaymentConfigCache } from "@/lib/orders/usePaymentConfig";

const fetchPaymentConfig = vi.hoisted(() => vi.fn());
vi.mock("@/lib/orders/api", () => ({ fetchPaymentConfig }));
vi.mock("next/navigation", () => ({ usePathname: () => "/khoa-hoc" }));
vi.mock("@/components/auth/LogoutButton", () => ({ LogoutButton: () => <button type="button">Đăng xuất</button> }));

let state: AuthState;
vi.mock("@/lib/auth/AuthProvider", () => ({ useOptionalAuth: () => ({ state, refresh: vi.fn() }) }));

import { ShellHeader } from "./ShellHeader";

const user = { id: 1, name: "Minh Anh", email: "a@example.com", phone: null, role: "hoc_sinh", grade_level: 9, is_verified: true, cart_count: 3 };
const cfg = (enabled: boolean) => ({ paid_checkout_enabled: enabled, payment_methods: enabled ? ["manual"] : [], manual_payment: null });

beforeEach(() => {
  fetchPaymentConfig.mockReset();
  resetPaymentConfigCache();
});

describe("ShellHeader: biểu tượng giỏ (FW3)", () => {
  it("đã đăng nhập + có phương thức thanh toán -> liên kết giỏ kèm số lượng từ cart_count", async () => {
    state = { status: "user", user };
    fetchPaymentConfig.mockResolvedValue(cfg(true));
    render(<ShellHeader />);
    const link = await screen.findByRole("link", { name: "Giỏ hàng, 3 khóa" });
    expect(link).toHaveAttribute("href", "/gio-hang");
  });

  it("thanh toán tắt (payment_methods rỗng) -> không có giỏ", async () => {
    state = { status: "user", user };
    fetchPaymentConfig.mockResolvedValue(cfg(false));
    render(<ShellHeader />);
    await vi.waitFor(() => expect(fetchPaymentConfig).toHaveBeenCalled());
    expect(screen.queryByRole("link", { name: /Giỏ hàng/ })).not.toBeInTheDocument();
  });

  it("khách -> không gọi config và không có giỏ", () => {
    state = { status: "guest" };
    render(<ShellHeader />);
    expect(fetchPaymentConfig).not.toHaveBeenCalled();
    expect(screen.queryByRole("link", { name: /Giỏ hàng/ })).not.toBeInTheDocument();
  });

  it("lỗi tải config -> ẩn giỏ, không vỡ header", async () => {
    state = { status: "user", user };
    fetchPaymentConfig.mockRejectedValue(new Error("x"));
    render(<ShellHeader />);
    await vi.waitFor(() => expect(fetchPaymentConfig).toHaveBeenCalled());
    expect(screen.queryByRole("link", { name: /Giỏ hàng/ })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "VitaminVui — Trang chủ" })).toBeInTheDocument();
  });
});
