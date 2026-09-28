import { render, screen, waitFor } from "@testing-library/react";
import { ApiError } from "@vitaminvui/api-client";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { SiteHeader } from "./SiteHeader";

const authFetchMock = vi.fn();
vi.mock("@/lib/api", () => ({
  authFetch: (...args: unknown[]) => authFetchMock(...args),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

describe("SiteHeader", () => {
  beforeEach(() => {
    authFetchMock.mockReset();
  });

  it("khách chưa đăng nhập (401 UNAUTHENTICATED) -> hiện link Đăng nhập/Đăng ký, KHÔNG hiện tên", async () => {
    authFetchMock.mockRejectedValueOnce(new ApiError(401, { message: "Chưa đăng nhập", code: "UNAUTHENTICATED" }));
    render(<SiteHeader />);

    expect(await screen.findByRole("link", { name: "Đăng nhập" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Đăng ký" })).toBeInTheDocument();
    expect(screen.queryByText(/Xin chào/)).not.toBeInTheDocument();

    // Dò trạng thái đăng nhập ở nơi KHÔNG bắt buộc đăng nhập phải dùng suppressAuthEvents
    // (không được coi 401 là "mất phiên" — nếu không header công khai sẽ bị
    // ForcedLogoutOverlay ép chuyển hướng /dang-nhap ngay cả với khách chưa đăng nhập).
    expect(authFetchMock).toHaveBeenCalledWith(
      "/api/v1/auth/me",
      expect.objectContaining({ suppressAuthEvents: true }),
    );
  });

  it("đã đăng nhập -> hiện tên và nút Đăng xuất (AC3)", async () => {
    authFetchMock.mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" });
    render(<SiteHeader />);

    await waitFor(() => expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Đăng xuất" })).toBeInTheDocument();
  });
});
