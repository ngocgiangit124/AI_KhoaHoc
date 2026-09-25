import { act, render, screen } from "@testing-library/react";
import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { describe, expect, it, vi } from "vitest";
import { ForcedLogoutOverlay } from "./ForcedLogoutOverlay";

describe("ForcedLogoutOverlay (US-014)", () => {
  it("không hiển thị gì khi chưa có sự kiện", () => {
    render(<ForcedLogoutOverlay />);
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
  });

  it("forced-logout (SESSION_REPLACED) hiện overlay chặn toàn màn hình, không tự điều hướng", () => {
    const navigate = vi.fn();
    render(<ForcedLogoutOverlay navigate={navigate} />);

    act(() => {
      window.dispatchEvent(
        new CustomEvent(FORCED_LOGOUT_EVENT, { detail: { code: "SESSION_REPLACED" } }),
      );
    });

    expect(screen.getByRole("alertdialog")).toBeInTheDocument();
    expect(screen.getByText("Tài khoản vừa đăng nhập ở thiết bị khác")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Đăng nhập lại" })).toHaveAttribute("href", "/dang-nhap");
    expect(navigate).not.toHaveBeenCalled();
  });

  it.each(["SESSION_EXPIRED", "SESSION_REVOKED", "UNAUTHENTICATED", "STAFF_IDLE_TIMEOUT"])(
    "login-required (%s) chuyển hướng thẳng tới trang đăng nhập, KHÔNG hiện overlay",
    (code) => {
      const navigate = vi.fn();
      render(<ForcedLogoutOverlay loginHref="/dang-nhap" navigate={navigate} />);

      act(() => {
        window.dispatchEvent(new CustomEvent(LOGIN_REQUIRED_EVENT, { detail: { code } }));
      });

      expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
      expect(navigate).toHaveBeenCalledTimes(1);
      expect(navigate.mock.calls[0]?.[0]).toMatch(/^\/dang-nhap/);
    },
  );

  it("login-required giữ đường dẫn quay lại qua ?next= (an toàn qua safeRedirect)", () => {
    window.history.pushState({}, "", "/tai-khoan/don-hang?tab=cho-thanh-toan");
    const navigate = vi.fn();
    render(<ForcedLogoutOverlay navigate={navigate} />);

    act(() => {
      window.dispatchEvent(
        new CustomEvent(LOGIN_REQUIRED_EVENT, { detail: { code: "SESSION_EXPIRED" } }),
      );
    });

    expect(navigate).toHaveBeenCalledWith(
      "/dang-nhap?next=" + encodeURIComponent("/tai-khoan/don-hang?tab=cho-thanh-toan"),
    );

    window.history.pushState({}, "", "/");
  });

  it("login-required ở trang chủ không thêm ?next= thừa", () => {
    window.history.pushState({}, "", "/");
    const navigate = vi.fn();
    render(<ForcedLogoutOverlay navigate={navigate} />);

    act(() => {
      window.dispatchEvent(
        new CustomEvent(LOGIN_REQUIRED_EVENT, { detail: { code: "UNAUTHENTICATED" } }),
      );
    });

    expect(navigate).toHaveBeenCalledWith("/dang-nhap");
  });
});
