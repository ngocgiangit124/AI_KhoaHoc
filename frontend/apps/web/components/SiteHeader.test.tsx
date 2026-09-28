import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ToastProvider } from "@vitaminvui/ui";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { SiteHeader } from "./SiteHeader";

const authFetchMock = vi.fn();
vi.mock("@/lib/api", () => ({
  authFetch: (...args: unknown[]) => authFetchMock(...args),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

function renderSiteHeader() {
  return render(
    <ToastProvider>
      <SiteHeader />
    </ToastProvider>,
  );
}

describe("SiteHeader", () => {
  beforeEach(() => {
    authFetchMock.mockReset();
  });

  it("khách chưa đăng nhập (401 UNAUTHENTICATED) -> hiện link Đăng nhập/Đăng ký, KHÔNG hiện tên", async () => {
    authFetchMock.mockRejectedValueOnce(new ApiError(401, { message: "Chưa đăng nhập", code: "UNAUTHENTICATED" }));
    renderSiteHeader();

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
    renderSiteHeader();

    await waitFor(() => expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Đăng xuất" })).toBeInTheDocument();
  });

  it("security L6 — đăng xuất lỗi mạng: hiện toast lỗi tiếng Việt, KHÔNG im lặng, vẫn giữ trạng thái đã đăng nhập", async () => {
    const user = userEvent.setup();
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" }) // GET /auth/me lúc mount
      .mockRejectedValueOnce(new NetworkError(new TypeError("Failed to fetch"))); // POST /auth/logout
    renderSiteHeader();

    await waitFor(() => expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Đăng xuất" }));

    expect(await screen.findByText("Đăng xuất chưa thành công, vui lòng thử lại.")).toBeInTheDocument();
    // Không đăng xuất được ở server thì UI vẫn phải giữ nguyên trạng thái đã đăng nhập
    // (không tự ý coi như đã đăng xuất khi chưa chắc).
    expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument();
  });

  it("security L6 — đăng xuất lỗi 5xx: hiện toast lỗi tiếng Việt", async () => {
    const user = userEvent.setup();
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" })
      .mockRejectedValueOnce(new ApiError(500, { message: "Đã có lỗi xảy ra. Vui lòng thử lại sau." }));
    renderSiteHeader();

    await waitFor(() => expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Đăng xuất" }));

    expect(await screen.findByText("Đăng xuất chưa thành công, vui lòng thử lại.")).toBeInTheDocument();
  });

  it("security L6 — đăng xuất trả 401 (phiên đã hết từ trước): coi như đã đăng xuất, header cập nhật, KHÔNG hiện toast lỗi", async () => {
    const user = userEvent.setup();
    authFetchMock
      .mockResolvedValueOnce({ id: 1, name: "Nguyễn Minh An" }) // GET /auth/me lúc mount
      .mockRejectedValueOnce(new ApiError(401, { message: "Chưa đăng nhập", code: "UNAUTHENTICATED" })) // POST /auth/logout
      .mockRejectedValueOnce(new ApiError(401, { message: "Chưa đăng nhập", code: "UNAUTHENTICATED" })); // GET /auth/me sau auth-changed
    renderSiteHeader();

    await waitFor(() => expect(screen.getByText("Xin chào, Nguyễn Minh An")).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Đăng xuất" }));

    await waitFor(() => expect(screen.getByRole("link", { name: "Đăng nhập" })).toBeInTheDocument());
    expect(screen.queryByText(/Đăng xuất chưa thành công/)).not.toBeInTheDocument();
  });
});
